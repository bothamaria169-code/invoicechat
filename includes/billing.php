<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

/**
 * Plans and credits.
 *
 *  - Free: a few documents a month, "Made with InvoiceChat" footer on the PDF.
 *  - Pro (R50/month): a large monthly allowance, no footer, more AI.
 *  - Credits (R10 = 10 documents): never expire, used once the month's
 *    allowance is gone, on any plan.
 *
 * A document is charged once, the first time its PDF is made; re-making the
 * PDF after an edit is free. Prices and allowances are in Admin → Billing.
 * Payment is an OTT voucher the admin checks and approves (payments table).
 */
function billingConfig(): array
{
    return [
        'free_docs'   => (int) get_setting('bill_free_docs', '5'),
        'pro_docs'    => (int) get_setting('bill_pro_docs', '100'),
        'pro_price'   => (int) get_setting('bill_pro_price', '50'),
        'pack_price'  => (int) get_setting('bill_pack_price', '10'),
        'pack_size'   => (int) get_setting('bill_pack_size', '10'),
        'free_ai'     => (int) get_setting('bill_free_ai', '10'),
        'pro_ai'      => (int) get_setting('bill_pro_ai', '300'),
        'signup_gift' => (int) get_setting('bill_signup_gift', '0'),
    ];
}

/** What a user can buy. Keys are stored on payments.package. */
function billingPackages(): array
{
    $c = billingConfig();
    return [
        'credits_1' => ['label' => $c['pack_size'] . ' document credits',      'price' => $c['pack_price'],     'credits' => $c['pack_size'],     'months' => 0, 'note' => 'Never expire'],
        'credits_5' => ['label' => ($c['pack_size'] * 5) . ' document credits', 'price' => $c['pack_price'] * 5, 'credits' => $c['pack_size'] * 5, 'months' => 0, 'note' => 'Never expire'],
        'pro_1'     => ['label' => 'Pro · 1 month',   'price' => $c['pro_price'],     'credits' => 0, 'months' => 1, 'note' => $c['pro_docs'] . ' documents a month, no footer, AI assistant'],
        'pro_3'     => ['label' => 'Pro · 3 months',  'price' => $c['pro_price'] * 3, 'credits' => 0, 'months' => 3, 'note' => 'Same as Pro, paid for 3 months'],
    ];
}

function isPro(array $user): bool
{
    return ($user['plan'] ?? 'free') === 'pro' && !empty($user['plan_expires_at']) && strtotime($user['plan_expires_at']) > time();
}

/** This month's allowance, how much of it is used, and the credit balance. */
function usageFor(array $user): array
{
    $c = billingConfig();
    $s = getDB()->prepare("SELECT COUNT(*) FROM invoices WHERE user_id = ? AND charge_source = 'plan' AND charged_at >= ?");
    $s->execute([$user['id'], date('Y-m-01 00:00:00')]);
    $used      = (int) $s->fetchColumn();
    $pro       = isPro($user);
    $allowance = $pro ? $c['pro_docs'] : $c['free_docs'];
    return [
        'pro'       => $pro,
        'plan'      => $pro ? 'Pro' : 'Free',
        'allowance' => $allowance,
        'used'      => $used,
        'left'      => max(0, $allowance - $used),
        'credits'   => (int) ($user['credits'] ?? 0),
        'renews'    => date('j M', strtotime('first day of next month')),
        'expires'   => $pro ? $user['plan_expires_at'] : null,
    ];
}

function freshUser(int $userId): array
{
    $s = getDB()->prepare('SELECT * FROM users WHERE id = ?');
    $s->execute([$userId]);
    return $s->fetch() ?: [];
}

/**
 * Charges one document (once). Returns '' when it may be generated, or a
 * message explaining how to get more.
 */
function chargeDocument(int $userId, int $invoiceId): string
{
    $db = getDB();
    $s  = $db->prepare('SELECT charged_at FROM invoices WHERE id = ? AND user_id = ?');
    $s->execute([$invoiceId, $userId]);
    if ($s->fetchColumn()) return ''; // already paid for — re-making the PDF is free

    $u = usageFor(freshUser($userId));
    if ($u['left'] > 0) {
        $db->prepare("UPDATE invoices SET charged_at = NOW(), charge_source = 'plan' WHERE id = ?")->execute([$invoiceId]);
        return '';
    }
    // Atomic: two tabs can't spend the same last credit.
    $take = $db->prepare('UPDATE users SET credits = credits - 1 WHERE id = ? AND credits > 0');
    $take->execute([$userId]);
    if ($take->rowCount() === 1) {
        $db->prepare("UPDATE invoices SET charged_at = NOW(), charge_source = 'credit' WHERE id = ?")->execute([$invoiceId]);
        $db->prepare("INSERT INTO credit_ledger (user_id, delta, kind, note) VALUES (?, -1, 'use', ?)")->execute([$userId, 'Document #' . $invoiceId]);
        return '';
    }
    $c = billingConfig();
    return "You've used all {$u['allowance']} documents included in your *{$u['plan']}* plan this month, and you have no credits left.\n\n"
         . "• Top up: *R{$c['pack_price']} = {$c['pack_size']} documents* (credits never expire)\n"
         . ($u['pro'] ? '' : "• Or go *Pro for R{$c['pro_price']}/month*: {$c['pro_docs']} documents a month, no footer, AI assistant\n")
         . "\nPay with an OTT voucher from any shop: " . APP_URL . '/billing.php';
}

