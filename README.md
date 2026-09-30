# XHTTP SaaS

SaaS para gerenciamento de servidores e contas SSH através do Telegram.

## Instalação rápida

Na VPS nova, execute:

    bash <(curl -fsSL https://raw.githubusercontent.com/Luciliosantos/xhttp-saas/main/install.sh)

## Requisitos

- VPS Linux com acesso root
- Internet
- Bot Telegram
- Mercado Pago para pagamentos

## Arquitetura

Telegram -> Bot XHTTP SaaS -> VPS central -> SSH -> Servidor do cliente

## Serviços

Ver status: systemctl status xhttp-saas

Reiniciar: systemctl restart xhttp-saas

Parar: systemctl stop xhttp-saas

Iniciar: systemctl start xhttp-saas

Logs: journalctl -u xhttp-saas -f

## Atualização

cd /root/xhttp-saas
git pull origin main
composer install --no-dev --optimize-autoloader
systemctl restart xhttp-saas

## Segurança

Nunca publicar tokens do Telegram, Access Token do Mercado Pago, senhas SSH, chaves privadas ou certificados privados.

## Repositório

https://github.com/Luciliosantos/xhttp-saas

## ⚡ Instalação automática

Em uma VPS nova, execute como root:

```bash
bash <(curl -fsSL https://raw.githubusercontent.com/Luciliosantos/xhttp-saas/main/install.sh)
```

O instalador configura automaticamente PHP, Composer, banco SQLite, bot, webhook, serviço systemd e Nginx.

### Webhook

O webhook utiliza a porta 8443. Os certificados devem ficar em `ssl/origin.crt` e `ssl/origin.key`.

### Segurança

Nunca publique tokens, senhas SSH, Access Token do Mercado Pago ou chaves privadas no GitHub.
