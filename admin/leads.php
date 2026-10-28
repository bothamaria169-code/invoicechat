<?php
declare(strict_types=1);

require_once __DIR__ . '/_shell.php';
require_once BASE_DIR . '/includes/leads.php';

$db  = getDB();
$me  = (int) $ADMIN['id'];
$tab = in_array($_GET['tab'] ?? '', ['call', 'all', 'import', 'scripts'], true) ? $_GET['tab'] : 'call';

function leadById(int $id): ?array
{
    $s = getDB()->prepare('SELECT * FROM leads WHERE id = ?');
    $s->execute([$id]);
    return $s->fetch() ?: null;
}

function logLead(int $leadId, string $kind, ?string $outcome, string $detail, ?int $adminId): void
{
    getDB()->prepare('INSERT INTO lead_activity (lead_id, kind, outcome, detail, admin_id) VALUES (?, ?, ?, ?, ?)')
        ->execute([$leadId, $kind, $outcome, mb_substr($detail, 0, 1000), $adminId]);
}

// ── Actions ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form = (string) ($_POST['form'] ?? '');
    $back = (string) ($_POST['back'] ?? 'leads.php');
    $back = preg_match('/^leads\.php(\?[\w=&%.+-]*)?$/', $back) ? $back : 'leads.php';
    $lead = isset($_POST['lead_id']) ? leadById((int) $_POST['lead_id']) : null;

    if ($form === 'outcome' && $lead && isset(LEAD_OUTCOMES[$_POST['outcome'] ?? ''])) {
        [$label, $status, $when] = LEAD_OUTCOMES[$_POST['outcome']];
        $after = match (true) {
            $when === 'pick' => ($t = strtotime((string) ($_POST['call_after'] ?? ''))) && $t > time() ? date('Y-m-d H:i:s', $t) : date('Y-m-d H:i:s', strtotime('+1 day 09:00')),
            $when !== null   => date('Y-m-d H:i:s', strtotime($when)),
            default          => null,
        };
        // Five unanswered tries and the lead is parked as unreachable.
        if (in_array($_POST['outcome'], ['no_answer', 'voicemail'], true) && $lead['attempts'] + 1 >= 5) { $status = 'unreachable'; $after = null; }
        $phone = normPhone((string) ($_POST['phone'] ?? '')) ?? $lead['phone'];
        $notes = trim((string) ($_POST['notes'] ?? ''));
        $db->prepare('UPDATE leads SET status = ?, call_after = ?, attempts = attempts + 1, last_called_at = NOW(), phone = ?,
                      notes = TRIM(CONCAT(COALESCE(notes, ""), ?)) WHERE id = ?')
           ->execute([$status, $after, $phone, $notes !== '' ? "\n" . date('j M H:i') . ' — ' . $notes : '', $lead['id']]);
        logLead((int) $lead['id'], 'call', $_POST['outcome'], $label . ($notes !== '' ? ': ' . $notes : ''), $me);
        flash('success', leadName($lead) . ': ' . $label . ($after ? ' · back on the list ' . date('D j M H:i', strtotime($after)) : ' · off the list'));
        redirect('admin/' . $back);
    }

    if ($form === 'wa' && $lead) {
        $db->prepare('UPDATE leads SET whatsapp_at = NOW() WHERE id = ?')->execute([$lead['id']]);
        logLead((int) $lead['id'], 'whatsapp', null, 'WhatsApp: ' . (whatsappTemplates()[$_POST['template'] ?? '']['name'] ?? 'message'), $me);
        jsonResponse(['ok' => true]);
    }

    if ($form === 'email' && $lead) {
        $tpl = emailTemplates()[$_POST['template'] ?? ''] ?? null;
        $err = $tpl ? emailLead($lead, $tpl['subject'], $tpl['body'], $me) : 'Choose a template.';
        flash($err === '' ? 'success' : 'error', $err === '' ? 'Emailed ' . $lead['email'] . ' (“' . $tpl['name'] . '”).' : 'Not sent: ' . $err);
        redirect('admin/' . $back);
    }

    if ($form === 'edit' && $lead) {
        $f = fn(string $k, int $len) => ($v = trim((string) ($_POST[$k] ?? ''))) !== '' ? mb_substr($v, 0, $len) : null;
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        try {
            $db->prepare('UPDATE leads SET first_name = ?, last_name = ?, business = ?, job_title = ?, email = ?, phone = ?, industry = ?, town = ?, province = ?, website = ?, status = ? WHERE id = ?')
               ->execute([$f('first_name', 100), $f('last_name', 100), $f('business', 190), $f('job_title', 120), filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null,
                          normPhone((string) ($_POST['phone'] ?? '')), $f('industry', 150), $f('town', 100), $f('province', 60), $f('website', 190),
                          isset(LEAD_STATUSES[$_POST['status'] ?? '']) ? $_POST['status'] : $lead['status'], $lead['id']]);
            flash('success', 'Lead saved.');
        } catch (PDOException) {
            flash('error', 'Another lead already has that email address.');
        }
        redirect('admin/leads.php?id=' . $lead['id']);
    }

    if ($form === 'add') {
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $phone = normPhone((string) ($_POST['phone'] ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) && !$phone) {
            flash('error', 'Add at least a valid email or SA mobile number.');
        } else {
            try {
                $db->prepare("INSERT INTO leads (email, first_name, business, phone, source, status, token) VALUES (?, ?, ?, ?, 'manual', 'to_call', ?)")
                   ->execute([filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null, trim((string) $_POST['first_name']) ?: null, trim((string) $_POST['business']) ?: null, $phone, bin2hex(random_bytes(16))]);
                flash('success', 'Lead added to the call list.');
            } catch (PDOException) {
                flash('error', 'That email is already a lead.');
            }
        }
        redirect('admin/leads.php?tab=all');
    }

    if ($form === 'fill') {
        $n = max(1, min(500, (int) ($_POST['count'] ?? 30)));
        $args = [];
        $where = leadFilterSql(['source' => $_POST['source'] ?? '', 'province' => $_POST['province'] ?? '', 'has' => 'phone', 'business' => $_POST['business'] ?? ''], $args);
        $s = $db->prepare("UPDATE leads l SET l.status = 'to_call', l.call_after = NULL WHERE l.status = 'new' AND {$where} ORDER BY (l.business IS NULL), l.id LIMIT {$n}");
        $s->execute($args);
        flash('success', $s->rowCount() . ' leads added to today\'s call list.');
        redirect('admin/leads.php');
    }

    if ($form === 'bulk') {
        $args = [];
        $where = leadFilterSql($_POST, $args);
        $n = max(1, min(5000, (int) ($_POST['limit'] ?? 100)));
        $to = (string) ($_POST['to'] ?? '');
        if ($to === 'to_call') {
            $s = $db->prepare("UPDATE leads l SET l.status = 'to_call', l.call_after = NULL WHERE l.status IN ('new','not_interested','unreachable') AND l.phone IS NOT NULL AND {$where} LIMIT {$n}");
        } elseif ($to === 'delete') {
            $s = $db->prepare("DELETE l FROM leads l WHERE l.status IN ('new','to_call') AND {$where}");
        } else {
            redirect('admin/leads.php?tab=all');
        }
        $s->execute($args);
        flash('success', $s->rowCount() . ' leads ' . ($to === 'delete' ? 'deleted (contacted leads and do-not-contact records were kept).' : 'moved to the call list.'));
        redirect('admin/leads.php?tab=' . ($to === 'delete' ? 'import' : 'call'));
    }

    if ($form === 'import') {
        if (empty($_FILES['file']['tmp_name']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
            flash('error', 'Choose a .xlsx or .csv file (max ' . ini_get('upload_max_filesize') . ' on this server). Bigger files: use the command in the box below.');
        } else {
            $source = trim((string) ($_POST['source'] ?? '')) ?: pathinfo($_FILES['file']['name'], PATHINFO_FILENAME);
            $r = importLeads($_FILES['file']['tmp_name'], $_FILES['file']['name'], mb_substr($source, 0, 120), !empty($_POST['business_only']), (int) ($_POST['limit'] ?? 0));
            flash($r['error'] ? 'error' : 'success', $r['error'] ?: "Imported “{$source}”: {$r['added']} new, {$r['updated']} already known (blanks filled), {$r['skipped']} skipped. Columns used: " . implode(', ', $r['columns']) . '.');
        }
        redirect('admin/leads.php?tab=import');
    }

    if ($form === 'agent') {
        set_setting('call_agent_name', mb_substr(trim((string) ($_POST['call_agent_name'] ?? '')), 0, 60));
        flash('success', 'Scripts now introduce ' . callAgent() . '.');
        redirect('admin/leads.php?tab=scripts');
    }
}

// ── Shared bits ───────────────────────────────────────────────────
$sources   = $db->query('SELECT source, COUNT(*) n, SUM(status = "new") fresh, SUM(phone IS NOT NULL) phones, SUM(email IS NOT NULL) emails, SUM(status = "signed_up") won FROM leads GROUP BY source ORDER BY MAX(id) DESC')->fetchAll();
$provinces = $db->query('SELECT province FROM leads WHERE province IS NOT NULL GROUP BY province ORDER BY COUNT(*) DESC LIMIT 15')->fetchAll(PDO::FETCH_COLUMN);
$dueSql    = "status IN ('to_call','interested') AND (call_after IS NULL OR call_after <= NOW())";
$counts    = $db->query("SELECT COUNT(*) total, SUM({$dueSql}) due, SUM(status = 'new') fresh, SUM(status = 'interested') hot, SUM(status = 'signed_up') won FROM leads")->fetch();
$today     = $db->query("SELECT SUM(kind = 'call') calls, SUM(kind = 'email') emails, SUM(kind = 'whatsapp') wa, SUM(outcome IN ('interested','signed_up')) wins FROM lead_activity WHERE created_at >= CURDATE()")->fetch();

function leadActions(array $l, string $back): string
{
    ob_start(); ?>
    <div class="lead-actions">
      <?php if ($l['phone']): ?>
        <a class="btn btn-primary" href="tel:<?= e($l['phone']) ?>"><i class="fa-solid fa-phone"></i> <?= e(preg_replace('/(\d{3})(\d{3})(\d{4})/', '$1 $2 $3', $l['phone'])) ?></a>
        <div class="dd">
          <button type="button" class="btn btn-ghost" style="color:#128c7e"><i class="fa-brands fa-whatsapp"></i> WhatsApp <i class="fa-solid fa-caret-down"></i></button>
          <div class="dd__menu">
            <?php foreach (whatsappTemplates() as $k => $t): ?>
              <a target="_blank" rel="noopener" data-wa="<?= (int) $l['id'] ?>" data-tpl="<?= $k ?>" href="https://wa.me/<?= waNumber($l['phone']) ?>?text=<?= rawurlencode(fillScript($t['text'], $l, 'whatsapp')) ?>"><?= e($t['name']) ?></a>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endif; ?>
      <?php if ($l['email']): ?>
        <form method="POST" class="dd-inline"><?= csrfField() ?><input type="hidden" name="form" value="email"><input type="hidden" name="lead_id" value="<?= (int) $l['id'] ?>"><input type="hidden" name="back" value="<?= e($back) ?>">
          <select name="template" class="select-sm" aria-label="Email template"><?php foreach (emailTemplates() as $k => $t): ?><option value="<?= $k ?>"><?= e($t['name']) ?></option><?php endforeach; ?></select>
          <button class="btn btn-ghost"><i class="fa-regular fa-envelope"></i> Email</button>
        </form>
      <?php endif; ?>
    </div>
    <?php return (string) ob_get_clean();
}

function outcomeForm(array $l, string $back): string
{
    ob_start(); ?>
    <form method="POST" class="outcome"><?= csrfField() ?><input type="hidden" name="form" value="outcome"><input type="hidden" name="lead_id" value="<?= (int) $l['id'] ?>"><input type="hidden" name="back" value="<?= e($back) ?>">
      <div class="chips">
        <?php foreach (LEAD_OUTCOMES as $k => [$label]): ?>
          <label class="chip-check chip-sm"><input type="radio" name="outcome" value="<?= $k ?>" required> <?= e($label) ?></label>
        <?php endforeach; ?>
      </div>
      <div class="outcome__row">
        <input class="input" name="notes" placeholder="Notes (what they said, best time…)">
        <input class="input" type="datetime-local" name="call_after" title="Call back at (for Call back later)" value="<?= date('Y-m-d\T09:00', strtotime('+1 day')) ?>">
        <?php if (!$l['phone']): ?><input class="input" name="phone" placeholder="Add mobile"><?php endif; ?>
        <button class="btn btn-dark"><i class="fa-solid fa-check"></i> Save call</button>
      </div>
    </form>
    <?php return (string) ob_get_clean();
}

function leadMeta(array $l): string
{
    $bits = array_filter([$l['job_title'], $l['industry'], trim(($l['town'] ?? '') . ($l['province'] ? ', ' . $l['province'] : ''), ', ')]);
    return e(implode(' · ', $bits));
}

// ── Single lead ───────────────────────────────────────────────────
if (isset($_GET['id']) && ($l = leadById((int) $_GET['id']))) {
    $acts = $db->prepare('SELECT a.*, u.email admin FROM lead_activity a LEFT JOIN users u ON u.id = a.admin_id WHERE a.lead_id = ? ORDER BY a.id DESC LIMIT 50');
    $acts->execute([$l['id']]);
    $acts = $acts->fetchAll();
    $back = 'leads.php?id=' . $l['id'];
    adminOpen('leads', leadName($l), leadMeta($l) ?: 'Lead from ' . e($l['source']), '<a class="btn btn-ghost" href="leads.php"><i class="fa-solid fa-arrow-left"></i> Call list</a>');
    ?>
    <div class="grid-2">
      <section class="card">
        <div class="card__head"><h2>Contact</h2><span class="tag <?= LEAD_STATUSES[$l['status']][1] ?>"><?= LEAD_STATUSES[$l['status']][0] ?></span></div>
        <div class="card__body">
          <?= leadActions($l, $back) ?>
          <?php if ($l['status'] !== 'do_not_contact'): ?><h3 class="mini-h">Log a call</h3><?= outcomeForm($l, $back) ?><?php endif; ?>
          <h3 class="mini-h">Activity</h3>
          <?php if (!$acts): ?><p class="hint">Nothing yet.</p><?php endif; ?>
          <?php foreach ($acts as $a): ?>
            <div class="act"><i class="<?= ['call' => 'fa-solid fa-phone', 'email' => 'fa-solid fa-envelope', 'whatsapp' => 'fa-brands fa-whatsapp', 'system' => 'fa-solid fa-gear'][$a['kind']] ?? 'fa-solid fa-circle' ?>"></i>
              <div><strong><?= e($a['detail']) ?></strong><small><?= timeAgo($a['created_at']) ?><?= $a['admin'] ? ' · ' . e($a['admin']) : '' ?></small></div></div>
          <?php endforeach; ?>
          <?php if ($l['notes']): ?><h3 class="mini-h">Notes</h3><p style="white-space:pre-line;font-size:.86rem;color:var(--text-2)"><?= e($l['notes']) ?></p><?php endif; ?>
        </div>
      </section>
      <section class="card">
        <div class="card__head"><h2>Details</h2><span class="muted">Source: <?= e($l['source']) ?></span></div>
        <form method="POST" class="card__body"><?= csrfField() ?><input type="hidden" name="form" value="edit"><input type="hidden" name="lead_id" value="<?= (int) $l['id'] ?>">
          <div class="form-grid">
            <?php foreach (['first_name' => 'First name', 'last_name' => 'Last name', 'business' => 'Business', 'job_title' => 'Job title', 'email' => 'Email', 'phone' => 'Mobile', 'industry' => 'Industry', 'town' => 'Town', 'province' => 'Province', 'website' => 'Website'] as $k => $label): ?>
              <div class="field"><label><?= $label ?></label><input name="<?= $k ?>" value="<?= e($l[$k]) ?>"></div>
            <?php endforeach; ?>
            <div class="field"><label>Status</label><select name="status"><?php foreach (LEAD_STATUSES as $k => [$label]): ?><option value="<?= $k ?>" <?= $l['status'] === $k ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select></div>
          </div>
          <div class="form-foot"><button class="btn btn-primary">Save</button><?php if ($l['user_id']): ?><a class="btn btn-ghost" href="users.php?id=<?= (int) $l['user_id'] ?>">Open their account</a><?php endif; ?></div>
        </form>
      </section>
    </div>
    <?php
    adminClose(leadScripts());
    exit;
}

function leadScripts(): string
{
    return '<style>
      .lead-actions{display:flex;flex-wrap:wrap;gap:.45rem;margin-bottom:.9rem}.lead-actions .btn{padding:.55rem .9rem;font-size:.86rem}
      .dd{position:relative}.dd__menu{display:none;position:absolute;z-index:20;top:110%;left:0;min-width:200px;background:var(--surface);border:1px solid var(--line);border-radius:12px;box-shadow:var(--e-2);padding:.3rem}
      .dd.open .dd__menu{display:block}.dd__menu a{display:block;padding:.55rem .7rem;border-radius:8px;color:var(--text);font-size:.85rem;text-decoration:none}.dd__menu a:hover{background:var(--surface-2)}
      .dd-inline{display:flex;gap:.35rem;align-items:center}
      .chip-sm{padding:.35rem .65rem;font-size:.78rem}.outcome .chips{margin-bottom:.5rem}
      .outcome__row{display:grid;grid-template-columns:1fr auto auto auto;gap:.4rem}.outcome__row .input{padding:.55rem .7rem;font-size:.85rem;border:1px solid var(--line);border-radius:10px;background:var(--surface)}
      .mini-h{font-size:.82rem;text-transform:uppercase;letter-spacing:.06em;color:var(--text-3);font-family:var(--font);margin:1.1rem 0 .5rem}
      .act{display:flex;gap:.6rem;padding:.4rem 0;font-size:.85rem}.act i{color:var(--text-3);margin-top:.2rem;width:16px}.act small{display:block;color:var(--text-3);font-size:.75rem}.act strong{font-weight:600}
      .lead-card{padding:1.1rem 1.2rem;border-bottom:1px solid var(--line)}.lead-card:last-child{border-bottom:0}
      .lead-card__top{display:flex;justify-content:space-between;gap:1rem;margin-bottom:.7rem}.lead-card__top h3{font-family:var(--font);font-size:1.02rem}.lead-card__top small{color:var(--text-3);font-size:.8rem;display:block}
      details.script{margin:.2rem 0 .8rem;border:1px dashed var(--line-strong);border-radius:12px;padding:.6rem .8rem;font-size:.86rem}details.script summary{cursor:pointer;font-weight:700;color:var(--brand)}
      .script dl{display:grid;grid-template-columns:120px 1fr;gap:.35rem .8rem;margin-top:.6rem}.script dt{font-weight:800;color:var(--text-3);font-size:.75rem;text-transform:uppercase;padding-top:.15rem}.script dd{color:var(--text)}
      @media(max-width:640px){.outcome__row{grid-template-columns:1fr}.script dl{grid-template-columns:1fr}.lead-card__top{flex-direction:column}}
    </style>
    <script>
      document.querySelectorAll(".dd > button").forEach(function(b){b.onclick=function(e){e.stopPropagation();b.parentNode.classList.toggle("open");};});
      document.addEventListener("click",function(){document.querySelectorAll(".dd.open").forEach(function(d){d.classList.remove("open");});});
      document.querySelectorAll("[data-wa]").forEach(function(a){a.addEventListener("click",function(){
        var fd=new FormData();fd.append("form","wa");fd.append("lead_id",a.dataset.wa);fd.append("template",a.dataset.tpl);fd.append("_csrf",' . json_encode(csrfToken()) . ');
        fetch("leads.php",{method:"POST",body:fd,keepalive:true});});});
    </script>';
}

adminOpen('leads', 'Leads & calls', 'Call, WhatsApp and email your leads with ready scripts — every call is logged.',
    '<a class="btn btn-ghost" href="campaigns.php"><i class="fa-solid fa-envelopes-bulk"></i> Email campaign</a><a class="btn btn-primary" href="?tab=import"><i class="fa-solid fa-file-import"></i> Import leads</a>');
?>
<div class="kpis">
  <div class="kpi kpi--amber"><div class="kpi__ico"><i class="fa-solid fa-phone-volume"></i></div><div><div class="kpi__label">Due to call now</div><div class="kpi__value"><?= (int) $counts['due'] ?></div><div class="kpi__delta"><?= (int) $counts['hot'] ?> interested · follow up</div></div></div>
  <div class="kpi"><div class="kpi__ico"><i class="fa-solid fa-headset"></i></div><div><div class="kpi__label">Today</div><div class="kpi__value"><?= (int) $today['calls'] ?> calls</div><div class="kpi__delta"><?= (int) $today['wa'] ?> WhatsApps · <?= (int) $today['emails'] ?> emails · <b><?= (int) $today['wins'] ?></b> wins</div></div></div>
  <div class="kpi kpi--mint"><div class="kpi__ico"><i class="fa-solid fa-trophy"></i></div><div><div class="kpi__label">Signed up from leads</div><div class="kpi__value"><?= (int) $counts['won'] ?></div><div class="kpi__delta"><?= $counts['total'] ? round($counts['won'] / max(1, $counts['total'] - $counts['fresh']) * 100, 1) : 0 ?>% of contacted leads</div></div></div>
  <div class="kpi kpi--violet"><div class="kpi__ico"><i class="fa-solid fa-address-book"></i></div><div><div class="kpi__label">All leads</div><div class="kpi__value"><?= number_format((int) $counts['total']) ?></div><div class="kpi__delta"><?= number_format((int) $counts['fresh']) ?> not contacted yet</div></div></div>
</div>

<nav class="tabs">
  <a href="?tab=call" class="<?= $tab === 'call' ? 'is-on' : '' ?>"><i class="fa-solid fa-phone"></i> Call list (<?= (int) $counts['due'] ?>)</a>
  <a href="?tab=all" class="<?= $tab === 'all' ? 'is-on' : '' ?>"><i class="fa-solid fa-table-list"></i> All leads</a>
  <a href="?tab=import" class="<?= $tab === 'import' ? 'is-on' : '' ?>"><i class="fa-solid fa-file-import"></i> Import & lists</a>
  <a href="?tab=scripts" class="<?= $tab === 'scripts' ? 'is-on' : '' ?>"><i class="fa-solid fa-scroll"></i> Scripts & templates</a>
</nav>

<?php if ($tab === 'call'):
    $rows = $db->query("SELECT * FROM leads WHERE {$dueSql} ORDER BY (status = 'interested') DESC, call_after IS NULL, call_after, attempts, id LIMIT 25")->fetchAll();
    $later = (int) $db->query("SELECT COUNT(*) FROM leads WHERE status IN ('to_call','interested') AND call_after > NOW()")->fetchColumn();
    $scripts = callScripts(); ?>
<section class="card">
  <form method="POST" class="toolbar"><?= csrfField() ?><input type="hidden" name="form" value="fill">
    <strong style="font-size:.88rem">Fill today's list:</strong>
    <input class="input" type="number" name="count" value="30" min="1" max="500" style="flex:0;min-width:90px">
    <select name="source"><option value="">From any list</option><?php foreach ($sources as $s): ?><option value="<?= e($s['source']) ?>"><?= e($s['source']) ?> (<?= (int) $s['fresh'] ?> new)</option><?php endforeach; ?></select>
    <select name="province"><option value="">Any province</option><?php foreach ($provinces as $p): ?><option><?= e($p) ?></option><?php endforeach; ?></select>
    <label class="check" style="margin:0"><input type="checkbox" name="business" value="1" checked> Businesses only</label>
    <button class="btn-xs"><i class="fa-solid fa-plus"></i> Add new leads with a mobile</button>
    <span class="count"><?= $later ?> scheduled for later</span>
  </form>
  <?php if (!$rows): ?>
    <div class="empty"><i class="fa-solid fa-mug-hot"></i>Nobody to call right now. Fill the list above<?= $counts['total'] ? '' : ' — or <a href="?tab=import">import your first leads</a>' ?>.</div>
  <?php endif; ?>
  <?php foreach ($rows as $l): $script = $l['status'] === 'interested' || $l['attempts'] > 0 && $l['last_emailed_at'] ? $scripts['follow'] : $scripts['cold']; ?>
    <article class="lead-card">
      <div class="lead-card__top">
        <div><h3><a href="?id=<?= (int) $l['id'] ?>" style="color:inherit"><?= e(leadName($l)) ?></a><?= $l['business'] && leadName($l) !== $l['business'] ? ' <span class="muted">· ' . e($l['business']) . '</span>' : '' ?></h3>
          <small><?= leadMeta($l) ?: '—' ?></small>
          <small><?= $l['email'] ? e($l['email']) . ' · ' : '' ?>List: <?= e($l['source']) ?></small></div>
        <div style="text-align:right"><span class="tag <?= LEAD_STATUSES[$l['status']][1] ?>"><?= LEAD_STATUSES[$l['status']][0] ?></span>
          <small class="muted" style="display:block;margin-top:.3rem"><?= $l['attempts'] ? $l['attempts'] . ' tries · last ' . timeAgo($l['last_called_at']) : 'First call' ?><?= $l['last_emailed_at'] ? ' · emailed ' . timeAgo($l['last_emailed_at']) : '' ?><?= $l['whatsapp_at'] ? ' · WhatsApped ' . timeAgo($l['whatsapp_at']) : '' ?></small></div>
      </div>
      <?= leadActions($l, 'leads.php') ?>
      <details class="script"<?= $l['attempts'] === 0 ? ' open' : '' ?>><summary><?= e($script['title']) ?> — script</summary>
        <dl><?php foreach ($script['steps'] as [$k, $v]): ?><dt><?= e($k) ?></dt><dd><?= preg_replace('/\*([^*]+)\*/', '<strong>$1</strong>', nl2br(e(fillScript($v, $l)))) ?></dd><?php endforeach; ?></dl>
      </details>
      <?php if ($l['notes']): ?><p class="hint" style="white-space:pre-line;margin:-.3rem 0 .6rem"><?= e(mb_substr($l['notes'], -300)) ?></p><?php endif; ?>
      <?= outcomeForm($l, 'leads.php') ?>
    </article>
  <?php endforeach; ?>
</section>

<?php elseif ($tab === 'all'):
    $f = ['q' => trim((string) ($_GET['q'] ?? '')), 'status' => $_GET['status'] ?? '', 'source' => $_GET['source'] ?? '', 'province' => $_GET['province'] ?? '', 'has' => $_GET['has'] ?? '', 'business' => $_GET['business'] ?? ''];
    $args = [];
    $where = leadFilterSql($f, $args);
    $cnt = $db->prepare("SELECT COUNT(*) FROM leads l WHERE {$where}");
    $cnt->execute($args);
    $total = (int) $cnt->fetchColumn();
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $s = $db->prepare("SELECT * FROM leads l WHERE {$where} ORDER BY l.id DESC LIMIT 50 OFFSET " . (($page - 1) * 50));
    $s->execute($args);
    $rows = $s->fetchAll();
    $qs = fn(array $o) => '?' . http_build_query(array_filter(array_merge(['tab' => 'all'] + $f, $o), fn($v) => $v !== '' && $v !== null));
    if (isset($_GET['export'])) {
        $s = $db->prepare("SELECT first_name, last_name, business, job_title, email, phone, industry, town, province, source, status, attempts, last_called_at, emails_sent FROM leads l WHERE {$where} ORDER BY l.id");
        $s->execute($args);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="invoicechat-leads-' . date('Y-m-d') . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['First name', 'Last name', 'Business', 'Job title', 'Email', 'Mobile', 'Industry', 'Town', 'Province', 'List', 'Status', 'Calls', 'Last called', 'Emails sent']);
        foreach ($s as $r) fputcsv($out, $r);
        exit;
    } ?>
<section class="card">
  <form class="toolbar" method="get"><input type="hidden" name="tab" value="all">
    <input class="input" type="search" name="q" value="<?= e($f['q']) ?>" placeholder="Search name, business, email or number">
    <select name="status" onchange="this.form.submit()"><option value="">Any status</option><?php foreach (LEAD_STATUSES as $k => [$label]): ?><option value="<?= $k ?>" <?= $f['status'] === $k ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select>
    <select name="source" onchange="this.form.submit()"><option value="">Any list</option><?php foreach ($sources as $src): ?><option value="<?= e($src['source']) ?>" <?= $f['source'] === $src['source'] ? 'selected' : '' ?>><?= e($src['source']) ?></option><?php endforeach; ?></select>
    <select name="province" onchange="this.form.submit()"><option value="">Any province</option><?php foreach ($provinces as $p): ?><option <?= $f['province'] === $p ? 'selected' : '' ?>><?= e($p) ?></option><?php endforeach; ?></select>
    <select name="has" onchange="this.form.submit()"><option value="">Email or mobile</option><option value="phone" <?= $f['has'] === 'phone' ? 'selected' : '' ?>>Has mobile</option><option value="email" <?= $f['has'] === 'email' ? 'selected' : '' ?>>Has email</option><option value="both" <?= $f['has'] === 'both' ? 'selected' : '' ?>>Has both</option></select>
    <label class="check" style="margin:0"><input type="checkbox" name="business" value="1" <?= $f['business'] ? 'checked' : '' ?> onchange="this.form.submit()"> Businesses</label>
    <span class="count"><?= number_format($total) ?> leads · <a href="<?= e($qs(['export' => 1])) ?>">Export</a></span>
  </form>
  <form method="POST" class="toolbar" style="background:var(--surface-2)"><?= csrfField() ?><input type="hidden" name="form" value="bulk">
    <?php foreach ($f as $k => $v): ?><input type="hidden" name="<?= $k ?>" value="<?= e($v) ?>"><?php endforeach; ?>
    <strong style="font-size:.85rem">With these <?= number_format($total) ?>:</strong>
    <input class="input" type="number" name="limit" value="<?= min(100, max(1, $total)) ?>" min="1" max="5000" style="flex:0;min-width:90px">
    <button class="btn-xs" name="to" value="to_call"><i class="fa-solid fa-phone"></i> Add to call list</button>
    <a class="btn-xs" href="campaigns.php?<?= e(http_build_query(array_filter($f))) ?>"><i class="fa-regular fa-envelope"></i> Email them</a>
    <button class="btn-xs danger" name="to" value="delete" onclick="return confirm('Delete the uncontacted leads that match? Contacted and do-not-contact leads are kept.')"><i class="fa-solid fa-trash-can"></i> Delete uncontacted</button>
  </form>
  <details style="padding:.8rem 1.2rem;border-bottom:1px solid var(--line)"><summary style="cursor:pointer;font-weight:700;font-size:.88rem">+ Add a lead by hand</summary>
    <form method="POST" class="toolbar" style="padding:.8rem 0 0;border:0"><?= csrfField() ?><input type="hidden" name="form" value="add">
      <input class="input" name="first_name" placeholder="First name"><input class="input" name="business" placeholder="Business"><input class="input" name="phone" placeholder="Mobile"><input class="input" name="email" placeholder="Email"><button class="btn-xs">Add to call list</button></form>
  </details>
  <?php if (!$rows): ?><div class="empty"><i class="fa-solid fa-address-book"></i>No leads match. <a href="?tab=import">Import a list</a>.</div><?php else: ?>
  <table class="tbl">
    <thead><tr><th>Lead</th><th>Contact</th><th>Area</th><th>List</th><th>Status</th><th>Last touch</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $l): ?>
      <tr>
        <td class="cell-main"><a href="?id=<?= (int) $l['id'] ?>" style="color:inherit;text-decoration:none"><strong><?= e(leadName($l)) ?></strong><?php if ($l['business'] && leadName($l) !== $l['business']): ?><br><span class="muted"><?= e($l['business']) ?></span><?php endif; ?></a></td>
        <td data-label="Contact"><?= e($l['phone'] ?: '—') ?><br><span class="muted"><?= e($l['email'] ?: '') ?></span></td>
        <td data-label="Area" class="muted"><?= e(trim(($l['town'] ?? '') . ', ' . ($l['province'] ?? ''), ', ') ?: '—') ?></td>
        <td data-label="List" class="muted"><?= e($l['source']) ?></td>
        <td data-label="Status"><span class="tag <?= LEAD_STATUSES[$l['status']][1] ?>"><?= LEAD_STATUSES[$l['status']][0] ?></span></td>
        <td data-label="Last touch" class="muted"><?= timeAgo(max((string) $l['last_called_at'], (string) $l['last_emailed_at'], (string) $l['whatsapp_at']) ?: null) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
  <?php $pages = (int) ceil($total / 50); if ($pages > 1): ?>
    <div class="pager"><span>Page <?= $page ?> of <?= $pages ?></span><div>
      <?php if ($page > 1): ?><a class="btn-xs" href="<?= e($qs(['page' => $page - 1])) ?>">← Prev</a><?php endif; ?>
      <?php if ($page < $pages): ?><a class="btn-xs" href="<?= e($qs(['page' => $page + 1])) ?>">Next →</a><?php endif; ?>
    </div></div>
  <?php endif; ?>
