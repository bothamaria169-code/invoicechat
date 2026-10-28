<?php
declare(strict_types=1);

// One-click unsubscribe from lead emails (POPIA). The token is per lead, so
// the link can't be used to unsubscribe anyone else.
require_once __DIR__ . '/includes/leads.php';
require_once __DIR__ . '/includes/layout.php';

startAppSession();
$token = (string) ($_GET['t'] ?? $_POST['t'] ?? '');
$lead  = null;
if (preg_match('/^[a-f0-9]{32}$/', $token)) {
    $s = getDB()->prepare('SELECT * FROM leads WHERE token = ?');
    $s->execute([$token]);
    $lead = $s->fetch() ?: null;
}
// Mail clients' "unsubscribe" buttons POST without a form; GET only shows the page.
$done = false;
if ($lead && $_SERVER['REQUEST_METHOD'] === 'POST') {
    getDB()->prepare("UPDATE leads SET status = 'do_not_contact', call_after = NULL WHERE id = ?")->execute([$lead['id']]);
    getDB()->prepare("INSERT INTO lead_activity (lead_id, kind, outcome, detail) VALUES (?, 'system', 'do_not_contact', 'Unsubscribed by email link')")->execute([$lead['id']]);
    $done = true;
}

authOpen('Unsubscribe', $done ? 'You\'re unsubscribed' : 'Unsubscribe', $done
    ? 'We won\'t email or call ' . e($lead['email'] ?: 'you') . ' again. Sorry for the bother.'
    : ($lead ? 'Stop all emails and calls from ' . APP_NAME . ' to <strong>' . e($lead['email'] ?: $lead['phone']) . '</strong>?' : 'This unsubscribe link isn\'t valid. Email ' . e(ADMIN_EMAIL) . ' and we\'ll remove you by hand.'));
if ($lead && !$done): ?>
      <form method="POST"><input type="hidden" name="t" value="<?= e($token) ?>"><button class="btn btn-primary btn-block btn-lg">Yes, unsubscribe me</button></form>
<?php endif;
authClose();
