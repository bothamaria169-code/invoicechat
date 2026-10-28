<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/ai.php';
require_once __DIR__ . '/includes/logos.php';
require_once __DIR__ . '/includes/layout.php';

startAppSession();
requireLogin();
$user = getCurrentUser();
if (!$user) redirect('login.php');

$name   = $user['company_name'] ?: ($user['full_name'] ?: 'My Business');
$accent = accentColor($user);
$ai     = aiEnabled();

pageHead('Logo maker – ' . APP_NAME, ['css' => ['style.css', 'admin.css']]);
?>
<body>
<header class="site-nav"><div class="wrap"><?= brandMark() ?><nav><a href="chat.php" class="btn btn-ghost"><i class="fa-solid fa-arrow-left"></i> Back to chat</a></nav></div></header>
<main class="wrap" style="padding:2rem 0 4rem;max-width:1080px">
  <div class="adm-head"><div><h1>Logo maker</h1><p>Make a clean logo from your initials in seconds. It's added to every invoice and quote.</p></div>
    <?php if (!empty($user['logo_path']) && is_file($user['logo_path'])): ?><div class="adm-head__actions"><span class="tag tag-on"><i class="fa-solid fa-check"></i> You already have a logo — saving a new one replaces it</span></div><?php endif; ?></div>

  <section class="card">
    <div class="card__head"><div><h2>Letter logo</h2><p>Free on every plan.</p></div></div>
    <div class="card__body">
      <div class="form-grid">
        <div class="field"><label>Business name</label><input id="lgName" value="<?= e($name) ?>" maxlength="60"></div>
        <div class="field"><label>Letters (1–3)</label><input id="lgLetters" value="<?= e(initialsFor($name)) ?>" maxlength="3" style="text-transform:uppercase"></div>
      </div>
      <div class="field"><label>Colour</label>
        <div class="chips" id="lgColors">
          <?php foreach (array_unique(array_merge([$accent], LOGO_COLORS)) as $i => $c): ?>
            <button type="button" class="swatch<?= $i === 0 ? ' is-on' : '' ?>" data-color="<?= e($c) ?>" style="background:<?= e($c) ?>" aria-label="<?= e($c) ?>"></button>
          <?php endforeach; ?>
          <input type="color" id="lgCustom" value="<?= e($accent) ?>" title="Custom colour" style="width:42px;height:42px;border:0;background:none;padding:0">
        </div>
      </div>
      <div class="logo-grid" id="lgGrid">
        <?php foreach (LOGO_STYLES as $i => $s): ?>
          <button type="button" class="logo-pick<?= $i === 0 ? ' is-on' : '' ?>" data-style="<?= $s ?>"><img alt="<?= e(ucfirst($s)) ?> style"><span><?= e(ucfirst($s)) ?></span></button>
        <?php endforeach; ?>
      </div>
      <div class="form-foot" style="margin-top:1rem"><button class="btn btn-primary" id="lgSave"><i class="fa-solid fa-check"></i> Use this logo</button><span class="hint" id="lgMsg" style="margin:0"></span></div>
    </div>
  </section>

  <section class="card">
    <div class="card__head"><div><h2>✨ AI symbol logo</h2><p><?= $ai ? 'Describe your business and get symbol ideas, then generate an image. Uses AI requests (' . aiLeft($user) . ' left this month).' : 'Switches on once the admin adds the AI key.' ?></p></div></div>
    <div class="card__body">
      <?php if ($ai): ?>
        <div class="form-grid">
          <div class="field"><label>What does your business do?</label><input id="aiIndustry" placeholder="e.g. plumbing, catering, hair salon, IT support"></div>
          <div class="field" style="display:flex;align-items:flex-end"><button class="btn btn-ghost btn-block" id="aiIdeas"><i class="fa-solid fa-lightbulb"></i> Suggest ideas</button></div>
        </div>
        <div class="logo-grid" id="aiOut"></div>
      <?php else: ?>
        <p class="hint">Letter logos above work right away.</p>
      <?php endif; ?>
    </div>
  </section>
