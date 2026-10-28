<?php
declare(strict_types=1);

require_once __DIR__ . '/_shell.php';
require_once BASE_DIR . '/includes/leads.php';

$db = getDB();
$templates = emailTemplates();
$filterKeys = ['q', 'status', 'source', 'province', 'industry', 'has', 'business'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form = (string) ($_POST['form'] ?? '');
    $subject = trim((string) ($_POST['subject'] ?? ''));
    $body    = trim((string) ($_POST['body'] ?? ''));

    if ($form === 'test') {
        $fake = ['id' => 0, 'first_name' => explode(' ', (string) ($ADMIN['full_name'] ?: 'Admin'))[0], 'business' => 'Test Business', 'industry' => 'Services',
                 'town' => 'Cape Town', 'source' => 'test', 'token' => str_repeat('0', 32), 'email' => $ADMIN['email'], 'status' => 'new'];
        $err = sendMail($ADMIN['email'], '[TEST] ' . fillScript($subject, $fake, 'email'), leadEmailHtml($fake, $body));
        flash($err === '' ? 'success' : 'error', $err === '' ? 'Test sent to ' . $ADMIN['email'] . '.' : 'Not sent: ' . $err);
        $_SESSION['campaign_draft'] = $_POST;
        redirect('admin/campaigns.php?' . http_build_query(array_intersect_key($_POST, array_flip($filterKeys))));
    }

    if ($form === 'queue') {
        if ($subject === '' || $body === '') {
            flash('error', 'Add a subject and a message.');
            redirect('admin/campaigns.php');
        }
        $f = array_intersect_key($_POST, array_flip($filterKeys));
        $f['has'] = in_array($f['has'] ?? '', ['both'], true) ? 'both' : 'email';
        $args = [];
        $where = leadFilterSql($f, $args);
        $limit = max(1, min(20000, (int) ($_POST['limit'] ?? 200)));
        // Never: unsubscribed, signed up, not interested, or emailed in the last 14 days.
        $where .= " AND l.status NOT IN ('do_not_contact','signed_up','not_interested','wrong_number') AND (l.last_emailed_at IS NULL OR l.last_emailed_at < NOW() - INTERVAL 14 DAY)";
        $db->prepare('INSERT INTO email_campaigns (name, subject, body, filters, created_by) VALUES (?, ?, ?, ?, ?)')
           ->execute([mb_substr(trim((string) ($_POST['name'] ?? '')) ?: $subject, 0, 150), mb_substr($subject, 0, 200), $body, json_encode($f), $ADMIN['id']]);
        $cid = (int) $db->lastInsertId();
        $ins = $db->prepare("INSERT IGNORE INTO campaign_queue (campaign_id, lead_id) SELECT ?, l.id FROM leads l WHERE {$where} ORDER BY (l.business IS NULL), l.id LIMIT {$limit}");
        $ins->execute(array_merge([$cid], $args));
        $n = $ins->rowCount();
        $db->prepare('UPDATE email_campaigns SET total = ?, status = ? WHERE id = ?')->execute([$n, $n ? 'sending' : 'done', $cid]);
        unset($_SESSION['campaign_draft']);
        $per = (int) get_setting('campaign_per_run', '25');
        flash($n ? 'success' : 'error', $n
            ? "Queued {$n} emails. They go out {$per} every 5 minutes (about " . ceil($n / max(1, $per) * 5 / 60) . ' h). Emailed leads join the call list the next day.'
            : 'No leads match (people emailed in the last 14 days, unsubscribed, or already signed up are left out).');
        redirect('admin/campaigns.php');
    }

    if ($form === 'state') {
        $to = in_array($_POST['to'] ?? '', ['paused', 'sending', 'cancelled'], true) ? $_POST['to'] : null;
        if ($to) {
            $db->prepare("UPDATE email_campaigns SET status = ? WHERE id = ? AND status IN ('sending','paused')")->execute([$to, (int) $_POST['id']]);
            if ($to === 'cancelled') $db->prepare("UPDATE campaign_queue SET status = 'cancelled' WHERE campaign_id = ? AND status = 'queued'")->execute([(int) $_POST['id']]);
            flash('success', 'Campaign ' . $to . '.');
        }
        redirect('admin/campaigns.php');
    }

    if ($form === 'speed') {
        set_setting('campaign_per_run', (string) max(1, min(100, (int) ($_POST['campaign_per_run'] ?? 25))));
        flash('success', 'Sending speed saved.');
        redirect('admin/campaigns.php');
    }
}