</section>

<?php elseif ($tab === 'import'): ?>
<div class="grid-2">
  <section class="card">
    <div class="card__head"><div><h2>Import a lead list</h2><p>.xlsx or .csv with a header row. Matching columns are found automatically.</p></div></div>
    <form method="POST" enctype="multipart/form-data" class="card__body" data-busy><?= csrfField() ?><input type="hidden" name="form" value="import">
      <div class="field"><label>File</label><input type="file" name="file" accept=".xlsx,.csv" required><div class="hint">This server accepts uploads up to <?= e(ini_get('upload_max_filesize')) ?>.</div></div>
      <div class="form-grid">
        <div class="field"><label>List name</label><input name="source" placeholder="e.g. WC Business data"></div>
        <div class="field"><label>Import only the first … rows</label><input type="number" name="limit" min="0" value="0"><div class="hint">0 = all rows. Try 500 first on a new list.</div></div>
      </div>
      <label class="check"><input type="checkbox" name="business_only" value="1" checked> Only rows with a business / company name (recommended — InvoiceChat sells to businesses)</label>
      <div class="alert alert-info"><i class="fa-solid fa-user-shield"></i><span>Only names, business, job title, email, mobile, industry, town, province and website are read. <strong>ID numbers, bank details, salaries and credit data are never imported.</strong> Rows marked opted-out/unsubscribed are skipped. Duplicates (same email or mobile) are merged.</span></div>
      <button class="btn btn-primary"><i class="fa-solid fa-file-import"></i> Import</button>
    </form>
  </section>
  <section class="card">
    <div class="card__head"><h2>Big files</h2></div>
    <div class="card__body">
      <p class="hint" style="margin:0 0 .6rem">Upload the file to <code>/home/swiftfusionco/leads/</code> with File Manager (outside the website), then in cPanel → <strong>Terminal</strong>:</p>
      <div class="copy-box"><code>php <?= e(str_replace('\\', '/', BASE_DIR)) ?>/import-leads.php ~/leads/list12.xlsx "List 12" --business</code></div>
      <p class="hint">Recognised columns: EMAIL, FNAME/NAME, LNAME/SURNAME, BNAME/COMPANY/BUSINESS_NAME/TradeName, MOBILE/CELL, INDUSTRY, JOB_TITLE, TOWN, PROVINCE, WEBSITE (and the Org_Cnt_… columns in business registers).</p>
      <p class="hint">Best lists for InvoiceChat are the ones with a business column: <em>list12, list14, list19, list21, list22, list28, list29, sa1–sa11, sa100k/sa300k, WC Business data, Corporate Export</em>.</p>
    </div>
  </section>
