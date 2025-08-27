quero que o meu install.sh faça essa correção durante a instalação para que o codigo tenha permissão para salvar e apagar os arquivo de backps na pasta backups no meu projeto.
#!/bin/bash

# --- VARIAVEIS DE CONFIGURACAO ---
APACHE_CONF_FILE="netsave.conf"
PROJECT_DIR="/var/www/NetSave_Config"
DOMAIN_NAME="172.16.11.2"  # Substitua pelo seu dominio ou IP
REPO_URL="https://github.com/WanderonRibas/NetSave_Config.git"

# Configuracoes do MariaDB
DB_NAME="net_backup"
DB_USER="net_backup_user"
DB_PASSWORD="net_backup_user"

# --- ETAPA 1: INSTALACAO DOS PACOTES NECESSARIOS ---
echo "--- Atualizando lista de pacotes ---"
apt-get update

echo "--- Instalando Git, Apache2, PHP 8.2, MariaDB e Python ---"
apt-get install -y git apache2 php8.2 libapache2-mod-php8.2 php-mysql \
  mariadb-server mariadb-client python3 python3-pip python3-venv openssl

# --- ETAPA 2: CONFIGURACAO DO PROJETO ---
echo "--- Clonando repositorio do GitHub ---"
git clone "$REPO_URL" "$PROJECT_DIR"

echo "--- Ajustando permissoes do projeto ---"
chown -R www-data:www-data "$PROJECT_DIR"
chmod -R 755 "$PROJECT_DIR"

echo "--- Criando pasta para certificados SSL ---"
mkdir -p "$PROJECT_DIR/certs"

# --- Gerar certificados autoassinados caso nao existam ---
if [ ! -f "$PROJECT_DIR/certs/server.crt" ] || [ ! -f "$PROJECT_DIR/certs/server.key" ]; then
    echo "--- Gerando certificados SSL autoassinados ---"
    openssl req -x509 -nodes -days 365 -newkey rsa:2048 \
        -keyout "$PROJECT_DIR/certs/server.key" \
        -out "$PROJECT_DIR/certs/server.crt" \
        -subj "/CN=$DOMAIN_NAME"
fi

# --- Instalando dependencias Python ---
if [ -f "$PROJECT_DIR/python/requirements.txt" ]; then
    echo "--- Instalando dependencias Python ---"
    python3 -m venv "$PROJECT_DIR/python/venv"
    source "$PROJECT_DIR/python/venv/bin/activate"
    pip3 install -r "$PROJECT_DIR/python/requirements.txt"
    deactivate
fi

# --- ETAPA 3: CONFIGURACAO DO APACHE ---
# Definir ServerName global
grep -q "^ServerName" /etc/apache2/apache2.conf || echo "ServerName $DOMAIN_NAME" >> /etc/apache2/apache2.conf

# Corrige MPM
a2dismod mpm_event mpm_worker
a2enmod mpm_prefork

# Cria arquivo de configuracao do modulo PHP se nao existir
if [ ! -f /etc/apache2/mods-available/php8.2.load ]; then
    echo "LoadModule php_module /usr/lib/apache2/modules/libphp8.2.so" > /etc/apache2/mods-available/php8.2.load
    cat <<EOF > /etc/apache2/mods-available/php8.2.conf
<FilesMatch "\.php$">
    SetHandler application/x-httpd-php
</FilesMatch>
EOF
fi

# Habilita modulos
a2enmod php8.2 ssl rewrite

# --- VirtualHost ---
tee /etc/apache2/sites-available/$APACHE_CONF_FILE > /dev/null <<EOF
<VirtualHost *:80>
    ServerName $DOMAIN_NAME
    Redirect / https://$DOMAIN_NAME/
</VirtualHost>

<VirtualHost *:443>
    ServerName $DOMAIN_NAME
    DocumentRoot $PROJECT_DIR
    DirectoryIndex index.php index.html

    <Directory $PROJECT_DIR>
        Options Indexes FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    <FilesMatch "\.php$">
        SetHandler application/x-httpd-php
    </FilesMatch>

    SSLEngine on
    SSLCertificateFile $PROJECT_DIR/certs/server.crt
    SSLCertificateKeyFile $PROJECT_DIR/certs/server.key
</VirtualHost>
EOF

# Habilita site e desabilita padrao
a2dissite 000-default.conf
a2ensite $APACHE_CONF_FILE

# --- ETAPA 4: CONFIGURACAO DO MARIA DB ---
echo "--- Criando banco de dados e usuario ---"
mysql -e "CREATE DATABASE IF NOT EXISTS \`$DB_NAME\`;"
mysql -e "CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASSWORD';"
mysql -e "GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost';"
mysql -e "FLUSH PRIVILEGES;"

# Importa init.sql apenas se existir
if [ -f "$PROJECT_DIR/mysql/init.sql" ]; then
    echo "--- Importando esquema do banco de dados ---"
    mysql "$DB_NAME" < "$PROJECT_DIR/mysql/init.sql"
fi

# Ajusta permissoes SSL
chown www-data:www-data "$PROJECT_DIR/certs/server.crt" "$PROJECT_DIR/certs/server.key"
chmod 644 "$PROJECT_DIR/certs/server.crt"
chmod 600 "$PROJECT_DIR/certs/server.key"

# --- Testa e reinicia Apache ---
apachectl configtest
if [ $? -eq 0 ]; then
    systemctl restart apache2
    echo "--- Apache reiniciado com sucesso! ---"
else
    echo "Erro de sintaxe. Verifique os logs do Apache."
fi

# --- ETAPA 5: CONFIGURACAO DO SERVICO SYSTEMD PARA APP.PY ---
if [ -f "$PROJECT_DIR/systemd/netsave-app.service" ]; then
    echo "--- Configurando serviço systemd do app.py ---"
    cp "$PROJECT_DIR/systemd/netsave-app.service" /etc/systemd/system/
    systemctl daemon-reload
    systemctl enable netsave-app.service
    systemctl start netsave-app.service
    echo "--- Serviço netsave-app iniciado e habilitado ---"
fi

# --- Fim do script ---
echo "--- Instalação completa! Acesse https://$DOMAIN_NAME ---"
