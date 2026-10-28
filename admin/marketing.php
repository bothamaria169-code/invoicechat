<?php
declare(strict_types=1);

require_once __DIR__ . '/_shell.php';
require_once BASE_DIR . '/includes/marketing.php';

$db  = getDB();
$tab = in_array($_GET['tab'] ?? '', ['compose', 'history', 'tracking', 'connect'], true) ? $_GET['tab'] : 'compose';
$connected = [
    'facebook'  => MetaPoster::connected('facebook'),
    'instagram' => MetaPoster::connected('instagram'),
    'tiktok'    => TikTok::connected(),
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form = (string) ($_POST['form'] ?? '');

    if ($form === 'compose') {
        $platforms = array_values(array_filter((array) ($_POST['platforms'] ?? []), fn($p) => isset(MARKETING_PLATFORMS[$p]) && $connected[$p]));
        $caption   = trim((string) ($_POST['caption'] ?? ''));
        $campaign  = preg_replace('/[^a-z0-9_-]/', '', strtolower((string) ($_POST['campaign'] ?? 'organic'))) ?: 'organic';
        $when      = (string) ($_POST['when'] ?? 'now');
        $at        = strtotime((string) ($_POST['scheduled_at'] ?? ''));
        $media = null; $kind = 'text';

        if (!$platforms) {
            flash('error', 'Pick at least one connected platform.');
            redirect('admin/marketing.php');
        }
        if ($caption === '') {
            flash('error', 'Write a caption first.');
            redirect('admin/marketing.php');
        }
        if (!empty($_FILES['media']['tmp_name']) && is_uploaded_file($_FILES['media']['tmp_name'])) {
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file($_FILES['media']['tmp_name']);
            $ext  = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'video/mp4' => 'mp4'][$mime] ?? null;
            if (!$ext) { flash('error', 'Upload a JPG, PNG or MP4.'); redirect('admin/marketing.php'); }
            if (!is_dir(MEDIA_DIR)) mkdir(MEDIA_DIR, 0755, true);
            $media = 'post_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            move_uploaded_file($_FILES['media']['tmp_name'], MEDIA_DIR . $media);
            $kind = $ext === 'mp4' ? 'video' : 'image';
        }
        $scheduled = $when === 'later' && $at && $at > time() ? date('Y-m-d H:i:s', $at) : date('Y-m-d H:i:s');

        $ins = $db->prepare('INSERT INTO marketing_posts (platform, kind, caption, link_url, media_path, status, scheduled_at, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $results = [];
        foreach ($platforms as $p) {
            $link = trackedLink($p, $campaign);
            $text = str_contains($caption, '{link}') ? str_replace('{link}', $p === 'instagram' ? 'the link in our bio' : $link, $caption) : $caption;
            $ins->execute([$p, $kind, $text, $link, $media, $when === 'later' ? 'scheduled' : 'posting', $scheduled, $ADMIN['id']]);
            $id = (int) $db->lastInsertId();
            if ($when !== 'later') {
                set_time_limit(240);
                $row = $db->query('SELECT * FROM marketing_posts WHERE id = ' . $id)->fetch();
                $r = publishMarketingPost($row);
                $results[] = MARKETING_PLATFORMS[$p] . ': ' . ($r['ok'] ? 'posted' : $r['error']);
            }
        }
        if ($when === 'later') {
            flash('success', 'Scheduled for ' . date('D j M, H:i', strtotime($scheduled)) . ' on ' . count($platforms) . ' platform(s). The cron job will post it.');
        } else {
            $anyFail = (bool) array_filter($results, fn($r) => !str_ends_with($r, 'posted'));
            flash($anyFail ? 'error' : 'success', implode(' · ', $results));
        }
        redirect('admin/marketing.php?tab=history');
    }

    if ($form === 'post_action') {
        $id = (int) ($_POST['id'] ?? 0);
        if (($_POST['do'] ?? '') === 'delete') {
            $db->prepare("DELETE FROM marketing_posts WHERE id = ? AND status <> 'posting'")->execute([$id]);
            flash('success', 'Removed.');
        } elseif (($_POST['do'] ?? '') === 'retry') {
            $db->prepare("UPDATE marketing_posts SET status = 'scheduled', scheduled_at = NOW(), error = NULL WHERE id = ? AND status = 'failed'")->execute([$id]);
            $row = $db->query('SELECT * FROM marketing_posts WHERE id = ' . $id)->fetch();
            if ($row) {
                $r = publishMarketingPost($row);
                flash($r['ok'] ? 'success' : 'error', $r['ok'] ? 'Posted.' : $r['error']);
            }
        }
        redirect('admin/marketing.php?tab=history');
    }

    if ($form === 'tracking') {
        set_setting('meta_pixel_id', preg_replace('/\D/', '', (string) $_POST['meta_pixel_id']));
        save_secret_setting('meta_capi_token', (string) $_POST['meta_capi_token']);
        set_setting('meta_test_event_code', trim((string) $_POST['meta_test_event_code']));
        set_setting('tiktok_pixel_id', preg_replace('/[^A-Z0-9]/i', '', (string) $_POST['tiktok_pixel_id']));
        save_secret_setting('tiktok_events_token', (string) $_POST['tiktok_events_token']);
        set_setting('tiktok_test_event_code', trim((string) $_POST['tiktok_test_event_code']));
        set_setting('ga4_id', preg_replace('/[^A-Z0-9-]/i', '', (string) $_POST['ga4_id']));
        flash('success', 'Tracking saved. Pixels now load on the website, sign-in and sign-up pages.');
        redirect('admin/marketing.php?tab=tracking');
    }
    if ($form === 'tracking_test') {
        trackServerEvent('CompleteRegistration', $ADMIN);
        $last = $db->query('SELECT channel, ok, detail FROM marketing_events ORDER BY id DESC LIMIT 2')->fetchAll();
        flash($last ? 'info' : 'error', $last
            ? implode(' · ', array_map(fn($e) => ucfirst($e['channel']) . ': ' . ($e['ok'] ? 'received ✓' : 'failed — ' . $e['detail']), $last)) . '. Check Events Manager → Test events.'
            : 'Nothing was sent — add a Pixel ID and access token first.');
        redirect('admin/marketing.php?tab=tracking');
    }

    if ($form === 'meta_keys') {
        set_setting('meta_app_id', preg_replace('/\D/', '', (string) $_POST['meta_app_id']));
        save_secret_setting('meta_app_secret', (string) $_POST['meta_app_secret']);
        flash('success', 'Meta app saved. Now press Connect with Facebook.');
        redirect('admin/marketing.php?tab=connect');
    }
    if ($form === 'tiktok_keys') {
        set_setting('tiktok_client_key', trim((string) $_POST['tiktok_client_key']));
        save_secret_setting('tiktok_client_secret', (string) $_POST['tiktok_client_secret']);
        flash('success', 'TikTok app saved. Now press Connect TikTok.');
        redirect('admin/marketing.php?tab=connect');
    }
    if ($form === 'disconnect') {
        ($_POST['which'] ?? '') === 'tiktok' ? TikTok::disconnect() : MetaPoster::disconnect();
        flash('success', 'Disconnected.');
        redirect('admin/marketing.php?tab=connect');
    }
}

