<?php
declare(strict_types=1);

require_once __DIR__ . '/billing.php';

/**
 * The AI assistant, on Groq's free API (OpenAI-compatible, Llama 3.3 70B).
 *
 *  - "Describe it": one sentence ("Invoice Acme for 3 logos at R500 and
 *    hosting R200, 15% VAT, due in 14 days") becomes a draft invoice or quote.
 *    Messy text is fine; a missing client or price is asked for one by one.
 *  - Photos: an old invoice, supplier invoice, slip or handwritten note is read
 *    by Groq's vision model (Llama 4 Scout) into the same kind of draft.
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

/** Groq's free vision model; changeable in Admin → Settings → AI if Groq renames it. */
const DEFAULT_VISION_MODEL = 'meta-llama/llama-4-scout-17b-16e-instruct';

function aiVisionModel(): string
{
    return get_setting('groq_vision_model', DEFAULT_VISION_MODEL);
}

/**
 * One chat completion that must answer with JSON. $user is the message text,
 * or content parts (text + image_url) for a photo.
 *
 * @return array{ok: bool, data: array, error: string}
 */
function groqJson(string $system, string|array $user, int $maxTokens = 900, ?string $model = null): array
{
    // Vision models take the instructions in the same message as the image.
    $messages = is_array($user)
        ? [['role' => 'user', 'content' => array_merge([['type' => 'text', 'text' => $system]], $user)]]
        : [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $user]];
    $ch = curl_init('https://api.groq.com/openai/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . aiKey(), 'Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => json_encode([
            'model'           => $model ?? get_setting('groq_model', 'llama-3.3-70b-versatile'),
            'temperature'     => 0.2,
            'max_tokens'      => $maxTokens,
            'response_format' => ['type' => 'json_object'],
            'messages'        => $messages,
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => is_array($user) ? 60 : 30,
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

/** Does this free text look like someone describing an invoice? Typos and slang welcome. */
function looksLikeDocumentRequest(string $text): bool
{
    if (mb_strlen($text) < 8) return false;
    if (preg_match('/\b(inv[oi]{1,3}[cs]|quot|qoute|kwot|estimat|bill\b|charge|faktuur)/iu', $text)) return true;
    return mb_strlen($text) >= 15
        && preg_match('/\d/', $text)
        && preg_match('/\b(for|@|x\s?\d|each|per|rand|R\s?\d|\d+\s?k)\b/i', $text);
}

/** The JSON shape and rules shared by typed messages and photos. */
function aiDocRules(): string
{
    $today = date('Y-m-d');
    return <<<SYS
Today is {$today}. Answer ONLY with JSON in this shape:
{"is_document":true,"doc_type":"invoice|quote","client_name":"","client_email":"","client_phone":"","items":[{"name":"","qty":1,"unit_price":0,"description":""}],"tax_rate":null,"discount_percent":null,"currency":null,"due_date":null,"notes":"","missing":[],"seen":""}
Rules:
- The input may be messy: typos, slang, SMS-style, no punctuation, or mixed English, Afrikaans, isiZulu, Sesotho and others. Read it generously.
- is_document is false only when there is nothing about selling, charging or quoting at all.
- doc_type is "quote" only for quote/quotation/estimate/kwotasie; otherwise "invoice".
- unit_price is the price of ONE unit as a plain number. "3 logos at R500 each" = qty 3, unit_price 500. "3 logos for R1500" = qty 3, unit_price 500. "5k" = 5000, "R1.5k" = 1500, "R1 500,00" = 1500.
- If an item has no price, keep the item with unit_price 0 and add "price for <item>" to "missing".
- Prices are in rand unless another currency is named. currency is a 3-letter code or null.
- "VAT" alone means tax_rate 15. Use null when not mentioned so the user's defaults apply.
- due_date is YYYY-MM-DD; "in 14 days" means today + 14. null if not mentioned.
- Never invent clients, items or prices. List anything unclear in "missing" (short phrases, e.g. "client name").
- Keep item names short and professional (e.g. "Logo design"), with correct spelling.
- "seen" is one short sentence saying what you understood or saw.
SYS;
}

/** Cleans what the model sent back; items without a price keep 0 so the user is asked. */
function aiNormalizeDoc(array $d, array $defaults): array
{
    $today = date('Y-m-d');
    $items = [];
    foreach ((array) ($d['items'] ?? []) as $it) {
        $name  = trim((string) ($it['name'] ?? ''));
        $qty   = (float) ($it['qty'] ?? 1);
        $price = parseAmount((string) ($it['unit_price'] ?? '0')) ?? 0.0;
        if ($name !== '') {
            $items[] = ['name' => mb_substr($name, 0, 200), 'qty' => $qty > 0 ? $qty : 1, 'price' => $price, 'desc' => mb_substr(trim((string) ($it['description'] ?? '')), 0, 500)];
        }
    }
    $due = (string) ($d['due_date'] ?? '');
    return [
        'is_document'      => ($d['is_document'] ?? true) !== false || $items !== [],
        'doc_type'         => ($d['doc_type'] ?? '') === 'quote' ? 'quote' : 'invoice',
        'client_name'      => mb_substr(trim((string) ($d['client_name'] ?? '')), 0, 200),
        'client_email'     => filter_var($d['client_email'] ?? '', FILTER_VALIDATE_EMAIL) ?: '',
        'client_phone'     => mb_substr(preg_replace('/[^\d+ ]/', '', (string) ($d['client_phone'] ?? '')), 0, 40),
        'items'            => array_slice($items, 0, 30),
        'tax_rate'         => is_numeric($d['tax_rate'] ?? null) ? (float) $d['tax_rate'] : (float) $defaults['tax_rate'],
        'discount_percent' => is_numeric($d['discount_percent'] ?? null) ? (float) $d['discount_percent'] : (float) $defaults['discount'],
        'currency'         => preg_match('/^[A-Z]{3}$/', strtoupper((string) ($d['currency'] ?? ''))) ? strtoupper((string) $d['currency']) : $defaults['currency'],
        'due_date'         => isValidDate($due) && $due >= $today ? $due : date('Y-m-d', strtotime('+30 days')),
        'notes'            => mb_substr(trim((string) ($d['notes'] ?? '')), 0, 1000),
        'missing'          => array_slice(array_map('strval', (array) ($d['missing'] ?? [])), 0, 5),
        'seen'             => mb_substr(trim((string) ($d['seen'] ?? '')), 0, 200),
    ];
}

/**
 * Turns one message into a structured document.
 *
 * @return array{ok: bool, doc: array, error: string}
 */
function aiParseDocument(array $user, string $text, array $defaults): array
{
    $r = groqJson("You turn a South African small-business owner's message into an invoice or quote.\n" . aiDocRules(), $text);
    logAi((int) $user['id'], 'draft', $r['ok']);
    return $r['ok'] ? ['ok' => true, 'error' => '', 'doc' => aiNormalizeDoc($r['data'], $defaults)] : ['ok' => false, 'doc' => [], 'error' => $r['error']];
}

/**
 * Reads a photo or scan (old invoice, supplier invoice, receipt, handwritten
 * note, price list) into the same structure as aiParseDocument().
 */
function aiReadImage(array $user, string $jpegPath, string $caption, array $defaults): array
{
    $system = "You read a photo or scan for a South African small-business owner who wants to make an invoice or quote from it. "
        . "It may be an old invoice or quote, a supplier's invoice, a till slip, a handwritten note or a price list, and it may be blurry, skewed or partly cut off. "
        . "Copy what you can read; never guess numbers you can't read (list them in \"missing\"). The client is who the document is addressed to (\"Bill to\", \"To\", \"Customer\"), not the business that issued it. "
        . "Use the unit price per line, not the line total. Ignore subtotals, VAT lines and totals as items.\n"
        . ($caption !== '' ? "The owner added this note, which overrides the photo: \"{$caption}\"\n" : '')
        . aiDocRules();
    $parts = [['type' => 'image_url', 'image_url' => ['url' => 'data:image/jpeg;base64,' . base64_encode((string) file_get_contents($jpegPath))]]];
    $r = groqJson($system, $parts, 1500, aiVisionModel());
    logAi((int) $user['id'], 'image', $r['ok']);
    return $r['ok'] ? ['ok' => true, 'error' => '', 'doc' => aiNormalizeDoc($r['data'], $defaults)] : ['ok' => false, 'doc' => [], 'error' => $r['error']];
}

/** "R1 500,50", "1.5k", "450" → float; null when it isn't an amount. */
function parseAmount(string $s): ?float
{
    $s = str_replace(['zar', 'r', ' ', "\u{a0}"], '', strtolower(trim($s)));
    $k = str_ends_with($s, 'k');
    if ($k) $s = substr($s, 0, -1);
    if (preg_match('/^\d+,\d{1,2}$/', $s)) $s = str_replace(',', '.', $s);
    $s = str_replace(',', '', $s);
    if (!preg_match('/^\d+(\.\d+)?$/', $s)) return null;
    return (float) $s * ($k ? 1000 : 1);
}

/** Messages to show instead of an AI draft when the AI is off or used up; [] when it may run. */
function aiGate(array $user): array
{
    if (!aiEnabled()) {
        return [msg('The AI assistant isn\'t switched on yet. Type /new to create an invoice step by step.')];
    }
    if (aiLeft($user) <= 0) {
        return [msgButtons("You've used this month's AI requests on the *" . usageFor($user)['plan'] . "* plan. Go Pro for more (" . APP_URL . "/billing.php), or create it step by step.", ['/new – Create invoice', '/quote – Create quote'])];
    }
    return [];
}

function aiBusy(): array
{
    return [msgButtons('Sorry, the AI assistant is busy right now. Let\'s do it step by step instead.', ['/new – Create invoice', '/quote – Create quote'])];
}

/**
 * The chat side of "Describe it": creates the draft and hands over to the
 * normal flow at the template step. Updates $state/$data/$invoiceId.
 * When nothing sellable was found, it asks once more and reads both messages together.
 */
function aiDraftInChat(int $userId, string $text, string &$state, array &$data, ?int &$invoiceId): array
{
    $user = freshUser($userId);
    if ($gate = aiGate($user)) return $gate;

    $earlier = $state === 'ai_more' ? (string) ($data['ai_text'] ?? '') : '';
    $pm = new \InvoiceChat\ProfileManager(getDB());
    $r  = aiParseDocument($user, trim($earlier . "\n" . $text), $pm->getSettings($userId));
    if (!$r['ok']) {
        error_log('InvoiceChat AI: ' . $r['error']);
        $state = 'idle';
        return aiBusy();
    }
    $doc = $r['doc'];
    if (!$doc['items']) {
        if (!$doc['is_document'] && $earlier === '') {
            return [msgButtons("I'm not sure what you'd like to do. I can make an invoice or quote from one message, e.g.\n\n*Invoice Acme Ltd for 3 logo designs at R500 each, 15% VAT, due in 14 days*\n\nOr send me a 📷 photo of an old invoice, quote or handwritten note.", ['/new – Create invoice', '/quote – Create quote', '/help – Commands'])];
        }
        if ($earlier === '') {
            $state = 'ai_more';
            $data  = ['ai_text' => $text];
            return [msg(($doc['client_name'] ? "Got it — a {$doc['doc_type']} for *{$doc['client_name']}*. " : '') . "What did you sell or do, and how much? e.g. *2 hours plumbing at R450 an hour, parts R300*")];
        }
        $state = 'idle';
        $data  = [];
        return [msgButtons("I still couldn't find what to charge for. Let's do it step by step — it only takes a minute.", ['/new – Create invoice', '/quote – Create quote'])];
    }
    return aiCreateDraft($userId, $doc, "✨ Here's your draft:", $state, $data, $invoiceId);
}

/**
 * A photo sent in the chat: checks it, shrinks it, reads it and makes a draft.
 *
 * @param array $file one entry of $_FILES
 */
function aiDraftFromPhoto(int $userId, array $file, string $caption, string &$state, array &$data, ?int &$invoiceId): array
{
    $user = freshUser($userId);
    if ($gate = aiGate($user)) return $gate;
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
        return [msg(($file['error'] ?? 0) === UPLOAD_ERR_INI_SIZE ? 'That photo is too big. Please send one under 10 MB.' : 'The photo didn\'t arrive. Please try again.')];
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    if ($mime === 'application/pdf') {
        return [msg("I can't read PDFs yet. Open the PDF, take a 📷 screenshot of it and send me the screenshot.")];
    }
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
        return [msg("Please send a JPG or PNG photo. (iPhone: Settings → Camera → Formats → *Most Compatible*, or send a screenshot of the photo.)")];
    }
    if ($file['size'] > 10 * 1024 * 1024) {
        return [msg('That photo is too big. Please send one under 10 MB.')];
    }
    $jpeg = shrinkForAi($file['tmp_name'], $mime);
    if ($jpeg === null) return [msg('I couldn\'t open that image. Please try another photo or a screenshot.')];

    $pm = new \InvoiceChat\ProfileManager(getDB());
    $r  = aiReadImage($user, $jpeg, mb_substr($caption, 0, 500), $pm->getSettings($userId));
    @unlink($jpeg);
    if (!$r['ok']) {
        error_log('InvoiceChat AI image: ' . $r['error']);
        return aiBusy();
    }
    $doc = $r['doc'];
    if (!$doc['items']) {
        return [msgButtons(($doc['seen'] ? "I saw: _{$doc['seen']}_\n\n" : '') . "But I couldn't read any items or prices from it. Tips for a better photo:\n• Lay the page flat in good light\n• Get the whole page in, straight on\n• Avoid shadows and glare\n\nOr just type it, e.g. *Invoice Acme for 3 logos at R500 each*", ['/new – Create invoice', '/quote – Create quote'])];
    }
    return aiCreateDraft($userId, $doc, '📷 Here\'s what I read' . ($doc['seen'] ? " from _{$doc['seen']}_" : '') . ':', $state, $data, $invoiceId);
}

/** Rotates (EXIF), scales to 1600px and saves as JPEG so phone photos fit Groq's 4 MB image limit. */
function shrinkForAi(string $path, string $mime): ?string
{
    $img = match ($mime) {
        'image/jpeg' => @imagecreatefromjpeg($path),
        'image/png'  => @imagecreatefrompng($path),
        'image/webp' => @imagecreatefromwebp($path),
        default      => false,
    };
    if (!$img) return null;
    if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
        $o = (int) (@exif_read_data($path)['Orientation'] ?? 1);
        $angle = [3 => 180, 6 => -90, 8 => 90][$o] ?? 0;
        if ($angle) $img = imagerotate($img, $angle, 0);
    }
    $w = imagesx($img);
    $h = imagesy($img);
    $scale = min(1, 1600 / max($w, $h));
    if ($scale < 1) $img = imagescale($img, (int) round($w * $scale), (int) round($h * $scale));
    $out = tempnam(sys_get_temp_dir(), 'icai');
    imagejpeg($img, $out, 82);
    imagedestroy($img);
    return $out;
}

/**
 * Saves the draft, then asks for the client and any missing prices one at a
 * time (state "ai_fill") before the template step.
 */
function aiCreateDraft(int $userId, array $doc, string $intro, string &$state, array &$data, ?int &$invoiceId): array
{
    $pm = new \InvoiceChat\ProfileManager(getDB());
    $im = new \InvoiceChat\InvoiceManager(getDB());
    $id = $im->create($userId, ['doc_type' => $doc['doc_type'], 'currency' => $doc['currency'], 'tax_rate' => $doc['tax_rate'],
        'discount_percent' => $doc['discount_percent'], 'template' => $pm->getSettings($userId)['template'], 'due_date' => $doc['due_date']]);
    $im->update($id, $userId, array_filter([
        'client_name' => $doc['client_name'] ?: 'Client', 'client_email' => $doc['client_email'], 'client_phone' => $doc['client_phone'], 'notes' => $doc['notes'],
    ], fn($v) => $v !== ''));
    $queue = $doc['client_name'] === '' ? [['ask' => 'client']] : [];
    foreach ($doc['items'] as $it) {
        $itemId = $im->addItem($id, $it['name'], $it['qty'], $it['price'], $it['desc']);
        if ($it['price'] <= 0) $queue[] = ['ask' => 'price', 'item' => $itemId, 'name' => $it['name']];
    }
    $im->recalcTotal($id, $userId);

    $invoiceId = $id;
    // The model's own "missing" list, minus what we're about to ask for.
    $check = array_values(array_filter($doc['missing'], fn($m) => !preg_match('/client name|price/i', $m)));
    $data  = ['doc_type' => $doc['doc_type'], 'ai_queue' => $queue, 'ai_check' => $check];
    $invoice = $im->get($id, $userId);
    $summary = msg($intro . "\n\n" . buildInvoiceSummary($invoice, $im->getItems($id), $invoice['currency']));

    if ($queue) {
        $state = 'ai_fill';
        return [$summary, aiQuestion($queue[0], $doc['doc_type'])];
    }
    return array_merge([$summary], aiFinishDraft($userId, $state, $data));
}

function aiQuestion(array $q, string $docType): array
{
    return $q['ask'] === 'client'
        ? msg("Who is this {$docType} for? Type the client or company name.")
        : msgButtons("How much do you charge for *{$q['name']}* (price for one)? e.g. 450 or R1 200", ['Skip']);
}

/** One answer while in state "ai_fill". */
function aiFillAnswer(int $userId, string $input, string &$state, array &$data, ?int &$invoiceId): array
{
    $queue = (array) ($data['ai_queue'] ?? []);
    $q     = $queue[0] ?? null;
    if (!$q || !$invoiceId) {
        return aiFinishDraft($userId, $state, $data);
    }
    $im   = new \InvoiceChat\InvoiceManager(getDB());
    $skip = preg_match('/^\s*(skip|later|no|none|-)\s*$/i', $input);

    if ($q['ask'] === 'client' && !$skip) {
        $name = mb_substr(trim($input), 0, 200);
        if (mb_strlen($name) < 2) return [msg('Please type the client\'s name, e.g. *Acme Ltd* or *Thabo Nkosi*.')];
        $im->update($invoiceId, $userId, ['client_name' => $name]);
    } elseif ($q['ask'] === 'price' && !$skip) {
        $price = parseAmount($input);
        if ($price === null) return [msgButtons("Please type just the amount for *{$q['name']}*, e.g. 450 or R1 200.", ['Skip'])];
        $im->updateItem((int) $q['item'], $invoiceId, ['unit_price' => $price]);
        $im->recalcTotal($invoiceId, $userId);
    } elseif ($q['ask'] === 'price') {
        $data['ai_check'][] = "price for {$q['name']} is R0";
    }

    array_shift($queue);
    $data['ai_queue'] = $queue;
    if ($queue) return [aiQuestion($queue[0], (string) ($data['doc_type'] ?? 'invoice'))];

    $invoice = $im->get($invoiceId, $userId);
    return array_merge([msg("Updated:\n\n" . buildInvoiceSummary($invoice, $im->getItems($invoiceId), $invoice['currency']))], aiFinishDraft($userId, $state, $data));
}

/** Hands the finished draft to the normal flow at the template step. */
function aiFinishDraft(int $userId, string &$state, array &$data): array
{
    $check = (array) ($data['ai_check'] ?? []);
    $state = 'invoice_template';
    $data  = ['doc_type' => $data['doc_type'] ?? 'invoice'];
    $out   = [];
    if ($check) $out[] = msg('⚠️ Please check: ' . implode(', ', $check) . '. Type /cancel to start over if anything is wrong.');
    if (($data['doc_type'] === 'invoice') && ($tip = bankingReminder(freshUser($userId))) !== '') $out[] = msg($tip);
    $out[] = msgButtons('Looks right? Choose a template:', ['Classic – formal & clean', 'Modern – minimal & sleek', 'Freelancer – compact', 'Corporate – bold grid', 'Banded – colour highlight']);
    return $out;
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
