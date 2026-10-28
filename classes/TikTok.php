<?php
declare(strict_types=1);

/**
 * Sends videos to the InvoiceChat TikTok account as drafts, through TikTok's
 * Content Posting API ("upload to inbox", scope video.upload). Adapted from
 * the CureMeds integration.
 *
 * A draft lands in the TikTok app's inbox; someone opens it there, adds a
 * sound, and presses Post. That is all a Sandbox (unaudited) app may do, so
 * it works from day one. The MP4 is uploaded from our server as a file, so
 * no domain verification is needed.
 */
final class TikTok
{
    private const AUTH  = 'https://www.tiktok.com/v2/auth/authorize/';
    private const API   = 'https://open.tiktokapis.com/v2/';
    public const SCOPES = 'user.info.basic,video.upload';

    /** @return array{client_key: string, client_secret: string, token: string, refresh: string, expires: int, name: string} */
    public static function config(): array
    {
        return [
            'client_key'    => trim(get_setting('tiktok_client_key')),
            'client_secret' => trim(get_setting('tiktok_client_secret')),
            'token'         => get_setting('tiktok_access_token'),
            'refresh'       => get_setting('tiktok_refresh_token'),
            'expires'       => (int) get_setting('tiktok_expires_at', '0'),
            'name'          => get_setting('tiktok_name'),
        ];
    }

    public static function ready(): bool
    {
        $c = self::config();
        return $c['client_key'] !== '' && $c['client_secret'] !== '';
    }

    public static function connected(): bool
    {
        return self::ready() && self::config()['refresh'] !== '';
    }

    /** Must be listed under Login Kit → Redirect URI in the TikTok developer portal, exactly. */
    public static function redirectUri(): string
    {
        return APP_URL . '/admin/tiktok-connect.php';
    }

    public static function loginUrl(string $state): string
    {
        return self::AUTH . '?' . http_build_query([
            'client_key'    => self::config()['client_key'],
            'scope'         => self::SCOPES,
            'response_type' => 'code',
            'redirect_uri'  => self::redirectUri(),
            'state'         => $state,
        ]);
    }

    /** Swaps the code TikTok sends back for tokens. Returns an error message, or '' on success. */
    public static function exchangeCode(string $code): string
    {
        $c = self::config();
        $r = self::form('oauth/token/', [
            'client_key' => $c['client_key'], 'client_secret' => $c['client_secret'],
            'code' => $code, 'grant_type' => 'authorization_code', 'redirect_uri' => self::redirectUri(),
        ]);
        if (empty($r['access_token'])) {
            return 'TikTok did not give us access: ' . self::error($r);
        }
        self::saveTokens($r);
        $me = self::json('GET', 'user/info/?fields=open_id,display_name', null);
        set_setting('tiktok_name', (string) ($me['data']['user']['display_name'] ?? ''));
        return '';
    }

    public static function disconnect(): void
    {
        foreach (['tiktok_access_token', 'tiktok_refresh_token', 'tiktok_expires_at', 'tiktok_name'] as $k) {
            set_setting($k, '');
        }
    }

    private static function saveTokens(array $r): void
    {
        set_setting('tiktok_access_token', (string) $r['access_token']);
        set_setting('tiktok_refresh_token', (string) ($r['refresh_token'] ?? self::config()['refresh']));
        set_setting('tiktok_expires_at', (string) (time() + (int) ($r['expires_in'] ?? 86400) - 300));
    }

    /** A working access token: they last a day, so it is refreshed (the refresh token lasts a year). */
    private static function token(): string
    {
        $c = self::config();
        if ($c['token'] !== '' && $c['expires'] > time()) {
            return $c['token'];
        }
        $r = self::form('oauth/token/', [
            'client_key' => $c['client_key'], 'client_secret' => $c['client_secret'],
            'grant_type' => 'refresh_token', 'refresh_token' => $c['refresh'],
        ]);
        if (empty($r['access_token'])) return '';
        self::saveTokens($r);
        return (string) $r['access_token'];
    }