</main>
<style>
  .swatch { width: 42px; height: 42px; border-radius: 12px; border: 3px solid var(--surface); box-shadow: 0 0 0 1px var(--line); cursor: pointer; }
  .swatch.is-on { box-shadow: 0 0 0 3px var(--text); }
  .logo-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: .8rem; margin-top: .6rem; }
  .logo-pick { border: 2px solid var(--line); border-radius: var(--r-lg); background: repeating-conic-gradient(var(--surface-2) 0 25%, var(--surface) 0 50%) 0 0 / 20px 20px; padding: .8rem; cursor: pointer; display: flex; flex-direction: column; align-items: center; gap: .5rem; font-weight: 700; font-size: .82rem; color: var(--text-2); }
  .logo-pick img { width: 100%; aspect-ratio: 1; object-fit: contain; }
  .logo-pick.is-on { border-color: var(--brand); box-shadow: 0 0 0 4px var(--brand-50); color: var(--brand); }
  .ai-card { border: 1px solid var(--line); border-radius: var(--r-lg); padding: .9rem; display: flex; flex-direction: column; gap: .5rem; font-size: .85rem; }
  .ai-card img { width: 100%; aspect-ratio: 1; object-fit: contain; border-radius: 10px; background: #fff; }
</style>
<script>
(function () {
  var csrf = <?= json_encode(csrfToken()) ?>, color = <?= json_encode($accent) ?>, style = 'circle', t;
  var nameEl = document.getElementById('lgName'), letEl = document.getElementById('lgLetters'), msg = document.getElementById('lgMsg');
  function q() { return 'name=' + encodeURIComponent(nameEl.value) + '&letters=' + encodeURIComponent(letEl.value) + '&color=' + encodeURIComponent(color.replace('#', '')); }
  function refresh() {
    document.querySelectorAll('.logo-pick[data-style]').forEach(function (b) { b.querySelector('img').src = 'ajax/logo.php?action=preview&style=' + b.dataset.style + '&' + q(); });
  }
  function post(data) {
    var fd = new FormData(); Object.keys(data).forEach(function (k) { fd.append(k, data[k]); }); fd.append('_csrf', csrf);
    return fetch('ajax/logo.php', { method: 'POST', body: fd }).then(function (r) { return r.json(); });
  }
  [nameEl, letEl].forEach(function (el) { el.addEventListener('input', function () { clearTimeout(t); t = setTimeout(refresh, 350); }); });
  document.querySelectorAll('.swatch').forEach(function (s) { s.onclick = function () {
    document.querySelectorAll('.swatch').forEach(function (x) { x.classList.toggle('is-on', x === s); }); color = s.dataset.color; refresh(); }; });
  document.getElementById('lgCustom').addEventListener('input', function () { color = this.value; document.querySelectorAll('.swatch').forEach(function (x) { x.classList.remove('is-on'); }); clearTimeout(t); t = setTimeout(refresh, 250); });
  document.querySelectorAll('.logo-pick[data-style]').forEach(function (b) { b.onclick = function () {
    document.querySelectorAll('.logo-pick[data-style]').forEach(function (x) { x.classList.toggle('is-on', x === b); }); style = b.dataset.style; }; });
  document.getElementById('lgSave').onclick = function () {
    var btn = this; btn.disabled = true; msg.textContent = 'Saving…';
    post({ action: 'save', style: style, color: color, name: nameEl.value, letters: letEl.value }).then(function (r) {
      btn.disabled = false; msg.textContent = r.ok ? '✓ Saved — your next PDF uses this logo and colour.' : (r.error || 'Could not save.');
    });
  };
  refresh();

  var ideasBtn = document.getElementById('aiIdeas'), out = document.getElementById('aiOut');
  if (!ideasBtn) return;
  function esc(s) { var d = document.createElement('div'); d.textContent = s || ''; return d.innerHTML; }
  ideasBtn.onclick = function () {
    ideasBtn.disabled = true; out.innerHTML = '<p class="hint">Thinking…</p>';
    post({ action: 'ideas', name: nameEl.value, industry: document.getElementById('aiIndustry').value }).then(function (r) {
      ideasBtn.disabled = false;
      if (r.error) { out.innerHTML = '<p class="hint">' + esc(r.error) + '</p>'; return; }
      out.innerHTML = '';
      r.ideas.forEach(function (idea) {
        var card = document.createElement('div'); card.className = 'ai-card';
        card.innerHTML = '<strong>' + esc(idea.icon) + '</strong><span class="hint" style="margin:0">' + esc(idea.tagline) + '</span><div class="img"></div><button class="btn btn-ghost">Generate image</button>';
        var gen = card.querySelector('button'), box = card.querySelector('.img');
        gen.onclick = function () {
          gen.disabled = true; gen.textContent = 'Drawing… (up to 30 s)';
          post({ action: 'image', prompt: idea.prompt + ', colours ' + (idea.colors || []).join(' and ') }).then(function (img) {
            gen.disabled = false; gen.textContent = 'Try another';
            if (img.error) { box.innerHTML = '<p class="hint">' + esc(img.error) + '</p>'; return; }
            box.innerHTML = '<img src="' + img.src + '" alt="AI logo"><button class="btn btn-primary btn-block" style="margin-top:.5rem">Use this logo</button>';
            box.querySelector('button').onclick = function () {
              post({ action: 'save_ai', token: img.token }).then(function (s) { box.querySelector('button').textContent = s.ok ? '✓ Saved' : (s.error || 'Failed'); });
            };
          });
        };
        out.appendChild(card);
      });
    });
  };
})();
</script>
</body>
</html>