$posted7  = (int) $db->query("SELECT COUNT(*) FROM marketing_posts WHERE status = 'posted' AND posted_at > NOW() - INTERVAL 7 DAY")->fetchColumn();
$queued   = (int) $db->query("SELECT COUNT(*) FROM marketing_posts WHERE status = 'scheduled'")->fetchColumn();
$tracked  = (int) $db->query("SELECT COUNT(*) FROM users WHERE signup_source IS NOT NULL AND signup_source <> '' AND created_at > NOW() - INTERVAL 30 DAY")->fetchColumn();
$events30 = (int) $db->query('SELECT COUNT(*) FROM marketing_events WHERE ok = 1 AND created_at > NOW() - INTERVAL 30 DAY')->fetchColumn();
$pixelsOn = (get_setting('meta_pixel_id') !== '') + (get_setting('tiktok_pixel_id') !== '') + (get_setting('ga4_id') !== '');
$cronLast = get_setting('cron_last_run');

adminOpen('marketing', 'Marketing', 'Post to Facebook, Instagram and TikTok, and measure which ads bring sign-ups.');
?>
<div class="kpis">
  <div class="kpi"><div class="kpi__ico"><i class="fa-solid fa-paper-plane"></i></div><div><div class="kpi__label">Posted (7 days)</div><div class="kpi__value"><?= $posted7 ?></div><div class="kpi__delta"><?= $queued ?> scheduled</div></div></div>
  <div class="kpi kpi--violet"><div class="kpi__ico"><i class="fa-solid fa-plug"></i></div><div><div class="kpi__label">Channels connected</div><div class="kpi__value"><?= array_sum($connected) ?>/3</div><div class="kpi__delta">Facebook · Instagram · TikTok</div></div></div>
  <div class="kpi kpi--mint"><div class="kpi__ico"><i class="fa-solid fa-crosshairs"></i></div><div><div class="kpi__label">Tracked sign-ups (30 d)</div><div class="kpi__value"><?= $tracked ?></div><div class="kpi__delta"><?= $events30 ?> server events delivered</div></div></div>
  <div class="kpi kpi--amber"><div class="kpi__ico"><i class="fa-solid fa-chart-simple"></i></div><div><div class="kpi__label">Pixels active</div><div class="kpi__value"><?= $pixelsOn ?>/3</div><div class="kpi__delta">Meta · TikTok · Google</div></div></div>
