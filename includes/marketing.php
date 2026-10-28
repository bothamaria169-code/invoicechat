<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once BASE_DIR . '/classes/MetaPoster.php';
require_once BASE_DIR . '/classes/TikTok.php';
require_once BASE_DIR . '/classes/YouTube.php';

const MARKETING_PLATFORMS = ['facebook' => 'Facebook Page', 'instagram' => 'Instagram', 'tiktok' => 'TikTok (draft)', 'youtube' => 'YouTube Shorts'];

/** Ready-made captions; {link} becomes the tracked sign-up link for the platform. */
function captionTemplates(): array
{
    return [
        'Launch'      => "Still making invoices in Word? 😅\n\nWith InvoiceChat you just chat: who's it for, what did you sell, how much. You get a branded PDF invoice in under 2 minutes and send it by WhatsApp or email.\n\n✅ Free to start\n✅ VAT, discounts & bank details\n✅ Works on your phone\n\nTry it 👉 {link}\n\n#SmallBusinessSA #Freelancer #Invoicing #SouthAfrica",
        'Quotes'      => "Send a quote today, turn it into an invoice tomorrow — with one tap. 📄➡️💰\n\nInvoiceChat keeps your quotes and invoices in one chat, so nothing slips through the cracks.\n\nStart free 👉 {link}\n\n#SideHustleSA #Entrepreneur #SmallBusiness",
        'Get paid'    => "Cash flow starts with a clear invoice. 💸\n\nProfessional templates, your logo, your bank details — sent straight to your client's WhatsApp.\n\nCreate yours free 👉 {link}\n\n#GetPaid #SMEsouthafrica #BusinessTips",
        'Tip'         => "💡 Invoice tip: always put a due date and your bank reference on every invoice. Clients pay faster when they know exactly how.\n\nInvoiceChat adds both automatically 👉 {link}\n\n#BusinessTips #Freelance #SouthAfrica",
    ];
}

function trackedLink(string $platform, string $campaign = 'organic'): string
{
    return APP_URL . '/?utm_source=' . rawurlencode($platform) . '&utm_medium=social&utm_campaign=' . rawurlencode($campaign);
}

/** The public URL of an uploaded marketing file. */
function mediaUrl(?string $file): ?string
{
    return $file ? APP_URL . '/uploads/marketing/' . rawurlencode(basename($file)) : null;
}

/** Publishes one queued marketing_posts row and records the outcome on it. */
function publishMarketingPost(array $post): array
{
    $file = $post['media_path'] ? MEDIA_DIR . basename($post['media_path']) : null;
    $kind = (string) $post['kind'];

    if ($post['platform'] === 'youtube') {
        $r = $kind === 'video' && $file
            ? YouTube::upload($file, (string) $post['caption'])
            : ['ok' => false, 'id' => '', 'error' => 'YouTube needs a video (MP4).'];
    } elseif ($post['platform'] === 'tiktok') {
        $r = $kind === 'video' && $file
            ? TikTok::uploadDraft($file)
            : ['ok' => false, 'id' => '', 'error' => 'TikTok needs a video (MP4).'];
    } else {
        if (IS_LOCAL && $file) {
            $r = ['ok' => false, 'id' => '', 'error' => 'Meta must download the media from a public https address — post from the live site.'];
        } else {
            $r = MetaPoster::publish((string) $post['platform'], (string) $post['caption'], mediaUrl($post['media_path']), $kind);
        }
    }

    getDB()->prepare('UPDATE marketing_posts SET status = ?, posted_at = ?, remote_id = ?, error = ? WHERE id = ?')
        ->execute([$r['ok'] ? 'posted' : 'failed', $r['ok'] ? date('Y-m-d H:i:s') : null, $r['id'] ?: null, $r['ok'] ? null : mb_substr($r['error'], 0, 500), $post['id']]);
    return $r;
}

/** Called by cron.php: publishes every scheduled post that is due. */
function runDuePosts(int $limit = 5): array
{
    $rows = getDB()->query("SELECT * FROM marketing_posts WHERE status = 'scheduled' AND scheduled_at <= NOW() ORDER BY scheduled_at LIMIT " . $limit)->fetchAll();
    $out = [];
    foreach ($rows as $row) {
        // Claim the row first so an overlapping cron run can't post it twice.
        $claim = getDB()->prepare("UPDATE marketing_posts SET status = 'posting' WHERE id = ? AND status = 'scheduled'");
        $claim->execute([$row['id']]);
        if ($claim->rowCount() === 1) {
            $r = publishMarketingPost($row);
            $out[] = "#{$row['id']} {$row['platform']}: " . ($r['ok'] ? 'posted' : 'failed — ' . $r['error']);
        }
    }
    return $out;
}
