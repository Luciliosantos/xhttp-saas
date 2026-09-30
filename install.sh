#!/bin/bash
set -e

APP_DIR="/root/xhttp-saas"
REPO="https://github.com/Luciliosantos/xhttp-saas.git"

echo "========================================"
echo "       XHTTP SaaS - INSTALADOR"
echo "========================================"

if [ "$(id -u)" != "0" ]; then
    echo "ERRO: execute como root."
    exit 1
fi

echo "[1/6] Atualizando sistema..."
apt-get update -y

echo "[2/6] Instalando dependências..."
apt-get install -y \
    git \
    curl \
    unzip \
    php-cli \
    php-sqlite3 \
    php-curl \
    php-mbstring \
    php-xml \
    php-zip \
    composer \
    nginx \
    sshpass

echo "[3/6] Preparando projeto..."

if [ -d "$APP_DIR/.git" ]; then
    cd "$APP_DIR"
    git pull --ff-only origin main
else
    rm -rf "$APP_DIR"
    git clone "$REPO" "$APP_DIR"
    cd "$APP_DIR"
fi

echo "[4/6] Instalando Composer..."
composer install --no-dev --optimize-autoloader

mkdir -p "$APP_DIR/data"
mkdir -p "$APP_DIR/ssl"

echo "[5/6] Configurando ambiente..."

if [ ! -f "$APP_DIR/.env" ]; then
    read -rp "Token do bot Telegram: " BOT_TOKEN
    read -rsp "Access Token do Mercado Pago: " MP_ACCESS_TOKEN
    echo
    read -rp "Domínio do webhook (ex: mp.seudominio.com): " MP_DOMAIN

    cat > "$APP_DIR/.env" <<ENV
BOT_TOKEN=$BOT_TOKEN
MP_ACCESS_TOKEN=$MP_ACCESS_TOKEN
MP_DOMAIN=$MP_DOMAIN
ENV

    chmod 600 "$APP_DIR/.env"
else
    echo ".env já existe. Mantendo configuração atual."
fi

echo "[6/6] Verificando sistema..."

php -l "$APP_DIR/bot.php"
php -l "$APP_DIR/webhook.php"

cat > /etc/systemd/system/xhttp-saas.service <<SERVICE
[Unit]
Description=XHTTP SaaS Telegram Bot
After=network.target

[Service]
Type=simple
WorkingDirectory=$APP_DIR
ExecStart=/usr/bin/php $APP_DIR/bot.php
Restart=always
RestartSec=5
User=root

[Install]
WantedBy=multi-user.target
SERVICE

systemctl daemon-reload
systemctl enable xhttp-saas.service

echo
echo "========================================"
echo "       INSTALAÇÃO PREPARADA"
echo "========================================"
echo
echo "Projeto: $APP_DIR"
echo "Serviço: xhttp-saas"
echo
echo "Para iniciar:"
echo "systemctl start xhttp-saas"
echo