</div>

<nav class="tabs">
  <a href="?tab=compose" class="<?= $tab === 'compose' ? 'is-on' : '' ?>"><i class="fa-solid fa-pen-to-square"></i> New post</a>
  <a href="?tab=history" class="<?= $tab === 'history' ? 'is-on' : '' ?>"><i class="fa-solid fa-list"></i> Posts</a>
  <a href="?tab=tracking" class="<?= $tab === 'tracking' ? 'is-on' : '' ?>"><i class="fa-solid fa-crosshairs"></i> Pixels & ads tracking</a>
  <a href="?tab=connect" class="<?= $tab === 'connect' ? 'is-on' : '' ?>"><i class="fa-solid fa-plug"></i> Connections</a>
</nav>

<?php if ($tab === 'compose'): ?>
<div class="grid-2">
  <section class="card">
    <div class="card__head"><div><h2>Create a post</h2><p>One caption, sent to every platform you tick. Links get UTM tags per platform.</p></div></div>
    <div class="card__body">
      <?php if (!array_filter($connected)): ?>
        <div class="alert alert-info"><i class="fa-solid fa-plug"></i><span>No channels connected yet. <a href="?tab=connect">Connect Facebook, Instagram or TikTok</a> first.</span></div>
      <?php endif; ?>
      <form method="POST" enctype="multipart/form-data" data-busy><?= csrfField() ?><input type="hidden" name="form" value="compose">
        <div class="field"><label>Post to</label>
          <div class="chips">
            <?php foreach (MARKETING_PLATFORMS as $p => $label): ?>
              <label class="chip-check" title="<?= $connected[$p] ? '' : 'Not connected' ?>"><input type="checkbox" name="platforms[]" value="<?= $p ?>" <?= $connected[$p] ? 'checked' : 'disabled' ?>>
                <i class="fa-brands fa-<?= $p === 'facebook' ? 'facebook' : ($p === 'instagram' ? 'instagram' : 'tiktok') ?>"></i> <?= e($label) ?></label>
            <?php endforeach; ?>
          </div>
        </div>
        <div class="field"><label for="tpl">Start from a template</label>
          <select id="tpl"><option value="">— Write my own —</option><?php foreach (captionTemplates() as $name => $txt): ?><option value="<?= e($txt) ?>"><?= e($name) ?></option><?php endforeach; ?></select>
        </div>
        <div class="field"><label for="caption">Caption <span class="hint" id="capCount" style="margin:0">0 / 2200</span></label>
          <textarea id="caption" name="caption" rows="9" maxlength="2200" placeholder="Write your post… use {link} for the tracked sign-up link"></textarea>
          <div class="hint">{link} becomes <?= e(trackedLink('facebook')) ?> (with the right utm_source per platform; Instagram says “link in bio”).</div>
        </div>
        <div class="form-grid">
          <div class="field"><label>Photo or video</label><input type="file" name="media" accept="image/jpeg,image/png,video/mp4"><div class="hint">JPG/PNG for Facebook & Instagram (1080×1350 is ideal). MP4 for TikTok and Reels.</div></div>
          <div class="field"><label>Campaign tag</label><input name="campaign" value="organic" placeholder="e.g. launch-oct"></div>
        </div>
        <div class="field"><label>When</label>
          <div class="chips">
            <label class="chip-check"><input type="radio" name="when" value="now" checked> Post now</label>
            <label class="chip-check"><input type="radio" name="when" value="later"> Schedule</label>
            <input type="datetime-local" name="scheduled_at" class="input" style="width:auto" value="<?= date('Y-m-d\TH:i', strtotime('+1 hour')) ?>">
          </div>
          <div class="hint">Scheduled posts go out via the cron job (<?= $cronLast ? 'last ran ' . timeAgo($cronLast) : '<strong>not running yet</strong> — see Connections' ?>).</div>
        </div>
        <div class="form-foot"><button class="btn btn-primary"><i class="fa-solid fa-paper-plane"></i> Publish</button></div>
      </form>
    </div>
  </section>
  <section class="card">
    <div class="card__head"><h2>What works</h2></div>
    <div class="card__body">
      <ol class="steps-list">
        <li><strong>3–5 posts a week</strong>, best at 07:00–09:00, 12:00–13:00 and 17:00–20:00 (SA time).</li>
        <li>Show the product: a 10–20 s screen recording of making an invoice on a phone is the best TikTok/Reel.</li>
        <li>Rotate angles: time saved, getting paid faster, looking professional, WhatsApp delivery.</li>
        <li>Use a <strong>campaign tag</strong> per idea, then compare sign-ups by source on the Dashboard.</li>
        <li>TikTok posts arrive as <strong>drafts</strong> in the TikTok app inbox — add a trending sound and press Post.</li>
      </ol>
      <div class="hint">Sign-up links for ads (copy into Ads Manager):</div>
      <?php foreach (['facebook' => 'paid', 'tiktok' => 'paid'] as $src => $c): ?>
        <div class="copy-box"><code><?= e(APP_URL . '/register.php?utm_source=' . $src . '&utm_medium=' . $c . '&utm_campaign=launch') ?></code><button type="button" class="btn-xs" data-copy="<?= e(APP_URL . '/register.php?utm_source=' . $src . '&utm_medium=' . $c . '&utm_campaign=launch') ?>"><i class="fa-regular fa-copy"></i></button></div>
      <?php endforeach; ?>
    </div>
  </section>
