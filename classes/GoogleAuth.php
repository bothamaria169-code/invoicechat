<?php
declare(strict_types=1);

/**
 * "Continue with Google" — the OAuth 2.0 authorisation-code flow, no library.
 *
 * The Client ID and secret come from Google Cloud Console (see
 * docs/GOOGLE-LOGIN-SETUP.md) and are saved in Admin → Settings → Google
 * sign-in. Google tells us who the person is (verified email, name, photo).
 */
final class GoogleAuth
{
    private const AUTH     = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN    = 'https://oauth2.googleapis.com/token';
    private const USERINFO = 'https://openidconnect.googleapis.com/v1/userinfo';

    public static function enabled(): bool
    {
        return get_setting('google_login_enabled', '1') === '1'
            && trim(get_setting('google_client_id')) !== ''
            && trim(get_setting('google_client_secret')) !== '';
    }

    /** Must match an "Authorised redirect URI" in Google Cloud Console exactly. */
    public static function redirectUri(): string
    {
        return APP_URL . '/auth/google.php';
    }

    public static function loginUrl(string $state): string
    {
        return self::AUTH . '?' . http_build_query([
            'client_id'     => trim(get_setting('google_client_id')),
            'redirect_uri'  => self::redirectUri(),
            'response_type' => 'code',
            'scope'         => 'openid email profile',
            'state'         => $state,
            'prompt'        => 'select_account',
        ]);
    }

    /**
     * Swaps the code Google sent back for the person's verified profile.
     *
     * @return array{sub: string, email: string, name: string, picture: string}
     */
    public static function profile(string $code): array
    {
        $token = self::post(self::TOKEN, [
            'code'          => $code,
            'client_id'     => trim(get_setting('google_client_id')),
            'client_secret' => trim(get_setting('google_client_secret')),
            'redirect_uri'  => self::redirectUri(),
            'grant_type'    => 'authorization_code',
        ]);
        if (empty($token['access_token'])) {
            throw new RuntimeException('Google did not accept the sign-in (' . ($token['error_description'] ?? $token['error'] ?? 'no reason given') . ').');
        }
        $ch = curl_init(self::USERINFO);
        curl_setopt_array($ch, [CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token['access_token']], CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20]);
        $me = json_decode((string) curl_exec($ch), true) ?: [];
        curl_close($ch);
        if (empty($me['sub']) || empty($me['email'])) {
            throw new RuntimeException('Google did not share your email address.');
        }
        if (empty($me['email_verified'])) {
            throw new RuntimeException('That Google account\'s email is not verified yet.');
        }
        return [
            'sub'     => (string) $me['sub'],
            'email'   => strtolower((string) $me['email']),
            'name'    => mb_substr(trim((string) ($me['name'] ?? '')), 0, 150),
            'picture' => mb_substr((string) ($me['picture'] ?? ''), 0, 500),
        ];
    }

    private static function post(string $url, array $fields): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($fields), CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20]);
        $body = (string) curl_exec($ch);
        $err  = curl_error($ch);
        curl_close($ch);
        return $err !== '' ? ['error' => $err] : (json_decode($body, true) ?: ['error' => 'Unexpected reply from Google']);
    }
}
