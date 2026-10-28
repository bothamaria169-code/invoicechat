<?php
declare(strict_types=1);

require_once __DIR__ . '/billing.php';

/**
 * Lead CRM (after the EasyAuctions call list): import lead lists, call them
 * with a script, record each outcome, follow up by WhatsApp or email, and run
 * throttled email campaigns with an unsubscribe link in every message.
 *
 * Only contact and business fields are imported. ID numbers, bank details,
 * salaries, credit scores and similar columns are never read.
 */
const LEAD_STATUSES = [
    'new'            => ['New', 'tag-user'],
    'to_call'        => ['To call', 'tag-google'],
    'interested'     => ['Interested', 'tag-admin'],
    'signed_up'      => ['Signed up', 'tag-on'],
    'not_interested' => ['Not interested', 'tag-off'],
    'unreachable'    => ['Unreachable', 'tag-off'],
    'wrong_number'   => ['Wrong number', 'tag-off'],
    'do_not_contact' => ['Do not contact', 'tag-off'],
];

/** Call outcomes: the new status, and when the lead comes back on the list (null = off the list). */
const LEAD_OUTCOMES = [
    'interested'     => ['Interested — sent the link', 'interested', '+3 days'],
    'signed_up'      => ['Signed up on the call', 'signed_up', null],
    'call_back'      => ['Call back later', 'to_call', 'pick'],
    'no_answer'      => ['No answer', 'to_call', '+3 hours'],
    'voicemail'      => ['Left voicemail', 'to_call', '+1 day'],
    'not_interested' => ['Not interested', 'not_interested', null],
    'wrong_number'   => ['Wrong number', 'wrong_number', null],
    'do_not_contact' => ['Asked not to be contacted', 'do_not_contact', null],
];

/** Column names in lead files → our fields. First match in each list wins. */
const LEAD_COLUMNS = [
    'email'      => ['EMAIL', 'EMAILADDRESS', 'ORGCNTEMAIL'],
    'first_name' => ['FNAME', 'FIRSTNAME', 'ORGCNTFIRSTNAME', 'NAME', 'DISPLAYNAME', 'FULLNAME'],
    'last_name'  => ['LNAME', 'SURNAME', 'SURAME', 'LASTNAME', 'ORGCNTSURNAME'],
    'business'   => ['BNAME', 'BUSINESSNAME', 'COMPANYNAME', 'COMPANY', 'TRADENAME', 'LEGALNAME'],
    'job_title'  => ['JOBTITLE', 'ORGCNTJOBTITLE', 'OCCUPATION', 'TITLE'],
    'phone'      => ['MOBILE', 'CELL', 'MOBILENUMBER', 'MOBILE1', 'ORGCNTCELL', 'TEL1', 'PHONE', 'OFFICE', 'SWITCHBOARD'],
    'industry'   => ['INDUSTRY', 'SICCODEDESCRIPTION'],
    'town'       => ['TOWN', 'PHYSICALMUNICIPALITY', 'TOWN1', 'SUBURB'],
    'province'   => ['PROVINCE', 'PHYSICALPROVINCE', 'ADDRESSPROVINCE'],
    'website'    => ['WEBSITE'],
];

function normPhone(?string $raw): ?string
{
    $d = preg_replace('/\D/', '', (string) $raw);
    if (strlen($d) === 11 && str_starts_with($d, '27')) $d = '0' . substr($d, 2);
    if (strlen($d) === 9 && preg_match('/^[1-8]/', $d)) $d = '0' . $d;
    return preg_match('/^0[1-8]\d{8}$/', $d) ? $d : null;
}

/** 0821234567 → 27821234567 for wa.me links. */
function waNumber(?string $phone): string
{
    return $phone ? '27' . substr($phone, 1) : '';
}

function leadName(array $l): string
{
    return trim(($l['first_name'] ?? '') . ' ' . ($l['last_name'] ?? '')) ?: ($l['business'] ?: ($l['email'] ?: 'Lead #' . $l['id']));
}

function niceCase(?string $s): ?string
{
    $s = trim((string) $s);
    if ($s === '') return null;
    return ($s === mb_strtoupper($s) || $s === mb_strtolower($s)) ? mb_convert_case(mb_strtolower($s), MB_CASE_TITLE) : $s;
}

// ── Reading files ─────────────────────────────────────────────────

