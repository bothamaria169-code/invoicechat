<?php
declare(strict_types=1);

/**
 * Uploads videos to the InvoiceChat YouTube channel (YouTube Data API v3,
 * resumable upload). Vertical videos under 60 s become Shorts.
 *
 * It reuses the Google sign-in OAuth client (Admin → Settings → Google
 * sign-in): enable "YouTube Data API v3" in that Google Cloud project, add the
 * redirect URI below to the same client, then Admin → Social & ads →
 * Connections → Connect YouTube.
 */
final class YouTube
{
    private const SCOPE = 'https://www.googleapis.com/auth/youtube.upload https://www.googleapis.com/auth/youtube.readonly';

    public static function ready(): bool
    {
        return trim(get_setting('google_client_id')) !== '' && trim(get_setting('google_client_secret')) !== '';
    }

    public static function connected(): bool
    {
        return self::ready() && get_setting('youtube_refresh_token') !== '';
    }

    public static function redirectUri(): string
    {
        return APP_URL . '/admin/youtube-connect.php';
    }

    public static function loginUrl(string $state): string
    {
        return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
            'client_id'     => trim(get_setting('google_client_id')),
            'redirect_uri'  => self::redirectUri(),
            'response_type' => 'code',
            'scope'         => self::SCOPE,
            'access_type'   => 'offline',
            'prompt'        => 'consent', // always return a refresh token
            'state'         => $state,
        ]);
    }

    /** Returns an error message, or '' on success. */
    public static function exchangeCode(string $code): string
    {
        $r = self::token(['code' => $code, 'grant_type' => 'authorization_code', 'redirect_uri' => self::redirectUri()]);
        if (empty($r['refresh_token'])) {
            return 'Google did not give us offline access: ' . ($r['error_description'] ?? $r['error'] ?? 'no refresh token') . '.';
        }
        set_setting('youtube_refresh_token', (string) $r['refresh_token']);
        $ch = curl_init('https://www.googleapis.com/youtube/v3/channels?part=snippet&mine=true');
        curl_setopt_array($ch, [CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $r['access_token']], CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20]);
        $me = json_decode((string) curl_exec($ch), true) ?: [];
        curl_close($ch);
        set_setting('youtube_channel', (string) ($me['items'][0]['snippet']['title'] ?? ''));
        return '';
    }

    public static function disconnect(): void
    {
        set_setting('youtube_refresh_token', '');
        set_setting('youtube_channel', '');
    }

    /** @return array{ok: bool, id: string, error: string} */
    public static function upload(string $file, string $caption): array
    {
        if (!self::connected()) return ['ok' => false, 'id' => '', 'error' => 'YouTube is not connected.'];
        if (!is_file($file)) return ['ok' => false, 'id' => '', 'error' => 'The video file is missing.'];
        $t = self::token(['refresh_token' => get_setting('youtube_refresh_token'), 'grant_type' => 'refresh_token']);
        if (empty($t['access_token'])) return ['ok' => false, 'id' => '', 'error' => 'YouTube sign-in expired — press Connect YouTube again.'];

        $lines = preg_split('/\R/', trim($caption)) ?: [''];
        $title = mb_substr(trim(preg_replace('/[<>#]/', '', $lines[0])) ?: APP_NAME, 0, 90) . ' #Shorts';
        $tags  = preg_match_all('/#(\w+)/u', $caption, $m) ? array_slice($m[1], 0, 15) : [];
        $meta  = json_encode([
            'snippet' => ['title' => $title, 'description' => mb_substr(str_replace(['<', '>'], '', $caption), 0, 4900), 'tags' => $tags, 'categoryId' => '27'],
            'status'  => ['privacyStatus' => get_setting('youtube_privacy', 'public'), 'selfDeclaredMadeForKids' => false],
        ]);

        $ch = curl_init('https://www.googleapis.com/upload/youtube/v3/videos?uploadType=resumable&part=snippet,status');
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => $meta, CURLOPT_HEADER => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $t['access_token'], 'Content-Type: application/json; charset=UTF-8',
                'X-Upload-Content-Type: video/mp4', 'X-Upload-Content-Length: ' . filesize($file)],
        ]);
        $head = (string) curl_exec($ch);
        curl_close($ch);
        if (!preg_match('/^location:\s*(\S+)/mi', $head, $loc)) {
            return ['ok' => false, 'id' => '', 'error' => 'YouTube refused the upload: ' . mb_substr(trim((string) strstr($head, '{')), 0, 200)];
        }

        $fh = fopen($file, 'rb');
        $ch = curl_init(trim($loc[1]));
        curl_setopt_array($ch, [
            CURLOPT_PUT => true, CURLOPT_INFILE => $fh, CURLOPT_INFILESIZE => filesize($file), CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 600,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $t['access_token'], 'Content-Type: video/mp4'],
        ]);
        $body = json_decode((string) curl_exec($ch), true) ?: [];
        curl_close($ch);
        fclose($fh);
        return !empty($body['id'])
            ? ['ok' => true, 'id' => (string) $body['id'], 'error' => '']
            : ['ok' => false, 'id' => '', 'error' => 'Upload failed: ' . ($body['error']['message'] ?? 'no reason given')];
    }

    private static function token(array $fields): array
    {
        $ch = curl_init('https://oauth2.googleapis.com/token');
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_POSTFIELDS => http_build_query($fields + [
            'client_id' => trim(get_setting('google_client_id')), 'client_secret' => trim(get_setting('google_client_secret')),
        ])]);
        $r = json_decode((string) curl_exec($ch), true) ?: [];
        curl_close($ch);
        return $r;
    }
}