    /**
     * Sends one local MP4 to the TikTok inbox as a draft.
     *
     * @return array{ok: bool, id: string, error: string}
     */
    public static function uploadDraft(string $file): array
    {
        if (!self::connected()) {
            return ['ok' => false, 'id' => '', 'error' => 'TikTok is not connected.'];
        }
        if (self::token() === '') {
            return ['ok' => false, 'id' => '', 'error' => 'TikTok sign-in has expired — press Connect TikTok again.'];
        }
        $video = is_file($file) ? (string) file_get_contents($file) : '';
        if ($video === '') {
            return ['ok' => false, 'id' => '', 'error' => 'The video file is missing.'];
        }

        $size = strlen($video);
        $init = self::json('POST', 'post/publish/inbox/video/init/', ['source_info' => [
            'source' => 'FILE_UPLOAD', 'video_size' => $size, 'chunk_size' => $size, 'total_chunk_count' => 1,
        ]]);
        $uploadUrl = (string) ($init['data']['upload_url'] ?? '');
        $publishId = (string) ($init['data']['publish_id'] ?? '');
        if ($uploadUrl === '') {
            error_log('TikTok init failed: ' . json_encode($init));
            return ['ok' => false, 'id' => '', 'error' => 'TikTok said: ' . self::error($init)];
        }

        $ch = curl_init($uploadUrl);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => 'PUT',
            CURLOPT_POSTFIELDS     => $video,
            CURLOPT_HTTPHEADER     => ['Content-Type: video/mp4', 'Content-Length: ' . $size, 'Content-Range: bytes 0-' . ($size - 1) . '/' . $size],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 300,
        ]);
        curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($code < 200 || $code >= 300) {
            return ['ok' => false, 'id' => $publishId, 'error' => 'Upload to TikTok failed (' . ($err ?: 'HTTP ' . $code) . ').'];
        }

        // An accepted upload can still be rejected while TikTok processes it.
        $status = 'PROCESSING_UPLOAD';
        for ($try = 0; $try < 12 && in_array($status, ['PROCESSING_UPLOAD', 'PROCESSING_DOWNLOAD'], true); $try++) {
            sleep(5);
            $r      = self::json('POST', 'post/publish/status/fetch/', ['publish_id' => $publishId]);
            $status = (string) ($r['data']['status'] ?? 'UNKNOWN');
            if ($status === 'FAILED') {
                return ['ok' => false, 'id' => $publishId, 'error' => 'TikTok rejected the video: ' . ($r['data']['fail_reason'] ?? 'no reason given') . '.'];
            }
        }
        return ['ok' => true, 'id' => $publishId, 'error' => ''];
    }

    private static function form(string $path, array $fields): array
    {
        $ch = curl_init(self::API . $path);
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($fields),
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30,
        ]);
        $body = (string) curl_exec($ch);
        $err  = curl_error($ch);
        curl_close($ch);
        return $err !== '' ? ['error' => ['message' => $err]] : (json_decode($body, true) ?: ['error' => ['message' => 'Unexpected reply from TikTok']]);
    }

    private static function json(string $method, string $path, ?array $payload): array
    {
        $ch = curl_init(self::API . $path);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . self::token(), 'Content-Type: application/json; charset=UTF-8'],
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30,
        ] + ($payload !== null ? [CURLOPT_POSTFIELDS => json_encode($payload)] : []));
        $body = (string) curl_exec($ch);
        $err  = curl_error($ch);
        curl_close($ch);
        return $err !== '' ? ['error' => ['message' => $err]] : (json_decode($body, true) ?: ['error' => ['message' => 'Unexpected reply from TikTok']]);
    }

    private static function error(array $r): string
    {
        $e = $r['error'] ?? null;
        if (is_array($e) && ($e['code'] ?? '') === 'ok') return 'no reason given';
        return (string) (is_array($e) ? ($e['message'] ?? $e['code'] ?? 'no reason given') : ($r['error_description'] ?? $e ?? 'no reason given'));
    }
}