</div>
<script>
  (function () {
    var cap = document.getElementById('caption'), cnt = document.getElementById('capCount');
    function count() { cnt.textContent = cap.value.length + ' / 2200'; }
    document.getElementById('tpl').addEventListener('change', function () { if (this.value) { cap.value = this.value; count(); } });
    cap.addEventListener('input', count);
  })();
</script>

<?php elseif ($tab === 'history'):
    $posts = $db->query('SELECT * FROM marketing_posts ORDER BY COALESCE(posted_at, scheduled_at, created_at) DESC LIMIT 100')->fetchAll(); ?>
<section class="card">
  <?php if (!$posts): ?><div class="empty"><i class="fa-solid fa-bullhorn"></i>No posts yet. <a href="?tab=compose">Create your first one</a>.</div><?php else: ?>
  <table class="tbl">
    <thead><tr><th>Post</th><th>Platform</th><th>Status</th><th>When</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($posts as $p): ?>
      <tr>
        <td class="cell-main" style="max-width:420px"><div style="display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden"><?= e($p['caption']) ?></div>
          <?php if ($p['media_path']): ?><span class="muted"><i class="fa-solid <?= $p['kind'] === 'video' ? 'fa-video' : 'fa-image' ?>"></i> <?= e($p['kind']) ?></span><?php endif; ?>
          <?php if ($p['error']): ?><div style="color:var(--coral);font-size:.78rem;margin-top:.2rem"><?= e($p['error']) ?></div><?php endif; ?></td>
        <td data-label="Platform"><?= e(MARKETING_PLATFORMS[$p['platform']] ?? $p['platform']) ?></td>
        <td data-label="Status"><span class="tag <?= ['posted' => 'tag-on', 'failed' => 'tag-off', 'scheduled' => 'tag-google'][$p['status']] ?? 'tag-user' ?>"><?= e($p['status']) ?></span></td>
        <td data-label="When" class="muted"><?= date('d M, H:i', strtotime($p['posted_at'] ?: $p['scheduled_at'] ?: $p['created_at'])) ?></td>
        <td><div class="row-actions">
          <?php if ($p['status'] === 'failed'): ?>
            <form method="POST"><?= csrfField() ?><input type="hidden" name="form" value="post_action"><input type="hidden" name="do" value="retry"><input type="hidden" name="id" value="<?= $p['id'] ?>"><button class="btn-xs"><i class="fa-solid fa-rotate"></i> Retry</button></form>
          <?php endif; ?>
          <?php if ($p['status'] !== 'posting'): ?>
            <form method="POST" data-confirm="Remove this post from the list? (Already-published posts stay on the platform.)"><?= csrfField() ?><input type="hidden" name="form" value="post_action"><input type="hidden" name="do" value="delete"><input type="hidden" name="id" value="<?= $p['id'] ?>"><button class="btn-xs danger" aria-label="Remove"><i class="fa-solid fa-trash-can"></i></button></form>
          <?php endif; ?>
        </div></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</section>

