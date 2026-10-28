<?php
declare(strict_types=1);

/**
 * Posts to the InvoiceChat Facebook Page and its linked Instagram Business
 * account through Meta's Graph API. (Adapted from the CureMeds / BidCo poster.)
 *
 * Connecting: Admin → Marketing → Connections → save the Meta app ID and
 * secret → "Connect with Facebook". admin/meta-connect.php swaps the login for
 * a long-lived user token and then a Page token that does not expire.
 *
 * Meta fetches photos and videos itself, so media must be on a public https
 * address — it works on the live site, not from localhost.
 */
final class MetaPoster
{
    public const GRAPH_VERSION = 'v21.0';
    public const GRAPH = 'https://graph.facebook.com/' . self::GRAPH_VERSION . '/';

    public const SCOPES = ['pages_show_list', 'pages_manage_posts', 'pages_read_engagement',
        'instagram_basic', 'instagram_content_publish', 'business_management'];

    /** @return array{app_id: string, app_secret: string, page_id: string, page_name: string, token: string, instagram_id: string, instagram_name: string} */
    public static function config(): array
    {
        return [
            'app_id'         => preg_replace('/\D/', '', get_setting('meta_app_id')) ?? '',
            'app_secret'     => trim(get_setting('meta_app_secret')),
            'page_id'        => preg_replace('/\D/', '', get_setting('meta_page_id')) ?? '',
            'page_name'      => get_setting('meta_page_name'),
            'token'          => trim(get_setting('meta_page_token')),
            'instagram_id'   => preg_replace('/\D/', '', get_setting('meta_instagram_id')) ?? '',
            'instagram_name' => get_setting('meta_instagram_name'),
        ];
    }

    public static function ready(): bool
    {
        $c = self::config();
        return $c['app_id'] !== '' && $c['app_secret'] !== '';
    }

    public static function connected(string $platform): bool
    {
        $c = self::config();
        return match ($platform) {
            'facebook'  => $c['page_id'] !== '' && $c['token'] !== '',
            'instagram' => $c['instagram_id'] !== '' && $c['token'] !== '',
            default     => false,
        };
    }

    /** Must be listed under Facebook Login → Settings → Valid OAuth Redirect URIs, exactly. */
    public static function redirectUri(): string
    {
        return APP_URL . '/admin/meta-connect.php';
    }

    public static function loginUrl(string $state): string
    {
        return 'https://www.facebook.com/' . self::GRAPH_VERSION . '/dialog/oauth?' . http_build_query([
            'client_id'     => self::config()['app_id'],
            'redirect_uri'  => self::redirectUri(),
            'state'         => $state,
            'response_type' => 'code',
            'scope'         => implode(',', self::SCOPES),
        ]);
    }

    /**
     * Swaps the code Facebook sends back for the Pages this person manages.
     *
     * @return array{pages: array<string, array>, error: string}
     */
    public static function exchangeCode(string $code): array
    {
        $c = self::config();
        $short = self::get('oauth/access_token', [
            'client_id' => $c['app_id'], 'client_secret' => $c['app_secret'],
            'redirect_uri' => self::redirectUri(), 'code' => $code,
        ]);
        $long = empty($short['access_token']) ? $short : self::get('oauth/access_token', [
            'grant_type' => 'fb_exchange_token', 'client_id' => $c['app_id'],
            'client_secret' => $c['app_secret'], 'fb_exchange_token' => $short['access_token'],
        ]);
        if (empty($long['access_token'])) {
            return ['pages' => [], 'error' => 'Facebook did not give us access: ' . self::err($long)];
        }
        // A Page token fetched with a long-lived user token never expires.
        $accounts = self::get('me/accounts', [
            'fields' => 'id,name,access_token,instagram_business_account{id,username}',
            'limit' => 50, 'access_token' => $long['access_token'],
        ]);
        $pages = [];
        foreach ($accounts['data'] ?? [] as $row) {
            if (!empty($row['id']) && !empty($row['access_token'])) {
                $pages[(string) $row['id']] = [
                    'id'        => (string) $row['id'],
                    'name'      => (string) ($row['name'] ?? $row['id']),
                    'token'     => (string) $row['access_token'],
                    'instagram' => (string) ($row['instagram_business_account']['id'] ?? ''),
                    'ig_name'   => (string) ($row['instagram_business_account']['username'] ?? ''),
                ];
            }
        }
        return ['pages' => $pages, 'error' => $pages ? '' : (isset($accounts['error'])
            ? 'Facebook said: ' . self::err($accounts)
            : 'No Facebook Pages came back. When Facebook asks which Pages the app may use, tick your Page and its Instagram account.')];
    }

