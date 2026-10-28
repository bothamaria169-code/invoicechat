<?php
declare(strict_types=1);

require_once __DIR__ . '/billing.php';

/**
 * The AI assistant, on Groq's free API (OpenAI-compatible, Llama 3.3 70B).
 *
 *  - "Describe it": one sentence ("Invoice Acme for 3 logos at R500 and
 *    hosting R200, 15% VAT, due in 14 days") becomes a draft invoice or quote.
 *  - Logo ideas: text prompts for the logo maker.
 *
 * Key: console.groq.com → API Keys, saved in Admin → Settings → AI.
 * Monthly limits per plan are in Admin → Payments → Prices & plans.
 */
function aiKey(): string
{
    return trim(get_setting('groq_api_key', (string) secret('groq_api_key', '')));
}

function aiEnabled(): bool
{
    return aiKey() !== '' && get_setting('ai_enabled', '1') === '1';
}

/** Requests left this month for this user. */
function aiLeft(array $user): int
{
    $c = billingConfig();
    $limit = isPro($user) ? $c['pro_ai'] : $c['free_ai'];
    $s = getDB()->prepare('SELECT COUNT(*) FROM ai_requests WHERE user_id = ? AND ok = 1 AND created_at >= ?');
    $s->execute([$user['id'], date('Y-m-01 00:00:00')]);
    return max(0, $limit - (int) $s->fetchColumn());
}

/**
 * One chat completion that must answer with JSON.
 *
 * @return array{ok: bool, data: array, error: string}
 */