<?php elseif ($tab === 'tracking'):
    $events = $db->query('SELECT e.*, u.email FROM marketing_events e LEFT JOIN users u ON u.id = e.user_id ORDER BY e.id DESC LIMIT 12')->fetchAll(); ?>
<div class="grid-2">
  <section class="card">
    <div class="card__head"><div><h2>Pixels & Conversions API</h2><p>Every sign-up is reported from the browser and the server with one event id, so ad platforms count it once — even with ad blockers.</p></div></div>
    <div class="card__body">
      <form method="POST"><?= csrfField() ?><input type="hidden" name="form" value="tracking">
        <h3 style="font-size:.95rem;font-family:var(--font);margin-bottom:.7rem"><i class="fa-brands fa-meta" style="color:#0866ff"></i> Meta (Facebook & Instagram ads)</h3>
        <div class="form-grid">
          <div class="field"><label>Pixel / Dataset ID</label><input name="meta_pixel_id" value="<?= e(get_setting('meta_pixel_id')) ?>" placeholder="123456789012345"></div>
          <div class="field"><label>Test event code <span class="hint" style="margin:0">optional</span></label><input name="meta_test_event_code" value="<?= e(get_setting('meta_test_event_code')) ?>" placeholder="TEST12345"></div>
          <div class="field full"><label>Conversions API access token</label><input type="password" name="meta_capi_token" value="<?= e(masked(get_setting('meta_capi_token'))) ?>" autocomplete="new-password" placeholder="EAAG…"></div>
        </div>
        <h3 style="font-size:.95rem;font-family:var(--font);margin:.4rem 0 .7rem"><i class="fa-brands fa-tiktok"></i> TikTok ads</h3>
        <div class="form-grid">
          <div class="field"><label>Pixel code</label><input name="tiktok_pixel_id" value="<?= e(get_setting('tiktok_pixel_id')) ?>" placeholder="C4ABCDEF123456"></div>
          <div class="field"><label>Test event code <span class="hint" style="margin:0">optional</span></label><input name="tiktok_test_event_code" value="<?= e(get_setting('tiktok_test_event_code')) ?>" placeholder="TEST12345"></div>
          <div class="field full"><label>Events API access token</label><input type="password" name="tiktok_events_token" value="<?= e(masked(get_setting('tiktok_events_token'))) ?>" autocomplete="new-password"></div>
        </div>
        <h3 style="font-size:.95rem;font-family:var(--font);margin:.4rem 0 .7rem"><i class="fa-brands fa-google"></i> Google Analytics 4</h3>
        <div class="field"><label>Measurement ID</label><input name="ga4_id" value="<?= e(get_setting('ga4_id')) ?>" placeholder="G-XXXXXXXXXX"></div>
        <div class="form-foot"><button class="btn btn-primary">Save tracking</button></div>
      </form>
      <form method="POST" style="margin-top:.8rem"><?= csrfField() ?><input type="hidden" name="form" value="tracking_test"><button class="btn btn-ghost"><i class="fa-solid fa-vial"></i> Send a test sign-up event</button></form>
    </div>
  </section>
  <section class="card">
    <div class="card__head"><h2>Where to find these</h2></div>
    <div class="card__body">
      <ol class="steps-list">
        <li><strong>Meta:</strong> business.facebook.com → <strong>Events Manager</strong> → Connect data → Web → name it <em><?= APP_NAME ?></em> → copy the <strong>Dataset ID</strong>. Then <strong>Settings → Conversions API → Generate access token</strong>.</li>
        <li><strong>TikTok:</strong> ads.tiktok.com → <strong>Tools → Events → Web events</strong> → Set up → Manual → copy the <strong>Pixel code</strong>. In the pixel's <strong>Settings → Events API</strong>, generate the access token.</li>
        <li>Use the <strong>test event codes</strong> from each “Test events” tab while checking, then clear them.</li>
        <li>In both ad managers, optimise campaigns for <strong>CompleteRegistration</strong>.</li>
      </ol>
      <h3 style="font-size:.9rem;font-family:var(--font);margin:1rem 0 .5rem">Recent server events</h3>
      <?php if (!$events): ?><p class="hint">None yet — they appear after the next sign-up.</p><?php endif; ?>
      <?php foreach ($events as $ev): ?>
        <div class="muted" style="font-size:.8rem;padding:.2rem 0"><i class="fa-solid <?= $ev['ok'] ? 'fa-circle-check" style="color:var(--mint)' : 'fa-circle-xmark" style="color:var(--coral)' ?>"></i>
          <?= e(ucfirst($ev['channel'])) ?> · <?= e($ev['event']) ?> · <?= e($ev['email'] ?? '—') ?> · <?= timeAgo($ev['created_at']) ?><?= !$ev['ok'] && $ev['detail'] ? ' — ' . e($ev['detail']) : '' ?></div>
      <?php endforeach; ?>
    </div>
  </section>
