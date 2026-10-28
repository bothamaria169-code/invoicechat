<?php
declare(strict_types=1);

/**
 * Ad tracking for Meta (Facebook/Instagram) and TikTok, plus Google Analytics.
 *
 *  - Browser pixels on every public page (trackingHead()).
 *  - CompleteRegistration sent twice with one event id: from the browser on
 *    the first page after sign-up, and from the server (Meta Conversions API,
 *    TikTok Events API). The platforms de-duplicate on the id, and the server
 *    copy still counts when an ad blocker stops the pixel.
 *  - First-touch attribution (UTM tags, fbclid, ttclid, referrer) is kept in
 *    the session and saved on the account as signup_source.
 *
 * IDs and tokens are entered in Admin → Marketing → Tracking.
 */

function captureAttribution(): void
{
    startAppSession();
    $keys = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'fbclid', 'ttclid', 'gclid'];
    $hit  = array_filter(array_intersect_key($_GET, array_flip($keys)), 'is_string');
    if ($hit && empty($_SESSION['attribution'])) {
        $_SESSION['attribution'] = array_map(fn($v) => substr($v, 0, 120), $hit);
    }
    $ref = (string) ($_SERVER['HTTP_REFERER'] ?? '');
    $refHost = parse_url($ref, PHP_URL_HOST) ?: '';
    $ownHost = parse_url(APP_URL, PHP_URL_HOST) ?: '';
    if ($refHost !== '' && $refHost !== $ownHost && empty($_SESSION['referrer'])) {
        $_SESSION['referrer'] = substr($refHost, 0, 120);
    }
    // Meta's click id cookie, so server events match the ad click.
    if (!empty($_GET['fbclid']) && empty($_COOKIE['_fbc'])) {
        $fbc = 'fb.1.' . (int) (microtime(true) * 1000) . '.' . substr((string) $_GET['fbclid'], 0, 200);
        setcookie('_fbc', $fbc, ['expires' => time() + 90 * 86400, 'path' => '/', 'samesite' => 'Lax']);
        $_COOKIE['_fbc'] = $fbc;
    }
    if (!empty($_GET['ttclid'])) {
        $_SESSION['ttclid'] = substr((string) $_GET['ttclid'], 0, 200);
    }
}

/** "facebook / cpc / launch" style label for the admin, or the referring site. */
function signupSource(): string
{
    startAppSession();
    $a = $_SESSION['attribution'] ?? [];
    if (!empty($a['utm_source'])) {
        return substr(implode(' / ', array_filter([$a['utm_source'], $a['utm_medium'] ?? '', $a['utm_campaign'] ?? ''])), 0, 500);
    }
    if (!empty($a['fbclid'])) return 'facebook ad';
    if (!empty($a['ttclid'])) return 'tiktok ad';
    if (!empty($a['gclid']))  return 'google ad';
    return (string) ($_SESSION['referrer'] ?? '');
}

/** Pixel + analytics tags for <head>. Fires a pending sign-up event once. */
function trackingHead(): string
{
    $meta = preg_replace('/\D/', '', get_setting('meta_pixel_id'));
    $tt   = preg_replace('/[^A-Z0-9]/i', '', get_setting('tiktok_pixel_id'));
    $ga   = preg_replace('/[^A-Z0-9-]/i', '', get_setting('ga4_id'));
    $signupId = $_SESSION['track_signup'] ?? null;
    unset($_SESSION['track_signup']);
    $eid = $signupId ? json_encode((string) $signupId) : null;
    $out = '';

    if ($meta) {
        $out .= "<script>!function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,document,'script','https://connect.facebook.net/en_US/fbevents.js');"
              . "fbq('init','{$meta}');fbq('track','PageView');"
              . ($eid ? "fbq('track','CompleteRegistration',{},{eventID:{$eid}});" : '')
              . "</script>\n";
    }
    if ($tt) {
        $out .= "<script>!function(w,d,t){w.TiktokAnalyticsObject=t;var ttq=w[t]=w[t]||[];ttq.methods=['page','track','identify','instances','debug','on','off','once','ready','alias','group','enableCookie','disableCookie'],ttq.setAndDefer=function(t,e){t[e]=function(){t.push([e].concat(Array.prototype.slice.call(arguments,0)))}};for(var i=0;i<ttq.methods.length;i++)ttq.setAndDefer(ttq,ttq.methods[i]);ttq.instance=function(t){for(var e=ttq._i[t]||[],n=0;n<ttq.methods.length;n++)ttq.setAndDefer(e,ttq.methods[n]);return e},ttq.load=function(e,n){var i='https://analytics.tiktok.com/i18n/pixel/events.js';ttq._i=ttq._i||{},ttq._i[e]=[],ttq._i[e]._u=i,ttq._t=ttq._t||{},ttq._t[e]=+new Date,ttq._o=ttq._o||{},ttq._o[e]=n||{};var o=document.createElement('script');o.type='text/javascript',o.async=!0,o.src=i+'?sdkid='+e+'&lib='+t;var a=document.getElementsByTagName('script')[0];a.parentNode.insertBefore(o,a)};"
              . "ttq.load('{$tt}');ttq.page();"
              . ($eid ? "ttq.track('CompleteRegistration',{},{event_id:{$eid}});" : '')
              . "}(window,document,'ttq');</script>\n";
    }
    if ($ga) {
        $out .= "<script async src=\"https://www.googletagmanager.com/gtag/js?id={$ga}\"></script>"
              . "<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments)}gtag('js',new Date());gtag('config','{$ga}');"
              . ($eid ? "gtag('event','sign_up');" : '')
              . "</script>\n";
    }
    return $out;
}

