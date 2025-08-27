import paramiko
from netmiko import ConnectHandler
from datetime import datetime
import time
import re
import os

def criar_caminho_backup(base_dir, hostname, ext):
    """
    Cria dinamicamente a pasta do dispositivo e retorna caminho com timestamp.
    """
    pasta_host = os.path.join(base_dir, hostname)
    os.makedirs(pasta_host, exist_ok=True)

    timestamp = datetime.now().strftime("%Y-%m-%d_%H-%M-%S")
    nome_arquivo = f"{hostname}_{timestamp}{ext}"
    return os.path.join(pasta_host, nome_arquivo)

def backup_huawei(host, port, username, password, caminho_backup):
    device = {
        'device_type': 'huawei',
        'host': host,
        'port': port,
        'username': username,
        'password': password,
        'secret': password,
    }

    try:
        os.makedirs(caminho_backup, exist_ok=True)

        with ConnectHandler(**device) as conn:
            print(f"[{host}] Conectado com sucesso.")

            # 1. Pega o hostname (sysname no Huawei)
            sysname_output = conn.send_command('display current-configuration | include sysname')
            match = re.search(r'sysname\s+(\S+)', sysname_output)
            if match:
                hostname = match.group(1).strip()
            else:
                hostname = host.replace('.', '_')
                print(f"[{host}] Aviso: sysname não encontrado, usando IP como hostname.")

            # 2. Obtém a configuração completa
            conn.send_command('screen-length 0 temporary')
            config_output = conn.send_command('display current-configuration')

        # 3. Salva arquivo
        filename = criar_caminho_backup(caminho_backup, hostname, ".cfg")
        with open(filename, 'w', encoding='utf-8') as f:
            f.write(config_output)

        print(f"[{host}] ✅ Backup Huawei salvo em {filename}")
        return True, hostname

    except Exception as e:
        print(f"[{host}] ❌ Erro no backup: {e}")
        return False, None


def backup_mikrotik(host, port, username, password, caminho_backup):
    ssh = None
    sftp = None
    try:
        ssh = paramiko.SSHClient()
        ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
        ssh.connect(hostname=host, port=port, username=username, password=password, timeout=10)

        # Pega hostname (identity no Mikrotik)
        stdin, stdout, stderr = ssh.exec_command("/system identity print")
        identidade_saida = stdout.read().decode()
        match = re.search(r'name:\s*(\S+)', identidade_saida)
        if not match:
            hostname = host.replace('.', '_')
            print(f"[{host}] Aviso: não foi possível extrair identity, usando IP como hostname.")
        else:
            hostname = match.group(1)

        # Exporta config
        ssh.exec_command(f"/export file={hostname}")
        time.sleep(10)

        caminho_remoto = f"/{hostname}.rsc"
        os.makedirs(caminho_backup, exist_ok=True)
        nome_arquivo_local = criar_caminho_backup(caminho_backup, hostname, ".rsc")

        sftp = ssh.open_sftp()
        sftp.get(caminho_remoto, nome_arquivo_local)
        print(f"[{host}] ✅ Backup MikroTik salvo em {nome_arquivo_local}")
        return True, hostname

    except Exception as e:
        print(f"[{host}] ❌ Falha backup MikroTik: {e}")
        return False, None
    finally:
        if sftp: sftp.close()
        if ssh: ssh.close()


def backup_ubiquit(host, port, username, password, caminho_backup):
    ssh = None
    sftp = None
    try:
        ssh = paramiko.SSHClient()
        ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
        ssh.connect(hostname=host, port=port, username=username, password=password, timeout=10)

        # Pega hostname do próprio IP (não há comando padrão para nome no Ubiquiti EdgeOS)
        hostname = f"UBT_{host.replace('.', '_')}"

        caminho_remoto = '/config/config.boot'
        os.makedirs(caminho_backup, exist_ok=True)
        nome_arquivo_local = os.path.join(caminho_backup, f"{hostname}_config.boot")

        sftp = ssh.open_sftp()
        sftp.get(caminho_remoto, nome_arquivo_local)
        print(f"[{host}] ✅ Backup Ubiquiti salvo em {nome_arquivo_local}")
        return True, hostname

    except Exception as e:
        print(f"[{host}] ❌ Falha backup Ubiquiti: {e}")
        return False, None
    finally:
        if sftp: sftp.close()
        if ssh: ssh.close()
