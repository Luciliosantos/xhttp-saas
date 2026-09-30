<?php

error_reporting(E_ALL);
ini_set('display_errors', '1');

$configFile = __DIR__ . '/config.php';

if (!file_exists($configFile)) {
    die("ERRO: config.php não encontrado.\n");
}

$config = require $configFile;

// Carrega variáveis do arquivo .env
$envFile = __DIR__ . '/.env';
if (is_file($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || strpos($line, '=') === false) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        if ($key !== '' && getenv($key) === false) {
            putenv($key . '=' . $value);
        }
    }
}

$envFile = __DIR__ . '/.env';
if (is_file($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (strpos($line, '=') === false || str_starts_with(trim($line), '#')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        if ($key !== '' && getenv($key) === false) {
            putenv($key . '=' . $value);
        }
    }
}

/*
|--------------------------------------------------------------------------
| CONFIG
|--------------------------------------------------------------------------
*/

$TOKEN = '';

if (isset($config) && is_array($config)) {
    $TOKEN =
        $config['telegram']['token'] ??
        $config['telegram_token'] ??
        $config['token'] ??
        $config['bot_token'] ??
        '';
}

if (!$TOKEN && defined('BOT_TOKEN')) {
    $TOKEN = BOT_TOKEN;
}

if (!$TOKEN && isset($telegram_token)) {
    $TOKEN = $telegram_token;
}

if (!$TOKEN && getenv('BOT_TOKEN') !== false) {
    $TOKEN = trim((string)getenv('BOT_TOKEN'));
}

if (!$TOKEN) {
    die("ERRO: token do Telegram não configurado em config.php\n");
}

$DB_FILE = __DIR__ . '/data/database.sqlite';

if (!file_exists($DB_FILE)) {
    die("ERRO: banco não encontrado: $DB_FILE\n");
}

$db = new SQLite3($DB_FILE);
$db->busyTimeout(5000);

/*
|--------------------------------------------------------------------------
| TELEGRAM
|--------------------------------------------------------------------------
*/

function api($method, $data = [])
{
    global $TOKEN;

    $url = "https://api.telegram.org/bot{$TOKEN}/{$method}";

    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $data,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30
    ]);

    $response = curl_exec($ch);
    curl_close($ch);

    return json_decode($response, true);
}

function sendMessage($chat_id, $text, $keyboard = null)
{
    $data = [
        'chat_id' => $chat_id,
        'text' => $text,
        'parse_mode' => 'HTML'
    ];

    if ($keyboard !== null) {
        $data['reply_markup'] = json_encode([
            'inline_keyboard' => $keyboard
        ]);
    }

    return api('sendMessage', $data);
}

function editMessage($chat_id, $message_id, $text, $keyboard = null)
{
    $data = [
        'chat_id' => $chat_id,
        'message_id' => $message_id,
        'text' => $text,
        'parse_mode' => 'HTML'
    ];

    if ($keyboard !== null) {
        $data['reply_markup'] = json_encode([
            'inline_keyboard' => $keyboard
        ]);
    }

    return api('editMessageText', $data);
}

function answerCallback($id)
{
    api('answerCallbackQuery', [
        'callback_query_id' => $id
    ]);
}

/*
|--------------------------------------------------------------------------
| TECLADO PRINCIPAL
|--------------------------------------------------------------------------
*/

function mainKeyboard($chatId = null)
{
    global $config;

    $adminId = (string)($config['telegram']['admin_id'] ?? '');
    $isAdmin = $adminId !== '' && $chatId !== null && (string)$chatId === $adminId;

    $keyboard = [
        [
            [
                'text' => '🖥️ Meus Servidores',
                'callback_data' => 'servers'
            ]
        ],
        [
            [
                'text' => '👤 Contas SSH',
                'callback_data' => 'accounts'
            ]
        ],
        [
            [
                'text' => '💳 Minha assinatura',
                'callback_data' => 'subscription'
            ]
        ]
    ];

    if ($isAdmin) {
        $keyboard[] = [
            [
                'text' => '⚙️ Administração',
                'callback_data' => 'admin'
            ]
        ];
    }

    return $keyboard;
}
function serversKeyboard()
{
    return [
        [
            [
                'text' => '➕ Adicionar servidor',
                'callback_data' => 'add_server'
            ]
        ],
        [
            [
                'text' => '🔄 Atualizar',
                'callback_data' => 'servers'
            ]
        ],
        [
            [
                'text' => '◀️ Voltar',
                'callback_data' => 'home'
            ]
        ]
    ];
}

function accountsKeyboard()
{
    return [
        [
            [
                'text' => '➕ Criar conta SSH',
                'callback_data' => 'create_account'
            ]
        ],
        [
            [
                'text' => '🗑️ Excluir conta',
                'callback_data' => 'delete_account'
            ]
        ],
        [
            [
                'text' => '◀️ Voltar',
                'callback_data' => 'home'
            ]
        ]
    ];
}

/*
|--------------------------------------------------------------------------
| SERVIDORES
|--------------------------------------------------------------------------
*/


