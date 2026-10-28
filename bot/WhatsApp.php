<?php
declare(strict_types=1);

namespace InvoiceChat;

/**
 * Thin wrapper around the Meta WhatsApp Cloud API (graph.facebook.com).
 *
 * Sending notes:
 *  - To message a user with free-form content (text/document), the user must
 *    have messaged your number within the last 24 hours (the "customer service
 *    window"). Outside that window you must use an approved message *template*.
 *  - While your app is in development/unpublished, you can only message the
 *    recipients you've added as test numbers in the Meta dashboard.
 */
class WhatsApp
{
    public function __construct(
        private string $token,
        private string $phoneNumberId,
        private string $apiVersion = 'v21.0'
    ) {}

    public function isConfigured(): bool
    {
        return $this->token !== '' && $this->phoneNumberId !== '';
    }

    /** Normalise a phone number to digits only (E.164 without the +). */
    public static function normalise(string $phone): string
    {
        return preg_replace('/\D+/', '', $phone) ?? '';
    }

    /** Send a plain text message. Returns [ok, response|error]. */
    public function sendText(string $to, string $body): array
    {
        return $this->post([
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => self::normalise($to),
            'type'              => 'text',
            'text'              => ['preview_url' => true, 'body' => $body],
        ]);
    }

    /** Send a document (e.g. an invoice PDF) by public link. */
    public function sendDocument(string $to, string $link, string $filename, string $caption = ''): array
    {
        $doc = ['link' => $link, 'filename' => $filename];
        if ($caption !== '') $doc['caption'] = $caption;

        return $this->post([
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => self::normalise($to),
            'type'              => 'document',
            'document'          => $doc,
        ]);
    }

    /**
     * Send a pre-approved template message (needed outside the 24h window).
     * $components is the Cloud API "components" array (may be empty).
     */
    public function sendTemplate(string $to, string $templateName, string $lang = 'en_US', array $components = []): array
    {
        $template = ['name' => $templateName, 'language' => ['code' => $lang]];
        if (!empty($components)) $template['components'] = $components;

        return $this->post([
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => self::normalise($to),
            'type'              => 'template',
            'template'          => $template,
        ]);
    }

    // ── Internal HTTP ─────────────────────────────────────────────
    private function post(array $payload): array
    {
        $url = sprintf(
            'https://graph.facebook.com/%s/%s/messages',
            $this->apiVersion,
            $this->phoneNumberId
        );

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $this->token,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_TIMEOUT        => 30,
        ]);

        $raw  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            return ['ok' => false, 'error' => $err ?: 'Request failed'];
        }

        $json = json_decode((string)$raw, true) ?? [];
        if ($code >= 200 && $code < 300 && empty($json['error'])) {
            return ['ok' => true, 'response' => $json];
        }

        $msg = $json['error']['message'] ?? ('HTTP ' . $code);
        return ['ok' => false, 'error' => $msg, 'response' => $json];
    }
}
