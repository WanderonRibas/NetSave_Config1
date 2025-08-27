# -*- coding: utf-8 -*-
import threading
import time
import os
import mysql.connector
from datetime import datetime
import sys
import paramiko
import re

# === Dependências importadas (supondo que estão no mesmo diretório) ===
from ler_arquivos import carregar_dispositivos
from chave_client import validar_chave
from backups import backup_huawei, backup_mikrotik,backup_ubiquit
from bkp_vsol import backup_vsol

# Pega o diretório do script Python em execução
script_dir = os.path.dirname(os.path.abspath(__file__))

# === Log simples em arquivo TXT ===
log_file = os.path.join(script_dir, "backup_execucao.log")
sys.stdout = open(log_file, "w", encoding="utf-8")
sys.stderr = sys.stdout

# === Configurações ===
CHAVE_ATIVACAO = "teste"

# Constrói o caminho completo para a pasta de backups.
CAMINHO_BACKUP = os.path.join(script_dir, "backups")

DB_CONFIG = {
    'host': 'localhost',
    'user': 'net_backup_user',
    'password': 'net_backup_user',
    'database': 'net_backup'
}

# === Valida chave ===
if not validar_chave(CHAVE_ATIVACAO, "http://172.16.11.2:5001/validate"):
    sys.exit("Encerrando execução devido à chave inválida.")

# === Cria pasta de backup se necessário ===
os.makedirs(CAMINHO_BACKUP, exist_ok=True)


# --- Função de atualização unificada ---
def atualizar_dispositivo(ip, hostname, status):
    """
    Atualiza o 'status_backup' sempre.
    Só atualiza 'hostname' se o backup for bem-sucedido (status = 1).
    """
    conn = None
    cursor = None
    try:
        conn = mysql.connector.connect(**DB_CONFIG)
        cursor = conn.cursor()

        if int(status) == 1:
            # Backup OK → atualiza hostname e status
            sql = """
                UPDATE dispositivos
                SET hostname = %s, status_backup = %s
                WHERE ip = %s
            """
            dados = (hostname, int(status), ip)
            msg = f"[{ip}] 🔹 Hostname '{hostname}' e status '{int(status)}' atualizados com sucesso."
        else:
            # Backup falhou → atualiza apenas status
            sql = """
                UPDATE dispositivos
                SET status_backup = %s
                WHERE ip = %s
            """
            dados = (int(status), ip)
            msg = f"[{ip}] ⚠️ Backup falhou → apenas status '{int(status)}' atualizado."

        cursor.execute(sql, dados)
        conn.commit()
        print(msg)

    except mysql.connector.Error as e:
        print(f"[{ip}] ❌ Erro ao atualizar dados no banco: {e}")

    finally:
        if cursor:
            cursor.close()
        if conn and conn.is_connected():
            conn.close()


# --- Função para processar cada host ---
def processar_host(disp):
    ip = disp['ip']
    usuario = disp['usuario']
    senha = disp['senha']
    porta = disp.get('porta_ssh', 22)
    vendor = disp['vendor']
    
    hostname = "desconhecido"
    status = False

    print(f"\n[{ip}] Iniciando backup do vendor {vendor}...")

    try:
        # A lógica agora espera que as funções de backup retornem (status, hostname)
        if vendor == "Huawei":
            status, hostname = backup_huawei(ip, porta, usuario, senha, CAMINHO_BACKUP)
        elif vendor == "Mikrotik":
            status, hostname = backup_mikrotik(ip, porta, usuario, senha, CAMINHO_BACKUP)
        elif vendor == "VSOL":
            status, hostname = backup_vsol(ip, porta, usuario, senha, CAMINHO_BACKUP)
        elif vendor == "Ubiquit":
            status, hostname = backup_ubiquit(ip, porta, usuario, senha, CAMINHO_BACKUP)
        else:
            print(f"[{ip}] ❌ Vendor não suportado ou não identificado.")

    except Exception as e:
        print(f"[{ip}] ❌ Erro geral no backup: {e}")
        status = False

    # === Chamada única para atualizar o banco ===
    atualizar_dispositivo(ip, hostname, status)

    if status:
        print(f"[{ip}] ✅ Backup bem-sucedido e registrado no banco")
    else:
        print(f"[{ip}] ❌ Backup falhou e registrado no banco")


# === Execução paralela para todos os dispositivos do banco ===
threads = []
for disp in carregar_dispositivos():
    t = threading.Thread(target=processar_host, args=(disp,))
    threads.append(t)
    t.start()

for t in threads:
    t.join()

print("\n✅ Processo de backup finalizado para todos os hosts.")