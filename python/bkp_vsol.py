import paramiko
import logging
import time
import datetime
import os
import re

# --- Habilita debug do paramiko ---
paramiko.common.logging.basicConfig(level=paramiko.common.DEBUG)

# --- Função auxiliar para ler do shell até detectar prompt ---
def read_shell_until_prompt(shell, prompt_pattern=None, timeout=10, buffer_size=65535, interval=0.1):
    if prompt_pattern is None:
        prompt_pattern = [r'.*[>#]\s*$']  # padrão geral

    data = b''
    end_time = time.time() + timeout

    while True:
        if shell.recv_ready():
            chunk = shell.recv(buffer_size)
            data += chunk
            decoded_data = data.decode('utf-8', errors='ignore')
            print("DEBUG >>", repr(decoded_data.splitlines()[-1]))
            for pattern in prompt_pattern:
                if re.search(pattern, decoded_data):
                    print("DEBUG >> Prompt detectado com regex:", pattern)
                    return decoded_data
            end_time = time.time() + timeout
        else:
            time.sleep(interval)

        if time.time() > end_time:
            decoded_data = data.decode('utf-8', errors='ignore')
            print("DEBUG >> Timeout, último buffer recebido:\n", decoded_data)
            raise TimeoutError("Timeout aguardando prompt ou dados.")

# --- Cria caminho de backup ---
def criar_caminho_backup(base_dir, hostname, ext):
    pasta_host = os.path.join(base_dir, hostname)
    os.makedirs(pasta_host, exist_ok=True)
    timestamp = datetime.datetime.now().strftime("%Y-%m-%d_%H-%M-%S")
    nome_arquivo = f"{hostname}_{timestamp}{ext}"
    return os.path.join(pasta_host, nome_arquivo)

# --- Login adaptativo para antigas e novas versões ---
def adaptive_login(shell, username, password, timeout=30):
    end_time = time.time() + timeout
    buffer = ""

    while time.time() < end_time:
        if shell.recv_ready():
            data = shell.recv(65535).decode(errors="ignore")
            buffer += data

            # Se aparecer prompt de login antigo
            if re.search(r'Login:\s*$', buffer, re.IGNORECASE) or re.search(r'Username:\s*$', buffer, re.IGNORECASE):
                shell.send(username + "\n")
                buffer += read_shell_until_prompt(shell, prompt_pattern=[r'Password:\s*$'], timeout=10)
                shell.send(password + "\n")
                buffer += read_shell_until_prompt(shell, prompt_pattern=[r'.*[>#]\s*$'], timeout=20)
                return buffer

            # Se aparecer apenas prompt de senha
            if re.search(r'Password:\s*$', buffer, re.IGNORECASE):
                shell.send(password + "\n")
                buffer += read_shell_until_prompt(shell, prompt_pattern=[r'.*[>#]\s*$'], timeout=20)
                return buffer

            # Se cair direto no prompt novo
            if re.search(r'>\s*$', buffer) or re.search(r'#\s*$', buffer):
                return buffer

        else:
            time.sleep(0.5)

    raise TimeoutError("Timeout aguardando login/prompt")

# --- Função principal de backup compatível ---
def backup_vsol(host, port, username, password, caminho_backup):
    client = None
    try:
        client = paramiko.SSHClient()
        client.set_missing_host_key_policy(paramiko.AutoAddPolicy())
        client.connect(host, port=port, username=username, password=password, timeout=10)
        shell = client.invoke_shell()
        time.sleep(1)

        # Login adaptativo
        output_buffer = adaptive_login(shell, username, password, timeout=20)

        # Se precisar, entra no enable
        if re.search(r'>\s*$', output_buffer):
            shell.send("enable\n")
            output_buffer += read_shell_until_prompt(shell, prompt_pattern=[r'Password:\s*$', r'#\s*$'], timeout=5)
            shell.send(password + '\n')
            output_buffer += read_shell_until_prompt(shell, prompt_pattern=[r'#\s*$'], timeout=10)

        print(f"[+] Conectado a {host}. Executando comandos...")

        shell.send("terminal length 0\n")
        output_buffer += read_shell_until_prompt(shell, prompt_pattern=[r'#\s*$', r'>\s*$'], timeout=5)

        shell.send("show running-config\n")
        output = read_shell_until_prompt(shell, prompt_pattern=[r'#\s*$', r'>\s*$'], timeout=60, buffer_size=65535)

        # Extrai hostname
        hostname = "olt_vsol"
        match = re.search(r'^hostname\s+(\S+)', output, re.MULTILINE)
        if match:
            hostname = match.group(1).strip()
            print(f"[+] Hostname '{hostname}' encontrado.")
        else:
            print(f"[-] Hostname não encontrado. Usando padrão: '{hostname}'")

        # Limpa saída
        inicio = output.find("show running-config")
        if inicio != -1:
            output_limpo = output[inicio:]
            linhas = output_limpo.splitlines()
            if linhas and "show running-config" in linhas[0]:
                linhas = linhas[1:]
            linhas_filtradas = [linha for linha in linhas if not linha.strip().endswith("#") and linha.strip() != '']
            saida_final = "\n".join(linhas_filtradas)
        else:
            saida_final = output

        # Salva backup
        caminho_arquivo = criar_caminho_backup(caminho_backup, hostname, ".cfg")
        with open(caminho_arquivo, "w", encoding='utf-8') as f:
            f.write(saida_final.strip())

        print(f"[+] Backup salvo em {caminho_arquivo}")
        return True, hostname

    except paramiko.AuthenticationException:
        print(f"[-] Erro de autenticação para {host}.")
        return False, None
    except paramiko.SSHException as e:
        print(f"[-] Erro de SSH para {host}: {e}")
        return False, None
    except TimeoutError:
        print(f"[-] Timeout ao conectar {host}.")
        return False, None
    except Exception as e:
        print(f"[-] Erro inesperado em {host}: {e}")
        return False, None
    finally:
        if client:
            client.close()
            print(f"[*] Conexão com {host} fechada.")

# --- Exemplo de uso ---
if __name__ == "__main__":
    host = "172.16.212.2"
    port = 50022
    username = "userbkp"
    password = "Master77415611@#"
    caminho_backup = 'C:/Users/Wanderson/Documents/Projeto1/Projeto.Sistema.Backup/Backups'

    backup_vsol(host, port, username, password, caminho_backup)
