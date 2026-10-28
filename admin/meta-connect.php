<?php
declare(strict_types=1);

// Facebook login for the Page poster: start (?start), callback (?code), choose a Page (POST page_id).
require_once __DIR__ . '/_shell.php';
require_once BASE_DIR . '/includes/marketing.php';

if (isset($_GET['start'])) {
    if (!MetaPoster::ready()) { flash('error', 'Save the Meta App ID and secret first.'); redirect('admin/marketing.php?tab=connect'); }
    $_SESSION['meta_state'] = bin2hex(random_bytes(16));
    header('Location: ' . MetaPoster::loginUrl($_SESSION['meta_state']));
    exit;
}

if (isset($_GET['error'])) {
    flash('error', 'Facebook connection cancelled: ' . ($_GET['error_description'] ?? $_GET['error']));
    redirect('admin/marketing.php?tab=connect');
}

if (isset($_GET['code'])) {
    if (!hash_equals((string) ($_SESSION['meta_state'] ?? ''), (string) ($_GET['state'] ?? ''))) {
        flash('error', 'That Facebook link expired. Press Connect again.');
        redirect('admin/marketing.php?tab=connect');
    }
    unset($_SESSION['meta_state']);
    $r = MetaPoster::exchangeCode((string) $_GET['code']);
    if ($r['error'] !== '') { flash('error', $r['error']); redirect('admin/marketing.php?tab=connect'); }
    if (count($r['pages']) === 1) {
        MetaPoster::savePage(array_values($r['pages'])[0]);
        flash('success', 'Connected to ' . MetaPoster::config()['page_name'] . '.');
        redirect('admin/marketing.php?tab=connect');
    }
    $_SESSION['meta_pages'] = $r['pages'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['page_id'], $_SESSION['meta_pages'][$_POST['page_id']])) {
    MetaPoster::savePage($_SESSION['meta_pages'][$_POST['page_id']]);
    unset($_SESSION['meta_pages']);
    flash('success', 'Connected to ' . MetaPoster::config()['page_name'] . '.');
    redirect('admin/marketing.php?tab=connect');
}

$pages = $_SESSION['meta_pages'] ?? [];
if (!$pages) redirect('admin/marketing.php?tab=connect');

adminOpen('marketing', 'Choose your Facebook Page', 'You manage more than one Page. Which one should ' . APP_NAME . ' post to?');
?>
<section class="card" style="max-width:640px">
  <?php foreach ($pages as $p): ?>
    <form method="POST" class="list-row"><?= csrfField() ?><input type="hidden" name="page_id" value="<?= e($p['id']) ?>">
      <div class="avatar"><i class="fa-brands fa-facebook-f"></i></div>
      <div class="list-row__main"><strong><?= e($p['name']) ?></strong><small><?= $p['ig_name'] ? 'Instagram: @' . e($p['ig_name']) : 'No Instagram linked' ?></small></div>
      <button class="btn btn-primary" style="padding:.5rem .9rem">Use this Page</button>
    </form>
  <?php endforeach; ?>
</section>
<?php adminClose();