/** Streams rows (arrays of strings) from a .csv or .xlsx file. */
function readRows(string $path, string $name): \Generator
{
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if ($ext === 'csv' || $ext === 'txt') {
        $fh = fopen($path, 'r');
        $first = fgets($fh);
        $delim = substr_count((string) $first, ';') > substr_count((string) $first, ',') ? ';' : (str_contains((string) $first, "\t") ? "\t" : ',');
        rewind($fh);
        while (($row = fgetcsv($fh, 0, $delim)) !== false) {
            yield array_map(fn($v) => trim((string) preg_replace('/^\xEF\xBB\xBF/', '', (string) $v)), $row);
        }
        fclose($fh);
        return;
    }
    if ($ext !== 'xlsx') throw new RuntimeException('Upload a .xlsx or .csv file.');

    $zip = new ZipArchive();
    if ($zip->open($path) !== true) throw new RuntimeException('That Excel file could not be opened.');
    $strings = [];
    if (($xml = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
        $r = new XMLReader();
        $r->XML($xml);
        $buf = null;
        while ($r->read()) {
            if ($r->nodeType === XMLReader::ELEMENT && $r->name === 'si') $buf = '';
            elseif ($r->nodeType === XMLReader::ELEMENT && $r->name === 't' && $buf !== null) $buf .= $r->readString();
            elseif ($r->nodeType === XMLReader::END_ELEMENT && $r->name === 'si') { $strings[] = $buf; $buf = null; }
        }
        unset($xml);
    }
    $sheet = 'xl/worksheets/sheet1.xml';
    if ($zip->locateName($sheet) === false) {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            if (preg_match('#^xl/worksheets/[^/]+\.xml$#', $zip->getNameIndex($i))) { $sheet = $zip->getNameIndex($i); break; }
        }
    }
    $zip->close();

    $r = new XMLReader();
    $r->open('zip://' . $path . '#' . $sheet);
    $row = []; $col = 0; $type = '';
    while ($r->read()) {
        if ($r->nodeType === XMLReader::ELEMENT && $r->name === 'row') {
            $row = [];
        } elseif ($r->nodeType === XMLReader::ELEMENT && $r->name === 'c') {
            $ref  = (string) $r->getAttribute('r');
            $type = (string) $r->getAttribute('t');
            $col  = colIndex(preg_replace('/\d/', '', $ref));
            if ($r->isEmptyElement) $row[$col] = '';
        } elseif ($r->nodeType === XMLReader::ELEMENT && ($r->name === 'v' || $r->name === 't')) {
            $v = $r->readString();
            $row[$col] = trim($type === 's' ? (string) ($strings[(int) $v] ?? '') : $v);
        } elseif ($r->nodeType === XMLReader::END_ELEMENT && $r->name === 'row') {
            if ($row) {
                $out = array_fill(0, max(array_keys($row)) + 1, '');
                foreach ($row as $i => $v) $out[$i] = $v;
                yield $out;
            }
        }
    }
    $r->close();
}

function colIndex(string $letters): int
{
    $n = 0;
    foreach (str_split(strtoupper($letters)) as $ch) $n = $n * 26 + (ord($ch) - 64);
    return max(0, $n - 1);
}

/** Header row → [field => column index]. */
function mapColumns(array $header): array
{
    $norm = array_map(fn($h) => preg_replace('/[^A-Z0-9]/', '', strtoupper((string) $h)), $header);
    $map = [];
    foreach (LEAD_COLUMNS as $field => $aliases) {
        foreach ($aliases as $alias) {
            $i = array_search($alias, $norm, true);
            if ($i !== false) { $map[$field] = $i; break; }
        }
    }
    foreach (['OPTOUTDATE' => 'optout', 'STATUS' => 'status'] as $alias => $key) {
        $i = array_search($alias, $norm, true);
        if ($i !== false) $map[$key] = $i;
    }
    return $map;
}

/**
 * Imports one file. Existing leads (same email) keep their status and history;
 * only blank fields are filled in.
 *
 * @return array{added: int, updated: int, skipped: int, error: string, columns: array}
 */