function groqJson(string $system, string $user, int $maxTokens = 900): array
{
    $ch = curl_init('https://api.groq.com/openai/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . aiKey(), 'Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => json_encode([
            'model'           => get_setting('groq_model', 'llama-3.3-70b-versatile'),
            'temperature'     => 0.2,
            'max_tokens'      => $maxTokens,
            'response_format' => ['type' => 'json_object'],
            'messages'        => [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $user]],
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 8,
    ]);
    $raw  = (string) curl_exec($ch);
    $err  = curl_error($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($err !== '') return ['ok' => false, 'data' => [], 'error' => $err];
    $res = json_decode($raw, true) ?: [];
    if ($code !== 200) return ['ok' => false, 'data' => [], 'error' => (string) ($res['error']['message'] ?? 'HTTP ' . $code)];
    $data = json_decode((string) ($res['choices'][0]['message']['content'] ?? ''), true);
    return is_array($data) ? ['ok' => true, 'data' => $data, 'error' => ''] : ['ok' => false, 'data' => [], 'error' => 'The AI answer was not readable.'];
}

function logAi(int $userId, string $kind, bool $ok): void
{
    getDB()->prepare('INSERT INTO ai_requests (user_id, kind, ok) VALUES (?, ?, ?)')->execute([$userId, $kind, $ok ? 1 : 0]);
}

/** Does this free text look like someone describing an invoice? */
function looksLikeDocumentRequest(string $text): bool
{
    return mb_strlen($text) >= 15
        && preg_match('/\d/', $text)
        && preg_match('/\b(invoice|quote|quotation|bill|charge|for|@|x\s?\d|each|per|rand|R\s?\d)/i', $text);
}

/**
 * Turns one sentence into a structured document.
 *
 * @return array{ok: bool, doc: array, error: string}
 */
function aiParseDocument(array $user, string $text, array $defaults): array
{
    $today  = date('Y-m-d');
    $system = <<<SYS
You turn a South African small-business owner's message into an invoice or quote. Today is {$today}.
Answer ONLY with JSON in this shape:
{"doc_type":"invoice|quote","client_name":"","client_email":"","client_phone":"","items":[{"name":"","qty":1,"unit_price":0,"description":""}],"tax_rate":null,"discount_percent":null,"currency":null,"due_date":null,"notes":"","missing":[]}
Rules:
- doc_type is "quote" only if they say quote/quotation/estimate; otherwise "invoice".
- unit_price is the price of ONE unit, a plain number without currency symbols. "3 logos at R500 each" = qty 3, unit_price 500. "3 logos for R1500" = qty 3, unit_price 500.
- Prices are in rand unless another currency is named. currency is a 3-letter code or null.
- "VAT" alone means tax_rate 15. Use null when not mentioned so the user's defaults apply.
- due_date is YYYY-MM-DD; "in 14 days" means today + 14. null if not mentioned.
- Never invent clients, items or prices. Put anything you couldn't find (like "client name" or "price for X") in "missing".
- Keep item names short and professional (e.g. "Logo design").
SYS;
    $r = groqJson($system, $text);
    logAi((int) $user['id'], 'draft', $r['ok']);
    if (!$r['ok']) return ['ok' => false, 'doc' => [], 'error' => $r['error']];

    $d = $r['data'];
    $items = [];
    foreach ((array) ($d['items'] ?? []) as $it) {
        $name = trim((string) ($it['name'] ?? ''));
        $qty  = (float) ($it['qty'] ?? 1);
        $price = (float) preg_replace('/[^\d.]/', '', (string) ($it['unit_price'] ?? 0));
        if ($name !== '' && $qty > 0) {
            $items[] = ['name' => mb_substr($name, 0, 200), 'qty' => $qty, 'price' => $price, 'desc' => mb_substr(trim((string) ($it['description'] ?? '')), 0, 500)];
        }
    }
    $due = (string) ($d['due_date'] ?? '');
    return ['ok' => true, 'error' => '', 'doc' => [
        'doc_type'         => ($d['doc_type'] ?? '') === 'quote' ? 'quote' : 'invoice',
        'client_name'      => mb_substr(trim((string) ($d['client_name'] ?? '')), 0, 200),
        'client_email'     => filter_var($d['client_email'] ?? '', FILTER_VALIDATE_EMAIL) ?: '',
        'client_phone'     => mb_substr(preg_replace('/[^\d+ ]/', '', (string) ($d['client_phone'] ?? '')), 0, 40),
        'items'            => $items,
        'tax_rate'         => is_numeric($d['tax_rate'] ?? null) ? (float) $d['tax_rate'] : (float) $defaults['tax_rate'],
        'discount_percent' => is_numeric($d['discount_percent'] ?? null) ? (float) $d['discount_percent'] : (float) $defaults['discount'],
        'currency'         => preg_match('/^[A-Z]{3}$/', strtoupper((string) ($d['currency'] ?? ''))) ? strtoupper((string) $d['currency']) : $defaults['currency'],
        'due_date'         => isValidDate($due) && $due >= $today ? $due : date('Y-m-d', strtotime('+30 days')),
        'notes'            => mb_substr(trim((string) ($d['notes'] ?? '')), 0, 1000),
        'missing'          => array_slice(array_map('strval', (array) ($d['missing'] ?? [])), 0, 5),
    ]];
}

/**
 * The chat side of "Describe it": creates the draft and hands over to the
 * normal flow at the template step. Updates $state/$data/$invoiceId.
 */
function aiDraftInChat(int $userId, string $text, string &$state, array &$data, ?int &$invoiceId): array
{
    $user = freshUser($userId);
    if (!aiEnabled()) {
        return [msg('The AI assistant isn\'t switched on yet. Type /new to create an invoice step by step.')];
    }
    if (aiLeft($user) <= 0) {
        return [msgButtons("You've used this month's AI requests on the *" . usageFor($user)['plan'] . "* plan. Go Pro for more (" . APP_URL . "/billing.php), or create it step by step.", ['/new – Create invoice', '/quote – Create quote'])];
    }

    $pm = new \InvoiceChat\ProfileManager(getDB());
    $r  = aiParseDocument($user, $text, $pm->getSettings($userId));
    if (!$r['ok']) {
        error_log('InvoiceChat AI: ' . $r['error']);
        return [msgButtons('Sorry, the AI assistant is busy right now. Let\'s do it step by step instead.', ['/new – Create invoice', '/quote – Create quote'])];
    }
    $doc = $r['doc'];
    if (!$doc['items']) {
        return [msg("I couldn't find any items or prices in that. Try something like:\n\n*Invoice Acme Ltd for 3 logo designs at R500 each and hosting R200, 15% VAT, due in 14 days*")];
    }

    $im = new \InvoiceChat\InvoiceManager(getDB());
    $id = $im->create($userId, ['doc_type' => $doc['doc_type'], 'currency' => $doc['currency'], 'tax_rate' => $doc['tax_rate'],
        'discount_percent' => $doc['discount_percent'], 'template' => $pm->getSettings($userId)['template'], 'due_date' => $doc['due_date']]);
    $im->update($id, $userId, array_filter([
        'client_name' => $doc['client_name'] ?: 'Client', 'client_email' => $doc['client_email'], 'client_phone' => $doc['client_phone'], 'notes' => $doc['notes'],
    ], fn($v) => $v !== ''));
    foreach ($doc['items'] as $it) {
        $im->addItem($id, $it['name'], $it['qty'], $it['price'], $it['desc']);
    }
    $im->recalcTotal($id, $userId);

    $invoiceId = $id;
    $data      = ['doc_type' => $doc['doc_type']];
    $state     = 'invoice_template';
    $invoice   = $im->get($id, $userId);
    $missing   = $doc['missing'] ? "\n\n⚠️ Please check: " . implode(', ', $doc['missing']) . '. Type /cancel to start over if anything is wrong.' : '';

    return [
        msg("✨ Here's your draft:\n\n" . buildInvoiceSummary($invoice, $im->getItems($id), $invoice['currency']) . $missing),
        msgButtons('Looks right? Choose a template:', ['Classic – formal & clean', 'Modern – minimal & sleek', 'Freelancer – compact', 'Corporate – bold grid', 'Banded – colour highlight']),
    ];
}

/** Three short logo concepts (name, tagline, icon idea, colours) for the logo maker. */
function aiLogoIdeas(array $user, string $business, string $industry): array
{
    $r = groqJson(
        'You are a brand designer for South African small businesses. Answer ONLY with JSON: {"ideas":[{"tagline":"","icon":"","colors":["#hex","#hex"],"prompt":""}]} with exactly 3 ideas. '
        . '"icon" is 2-4 words for a simple symbol. "prompt" is an image-generator prompt for a flat, minimal, vector logo mark of that symbol on a plain white background, no text, no letters.',
        "Business: {$business}\nIndustry: {$industry}", 600
    );
    logAi((int) $user['id'], 'logo', $r['ok']);
    return $r['ok'] ? array_slice((array) ($r['data']['ideas'] ?? []), 0, 3) : [];
}
