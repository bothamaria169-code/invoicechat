<?php
declare(strict_types=1);

// Logo maker: letter-mark previews and saving (GD), plus AI symbol ideas (Groq)
// and images (Pollinations, free, no key).
require_once dirname(__DIR__) . '/includes/ai.php';
require_once dirname(__DIR__) . '/includes/logos.php';
require_once dirname(__DIR__) . '/vendor/autoload.php';

startAppSession();
if (!isLoggedIn()) jsonResponse(['error' => 'Unauthenticated'], 401);
$userId = (int) $_SESSION['user_id'];
$action = (string) ($_GET['action'] ?? $_POST['action'] ?? '');

$style   = in_array($_REQUEST['style'] ?? '', LOGO_STYLES, true) ? $_REQUEST['style'] : 'circle';
$color   = '#' . hexClean((string) ($_REQUEST['color'] ?? '3b5bfd'));
$name    = mb_substr(trim((string) ($_REQUEST['name'] ?? '')), 0, 60);
$letters = mb_strtoupper(mb_substr(preg_replace('/[^\p{L}\p{N}&]/u', '', (string) ($_REQUEST['letters'] ?? '')), 0, 3)) ?: initialsFor($name ?: 'IC');

if ($action === 'preview') {
    session_write_close(); // previews load six at a time; don't hold the session lock
    header('Content-Type: image/png');
    header('Cache-Control: private, max-age=600');
    echo renderLogo($letters, $name, $style, $color);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrfValid()) jsonResponse(['error' => 'Session expired — refresh the page.'], 419);

if ($action === 'save') {
    saveUserLogo($userId, renderLogo($letters, $name, $style, $color));
    // The invoice accent colour follows the logo, so documents match.
    (new \InvoiceChat\ProfileManager(getDB()))->updateSettings($userId, ['accent_color' => $color]);
    jsonResponse(['ok' => true]);
}

$user = freshUser($userId);

if ($action === 'ideas') {
    if (!aiEnabled()) jsonResponse(['error' => 'AI isn\'t switched on yet.'], 400);
    if (aiLeft($user) <= 0) jsonResponse(['error' => 'You\'ve used this month\'s AI requests. Go Pro for more.'], 402);
    $ideas = aiLogoIdeas($user, $name ?: ($user['company_name'] ?: 'My business'), mb_substr(trim((string) ($_POST['industry'] ?? '')), 0, 80));
    $ideas ? jsonResponse(['ideas' => $ideas]) : jsonResponse(['error' => 'The AI is busy — try again in a minute.'], 503);
}

if ($action === 'image') {
    if (aiLeft($user) <= 0) jsonResponse(['error' => 'You\'ve used this month\'s AI requests. Go Pro for more.'], 402);
    $prompt = mb_substr(trim((string) ($_POST['prompt'] ?? '')), 0, 400) ?: 'minimal flat vector logo mark, simple geometric symbol';
    $url = 'https://image.pollinations.ai/prompt/' . rawurlencode($prompt . ', flat vector logo, centered, plain white background, no text')
         . '?width=512&height=512&nologo=true&seed=' . random_int(1, 999999);
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 60]);
    $img  = (string) curl_exec($ch);
    $type = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);
    logAi($userId, 'logo_image', $img !== '' && str_starts_with($type, 'image/'));
    if ($img === '' || !str_starts_with($type, 'image/')) jsonResponse(['error' => 'The image service is busy — try again.'], 503);
    $token = bin2hex(random_bytes(8));
    $_SESSION['ai_logos'][$token] = base64_encode($img);
    $_SESSION['ai_logos'] = array_slice($_SESSION['ai_logos'], -6, null, true); // keep the session small
    jsonResponse(['token' => $token, 'src' => 'data:' . $type . ';base64,' . base64_encode($img)]);
}

if ($action === 'save_ai') {
    $data = $_SESSION['ai_logos'][(string) ($_POST['token'] ?? '')] ?? null;
    if (!$data) jsonResponse(['error' => 'That image expired — generate it again.'], 400);
    $bin = base64_decode($data);
    $ext = str_starts_with($bin, "\x89PNG") ? 'png' : 'jpg';
    saveUserLogo($userId, $bin, $ext);
    jsonResponse(['ok' => true]);
}

jsonResponse(['error' => 'Unknown action'], 400);
