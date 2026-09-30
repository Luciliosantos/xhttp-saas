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

echo "[6/6] Criando banco SQLite..."

DB="$APP_DIR/data/database.sqlite"

php -r '
$db = new SQLite3(getenv("DB"));

$tables = [
"CREATE TABLE IF NOT EXISTS clients (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    telegram_id TEXT UNIQUE NOT NULL,
    name TEXT,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP
)",

"CREATE TABLE IF NOT EXISTS servers (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    client_id INTEGER NOT NULL,
    name TEXT,
    ip TEXT NOT NULL,
    port INTEGER DEFAULT 22,
    username TEXT NOT NULL,
    credential TEXT NOT NULL,
    status TEXT DEFAULT "unknown",
    created_at TEXT DEFAULT CURRENT_TIMESTAMP
)",

"CREATE TABLE IF NOT EXISTS ssh_accounts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    server_id INTEGER NOT NULL,
    username TEXT NOT NULL,
    password TEXT NOT NULL,
    expires_at TEXT NOT NULL,
    status TEXT DEFAULT "active",
    created_at TEXT DEFAULT CURRENT_TIMESTAMP
)",

"CREATE TABLE IF NOT EXISTS bot_states (
    chat_id INTEGER PRIMARY KEY,
    step TEXT NOT NULL,
    username TEXT DEFAULT "",
    password TEXT DEFAULT ""
)",

"CREATE TABLE IF NOT EXISTS plans (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    days INTEGER NOT NULL,
    price REAL NOT NULL,
    active INTEGER DEFAULT 1
)",

"CREATE TABLE IF NOT EXISTS subscriptions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    client_id INTEGER NOT NULL,
    plan_id INTEGER NOT NULL,
    starts_at TEXT NOT NULL,
    expires_at TEXT NOT NULL,
    status TEXT DEFAULT "active"
)",

"CREATE TABLE IF NOT EXISTS payments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    client_id INTEGER NOT NULL,
    subscription_id INTEGER NOT NULL,
    preference_id TEXT NOT NULL UNIQUE,
    external_reference TEXT DEFAULT "",
    payment_id TEXT DEFAULT "",
    amount REAL NOT NULL,
    status TEXT DEFAULT "pending",
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    paid_at TEXT DEFAULT ""
)"
];

foreach ($tables as $sql) {
    if (!$db->exec($sql)) {
        fwrite(STDERR, "Erro SQLite: ".$db->lastErrorMsg().PHP_EOL);
        exit(1);
    }
}

$db->exec("INSERT INTO plans (name, days, price, active)
SELECT "Mensal", 30, 15.0, 1
WHERE NOT EXISTS (SELECT 1 FROM plans)");

echo "Banco SQLite configurado com sucesso.\n";
' DB="$DB"

echo "Verificando PHP..."
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