/**
 * Server-side copy of a conversion. Returns the event id the browser pixel
 * must reuse so the two copies count once.
 */
function trackServerEvent(string $event, array $user): string
{
    $eventId = $event . '-' . ($user['id'] ?? 0) . '-' . bin2hex(random_bytes(4));
    $email   = hash('sha256', strtolower(trim((string) $user['email'])));
    $url     = APP_URL . '/register.php';

    $pixel = preg_replace('/\D/', '', get_setting('meta_pixel_id'));
    $token = get_setting('meta_capi_token');
    if ($pixel && $token) {
        $userData = array_filter([
            'em'                => [$email],
            'external_id'       => [hash('sha256', (string) $user['id'])],
            'client_ip_address' => clientIp(),
            'client_user_agent' => userAgent(),
            'fbp'               => $_COOKIE['_fbp'] ?? null,
            'fbc'               => $_COOKIE['_fbc'] ?? null,
        ]);
        $body = ['data' => [[
            'event_name'       => $event,
            'event_time'       => time(),
            'event_id'         => $eventId,
            'action_source'    => 'website',
            'event_source_url' => $url,
            'user_data'        => $userData,
        ]]];
        if ($code = get_setting('meta_test_event_code')) $body['test_event_code'] = $code;
        $r = trackingPost('https://graph.facebook.com/v21.0/' . $pixel . '/events?access_token=' . rawurlencode($token), $body, []);
        logMarketingEvent($event, (int) $user['id'], 'meta', isset($r['events_received']), $r['error']['message'] ?? '');
    }

    $ttPixel = preg_replace('/[^A-Z0-9]/i', '', get_setting('tiktok_pixel_id'));
    $ttToken = get_setting('tiktok_events_token');
    if ($ttPixel && $ttToken) {
        $body = [
            'event_source'    => 'web',
            'event_source_id' => $ttPixel,
            'data' => [[
                'event'      => $event,
                'event_time' => time(),
                'event_id'   => $eventId,
                'user'       => array_filter([
                    'email'       => $email,
                    'external_id' => hash('sha256', (string) $user['id']),
                    'ip'          => clientIp(),
                    'user_agent'  => userAgent(),
                    'ttclid'      => $_SESSION['ttclid'] ?? null,
                    'ttp'         => $_COOKIE['_ttp'] ?? null,
                ]),
                'page' => ['url' => $url],
            ]],
        ];
        if ($code = get_setting('tiktok_test_event_code')) $body['test_event_code'] = $code;
        $r = trackingPost('https://business-api.tiktok.com/open_api/v1.3/event/track/', $body, ['Access-Token: ' . $ttToken]);
        logMarketingEvent($event, (int) $user['id'], 'tiktok', ($r['code'] ?? -1) === 0, (string) ($r['message'] ?? ''));
    }
    return $eventId;
}

function trackingPost(string $url, array $body, array $headers): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($body),
        CURLOPT_HTTPHEADER     => array_merge(['Content-Type: application/json'], $headers),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 6,
        CURLOPT_CONNECTTIMEOUT => 4,
    ]);
    $raw = (string) curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    if ($err !== '') return ['error' => ['message' => $err], 'message' => $err];
    return json_decode($raw, true) ?: ['error' => ['message' => substr($raw, 0, 200)], 'message' => substr($raw, 0, 200)];
}

function logMarketingEvent(string $event, ?int $userId, string $channel, bool $ok, string $detail = ''): void
{
    try {
        getDB()->prepare('INSERT INTO marketing_events (event, user_id, channel, ok, detail) VALUES (?, ?, ?, ?, ?)')
            ->execute([$event, $userId, $channel, $ok ? 1 : 0, substr($detail, 0, 500)]);
    } catch (\Throwable) {
        // Tracking must never break a sign-up.
    }
}