function sshRemoteExec($server, $command)
{
    $host = trim($server['ip'] ?? $server['host'] ?? '');
    $port = (int)($server['port'] ?? 22);
    $user = trim($server['username'] ?? $server['user'] ?? '');
    $password = (string)($server['credential'] ?? $server['password'] ?? '');

    if ($host === '' || $user === '' || $password === '') {
        return [
            'ok' => false,
            'output' => '',
            'error' => 'Dados SSH do servidor incompletos.'
        ];
    }

    $target = $user . '@' . $host;

    $cmd = 'sshpass -p ' . escapeshellarg($password) .
           ' ssh -o StrictHostKeyChecking=no' .
           ' -o UserKnownHostsFile=/dev/null' .
           ' -o ConnectTimeout=10' .
           ' -p ' . $port .
           ' ' . escapeshellarg($target) .
           ' ' . escapeshellarg($command) .
           ' 2>&1';

    exec($cmd, $output, $rc);

    return [
        'ok' => ($rc === 0),
        'output' => implode("\n", $output),
        'error' => ($rc === 0 ? '' : implode("\n", $output))
    ];
}

function getClientId($telegramId)
{
    global $db;

    $telegramId = (string)$telegramId;

    $stmt = $db->prepare("
        SELECT id
        FROM clients
        WHERE telegram_id = :telegram_id
        LIMIT 1
    ");
    $stmt->bindValue(':telegram_id', $telegramId, SQLITE3_TEXT);

    $result = $stmt->execute();
    $row = $result->fetchArray(SQLITE3_ASSOC);

    if ($row) {
        return (int)$row['id'];
    }

    $stmt = $db->prepare("
        INSERT INTO clients (telegram_id, name)
        VALUES (:telegram_id, :name)
    ");
    $stmt->bindValue(':telegram_id', $telegramId, SQLITE3_TEXT);
    $stmt->bindValue(':name', 'Cliente ' . $telegramId, SQLITE3_TEXT);

    if (!$stmt->execute()) {
        return 0;
    }

    return (int)$db->lastInsertRowID();
}

function getSubscription($telegramId)
{
    global $db;

    $clientId = getClientId($telegramId);

    if ($clientId <= 0) {
        return null;
    }

    $stmt = $db->prepare("
        SELECT
            s.id,
            s.client_id,
            s.plan_id,
            s.starts_at,
            s.expires_at,
            s.status,
            p.name AS plan_name,
            p.days,
            p.price
        FROM subscriptions s
        INNER JOIN plans p ON p.id = s.plan_id
        WHERE s.client_id = :client_id
        ORDER BY s.id DESC
        LIMIT 1
    ");

    $stmt->bindValue(':client_id', $clientId, SQLITE3_INTEGER);

    $result = $stmt->execute();

    return $result->fetchArray(SQLITE3_ASSOC) ?: null;
}

function subscriptionIsActive($telegramId)
{
    $subscription = getSubscription($telegramId);

    if (!$subscription) {
        return false;
    }

    if ($subscription['status'] !== 'active') {
        return false;
    }

    return strtotime($subscription['expires_at']) > time();
}

function renewSubscription($telegramId)
{
    global $db;

    $clientId = getClientId($telegramId);

    if ($clientId <= 0) {
        return false;
    }

    $subscription = getSubscription($telegramId);

    if (!$subscription) {
        return false;
    }

    $now = time();
    $currentExpiry = strtotime($subscription['expires_at']);

    if ($currentExpiry > $now) {
        $newExpiry = date(
            'Y-m-d H:i:s',
            strtotime('+30 days', $currentExpiry)
        );
    } else {
        $newExpiry = date(
            'Y-m-d H:i:s',
            strtotime('+30 days')
        );
    }

    $stmt = $db->prepare("
        UPDATE subscriptions
        SET starts_at = :starts_at,
            expires_at = :expires_at,
            status = 'active'
        WHERE id = :id
    ");

    $stmt->bindValue(
        ':starts_at',
        date('Y-m-d H:i:s'),
        SQLITE3_TEXT
    );

    $stmt->bindValue(
        ':expires_at',
        $newExpiry,
        SQLITE3_TEXT
    );

    $stmt->bindValue(
        ':id',
        (int)$subscription['id'],
        SQLITE3_INTEGER
    );

    return (bool)$stmt->execute();
}

function getServers()
{
    global $db;

    $result = $db->query("
        SELECT *
        FROM servers
        ORDER BY id DESC
    ");

    $servers = [];

    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $servers[] = $row;
    }

    return $servers;
}

function serversText()
{
    $servers = getServers();

    $text = "🖥️ <b>MEUS SERVIDORES</b>\n\n";

    if (!$servers) {
        $text .= "Você ainda não possui servidores cadastrados.\n";
    } else {

        foreach ($servers as $server) {

            $id = (int)($server['id'] ?? 0);
            $name = $server['name'] ?? "Servidor #{$id}";
            $host = $server['host'] ?? ($server['ip'] ?? '-');
            $port = $server['port'] ?? 22;
            $user = $server['username'] ?? ($server['user'] ?? 'root');

            $text .=
                "🖥️ <b>{$name}</b>\n" .
                "🌐 {$host}\n" .
                "🔌 Porta: {$port}\n" .
                "👤 Usuário: {$user}\n\n";
        }
    }

    return $text;
}

/*
|--------------------------------------------------------------------------
| CONTAS SSH
|--------------------------------------------------------------------------
*/

function accountsText()
{
    global $db;

    $result = $db->query("
        SELECT *
        FROM ssh_accounts
        ORDER BY id DESC
    ");

    $text = "👤 <b>CONTAS SSH</b>\n\n";

    $count = 0;

    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {

        $count++;

        $status =
            (($row['status'] ?? '') === 'active')
            ? '🟢'
            : '🔴';

        $id = $row['id'] ?? '-';
        $username = htmlspecialchars($row['username'] ?? '-');
        $expires = htmlspecialchars($row['expires_at'] ?? '-');

        $text .=
            "{$status} <b>{$username}</b>\n" .
            "⏳ Vencimento: {$expires}\n" .
            "🆔 ID: {$id}\n\n";
    }

    if ($count === 0) {
        $text .= "Nenhuma conta SSH cadastrada.\n";
    }

    return $text;
}

/*
|--------------------------------------------------------------------------
| LOOP
|--------------------------------------------------------------------------
*/

$offset = 0;

echo "🚀 XHTTP SaaS iniciado.\n";

while (true) {

    // EXPIRAÇÃO AUTOMÁTICA DAS CONTAS SSH
$expired = $db->query("
    SELECT id, username, server_id
    FROM ssh_accounts
    WHERE expires_at <= datetime('now', '-3 hours')
");

while ($account = $expired->fetchArray(SQLITE3_ASSOC)) {
    $username = trim($account['username']);
    $id = (int)$account['id'];
    $serverId = (int)($account['server_id'] ?? 0);

    if ($username !== '' && $serverId > 0) {
        $serverStmt = $db->prepare("
            SELECT *
            FROM servers
            WHERE id = :id
            LIMIT 1
        ");
        $serverStmt->bindValue(':id', $serverId, SQLITE3_INTEGER);
        $server = $serverStmt->execute()->fetchArray(SQLITE3_ASSOC);

        if (!$server) {
            continue;
        }

        $u = escapeshellarg($username);

        $deleteRemote = sshRemoteExec(
            $server,
            "if id -u " . $u . " >/dev/null 2>&1; then userdel -r " . $u . "; fi"
        );

        if (!$deleteRemote['ok']) {
            continue;
        }
    }

    $delExpired = $db->prepare("
        DELETE FROM ssh_accounts
        WHERE id = :id
    ");
    $delExpired->bindValue(':id', $id, SQLITE3_INTEGER);
    $delExpired->execute();
}

$result = api('getUpdates', [
        'offset' => $offset,
        'timeout' => 25
    ]);

    if (!$result || !isset($result['ok'])) {
        sleep(2);
        continue;
    }

    $updates = (isset($result['result']) && is_array($result['result']))
        ? $result['result']
        : [];

    foreach ($updates as $update) {

        $offset = $update['update_id'] + 1;

        /*
        |--------------------------------------------------------------------------
        | MENSAGEM
        |--------------------------------------------------------------------------
        */

        if (isset($update['message'])) {

            $message = $update['message'];

            if (!isset($message['chat']['id'])) {
                continue;
            }

            $chat_id = $message['chat']['id'];
            $text = trim($message['text'] ?? '');

$adminIdCheck = (string)($config['telegram']['admin_id'] ?? '');
$isAdminCheck = $adminIdCheck !== '' && (string)$chat_id === $adminIdCheck;

if (!$isAdminCheck && !subscriptionIsActive($chat_id)) {
    if (
        $text === '🖥️ Meus Servidores' ||
        $text === '👤 Contas SSH'
    ) {
        sendMessage(
            $chat_id,
            "🔒 <b>Acesso bloqueado</b>\n\n" .
            "Você precisa ter uma assinatura ativa para utilizar esta função.\n\n" .
            "💳 Acesse <b>Minha assinatura</b> para contratar ou renovar."
        );
        continue;
    }
}


            if ($text === '/start') {

                sendMessage(
                    $chat_id,
                    "🚀 <b>XHTTP SaaS</b>\n\n" .
                    "Bem-vindo ao sistema.\n\n" .
                    "Escolha uma opção abaixo:",
                    mainKeyboard($chat_id)
                );

                continue;
            }

            /*
            |--------------------------------------------------------------------------
            | CANCELAR
            |--------------------------------------------------------------------------
            */

            if ($text === '/cancelar') {

                sendMessage(
                    $chat_id,
                    "❌ Operação cancelada.",
                    mainKeyboard($chat_id)
                );

                continue;
            }
        }

        /*
        |--------------------------------------------------------------------------
        | CALLBACK
        |--------------------------------------------------------------------------
        */

        if (isset($update['callback_query'])) {

            $callback = $update['callback_query'];

            $callback_id = $callback['id'];
            $data = $callback['data'] ?? '';

    $chat_id = $callback['message']['chat']['id'];
    $message_id = $callback['message']['message_id'];

            $adminIdBlock = (string)($config['telegram']['admin_id'] ?? '');
            $isAdminBlock = $adminIdBlock !== '' && (string)$chat_id === $adminIdBlock;

            if (
                !$isAdminBlock &&
                !subscriptionIsActive($chat_id) &&
                in_array($data, [
                    'servers',
                    'add_server',
                    'accounts',
                    'list_accounts',
                    'create_account',
                    'delete_account'
                ], true)
            ) {
                editMessage(
                    $chat_id,
                    $message_id,
                    "🔒 <b>Acesso bloqueado</b>\n\n" .
                    "Você precisa ter uma assinatura ativa para utilizar esta função.\n\n" .
                    "💳 Acesse <b>Minha assinatura</b> para contratar ou renovar."
                );
                continue;
            }


            $chat_id = $callback['message']['chat']['id'];
            $message_id = $callback['message']['message_id'];

            answerCallback($callback_id);

            /*
            |--------------------------------------------------------------------------
            | HOME
            |--------------------------------------------------------------------------
            */

            if ($data === 'home') {

                editMessage(
                    $chat_id,
                    $message_id,
                    "🚀 <b>XHTTP SaaS</b>\n\nEscolha uma opção:",
                    mainKeyboard($chat_id)
                );

                continue;
            }

            /*
            |--------------------------------------------------------------------------
            | SERVIDORES
            |--------------------------------------------------------------------------
            */

            if ($data === 'subscription') {

                $clientId = getClientId($chat_id);

                $stmt = $db->prepare("
                    SELECT
                        p.name AS plan_name,
                        p.price,
                        s.starts_at,
                        s.expires_at,
                        s.status
                    FROM subscriptions s
                    INNER JOIN plans p ON p.id = s.plan_id
                    WHERE s.client_id = :client_id
                    ORDER BY s.id DESC
                    LIMIT 1
                ");
                $stmt->bindValue(':client_id', $clientId, SQLITE3_INTEGER);

                $subscription = $stmt->execute()->fetchArray(SQLITE3_ASSOC);

                if (!$subscription) {
                    $textSubscription = "💳 <b>MINHA ASSINATURA</b>\n\nNenhuma assinatura encontrada.";
                } else {
                    $textSubscription =
                        "💳 <b>MINHA ASSINATURA</b>\n\n" .
                        "📦 Plano: <b>" . htmlspecialchars($subscription['plan_name']) . "</b>\n" .
                        "💰 Valor: <b>R$ " . number_format((float)$subscription['price'], 2, ',', '.') . "</b>\n" .
                        "📅 Início: <b>" . htmlspecialchars($subscription['starts_at']) . "</b>\n" .
                        "⏰ Vencimento: <b>" . htmlspecialchars($subscription['expires_at']) . "</b>\n" .
                        "📌 Status: <b>" .
                        (
                            subscriptionIsActive($chat_id)
                                ? "🟢 Ativa"
                                : "🔴 Expirada"
                        ) .
                        "</b>";
                }

                editMessage(
                    $chat_id,
                    $message_id,
                    $textSubscription,
                    [
                        [
                            [
                                'text' => '🔄 Renovar por R$ 15,00',
                                'callback_data' => 'renew_subscription'
                            ]
                        ],
                        [
                            [
                                'text' => '◀️ Voltar',
                                'callback_data' => 'home'
                            ]
                        ]
                    ]
                );

                continue;
            }

            if ($data === 'renew_subscription') {

                editMessage(
                    $chat_id,
                    $message_id,
                    "💳 <b>RENOVAÇÃO DA ASSINATURA</b>\n\n" .
                    "📦 Plano: <b>Mensal</b>\n" .
                    "💰 Valor: <b>R$ 15,00</b>\n" .
                    "📅 Duração: <b>30 dias</b>\n\n" .
                    "Deseja continuar para o pagamento?",
                    [
                        [
                            [
                                'text' => '✅ Continuar',
                                'callback_data' => 'confirm_renew_subscription'
                            ]
                        ],
                        [
                            [
                                'text' => '❌ Cancelar',
                                'callback_data' => 'subscription'
                            ]
                        ]
                    ]
                );

                continue;
            }

if ($data === 'confirm_renew_subscription') { $lines = file(__DIR__ 
    . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES); 
    $token = ''; foreach ($lines as $line) {
        if (strpos($line, 'MP_ACCESS_TOKEN=') === 0) { $token = 
            trim(substr($line, strlen('MP_ACCESS_TOKEN='))); break;
        }
    }
    if ($token === '') { editMessage( $chat_id, $message_id, 
            "Mercado Pago não está configurado.", [[
                [ 'text' => 'Voltar', 'callback_data' => 
                    'subscription'
                ] ]] ); continue;
    }
    require_once __DIR__ . '/vendor/autoload.php'; try { $clientId = 
        getClientId($chat_id); $subscription = 
        getSubscription($chat_id); if ($clientId <= 0 || 
        !$subscription) {
            throw new Exception('Assinatura não encontrada.');
        }
        \MercadoPago\MercadoPagoConfig::setAccessToken($token); 
        $client = new 
        \MercadoPago\Client\Preference\PreferenceClient(); 
        $preference = $client->create([
        'notification_url' => 'https://mp.lsnet.shop:8443/',
                'external_reference' => 'subscription_' . (int)$subscription['id'] . '_client_' . (int)$clientId . '_' . time(),
        'items' => [ [ 'title' => 'Renovação Mensal - XHTTP 
                    SaaS', 'quantity' => 1, 'unit_price' => 15.00
                ] ] ]); $preferenceId = $preference->id ?? ''; 
        $paymentLink = $preference->init_point ?? ''; if 
        ($preferenceId === '' || $paymentLink === '') {
            throw new Exception('Dados do pagamento não 
            retornados.');
        }
        $stmt = $db->prepare(" INSERT INTO payments (client_id, 
            subscription_id, preference_id, external_reference, amount, status) VALUES 
            (:client_id, :subscription_id, :preference_id, :external_reference, :amount, 
            'pending')
        "); $stmt->bindValue(':client_id', $clientId, 
        SQLITE3_INTEGER); $stmt->bindValue(
            ':subscription_id', (int)$subscription['id'], 
            SQLITE3_INTEGER
        ); $stmt->bindValue( ':preference_id', $preferenceId, 
            SQLITE3_TEXT
        ); $stmt->bindValue(':external_reference', $externalReference, SQLITE3_TEXT); $stmt->bindValue(':amount', 15.00, SQLITE3_FLOAT); if 
        (!$stmt->execute()) {
            throw new Exception('Não foi possível registrar o 
            pagamento.');
        }
        editMessage( $chat_id, $message_id, "PAGAMENTO DA 
            ASSINATURA\n\n" . "Plano: Mensal\n" . "Valor: R$ 
            15,00\n" . "Duração: 30 dias\n\n" . "Clique abaixo para 
            realizar o pagamento.\n\n" . "A assinatura só será 
            renovada após a confirmação do pagamento.", [
                [ [ 'text' => 'Pagar R$ 15,00', 'url' => 
                        $paymentLink
                    ] ], [ [ 'text' => 'Voltar', 'callback_data' => 
                        'subscription'
                    ] ] ] );
    } catch (\Throwable $e) {
        editMessage( $chat_id, $message_id, "Não foi possível criar 
            o pagamento.\n\n" . "Tente novamente.", [
                [ [ 'text' => 'Tentar novamente', 'callback_data' => 
                        'confirm_renew_subscription'
                    ] ], [ [ 'text' => 'Voltar', 'callback_data' => 
                        'subscription'
                    ] ] ] );
    }
    continue;
}


            if ($data === 'servers') {

                editMessage(
                    $chat_id,
                    $message_id,
                    serversText(),
                    serversKeyboard()
                );

                continue;
            }

            /*
            |--------------------------------------------------------------------------
            | ADICIONAR SERVIDOR
            |--------------------------------------------------------------------------
            */

            if ($data === 'add_server') {

                sendMessage(
                    $chat_id,
                    "➕ <b>Adicionar servidor</b>\n\n" .
                    "Envie os dados neste formato:\n\n" .
                    "<code>IP|PORTA|USUARIO|SENHA</code>\n\n" .
                    "Exemplo:\n" .
                    "<code>1.2.3.4|22|root|senha</code>\n\n" .
                    "Para cancelar: /cancelar"
                );

                continue;
            }

            /*
            |--------------------------------------------------------------------------
            | CONTAS
            |--------------------------------------------------------------------------
            */

            if ($data === 'accounts') {

                editMessage(
                    $chat_id,
                    $message_id,
                    accountsText(),
                    accountsKeyboard()
                );

                continue;
            }

            /*
            |--------------------------------------------------------------------------
            | LISTAR CONTAS
            |--------------------------------------------------------------------------
            */

            if ($data === 'list_accounts') {

                sendMessage(
                    $chat_id,
                    accountsText(),
                    accountsKeyboard()
                );

                continue;
            }

            /*
            |--------------------------------------------------------------------------
            | CRIAR CONTA
            |--------------------------------------------------------------------------
            */

            if ($data === 'create_account') {

            $db->exec("
                CREATE TABLE IF NOT EXISTS bot_states (
                    chat_id INTEGER PRIMARY KEY,
                    step TEXT NOT NULL,
                    username TEXT DEFAULT '',
                    password TEXT DEFAULT ''
                )
            ");

            $stmt = $db->prepare("
                INSERT INTO bot_states (chat_id, step, username, password)
                VALUES (:chat_id, 'username', '', '')
                ON CONFLICT(chat_id) DO UPDATE SET
                    step = 'username',
                    username = '',
                    password = ''
            ");
            $stmt->bindValue(':chat_id', $chat_id, SQLITE3_INTEGER);
            $stmt->execute();

            sendMessage(
                $chat_id,
                "➕ <b>CRIAR CONTA SSH</b>

"
                . "👤 Digite o <b>USUÁRIO</b> que deseja criar:

"
                . "Para cancelar: /cancelar"
            );

            continue;
        }

            /*
            |--------------------------------------------------------------------------
            | EXCLUIR CONTA
            |--------------------------------------------------------------------------
            */

            if ($data === 'delete_account') {

                sendMessage(
                    $chat_id,
                    "🗑️ <b>Excluir conta SSH</b>\n\n" .
                    "Envie o ID da conta que deseja excluir.\n\n" .
                    "Para cancelar: /cancelar"
                );

                continue;
            }

            /*
            |--------------------------------------------------------------------------
            | ADMIN
            |--------------------------------------------------------------------------
            */

            if ($data === 'admin') {

                $keyboard = [
                    [
                        [
                            'text' => '📊 Estatísticas',
                            'callback_data' => 'stats'
                        ]
                    ],
                    [
                        [
                            'text' => '🖥️ Servidores',
                            'callback_data' => 'servers'
                        ]
                    ],
                    [
                        [
                            'text' => '👤 Contas SSH',
                            'callback_data' => 'accounts'
                        ]
                    ],
                    [
                        [
                            'text' => '◀️ Voltar',
                            'callback_data' => 'home'
                        ]
                    ]
                ];

                editMessage(
                    $chat_id,
                    $message_id,
                    "⚙️ <b>ADMINISTRAÇÃO</b>\n\n" .
                    "Área administrativa do sistema.",
                    $keyboard
                );

                continue;
            }

            /*
            |--------------------------------------------------------------------------
            | ESTATÍSTICAS
            |--------------------------------------------------------------------------
            */

            if ($data === 'stats') {

                $servers = count(getServers());

                $result = $db->querySingle(
                    "SELECT COUNT(*) FROM ssh_accounts"
                );

                $accounts = (int)$result;

                sendMessage(
                    $chat_id,
                    "📊 <b>ESTATÍSTICAS</b>\n\n" .
                    "🖥️ Servidores: <b>{$servers}</b>\n" .
                    "👤 Contas SSH: <b>{$accounts}</b>",
                    [
                        [
                            [
                                'text' => '◀️ Voltar',
                                'callback_data' => 'admin'
                            ]
                        ]
                    ]
                );

                continue;
            }
        }

        /*
        |--------------------------------------------------------------------------
        | PROCESSAMENTO DE TEXTO
        |--------------------------------------------------------------------------
        */

        if (isset($update['message'])) {

            $message = $update['message'];

            if (!isset($message['chat']['id'])) {
                continue;
            }

            $chat_id = $message['chat']['id'];
            $text = trim($message['text'] ?? '');

            /*
            |--------------------------------------------------------------------------
            | CADASTRAR SERVIDOR
            |--------------------------------------------------------------------------
            */

            if (
                strpos($text, '|') !== false &&
                preg_match('/^([^|]+)\|(\d+)\|([^|]+)\|(.+)$/', $text, $m)
            ) {
                $host = trim($m[1]);
                $port = (int)$m[2];
                $username = trim($m[3]);
                $password = trim($m[4]);

                $clientId = getClientId($chat_id);

                $adminId = (string)($config['telegram']['admin_id'] ?? '');
                $isAdmin = $adminId !== '' && (string)$chat_id === $adminId;

                if (!$isAdmin && !subscriptionIsActive($chat_id)) {
                    sendMessage(
                        $chat_id,
                        "🔒 <b>Assinatura necessária</b>\n\n" .
                        "Você precisa ter uma assinatura ativa para cadastrar um servidor."
                    );
                    continue;
                }

                if ($clientId <= 0) {
                    sendMessage(
                        $chat_id,
                        "❌ Não foi possível identificar o cliente."
                    );
                    continue;
                }

                $stmt = $db->prepare("
                    INSERT INTO servers
                    (client_id, name, ip, port, username, credential, status)
                    VALUES
                    (:client_id, :name, :ip, :port, :username, :credential, :status)
                ");

                $stmt->bindValue(':client_id', $clientId, SQLITE3_INTEGER);
                $stmt->bindValue(':name', 'Servidor ' . $host, SQLITE3_TEXT);
                $stmt->bindValue(':ip', $host, SQLITE3_TEXT);
                $stmt->bindValue(':port', $port, SQLITE3_INTEGER);
                $stmt->bindValue(':username', $username, SQLITE3_TEXT);
                $stmt->bindValue(':credential', $password, SQLITE3_TEXT);
                $stmt->bindValue(':status', 'unknown', SQLITE3_TEXT);

                if ($stmt->execute()) {
                    sendMessage(
                        $chat_id,
                        "✅ <b>SERVIDOR CADASTRADO!</b>\n\n" .
                        "🖥️ {$host}\n" .
                        "🔌 Porta: {$port}\n" .
                        "👤 Usuário: {$username}",
                        serversKeyboard()
                    );
                } else {
                    sendMessage(
                        $chat_id,
                        "❌ Não foi possível cadastrar o servidor."
                    );
                }

                continue;
            }

/* ============================================================
   ASSISTENTE DE CRIAÇÃO DE CONTA SSH
   Usuário -> Senha -> Dias
   ============================================================ */

if (isset($update['message']) && isset($update['message']['text'])) {

    $db->exec("
        CREATE TABLE IF NOT EXISTS bot_states (
            chat_id INTEGER PRIMARY KEY,
            step TEXT NOT NULL,
            username TEXT DEFAULT '',
            password TEXT DEFAULT ''
        )
    ");

    $stateStmt = $db->prepare("
        SELECT step, username, password
        FROM bot_states
        WHERE chat_id = :chat_id
        LIMIT 1
    ");
    $stateStmt->bindValue(':chat_id', $chat_id, SQLITE3_INTEGER);
    $stateResult = $stateStmt->execute();
    $state = $stateResult ? $stateResult->fetchArray(SQLITE3_ASSOC) : false;

    if ($state) {

        /* ETAPA 1 - USUÁRIO */
        if ($state['step'] === 'username') {

            $username = trim($text);

            if ($username === '' || !preg_match('/^[a-zA-Z0-9_.-]+$/', $username)) {
                sendMessage(
                    $chat_id,
                    "❌ Usuário inválido.\n\n"
                    . "Use somente letras, números, ponto, hífen ou _.\n"
                    . "Digite o usuário novamente:"
                );
                continue;
            }

            $stmt = $db->prepare("
                UPDATE bot_states
                SET step = 'password', username = :username
                WHERE chat_id = :chat_id
            ");
            $stmt->bindValue(':username', $username, SQLITE3_TEXT);
            $stmt->bindValue(':chat_id', $chat_id, SQLITE3_INTEGER);
            $stmt->execute();

            sendMessage(
                $chat_id,
                "👤 Usuário: <code>" . htmlspecialchars($username) . "</code>\n\n"
                . "🔑 Agora digite a <b>SENHA</b>:"
            );

            continue;
        }

        /* ETAPA 2 - SENHA */
        if ($state['step'] === 'password') {

            $password = trim($text);

            if ($password === '' || strlen($password) < 1 || strlen($password) > 64 || strpos($password, '|') !== false) {
                sendMessage(
                    $chat_id,
                    "❌ Senha inválida.\n\n"
                    . "Digite uma senha válida novamente:"
                );
                continue;
            }

            $stmt = $db->prepare("
                UPDATE bot_states
                SET step = 'days', password = :password
                WHERE chat_id = :chat_id
            ");
            $stmt->bindValue(':password', $password, SQLITE3_TEXT);
            $stmt->bindValue(':chat_id', $chat_id, SQLITE3_INTEGER);
            $stmt->execute();

            sendMessage(
                $chat_id,
                "🔑 Senha recebida.\n\n"
                . "📅 Agora digite a quantidade de <b>DIAS</b> da conta:\n\n"
                . "Exemplo: <code>30</code>"
            );

            continue;
        }

        /* ETAPA 3 - DIAS */
        if ($state['step'] === 'days') {

            $daysText = trim($text);

            if (!ctype_digit($daysText) || (int)$daysText < 1 || (int)$daysText > 3650) {
                sendMessage(
                    $chat_id,
                    "❌ Quantidade de dias inválida.\n\n"
                    . "Digite somente números.\n"
                    . "Exemplo: <code>30</code>"
                );
                continue;
            }

            $days = (int)$daysText;
            $username = $state['username'];
            $password = $state['password'];

            /* Apaga o estado antes de entrar no código original */
            $stmt = $db->prepare("
                DELETE FROM bot_states
                WHERE chat_id = :chat_id
            ");
            $stmt->bindValue(':chat_id', $chat_id, SQLITE3_INTEGER);
            $stmt->execute();

            /* O bloco original já existente fará a criação real */
            $text = $username . '|' . $password . '|' . $days;
        }
    }
}

/*
            |--------------------------------------------------------------------------
            | CRIAR CONTA SSH
            |--------------------------------------------------------------------------
            */

            if (
                preg_match('/^([^|]+)\|([^|]+)\|(\d+)$/', $text, $m)
            ) {

                $username = trim($m[1]);
                $password = trim($m[2]);
                $days = (int)$m[3];

                if ($days < 1) {
                    $days = 1;
                }

                $expires = date(
                    'Y-m-d H:i:s',
                    strtotime("+{$days} days")
                );

                $servers = getServers();

                if (!$servers) {

                    sendMessage(
                        $chat_id,
                        "❌ Nenhum servidor cadastrado."
                    );

                    continue;
                }

                $server = $servers[0];

                $stmt = $db->prepare("
                    INSERT INTO ssh_accounts
                    (server_id, username, password, expires_at, status)
                    VALUES
                    (:server_id, :username, :password, :expires_at, 'active')
                ");

                $stmt->bindValue(
                    ':server_id',
                    (int)$server['id'],
                    SQLITE3_INTEGER
                );

                $stmt->bindValue(
                    ':username',
                    $username,
                    SQLITE3_TEXT
                );

                $stmt->bindValue(
                    ':password',
                    $password,
                    SQLITE3_TEXT
                );

                $stmt->bindValue(
                    ':expires_at',
                    $expires,
                    SQLITE3_TEXT
                );

                if ($stmt->execute()) {

                    // CRIAR USUARIO SSH REAL NO SERVIDOR
                    $u = escapeshellarg($username);
                    $pw = escapeshellarg($password);

                    exec("id -u " . $u . " >/dev/null 2>&1", $check, $exists);

                    if ($exists === 0) {
                        sendMessage(
                            $chat_id,
                            "❌ O usuário <code>" . htmlspecialchars($username) . "</code> já existe no servidor."
                        );

                        $del = $db->prepare("DELETE FROM ssh_accounts WHERE username = :username");
                        $del->bindValue(':username', $username, SQLITE3_TEXT);
                        $del->execute();

                        continue;
                    }

                    $createRemote = sshRemoteExec(
                        $server,
                        "useradd -m -s /bin/bash " . $u
                    );

                    if (!$createRemote['ok']) {
                        sendMessage(
                            $chat_id,
                            "❌ Não foi possível criar o usuário SSH no servidor."
                        );

                        $del = $db->prepare("DELETE FROM ssh_accounts WHERE username = :username");
                        $del->bindValue(':username', $username, SQLITE3_TEXT);
                        $del->execute();

                        continue;
                    }

                    $passwordRemote = sshRemoteExec(
                        $server,
                        "printf '%s:%s\\n' " . $u . " " . $pw . " | chpasswd"
                    );

                    if (!$passwordRemote['ok']) {
                        sshRemoteExec(
                            $server,
                            "userdel -r " . $u . " >/dev/null 2>&1"
                        );
                        sendMessage(
                            $chat_id,
                            "❌ O usuário foi criado, mas não foi possível definir a senha."
                        );

                        $del = $db->prepare("DELETE FROM ssh_accounts WHERE username = :username");
                        $del->bindValue(':username', $username, SQLITE3_TEXT);
                        $del->execute();

                        continue;
                    }

                    $expireDate = date('Y-m-d', strtotime($expires));
$expireRemote = sshRemoteExec(
                        $server,
                        "chage -E " . escapeshellarg($expireDate) . " " . $u
                    );

                    if (!$expireRemote["ok"]) {
                        sshRemoteExec(
                            $server,
                            "userdel -r " . $u . " >/dev/null 2>&1"
                        );
                        sendMessage(
                            $chat_id,
                            "❌ Não foi possível configurar a validade da conta."
                        );

                        $del = $db->prepare("DELETE FROM ssh_accounts WHERE username = :username");
                        $del->bindValue(':username', $username, SQLITE3_TEXT);
                        $del->execute();

                        continue;
                    }

                    sendMessage(
                        $chat_id,
                        "✅ <b>CONTA CADASTRADA</b>\n\n" .
                        "👤 Usuário: <code>{$username}</code>\n" .
                        "🔑 Senha: <code>{$password}</code>\n" .
                        "⏳ Dias: {$days}\n" .
                        "📅 Vencimento: {$expires}\n\n" .
                        "✅ Usuário SSH criado no servidor com sucesso."
                    );

                } else {

                    sendMessage(
                        $chat_id,
                        "❌ Erro ao salvar a conta."
                    );
                }

                continue;
            }

            /*
            |--------------------------------------------------------------------------
            | EXCLUIR CONTA POR ID
            |--------------------------------------------------------------------------
            */

            if (ctype_digit($text)) {


                $id = (int)$text;

    // EXCLUIR USUÁRIO SSH REAL DO SERVIDOR
                    $find = $db->prepare("
                        SELECT username, server_id
                        FROM ssh_accounts
                        WHERE id = :id
                    ");
                    $find->bindValue(':id', $id, SQLITE3_INTEGER);
                    $account = $find->execute()->fetchArray(SQLITE3_ASSOC);

                    if ($account && !empty($account['username'])) {
                        $u = escapeshellarg($account['username']);
                        $serverId = (int)($account['server_id'] ?? 0);

                        $serverStmt = $db->prepare("
                            SELECT *
                            FROM servers
                            WHERE id = :id
                            LIMIT 1
                        ");
                        $serverStmt->bindValue(':id', $serverId, SQLITE3_INTEGER);
                        $server = $serverStmt->execute()->fetchArray(SQLITE3_ASSOC);

                        if (!$server) {
                            sendMessage(
                                $chat_id,
                                "❌ Não foi possível localizar o servidor da conta."
                            );
                            continue;
                        }

                        $deleteRemote = sshRemoteExec(
                            $server,
                            "if id -u " . $u . " >/dev/null 2>&1; then userdel -r " . $u . "; fi"
                        );

                        if (!$deleteRemote['ok']) {
                            sendMessage(
                                $chat_id,
                                "❌ Não foi possível remover o usuário SSH do servidor."
                            );
                            continue;
                        }
                    }

$stmt = $db->prepare(
                    "DELETE FROM ssh_accounts WHERE id = :id"
                );

                $stmt->bindValue(
                    ':id',
                    $id,
                    SQLITE3_INTEGER
                );

                $stmt->execute();

                sendMessage(
                    $chat_id,
                    "🗑️ Conta #{$id} removida do cadastro.",
                    accountsKeyboard()
                );

                continue;
            }
        }
    }
}
