<?php
declare(strict_types=1);

/**
 * WhatsApp Cloud API webhook endpoint.
 *
 * Configure in Meta App Dashboard → WhatsApp → Configuration:
 *   Callback URL : https://YOURDOMAIN/invoicechat/webhook.php
 *   Verify token : the value of WHATSAPP_VERIFY_TOKEN in config/config.php
 *
 * Then subscribe to the "messages" webhook field.
 */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/db.php';

// ── 1. Verification handshake (GET) ───────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $mode      = $_GET['hub_mode']         ?? ($_GET['hub.mode']         ?? '');
    $token     = $_GET['hub_verify_token'] ?? ($_GET['hub.verify_token'] ?? '');
    $challenge = $_GET['hub_challenge']    ?? ($_GET['hub.challenge']    ?? '');

    if ($mode === 'subscribe' && hash_equals(WHATSAPP_VERIFY_TOKEN, (string)$token)) {
        header('Content-Type: text/plain');
        echo $challenge;
        exit;
    }
    http_response_code(403);
    echo 'Verification failed';
    exit;
}

// ── 2. Incoming events (POST) ─────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Always ACK quickly so Meta doesn't retry.
    http_response_code(200);

    $raw     = file_get_contents('php://input');
    $payload = json_decode($raw, true) ?? [];

    wa_log($raw);

    try {
        handleWebhookPayload($payload);
    } catch (\Throwable $e) {
        wa_log('Handler error: ' . $e->getMessage());
    }
    exit;
}

http_response_code(405);
echo 'Method not allowed';
exit;

// ──────────────────────────────────────────────────────────────────

function wa_log(string $line): void
{
    $dir = __DIR__ . '/logs';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    @file_put_contents(
        $dir . '/whatsapp.log',
        '[' . date('Y-m-d H:i:s') . '] ' . $line . PHP_EOL,
        FILE_APPEND
    );
}

/**
 * Walk the Cloud API payload and act on each incoming message.
 * Statuses (sent/delivered/read) are logged but otherwise ignored.
 */
function handleWebhookPayload(array $payload): void
{
    foreach ($payload['entry'] ?? [] as $entry) {
        foreach ($entry['changes'] ?? [] as $change) {
            $value = $change['value'] ?? [];

            // Delivery/read receipts
            foreach ($value['statuses'] ?? [] as $status) {
                wa_log("Status: {$status['status']} for {$status['id']}");
            }

            // Inbound messages
            foreach ($value['messages'] ?? [] as $message) {
                $from = $message['from'] ?? '';
                $type = $message['type'] ?? '';
                $text = $message['text']['body'] ?? '';
                wa_log("Message from {$from} ({$type}): {$text}");

                onIncomingMessage($from, $type, $text, $message);
            }
        }
    }
}

/**
 * Hook for incoming WhatsApp messages.
 *
 * Currently this auto-replies with a simple acknowledgement so you can verify
 * the round-trip end to end. To drive the full InvoiceChat bot over WhatsApp,
 * map $from (phone number) to a user account and pipe the text through
 * \InvoiceChat\BotEngine here.
 */
function onIncomingMessage(string $from, string $type, string $text, array $message): void
{
    if (WHATSAPP_TOKEN === '' || WHATSAPP_PHONE_NUMBER_ID === '' || $from === '') {
        return;
    }

    require_once __DIR__ . '/vendor/autoload.php';

    $wa    = new \InvoiceChat\WhatsApp(WHATSAPP_TOKEN, WHATSAPP_PHONE_NUMBER_ID, WHATSAPP_API_VERSION);
    $reply = "Hi! This is " . APP_NAME . ". We received your message and will get back to you shortly.";
    $res   = $wa->sendText($from, $reply);

    if (!$res['ok']) {
        wa_log('Auto-reply failed: ' . ($res['error'] ?? 'unknown'));
    }
}
