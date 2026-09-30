<?php

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/config.php';

use MercadoPago\MercadoPagoConfig;
use MercadoPago\Client\Payment\PaymentClient;

header('Content-Type: application/json');

$input = file_get_contents('php://input');
$data = json_decode($input, true);

file_put_contents(
    __DIR__ . '/webhook.log',
    date('Y-m-d H:i:s') . " | " . $input . PHP_EOL,
    FILE_APPEND | LOCK_EX
);


function telegramSendMessage(string $chatId, string $message): void
{
    $token = trim((string)getenv('BOT_TOKEN'));

    if ($token === '') {
        throw new RuntimeException('BOT_TOKEN não configurado');
    }

    $url = 'https://api.telegram.org/bot' . $token . '/sendMessage';

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_POSTFIELDS => [
            'chat_id' => $chatId,
            'text' => $message,
        ],
    ]);

    $response = curl_exec($ch);
    $error = curl_error($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false || $error !== '') {
        throw new RuntimeException('Erro Telegram: ' . $error);
    }

    if ($http < 200 || $http >= 300) {
        throw new RuntimeException('Telegram HTTP ' . $http);
    }
}

try {

    if (!is_array($data)) {
        echo json_encode(['ok' => true]);
        exit;
    }

    $type = $data['type'] ?? '';

    if ($type !== 'payment') {
        echo json_encode(['ok' => true]);
        exit;
    }

    $paymentId = $data['data']['id'] ?? '';

    if ($paymentId === '') {
        echo json_encode(['ok' => true]);
        exit;
    }

    $envFile = __DIR__ . '/.env';

    if (file_exists($envFile)) {
        foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if (str_starts_with(trim($line), 'MP_ACCESS_TOKEN=')) {
                putenv(trim($line));
                break;
            }
        }
    }

    $token = getenv('MP_ACCESS_TOKEN');

    if (!$token) {
        throw new Exception('MP_ACCESS_TOKEN não encontrado.');
    }

    MercadoPagoConfig::setAccessToken($token);

    $client = new PaymentClient();
    $payment = $client->get((int)$paymentId);

    $status = $payment->status ?? '';

    if ($status !== 'approved') {
        echo json_encode([
            'ok' => true,
            'status' => $status
        ]);
        exit;
    }

    $externalReference = $payment->external_reference ?? '';

    if ($preferenceId === '') {
        echo json_encode(['ok' => true]);
        exit;
    }

    $db = getDatabase();

    $stmt = $db->prepare("
        SELECT
            p.id,
            p.client_id,
            p.subscription_id,
            p.amount,
            p.status,
            c.telegram_id
        FROM payments p
        JOIN clients c ON c.id = p.client_id
        WHERE p.external_reference = :external_reference
        LIMIT 1
    ");

    $stmt->bindValue(':external_reference', $externalReference, SQLITE3_TEXT);

    $result = $stmt->execute();
    $row = $result->fetchArray(SQLITE3_ASSOC);

    if (!$row) {
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($row['status'] === 'approved') {
        echo json_encode([
            'ok' => true,
            'status' => 'already_processed'
        ]);
        exit;
    }

    $amount = (float)($payment->transaction_amount ?? 0);
    $expectedAmount = (float)$row['amount'];

    if (abs($amount - $expectedAmount) > 0.01) {
        file_put_contents(
            __DIR__ . '/webhook.log',
            date('Y-m-d H:i:s') . " | VALOR_INCORRETO payment={$paymentId} recebido={$amount} esperado={$expectedAmount}" . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );

        echo json_encode(['ok' => true]);
        exit;
    }

    $db->exec('BEGIN IMMEDIATE');

    try {

        $update = $db->prepare("
            UPDATE payments
            SET
                payment_id = :payment_id,
                status = 'approved',
                paid_at = datetime('now')
            WHERE id = :id
              AND status = 'pending'
        ");

        $update->bindValue(':payment_id', (string)$paymentId, SQLITE3_TEXT);
        $update->bindValue(':id', (int)$row['id'], SQLITE3_INTEGER);

        if (!$update->execute() || $db->changes() !== 1) {
            $db->exec('ROLLBACK');

            echo json_encode([
                'ok' => true,
                'status' => 'already_processed'
            ]);
            exit;
        }

        $subStmt = $db->prepare("
            SELECT expires_at
            FROM subscriptions
            WHERE id = :id
            LIMIT 1
        ");

        $subStmt->bindValue(':id', (int)$row['subscription_id'], SQLITE3_INTEGER);

        $subResult = $subStmt->execute();
        $subscription = $subResult->fetchArray(SQLITE3_ASSOC);

        if (!$subscription) {
            throw new Exception('Assinatura não encontrada.');
        }

        $now = new DateTime();

        try {
            $expiry = new DateTime($subscription['expires_at']);
        } catch (Exception $e) {
            $expiry = clone $now;
        }

        if ($expiry < $now) {
            $expiry = clone $now;
        }

        $expiry->modify('+30 days');

        $subUpdate = $db->prepare("
            UPDATE subscriptions
            SET
                starts_at = :starts_at,
                expires_at = :expires_at,
                status = 'active'
            WHERE id = :id
        ");

        $subUpdate->bindValue(':starts_at', $now->format('Y-m-d H:i:s'), SQLITE3_TEXT);
        $subUpdate->bindValue(':expires_at', $expiry->format('Y-m-d H:i:s'), SQLITE3_TEXT);
        $subUpdate->bindValue(':id', (int)$row['subscription_id'], SQLITE3_INTEGER);

        if (!$subUpdate->execute()) {
            throw new Exception('Não foi possível renovar a assinatura.');
        }

        $db->exec('COMMIT');

    } catch (Throwable $e) {

        $db->exec('ROLLBACK');
        throw $e;
    }

    sendMessage(
        $row['telegram_id'],
        "PAGAMENTO CONFIRMADO\n\n" .
        "Sua assinatura foi renovada com sucesso.\n\n" .
        "Novo vencimento: " . $expiry->format('d/m/Y H:i:s')
    );

    echo json_encode([
        'ok' => true,
        'status' => 'approved'
    ]);

} catch (Throwable $e) {

    file_put_contents(
        __DIR__ . '/webhook.log',
        date('Y-m-d H:i:s') . " | ERRO: " . $e->getMessage() . PHP_EOL,
        FILE_APPEND | LOCK_EX
    );

    http_response_code(200);

    echo json_encode([
        'ok' => false
    ]);
}