function importLeads(string $path, string $name, string $source, bool $businessOnly, int $limit = 0): array
{
    @set_time_limit(0);
    $db  = getDB();
    $map = null;
    $res = ['added' => 0, 'updated' => 0, 'skipped' => 0, 'error' => '', 'columns' => []];
    $batch = [];
    $seen  = 0;

    $flush = function () use (&$batch, &$res, $db, $source) {
        if (!$batch) return;
        // The same email twice in one file counts once.
        $uniq = [];
        foreach ($batch as $b) {
            $key = $b['email'] ?? ('p:' . $b['phone']);
            if (isset($uniq[$key])) { $res['skipped']++; continue; }
            $uniq[$key] = $b;
        }
        $batch = array_values($uniq);
        // Phone-only rows: skip numbers we already have.
        $phones = array_values(array_filter(array_map(fn($b) => $b['email'] ? null : $b['phone'], $batch)));
        $known  = [];
        if ($phones) {
            $q = $db->prepare('SELECT phone FROM leads WHERE phone IN (' . implode(',', array_fill(0, count($phones), '?')) . ')');
            $q->execute($phones);
            $known = array_flip($q->fetchAll(PDO::FETCH_COLUMN));
        }
        $emails = array_values(array_filter(array_column($batch, 'email')));
        $existing = 0;
        if ($emails) {
            $q = $db->prepare('SELECT COUNT(*) FROM leads WHERE email IN (' . implode(',', array_fill(0, count($emails), '?')) . ')');
            $q->execute($emails);
            $existing = (int) $q->fetchColumn();
        }
        $rows = []; $args = [];
        foreach ($batch as $b) {
            if (!$b['email'] && isset($known[$b['phone']])) { $res['skipped']++; continue; }
            $rows[] = '(?,?,?,?,?,?,?,?,?,?,?,?)';
            array_push($args, $b['email'], $b['first_name'], $b['last_name'], $b['business'], $b['job_title'], $b['phone'],
                $b['industry'], $b['town'], $b['province'], $b['website'], $source, bin2hex(random_bytes(16)));
        }
        if ($rows) {
            $s = $db->prepare('INSERT INTO leads (email, first_name, last_name, business, job_title, phone, industry, town, province, website, source, token) VALUES '
                . implode(',', $rows)
                . ' ON DUPLICATE KEY UPDATE first_name = COALESCE(first_name, VALUES(first_name)), last_name = COALESCE(last_name, VALUES(last_name)),
                    business = COALESCE(business, VALUES(business)), job_title = COALESCE(job_title, VALUES(job_title)), phone = COALESCE(phone, VALUES(phone)),
                    industry = COALESCE(industry, VALUES(industry)), town = COALESCE(town, VALUES(town)), province = COALESCE(province, VALUES(province)),
                    website = COALESCE(website, VALUES(website))');
            $s->execute($args);
            $res['added']   += count($rows) - $existing;
            $res['updated'] += $existing;
        }
        $batch = [];
    };

    try {
        foreach (readRows($path, $name) as $row) {
            if ($map === null) {
                $map = mapColumns($row);
                if (!isset($map['email']) && !isset($map['phone'])) {
                    $res['error'] = 'The first row has no EMAIL or MOBILE column, so this file can\'t be read (it may have no header row). Add a header row and try again.';
                    return $res;
                }
                $res['columns'] = array_keys(array_intersect_key($map, LEAD_COLUMNS));
                continue;
            }
            $get = fn(string $f) => isset($map[$f]) ? trim((string) ($row[$map[$f]] ?? '')) : '';
            $statusCol = strtolower($get('status'));
            if ($get('optout') !== '' || preg_match('/unsub|opt.?out|bounce|block|blacklist|invalid|spam|complain/', $statusCol)) { $res['skipped']++; continue; }

            $email = strtolower($get('email'));
            $email = filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
            $phone = normPhone($get('phone'));
            $biz   = niceCase($get('business'));
            if (!$email && !$phone) { $res['skipped']++; continue; }
            if ($businessOnly && !$biz) { $res['skipped']++; continue; }

            $first = $get('first_name'); $last = $get('last_name');
            if ($last === '' && str_contains($first, ' ') && !isset($map['last_name'])) { [$first, $last] = explode(' ', $first, 2); }
            $batch[] = [
                'email' => $email ? mb_substr($email, 0, 190) : null, 'phone' => $phone,
                'first_name' => niceCase(mb_substr($first, 0, 100)), 'last_name' => niceCase(mb_substr($last, 0, 100)),
                'business' => $biz ? mb_substr($biz, 0, 190) : null, 'job_title' => niceCase(mb_substr($get('job_title'), 0, 120)),
                'industry' => niceCase(mb_substr($get('industry'), 0, 150)), 'town' => niceCase(mb_substr($get('town'), 0, 100)),
                'province' => niceCase(mb_substr($get('province'), 0, 60)), 'website' => mb_substr($get('website'), 0, 190) ?: null,
            ];
            if (count($batch) >= 400) $flush();
            if ($limit && ++$seen >= $limit) break;
        }
        $flush();
    } catch (\Throwable $e) {
        $res['error'] = $e->getMessage();
    }
    return $res;
}

// ── Call scripts & templates ──────────────────────────────────────

function callAgent(): string
{
    return get_setting('call_agent_name', 'Trevor');
}

function leadSignupLink(array $lead, string $medium): string
{
    return APP_URL . '/register.php?utm_source=leads&utm_medium=' . $medium . '&utm_campaign=' . rawurlencode(preg_replace('/[^a-z0-9]+/', '-', strtolower((string) $lead['source'])) ?: 'list');
}

/** Fills [FirstName], [Business], [Agent], [Link], [Price] … for one lead. */
function fillScript(string $text, array $lead, string $medium = 'call'): string
{
    $c = billingConfig();
    $vars = [
        'FirstName' => $lead['first_name'] ?: 'there',
        'Business'  => $lead['business'] ?: 'your business',
        'Industry'  => $lead['industry'] ? mb_strtolower($lead['industry']) : 'business',
        'Town'      => $lead['town'] ?: 'your area',
        'Agent'     => callAgent(),
        'Link'      => leadSignupLink($lead, $medium),
        'Price'     => 'R' . $c['pro_price'],
        'Free'      => (string) $c['free_docs'],
        'Pack'      => 'R' . $c['pack_price'] . ' for ' . $c['pack_size'],
        'App'       => APP_NAME,
    ];
    return preg_replace_callback('/\[(\w+)\]/', fn($m) => $vars[$m[1]] ?? $m[0], $text);
}

/** The call scripts, as steps the agent reads down. */
function callScripts(): array
{
    return [
        'cold' => ['title' => 'Cold call — business owner', 'steps' => [
            ['Open', '"Hi, is that [FirstName]? This is [Agent] from [App]. Did I catch you at an OK time for one minute?"'],
            ['If busy', '"No problem — when’s better, later today or tomorrow morning?" → choose *Call back later*.'],
            ['Reason', '"I’m calling business owners in [Town] because most tell us invoices and quotes eat up their evenings. How do you do yours at [Business] at the moment — Word, Excel, a notebook?"'],
            ['Listen', 'Let them talk. Note the pain: slow, look unprofessional, forget to follow up, clients pay late.'],
            ['Pitch', '"[App] lets you make a professional invoice or quote on your phone in about two minutes — you just chat: who’s it for, what you sold, how much. It adds your logo and bank details and sends it by WhatsApp or email."'],
            ['Offer', '"It’s free to start — [Free] invoices a month. If you need more, Pro is [Price] a month, or top-ups are [Pack]. Pay with an OTT voucher from any shop."'],
            ['Close', '"Can I WhatsApp you the link now? It takes a minute to sign up and you can send your first invoice today."'],
            ['If yes', 'Send the WhatsApp (button on this card) → choose *Interested — sent the link*. We’ll remind you to check in 3 days.'],
            ['If no', '"No problem at all. Can I email you the details so you have them for later?" → send email → *Not interested* or *Call back later*.'],
        ]],
        'follow' => ['title' => 'Follow-up — sent the link', 'steps' => [
            ['Open', '"Hi [FirstName], [Agent] from [App] here — I sent you our link a few days ago. Did you get a chance to try it?"'],
            ['If they tried', '"Great! Did your first invoice go out OK? Anything I can help set up — your logo or bank details?"'],
            ['If not yet', '"I can stay on the line while you sign up — it’s one minute with Google. Then type /new and I’ll walk you through your first invoice."'],
            ['Close', 'Signed up → *Signed up on the call*. Still thinking → *Call back later* in a week.'],
        ]],
    ];
}

/** Quick answers for the common objections. */
function callAnswers(): array
{
    return [
        ['"I already use Excel / Word."', '"That works — this just saves time: no formatting, totals and VAT done for you, and it goes straight to WhatsApp. Try your next one with us and compare."'],
        ['"I don’t have time."', '"That’s exactly why — it takes 2 minutes per invoice. Can I send the link so you can try it tonight?"'],
        ['"How much?"', '"Free for [Free] invoices a month. Pro is [Price] a month, or [Pack] if you only need a few more."'],
        ['"I don’t have a card."', '"No card needed — buy an OTT voucher at any shop, type the PIN in the app, and we switch it on."'],
        ['"Is my data safe?"', '"Yes — your invoices are private to your account, PDFs open only with a secure link, and we follow POPIA."'],
        ['"Where did you get my number?"', '"From a business directory list. If you’d prefer we don’t contact you again I’ll remove you right now." → *Asked not to be contacted*.'],
    ];
}

/** Email templates (subject + HTML body). Every email gets an unsubscribe footer. */
function emailTemplates(): array
{
    return [
        'intro' => ['name' => 'Cold intro', 'subject' => 'Invoices for [Business] in 2 minutes',
            'body' => "<p>Hi [FirstName],</p><p>Most business owners we speak to lose hours every week on invoices and quotes — formatting in Word, fixing totals, chasing payments.</p><p><strong>[App]</strong> lets you create a professional invoice or quote on your phone in about two minutes, just by chatting. It adds your logo and bank details and sends it to your client by WhatsApp or email.</p><ul><li>Free for [Free] invoices a month</li><li>Pro is only [Price]/month — or top up [Pack]</li><li>Pay with an OTT voucher, no card needed</li></ul><p><a href=\"[Link]\">Create your free account →</a></p><p>Kind regards,<br>[Agent]<br>[App]</p>"],
        'after_call' => ['name' => 'After a call', 'subject' => 'The link we spoke about, [FirstName]',
            'body' => "<p>Hi [FirstName],</p><p>Thanks for your time on the phone today. As promised, here’s the link to start invoicing with [App]:</p><p><a href=\"[Link]\">[Link]</a></p><p>Sign up with Google or email, answer a few questions about [Business], then type <strong>/new</strong> — your first invoice will be ready in about two minutes.</p><p>Any questions, just reply to this email.</p><p>[Agent]<br>[App]</p>"],
        'follow_up' => ['name' => 'Follow-up', 'subject' => 'Did your first invoice go out, [FirstName]?',
            'body' => "<p>Hi [FirstName],</p><p>Just checking in — did you get a chance to try [App]?</p><p>If you haven’t yet, it takes a minute: <a href=\"[Link]\">create your free account</a>. You can even type something like <em>“Invoice Acme for 3 logos at R500 each, 15% VAT”</em> and our AI drafts it for you.</p><p>[Agent]<br>[App]</p>"],
    ];
}

function whatsappTemplates(): array
{
    return [
        'link'   => ['name' => 'Send the link', 'text' => "Hi [FirstName], it’s [Agent] from [App] 👋 As discussed, here’s the link to make professional invoices & quotes on your phone in 2 minutes — free for [Free] a month: [Link]"],
        'intro'  => ['name' => 'First message', 'text' => "Hi [FirstName], [Agent] here from [App]. We help businesses like [Business] send professional invoices & quotes by WhatsApp in 2 minutes. Free to start: [Link] — reply STOP if you’d rather not hear from us."],
        'follow' => ['name' => 'Follow-up', 'text' => "Hi [FirstName], just checking if you managed to try [App]? Happy to help you set up your first invoice. [Link]"],
    ];
}

function unsubscribeUrl(array $lead): string
{
    return APP_URL . '/unsubscribe.php?t=' . $lead['token'];
}

/** Wraps a lead email: branded layout + POPIA footer with an unsubscribe link. */
function leadEmailHtml(array $lead, string $bodyHtml): string
{
    return emailLayout('', fillScript($bodyHtml, $lead, 'email'), null, null,
        '<p style="margin-top:24px;font-size:12px;color:#7c8899">You received this because your business details are on a South African business list. '
        . '<a href="' . e(unsubscribeUrl($lead)) . '" style="color:#7c8899">Unsubscribe</a> and we won\'t contact you again.</p>');
}

/** Sends one template to one lead and records it. Returns '' or the error. */
function emailLead(array $lead, string $subject, string $body, ?int $adminId, ?int $campaignId = null): string
{
    if (!$lead['email']) return 'This lead has no email address.';
    if ($lead['status'] === 'do_not_contact') return 'This lead asked not to be contacted.';
    $err = sendMail($lead['email'], fillScript($subject, $lead, 'email'), leadEmailHtml($lead, $body), [], get_setting('signup_notify_email', ADMIN_EMAIL));
    if ($err !== '') return $err;
    $db = getDB();
    // Emailed leads go on the call list for the next day (unless already further along).
    $db->prepare("UPDATE leads SET emails_sent = emails_sent + 1, last_emailed_at = NOW(),
                  status = IF(status = 'new', 'to_call', status), call_after = IF(status = 'to_call' AND call_after IS NULL, NOW() + INTERVAL 1 DAY, call_after)
                  WHERE id = ?")->execute([$lead['id']]);
    $db->prepare("INSERT INTO lead_activity (lead_id, kind, detail, admin_id) VALUES (?, 'email', ?, ?)")
       ->execute([$lead['id'], ($campaignId ? "Campaign #{$campaignId}: " : '') . mb_substr(fillScript($subject, $lead, 'email'), 0, 200), $adminId]);
    return '';
}

/** Cron: sends the next queued campaign emails (throttled so the mailbox isn't blocked). */
function runCampaignQueue(?int $perRun = null): int
{
    $db = getDB();
    $perRun ??= (int) get_setting('campaign_per_run', '25');
    $rows = $db->query("SELECT q.id qid, q.campaign_id, c.subject, c.body, l.* FROM campaign_queue q
                        JOIN email_campaigns c ON c.id = q.campaign_id AND c.status = 'sending'
                        JOIN leads l ON l.id = q.lead_id
                        WHERE q.status = 'queued' ORDER BY q.id LIMIT " . max(1, $perRun))->fetchAll();
    $sent = 0;
    foreach ($rows as $r) {
        $claim = $db->prepare("UPDATE campaign_queue SET status = 'sending' WHERE id = ? AND status = 'queued'");
        $claim->execute([$r['qid']]);
        if ($claim->rowCount() !== 1) continue;
        $err = $r['status'] === 'do_not_contact' ? 'Unsubscribed' : emailLead($r, $r['subject'], $r['body'], null, (int) $r['campaign_id']);
        $db->prepare('UPDATE campaign_queue SET status = ?, error = ?, sent_at = NOW() WHERE id = ?')->execute([$err === '' ? 'sent' : 'failed', $err ?: null, $r['qid']]);
        $db->prepare('UPDATE email_campaigns SET ' . ($err === '' ? 'sent = sent + 1' : 'failed = failed + 1') . ' WHERE id = ?')->execute([$r['campaign_id']]);
        if ($err === '') $sent++;
    }
    $db->exec("UPDATE email_campaigns c SET status = 'done' WHERE status = 'sending' AND NOT EXISTS (SELECT 1 FROM campaign_queue q WHERE q.campaign_id = c.id AND q.status IN ('queued','sending'))");
    return $sent;
}

/** Filters from the lead list (GET/POST) → SQL. */
function leadFilterSql(array $f, array &$args): string
{
    $w = ['1=1'];
    if (($f['q'] ?? '') !== '') { $w[] = '(l.email LIKE ? OR l.business LIKE ? OR l.first_name LIKE ? OR l.last_name LIKE ? OR l.phone LIKE ?)'; $q = '%' . $f['q'] . '%'; array_push($args, $q, $q, $q, $q, $q); }
    if (isset(LEAD_STATUSES[$f['status'] ?? ''])) { $w[] = 'l.status = ?'; $args[] = $f['status']; }
    foreach (['source', 'province', 'industry'] as $k) {
        if (($f[$k] ?? '') !== '') { $w[] = "l.{$k} = ?"; $args[] = $f[$k]; }
    }
    if (($f['has'] ?? '') === 'phone') $w[] = 'l.phone IS NOT NULL';
    if (($f['has'] ?? '') === 'email') $w[] = 'l.email IS NOT NULL';
    if (($f['has'] ?? '') === 'both')  $w[] = 'l.phone IS NOT NULL AND l.email IS NOT NULL';
    if (($f['business'] ?? '') === '1') $w[] = 'l.business IS NOT NULL';
    return implode(' AND ', $w);
}