</div>

<?php else: $mc = MetaPoster::config(); $tc = TikTok::config(); ?>
<div class="grid-2 even">
  <section class="card">
    <div class="card__head"><div><h2><i class="fa-brands fa-meta" style="color:#0866ff"></i> Facebook & Instagram</h2><p>Posts to your Page and its linked Instagram Business account.</p></div></div>
    <div class="card__body">
      <div class="status-line <?= $connected['facebook'] ? 'ok' : 'off' ?>"><span class="dot"></span><?= $connected['facebook'] ? 'Page: ' . e($mc['page_name']) . ($mc['instagram_name'] ? ' · Instagram: @' . e($mc['instagram_name']) : ' · no Instagram linked') : 'Not connected' ?></div>
      <form method="POST"><?= csrfField() ?><input type="hidden" name="form" value="meta_keys">
        <div class="form-grid">
          <div class="field"><label>App ID</label><input name="meta_app_id" value="<?= e($mc['app_id']) ?>" placeholder="Digits only"></div>
          <div class="field"><label>App secret</label><input type="password" name="meta_app_secret" value="<?= e(masked($mc['app_secret'])) ?>" autocomplete="new-password"></div>
        </div>
        <div class="form-foot">
          <button class="btn btn-ghost">Save keys</button>
          <?php if (MetaPoster::ready()): ?><a class="btn btn-primary" href="meta-connect.php?start=1"><i class="fa-brands fa-facebook"></i> <?= $connected['facebook'] ? 'Reconnect' : 'Connect with Facebook' ?></a><?php endif; ?>
        </div>
      </form>
      <?php if ($connected['facebook']): ?><form method="POST" style="margin-top:.6rem" data-confirm="Disconnect Facebook & Instagram?"><?= csrfField() ?><input type="hidden" name="form" value="disconnect"><input type="hidden" name="which" value="meta"><button class="btn-xs danger">Disconnect</button></form><?php endif; ?>
      <ol class="steps-list" style="margin-top:1.2rem">
        <li>developers.facebook.com/apps → <strong>Create app</strong> → use case <em>Manage everything on your Page</em>.</li>
        <li>App settings → Basic: App domain <code><?= e(parse_url(APP_URL, PHP_URL_HOST)) ?></code>, privacy URL <code><?= e(APP_URL) ?>/privacy.php</code>. Copy App ID & secret here.</li>
        <li>Facebook Login for Business → Settings → <strong>Valid OAuth Redirect URIs</strong>:</li>
      </ol>
      <div class="copy-box"><code><?= e(MetaPoster::redirectUri()) ?></code><button type="button" class="btn-xs" data-copy="<?= e(MetaPoster::redirectUri()) ?>"><i class="fa-regular fa-copy"></i></button></div>
      <p class="hint">Permissions: <?= e(implode(', ', MetaPoster::SCOPES)) ?>. Switch the app to <strong>Live</strong> so the public sees posts.</p>
    </div>
  </section>
  <section class="card">
    <div class="card__head"><div><h2><i class="fa-brands fa-tiktok"></i> TikTok</h2><p>Videos arrive in the TikTok app inbox as drafts.</p></div></div>
    <div class="card__body">
      <div class="status-line <?= $connected['tiktok'] ? 'ok' : 'off' ?>"><span class="dot"></span><?= $connected['tiktok'] ? 'Connected' . ($tc['name'] ? ' · ' . e($tc['name']) : '') : 'Not connected' ?></div>
      <form method="POST"><?= csrfField() ?><input type="hidden" name="form" value="tiktok_keys">
        <div class="form-grid">
          <div class="field"><label>Client key</label><input name="tiktok_client_key" value="<?= e($tc['client_key']) ?>"></div>
          <div class="field"><label>Client secret</label><input type="password" name="tiktok_client_secret" value="<?= e(masked($tc['client_secret'])) ?>" autocomplete="new-password"></div>
        </div>
        <div class="form-foot">
          <button class="btn btn-ghost">Save keys</button>
          <?php if (TikTok::ready()): ?><a class="btn btn-dark" href="tiktok-connect.php?start=1"><i class="fa-brands fa-tiktok"></i> <?= $connected['tiktok'] ? 'Reconnect' : 'Connect TikTok' ?></a><?php endif; ?>
        </div>
      </form>
      <?php if ($connected['tiktok']): ?><form method="POST" style="margin-top:.6rem" data-confirm="Disconnect TikTok?"><?= csrfField() ?><input type="hidden" name="form" value="disconnect"><input type="hidden" name="which" value="tiktok"><button class="btn-xs danger">Disconnect</button></form><?php endif; ?>
      <ol class="steps-list" style="margin-top:1.2rem">
        <li>developers.tiktok.com → Manage apps → <strong>Connect an app</strong> → switch to <strong>Sandbox</strong>.</li>
        <li>Add products <strong>Login Kit</strong> + <strong>Content Posting API</strong>; scopes <code>user.info.basic</code>, <code>video.upload</code>.</li>
        <li>Sandbox → Target users → add the InvoiceChat TikTok account.</li>
        <li>Login Kit → <strong>Redirect URI</strong>:</li>
      </ol>
      <div class="copy-box"><code><?= e(TikTok::redirectUri()) ?></code><button type="button" class="btn-xs" data-copy="<?= e(TikTok::redirectUri()) ?>"><i class="fa-regular fa-copy"></i></button></div>
    </div>
  </section>
</div>
<section class="card">
  <div class="card__head"><div><h2><i class="fa-regular fa-clock"></i> Cron job (scheduled posts)</h2><p><?= $cronLast ? 'Last ran ' . timeAgo($cronLast) : 'Not running yet' ?></p></div></div>
  <div class="card__body">
    <p class="hint" style="margin:0 0 .5rem">cPanel → Cron Jobs → <strong>Once Per Five Minutes</strong>, command:</p>
    <div class="copy-box"><code>/usr/local/bin/php <?= e(str_replace('\\', '/', BASE_DIR)) ?>/cron.php &gt;/dev/null 2&gt;&amp;1</code></div>
    <p class="hint">On the server the path is usually <code>/home/swiftfusionco/public_html/invoicechat/cron.php</code>.</p>
  </div>
</section>
<?php endif; ?>

<?php adminClose('<script>document.querySelectorAll("form[data-busy]").forEach(function(f){f.addEventListener("submit",function(){var b=f.querySelector("button.btn-primary");if(b){b.disabled=true;b.classList.add("is-busy");}});});</script>');