</div>
<section class="card">
  <div class="card__head"><h2>Your lists</h2></div>
  <?php if (!$sources): ?><div class="empty"><i class="fa-solid fa-file-excel"></i>No lists imported yet.</div><?php else: ?>
  <table class="tbl"><thead><tr><th>List</th><th class="num">Leads</th><th class="num">Not contacted</th><th class="num">With mobile</th><th class="num">With email</th><th class="num">Signed up</th><th></th></tr></thead><tbody>
    <?php foreach ($sources as $s): ?>
      <tr><td class="cell-main"><strong><?= e($s['source']) ?></strong></td><td data-label="Leads" class="num"><?= number_format((int) $s['n']) ?></td><td data-label="Not contacted" class="num"><?= number_format((int) $s['fresh']) ?></td>
        <td data-label="With mobile" class="num"><?= number_format((int) $s['phones']) ?></td><td data-label="With email" class="num"><?= number_format((int) $s['emails']) ?></td><td data-label="Signed up" class="num"><?= (int) $s['won'] ?></td>
        <td><a class="btn-xs" href="?tab=all&source=<?= rawurlencode($s['source']) ?>">Open</a></td></tr>
    <?php endforeach; ?>
  </tbody></table>
  <?php endif; ?>
</section>

<?php else: $sample = ['first_name' => 'Thandi', 'business' => 'Mokoena Plumbing', 'industry' => 'Plumbing', 'town' => 'Soweto', 'source' => 'sample', 'id' => 0, 'token' => str_repeat('0', 32)]; ?>
<div class="grid-2">
  <section class="card">
    <div class="card__head"><div><h2>Call scripts</h2><p>Shown on every lead card with their name, business and town filled in.</p></div></div>
    <div class="card__body">
      <form method="POST" class="toolbar" style="padding:0 0 1rem;border:0"><?= csrfField() ?><input type="hidden" name="form" value="agent">
        <label style="font-weight:700;font-size:.85rem">Caller name in scripts</label><input class="input" name="call_agent_name" value="<?= e(callAgent()) ?>"><button class="btn-xs">Save</button></form>
      <?php foreach (callScripts() as $s): ?>
        <details class="script" open><summary><?= e($s['title']) ?></summary><dl><?php foreach ($s['steps'] as [$k, $v]): ?><dt><?= e($k) ?></dt><dd><?= preg_replace('/\*([^*]+)\*/', '<strong>$1</strong>', e(fillScript($v, $sample))) ?></dd><?php endforeach; ?></dl></details>
      <?php endforeach; ?>
      <details class="script" open><summary>Quick answers to objections</summary><dl><?php foreach (callAnswers() as [$q, $a]): ?><dt style="text-transform:none"><?= e($q) ?></dt><dd><?= e(fillScript($a, $sample)) ?></dd><?php endforeach; ?></dl></details>
    </div>
  </section>
  <section class="card">
    <div class="card__head"><h2>Email & WhatsApp templates</h2></div>
    <div class="card__body">
      <?php foreach (emailTemplates() as $t): ?>
        <details class="script"><summary><i class="fa-regular fa-envelope"></i> <?= e($t['name']) ?> — “<?= e(fillScript($t['subject'], $sample)) ?>”</summary><div style="margin-top:.6rem"><?= fillScript($t['body'], $sample, 'email') ?></div></details>
      <?php endforeach; ?>
      <?php foreach (whatsappTemplates() as $t): ?>
        <details class="script"><summary><i class="fa-brands fa-whatsapp"></i> <?= e($t['name']) ?></summary><p style="margin-top:.6rem"><?= e(fillScript($t['text'], $sample, 'whatsapp')) ?></p></details>
      <?php endforeach; ?>
      <h3 class="mini-h">Calling rules (POPIA & CPA)</h3>
      <ul class="steps-list">
        <li>Call business numbers Monday–Friday 08:00–20:00 and Saturday 09:00–13:00 only. No Sundays or public holidays.</li>
        <li>Say who you are and why you're calling in the first sentence.</li>
        <li>If anyone asks not to be contacted, choose <strong>Asked not to be contacted</strong> — they're removed from every list and campaign for good.</li>
        <li>Every email carries an unsubscribe link automatically.</li>
      </ul>
    </div>
  </section>
</div>
<?php endif; ?>
<?php adminClose(leadScripts());