$draft = $_SESSION['campaign_draft'] ?? [];
$f = array_intersect_key($_GET, array_flip($filterKeys));
$pick = $templates[$_GET['tpl'] ?? 'intro'] ?? $templates['intro'];
$args = [];
$where = leadFilterSql(array_merge($f, ['has' => ($f['has'] ?? '') === 'both' ? 'both' : 'email']), $args);
$c = $db->prepare("SELECT COUNT(*) FROM leads l WHERE {$where} AND l.status NOT IN ('do_not_contact','signed_up','not_interested','wrong_number') AND (l.last_emailed_at IS NULL OR l.last_emailed_at < NOW() - INTERVAL 14 DAY)");
$c->execute($args);
$eligible = (int) $c->fetchColumn();
$campaigns = $db->query('SELECT * FROM email_campaigns ORDER BY id DESC LIMIT 30')->fetchAll();
$unsubs = (int) $db->query("SELECT COUNT(*) FROM leads WHERE status = 'do_not_contact'")->fetchColumn();
$sources = $db->query('SELECT source FROM leads GROUP BY source ORDER BY MAX(id) DESC')->fetchAll(PDO::FETCH_COLUMN);

adminOpen('campaigns', 'Email campaigns', 'Email your leads in safe, throttled batches. Every email has an unsubscribe link; emailed leads go on the call list the next day.');
?>
<div class="grid-2">
  <section class="card">
    <div class="card__head"><div><h2>New campaign</h2><p><?= number_format($eligible) ?> leads match these filters and can be emailed now.</p></div></div>
    <form method="POST" class="card__body"><?= csrfField() ?>
      <div class="form-grid">
        <div class="field"><label>Who</label><select name="source"><option value="">All lists</option><?php foreach ($sources as $s): ?><option <?= ($f['source'] ?? '') === $s ? 'selected' : '' ?>><?= e($s) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label>Status</label><select name="status"><option value="">New + to call + interested</option><?php foreach (['new', 'to_call', 'interested', 'unreachable'] as $s): ?><option value="<?= $s ?>" <?= ($f['status'] ?? '') === $s ? 'selected' : '' ?>><?= LEAD_STATUSES[$s][0] ?></option><?php endforeach; ?></select></div>
        <?php foreach (['q', 'province', 'industry'] as $k): ?><input type="hidden" name="<?= $k ?>" value="<?= e($f[$k] ?? '') ?>"><?php endforeach; ?>
        <div class="field"><label>Send to at most</label><input type="number" name="limit" min="1" max="20000" value="<?= e($draft['limit'] ?? min(200, max(1, $eligible))) ?>"></div>
        <div class="field"><label><input type="checkbox" name="business" value="1" <?= !empty($f['business']) || !$f ? 'checked' : '' ?>> Businesses only</label><label><input type="checkbox" name="has" value="both" <?= ($f['has'] ?? '') === 'both' ? 'checked' : '' ?>> Only leads with a mobile too (to call after)</label></div>
      </div>
      <div class="field"><label>Start from</label>
        <div class="chips"><?php foreach ($templates as $k => $t): ?><a class="chip-check" href="?<?= e(http_build_query($f + ['tpl' => $k])) ?>"><?= e($t['name']) ?></a><?php endforeach; ?></div></div>
      <div class="field"><label>Campaign name</label><input name="name" value="<?= e($draft['name'] ?? $pick['name'] . ' — ' . date('j M')) ?>"></div>
      <div class="field"><label>Subject</label><input name="subject" value="<?= e($draft['subject'] ?? $pick['subject']) ?>" required></div>
      <div class="field"><label>Message (HTML)</label><textarea name="body" rows="12" required><?= e($draft['body'] ?? $pick['body']) ?></textarea>
        <div class="hint">Placeholders: [FirstName] [Business] [Industry] [Town] [Agent] [Link] [Free] [Price] [Pack]. The unsubscribe footer is added for you.</div></div>
      <div class="form-foot">
        <button class="btn btn-ghost" name="form" value="test"><i class="fa-solid fa-vial"></i> Send me a test</button>
        <button class="btn btn-primary" name="form" value="queue" onclick="return confirm('Queue this campaign?')"><i class="fa-solid fa-paper-plane"></i> Queue campaign</button>
      </div>
    </form>
  </section>
  <section class="card">
    <div class="card__head"><h2>Deliverability</h2></div>
    <div class="card__body">
      <form method="POST" class="toolbar" style="padding:0 0 1rem;border:0"><?= csrfField() ?><input type="hidden" name="form" value="speed">
        <label style="font-weight:700;font-size:.85rem">Emails per 5 minutes</label><input class="input" type="number" name="campaign_per_run" min="1" max="100" value="<?= e(get_setting('campaign_per_run', '25')) ?>" style="flex:0;min-width:80px"><button class="btn-xs">Save</button></form>
      <ul class="steps-list">
        <li>Shared cPanel hosting usually allows about <strong>300–500 emails an hour</strong>. 25 per 5 minutes (300/hour) is safe.</li>
        <li>Start with <strong>100–200 a day</strong> for the first week, then grow. Sudden big sends get the domain blacklisted.</li>
        <li>In cPanel → <strong>Email Deliverability</strong>, make sure SPF and DKIM show “Valid” for swiftfusion.co.za.</li>
        <li>For more volume, use a free <strong>Brevo</strong> account (300 emails/day free) — put its SMTP details in Settings → Email delivery.</li>
        <li><?= $unsubs ?> lead<?= $unsubs === 1 ? ' has' : 's have' ?> unsubscribed and will never be emailed again.</li>
      </ul>
    </div>
  </section>