    public static function savePage(array $page): void
    {
        set_setting('meta_page_id', $page['id']);
        set_setting('meta_page_token', $page['token']);
        set_setting('meta_page_name', mb_substr($page['name'], 0, 120));
        set_setting('meta_instagram_id', $page['instagram']);
        set_setting('meta_instagram_name', mb_substr($page['ig_name'], 0, 120));
    }

    public static function disconnect(): void
    {
        foreach (['meta_page_id', 'meta_page_token', 'meta_page_name', 'meta_instagram_id', 'meta_instagram_name'] as $k) {
            set_setting($k, '');
        }
    }

    /**
     * Publishes one post. $mediaUrl is a public image (jpg/png) or video (mp4).
     *
     * @return array{ok: bool, id: string, error: string}
     */
    public static function publish(string $platform, string $caption, ?string $mediaUrl, string $kind): array
    {
        if (!self::connected($platform)) {
            return ['ok' => false, 'id' => '', 'error' => ucfirst($platform) . ' is not connected.'];
        }
        $c = self::config();
        $t = $c['token'];

        if ($platform === 'facebook') {
            return match (true) {
                $kind === 'video' && $mediaUrl !== null => self::post($c['page_id'] . '/videos', ['file_url' => $mediaUrl, 'description' => $caption, 'access_token' => $t]),
                $kind === 'image' && $mediaUrl !== null => self::post($c['page_id'] . '/photos', ['url' => $mediaUrl, 'caption' => $caption, 'access_token' => $t]),
                default => self::post($c['page_id'] . '/feed', ['message' => $caption] + (preg_match('#https?://\S+#', $caption, $m) ? ['link' => $m[0]] : []) + ['access_token' => $t]),
            };
        }

        // Instagram: create a media container, wait until it's processed, publish it.
        if ($mediaUrl === null) {
            return ['ok' => false, 'id' => '', 'error' => 'Instagram needs a photo or video.'];
        }
        $fields = $kind === 'video'
            ? ['media_type' => 'REELS', 'video_url' => $mediaUrl, 'caption' => $caption, 'share_to_feed' => 'true']
            : ['image_url' => $mediaUrl, 'caption' => $caption];
        $box = self::post($c['instagram_id'] . '/media', $fields + ['access_token' => $t]);
        if (!$box['ok']) return $box;
        for ($i = 0; $i < ($kind === 'video' ? 24 : 6); $i++) {
            $st = self::get($box['id'], ['fields' => 'status_code', 'access_token' => $t]);
            $code = (string) ($st['status_code'] ?? '');
            if ($code === 'FINISHED') break;
            if ($code === 'ERROR') return ['ok' => false, 'id' => '', 'error' => 'Instagram could not process the media.'];
            sleep(5);
        }
        return self::post($c['instagram_id'] . '/media_publish', ['creation_id' => $box['id'], 'access_token' => $t]);
    }

    public static function get(string $path, array $query): array
    {
        $ch = curl_init(self::GRAPH . $path . '?' . http_build_query($query));
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_CONNECTTIMEOUT => 10]);
        $body  = (string) curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);
        if ($error !== '') return ['error' => ['message' => $error]];
        return json_decode($body, true) ?: ['error' => ['message' => 'Unexpected reply from Facebook']];
    }

    /** @return array{ok: bool, id: string, error: string} */
    private static function post(string $path, array $fields): array
    {
        $ch = curl_init(self::GRAPH . $path);
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($fields),
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 90, CURLOPT_CONNECTTIMEOUT => 15,
        ]);
        $body  = (string) curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);
        if ($error !== '') return ['ok' => false, 'id' => '', 'error' => $error];
        $json = json_decode($body, true) ?: [];
        if (isset($json['error'])) return ['ok' => false, 'id' => '', 'error' => self::err($json)];
        $id = (string) ($json['post_id'] ?? $json['id'] ?? '');
        return ['ok' => $id !== '', 'id' => $id, 'error' => $id === '' ? 'No post id came back: ' . mb_substr($body, 0, 150) : ''];
    }

    public static function err(array $reply): string
    {
        return (string) ($reply['error']['message'] ?? 'no reason given');
    }
}