/** Undoes chargeDocument() when the PDF then failed to render. */
function refundDocument(int $userId, int $invoiceId): void
{
    $db = getDB();
    $s  = $db->prepare('SELECT charge_source FROM invoices WHERE id = ? AND user_id = ? AND charged_at > NOW() - INTERVAL 5 MINUTE');
    $s->execute([$invoiceId, $userId]);
    $src = $s->fetchColumn();
    if (!$src) return;
    $db->prepare('UPDATE invoices SET charged_at = NULL, charge_source = NULL WHERE id = ?')->execute([$invoiceId]);
    if ($src === 'credit') addCredits($userId, 1, 'refund', 'PDF failed for document #' . $invoiceId);
}

function addCredits(int $userId, int $delta, string $kind, string $note = '', ?int $adminId = null): void
{
    $db = getDB();
    $db->prepare('UPDATE users SET credits = GREATEST(0, credits + ?) WHERE id = ?')->execute([$delta, $userId]);
    $db->prepare('INSERT INTO credit_ledger (user_id, delta, kind, note, created_by) VALUES (?, ?, ?, ?, ?)')->execute([$userId, $delta, $kind, mb_substr($note, 0, 255), $adminId]);
}

/** Pro time is added on top of any time still left. */
function extendPro(int $userId, int $months, string $note = '', ?int $adminId = null): void
{
    $u     = freshUser($userId);
    $start = isPro($u) ? strtotime($u['plan_expires_at']) : time();
    $until = date('Y-m-d H:i:s', strtotime("+{$months} month", $start));
    getDB()->prepare("UPDATE users SET plan = 'pro', plan_expires_at = ? WHERE id = ?")->execute([$until, $userId]);
    getDB()->prepare("INSERT INTO credit_ledger (user_id, delta, kind, note, created_by) VALUES (?, 0, 'pro', ?, ?)")
        ->execute([$userId, "Pro +{$months} month(s) until " . date('j M Y', strtotime($until)) . ($note ? " · {$note}" : ''), $adminId]);
}

/** Admin approved an OTT voucher: give the user what they paid for and tell them. */
function approvePayment(int $paymentId, int $adminId, string $note = ''): string
{
    $db = getDB();
    $claim = $db->prepare("UPDATE payments SET status = 'approved', reviewed_by = ?, reviewed_at = NOW(), admin_note = ? WHERE id = ? AND status = 'pending'");
    $claim->execute([$adminId, $note ?: null, $paymentId]);
    if ($claim->rowCount() !== 1) return 'That payment was already reviewed.';

    $p   = $db->query('SELECT * FROM payments WHERE id = ' . $paymentId)->fetch();
    $pkg = billingPackages()[$p['package']] ?? null;
    if (!$pkg) return 'Unknown package.';
    if ($pkg['credits']) addCredits((int) $p['user_id'], $pkg['credits'], 'purchase', 'OTT voucher #' . $paymentId, $adminId);
    if ($pkg['months'])  extendPro((int) $p['user_id'], $pkg['months'], 'OTT voucher #' . $paymentId, $adminId);

    $u = freshUser((int) $p['user_id']);
    $what = $pkg['credits'] ? "<strong>{$pkg['credits']} document credits</strong> were added. Your balance is now <strong>{$u['credits']}</strong>."
                            : '<strong>Pro</strong> is active until <strong>' . date('j F Y', strtotime($u['plan_expires_at'])) . '</strong>.';
    sendMail($u['email'], 'Payment approved — ' . $pkg['label'], emailLayout('Thank you — you\'re all set!',
        '<p>We verified your OTT voucher of <strong>R' . number_format((float) $p['amount'], 2) . '</strong>. ' . $what . '</p>', 'Create an invoice', APP_URL . '/chat.php'));
    return '';
}

function rejectPayment(int $paymentId, int $adminId, string $reason): string
{
    $db = getDB();
    $s = $db->prepare("UPDATE payments SET status = 'rejected', reviewed_by = ?, reviewed_at = NOW(), admin_note = ? WHERE id = ? AND status = 'pending'");
    $s->execute([$adminId, $reason ?: 'Voucher could not be verified', $paymentId]);
    if ($s->rowCount() !== 1) return 'That payment was already reviewed.';
    $p = $db->query('SELECT p.*, u.email FROM payments p JOIN users u ON u.id = p.user_id WHERE p.id = ' . $paymentId)->fetch();
    sendMail($p['email'], 'We couldn\'t verify your OTT voucher', emailLayout('Payment not approved',
        '<p>We couldn\'t verify the OTT voucher you sent for <strong>' . e(billingPackages()[$p['package']]['label'] ?? $p['package']) . '</strong>.</p>'
        . '<p><strong>Reason:</strong> ' . e($reason ?: 'The voucher could not be verified') . '</p>'
        . '<p>Please check the PIN and try again, or reply to this email and we\'ll help.</p>', 'Try again', APP_URL . '/billing.php'));
    return '';
}

/** A short line for the chat header and sidebar. */
function usageBadge(array $user): string
{
    $u = usageFor($user);
    return $u['plan'] . ' · ' . $u['left'] . '/' . $u['allowance'] . ' left' . ($u['credits'] ? ' + ' . $u['credits'] . ' credits' : '');
}