</div>

<section class="card">
  <div class="card__head"><h2>Campaigns</h2><span class="muted">Sending needs the cron job (Social & ads → Connections)</span></div>
  <?php if (!$campaigns): ?><div class="empty"><i class="fa-solid fa-envelopes-bulk"></i>No campaigns yet.</div><?php else: ?>
  <table class="tbl"><thead><tr><th>Campaign</th><th>Progress</th><th>Status</th><th>Created</th><th></th></tr></thead><tbody>
    <?php foreach ($campaigns as $cp): $pct = $cp['total'] ? round(($cp['sent'] + $cp['failed']) / $cp['total'] * 100) : 100; ?>
      <tr>
        <td class="cell-main"><strong><?= e($cp['name']) ?></strong><br><span class="muted"><?= e($cp['subject']) ?></span></td>
        <td data-label="Progress" style="min-width:160px"><div class="strength"><span style="width:<?= $pct ?>%;background:var(--mint)"></span></div><span class="muted"><?= (int) $cp['sent'] ?> sent · <?= (int) $cp['failed'] ?> failed · <?= (int) $cp['total'] ?> total</span></td>
        <td data-label="Status"><span class="tag <?= ['done' => 'tag-on', 'sending' => 'tag-google', 'cancelled' => 'tag-off', 'paused' => 'tag-user'][$cp['status']] ?? 'tag-user' ?>"><?= e($cp['status']) ?></span></td>
        <td data-label="Created" class="muted"><?= timeAgo($cp['created_at']) ?></td>
        <td><?php if (in_array($cp['status'], ['sending', 'paused'], true)): ?>
          <form method="POST" class="row-actions"><?= csrfField() ?><input type="hidden" name="form" value="state"><input type="hidden" name="id" value="<?= (int) $cp['id'] ?>">
            <?= $cp['status'] === 'sending' ? '<button class="btn-xs" name="to" value="paused">Pause</button>' : '<button class="btn-xs" name="to" value="sending">Resume</button>' ?>
            <button class="btn-xs danger" name="to" value="cancelled" onclick="return confirm('Cancel the emails not sent yet?')">Cancel</button></form>
        <?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
  </tbody></table>
  <?php endif; ?>
</section>
<?php adminClose();
