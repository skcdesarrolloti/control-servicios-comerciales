<?php

declare(strict_types=1);

namespace SCM\Commercial\Promotion\Social\Sync;

use RuntimeException;
use Throwable;

/**
 * Conexión OAuth y llamadas a la API de TikTok. Tokens cifrados con AES-256-GCM.
 * Copia de dashboard-marketing app/TikTokApiService.php (mismas llamadas y tablas); el acceso a datos pasa por SocialDb y
 * las tablas ya existen, así que aquí no se crean.
 */
final class TikTokApiService
{
  private TikTokRepository $repo;
  private string $clientKey;
  private string $clientSecret;
  private string $redirectUri;
  private array $configuredScopes;
  private string $tokenKey;

  public function __construct(?TikTokRepository $repo = null)
  {
    $this->repo = $repo ?? new TikTokRepository();
    $this->clientKey = trim((string) SocialDb::config('tiktok.client_key', ''));
    $this->clientSecret = trim((string) SocialDb::config('tiktok.client_secret', ''));
    $this->redirectUri = trim((string) SocialDb::config('tiktok.redirect_uri', ''));
    $this->configuredScopes = array_values(array_unique(array_filter(array_map('trim', explode(',', (string) SocialDb::config('tiktok.scopes', 'user.info.basic,video.list'))))));
    $this->tokenKey = trim((string) SocialDb::config('tiktok.token_key', '')) ?: $this->clientSecret;
  }

  public function isConfigured(): bool
  {
    return $this->clientKey !== '' && $this->clientSecret !== '' && $this->redirectUri !== '';
  }

  public function authorizationUrl(): string
  {
    $this->assertConfigured();
    $state = bin2hex(random_bytes(32));
    $_SESSION['tiktok_oauth_state'] = ['value' => $state, 'expires_at' => time() + 900];
    return 'https://www.tiktok.com/v2/auth/authorize/?' . http_build_query([
      'client_key' => $this->clientKey,
      'response_type' => 'code',
      'scope' => implode(',', $this->configuredScopes),
      'redirect_uri' => $this->redirectUri,
      'state' => $state,
      'disable_auto_auth' => 1,
    ], '', '&', PHP_QUERY_RFC3986);
  }

  public function completeAuthorization(string $code, string $state): array
  {
    $this->assertConfigured();
    $stored = $_SESSION['tiktok_oauth_state'] ?? [];
    unset($_SESSION['tiktok_oauth_state']);
    if (!is_array($stored) || empty($stored['value']) || (int) ($stored['expires_at'] ?? 0) < time() || !hash_equals((string) $stored['value'], $state)) {
      throw new RuntimeException('La autorización de TikTok expiró o no corresponde a esta sesión. Intenta conectar nuevamente.');
    }
    if ($code === '') {
      throw new RuntimeException('TikTok no devolvió el código de autorización.');
    }
    $token = $this->requestJson('POST_FORM', 'https://open.tiktokapis.com/v2/oauth/token/', [
      'client_key' => $this->clientKey,
      'client_secret' => $this->clientSecret,
      'code' => $code,
      'grant_type' => 'authorization_code',
      'redirect_uri' => $this->redirectUri,
    ]);
    $accessToken = trim((string) ($token['access_token'] ?? ''));
    $refreshToken = trim((string) ($token['refresh_token'] ?? ''));
    $openId = trim((string) ($token['open_id'] ?? ''));
    if ($accessToken === '' || $refreshToken === '' || $openId === '') {
      throw new RuntimeException('TikTok no entregó una autorización completa. Vuelve a conectar la cuenta.');
    }
    $scopes = trim((string) ($token['scope'] ?? ''));
    $profile = $this->userInfoWithToken($accessToken, $scopes);
    return $this->repo->saveConnection(array_merge($profile, [
      'open_id' => $openId,
      'access_token_enc' => $this->encrypt($accessToken),
      'refresh_token_enc' => $this->encrypt($refreshToken),
      'token_expires_at' => date('Y-m-d H:i:s', time() + max(60, (int) ($token['expires_in'] ?? 86400))),
      'refresh_expires_at' => date('Y-m-d H:i:s', time() + max(60, (int) ($token['refresh_expires_in'] ?? 31536000))),
      'scopes' => $scopes,
    ]));
  }

  public function accessToken(): string
  {
    $this->assertConfigured();
    $connection = $this->repo->connection();
    if (!$connection || (int) ($connection['connected'] ?? 0) !== 1) {
      throw new RuntimeException('Conecta primero la cuenta de TikTok.');
    }
    $expiresAt = strtotime((string) ($connection['token_expires_at'] ?? '')) ?: 0;
    if ($expiresAt > time() + 600 && trim((string) ($connection['access_token_enc'] ?? '')) !== '') {
      return $this->decrypt((string) $connection['access_token_enc']);
    }
    $refreshEnc = trim((string) ($connection['refresh_token_enc'] ?? ''));
    if ($refreshEnc === '') {
      throw new RuntimeException('La conexión de TikTok no tiene refresh token. Desconecta y vuelve a autorizar.');
    }
    $token = $this->requestJson('POST_FORM', 'https://open.tiktokapis.com/v2/oauth/token/', [
      'client_key' => $this->clientKey,
      'client_secret' => $this->clientSecret,
      'grant_type' => 'refresh_token',
      'refresh_token' => $this->decrypt($refreshEnc),
    ]);
    $accessToken = trim((string) ($token['access_token'] ?? ''));
    if ($accessToken === '') {
      throw new RuntimeException('TikTok no pudo renovar la autorización. Reconecta la cuenta.');
    }
    $newRefresh = trim((string) ($token['refresh_token'] ?? ''));
    $this->repo->updateTokens(
      (string) $connection['open_id'],
      $this->encrypt($accessToken),
      $newRefresh !== '' ? $this->encrypt($newRefresh) : $refreshEnc,
      date('Y-m-d H:i:s', time() + max(60, (int) ($token['expires_in'] ?? 86400))),
      date('Y-m-d H:i:s', time() + max(60, (int) ($token['refresh_expires_in'] ?? 31536000))),
      trim((string) ($token['scope'] ?? $connection['scopes'] ?? ''))
    );
    return $accessToken;
  }

  public function userInfo(): array
  {
    $connection = $this->repo->connection();
    return $this->userInfoWithToken($this->accessToken(), (string) ($connection['scopes'] ?? ''));
  }

  public function listVideos(?int $cursor = null): array
  {
    $fields = 'id,create_time,cover_image_url,share_url,video_description,duration,title,like_count,comment_count,share_count,view_count';
    $body = ['max_count' => 20];
    if ($cursor !== null && $cursor > 0) {
      $body['cursor'] = $cursor;
    }
    return $this->requestJson(
      'POST_JSON',
      'https://open.tiktokapis.com/v2/video/list/?fields=' . rawurlencode($fields),
      $body,
      ['Authorization: Bearer ' . $this->accessToken()]
    );
  }

  public function disconnect(): void
  {
    $connection = $this->repo->connection();
    if ($connection && (int) ($connection['connected'] ?? 0) === 1) {
      try {
        $this->requestJson('POST_FORM', 'https://open.tiktokapis.com/v2/oauth/revoke/', [
          'client_key' => $this->clientKey,
          'client_secret' => $this->clientSecret,
          'token' => $this->accessToken(),
        ], [], true);
      } catch (Throwable) {
        // La desconexión local debe continuar aunque TikTok ya haya revocado el token.
      }
    }
    $this->repo->disconnect();
  }

  private function userInfoWithToken(string $accessToken, string $scopes): array
  {
    $granted = array_filter(array_map('trim', explode(',', $scopes)));
    $fields = ['open_id', 'union_id', 'avatar_url', 'display_name'];
    if (in_array('user.info.profile', $granted, true)) {
      array_push($fields, 'bio_description', 'profile_deep_link', 'username');
    }
    if (in_array('user.info.stats', $granted, true)) {
      array_push($fields, 'follower_count', 'following_count', 'likes_count', 'video_count');
    }
    $response = $this->requestJson(
      'GET',
      'https://open.tiktokapis.com/v2/user/info/?fields=' . rawurlencode(implode(',', $fields)),
      [],
      ['Authorization: Bearer ' . $accessToken]
    );
    $user = (array) ($response['data']['user'] ?? []);
    if (trim((string) ($user['open_id'] ?? '')) === '') {
      throw new RuntimeException('TikTok no devolvió los datos del perfil autorizado.');
    }
    return [
      'open_id' => (string) ($user['open_id'] ?? ''),
      'union_id' => (string) ($user['union_id'] ?? ''),
      'display_name' => (string) ($user['display_name'] ?? ''),
      'username' => (string) ($user['username'] ?? ''),
      'avatar_url' => (string) ($user['avatar_url'] ?? ''),
      'profile_url' => (string) ($user['profile_deep_link'] ?? ''),
      'bio_description' => (string) ($user['bio_description'] ?? ''),
      'follower_count' => (int) ($user['follower_count'] ?? 0),
      'following_count' => (int) ($user['following_count'] ?? 0),
      'likes_count' => (int) ($user['likes_count'] ?? 0),
      'video_count' => (int) ($user['video_count'] ?? 0),
    ];
  }

  private function requestJson(string $method, string $url, array $payload = [], array $headers = [], bool $allowEmpty = false): array
  {
    $headers[] = 'Accept: application/json';
    $body = '';
    if ($method === 'POST_FORM') {
      $headers[] = 'Content-Type: application/x-www-form-urlencoded';
      $headers[] = 'Cache-Control: no-cache';
      $body = http_build_query($payload, '', '&', PHP_QUERY_RFC3986);
    } elseif ($method === 'POST_JSON') {
      $headers[] = 'Content-Type: application/json';
      $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }
    $status = 0;
    if (!function_exists('curl_init')) {
      throw new RuntimeException('El servidor necesita la extensión cURL para conectarse con TikTok.');
    }
    $curl = curl_init($url);
    $options = [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_TIMEOUT => 60, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_USERAGENT => 'SKC Dashboard Marketing/1.0', CURLOPT_HTTPHEADER => $headers];
    if ($method !== 'GET') {
      $options[CURLOPT_POST] = true;
      $options[CURLOPT_POSTFIELDS] = $body;
    }
    curl_setopt_array($curl, $options);
    $response = curl_exec($curl);
    $error = curl_error($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);
    if ($response === false || $error !== '') {
      throw new RuntimeException('No fue posible conectar con TikTok: ' . $error);
    }
    $raw = trim((string) $response);
    if ($raw === '' && $allowEmpty && $status < 400) return [];
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
      throw new RuntimeException('TikTok devolvió una respuesta que no se pudo interpretar.');
    }
    $rawError = $decoded['error'] ?? null;
    $apiError = is_array($rawError) ? $rawError : [];
    $apiCode = is_string($rawError) && $rawError !== '' ? $rawError : (string) ($apiError['code'] ?? 'ok');
    if ($status >= 400 || ($rawError !== null && ($apiCode === '' || $apiCode !== 'ok'))) {
      $message = (string) ($decoded['error_description'] ?? $apiError['message'] ?? (is_string($rawError) ? $rawError : '') ?: 'Solicitud rechazada por TikTok.');
      throw new RuntimeException('TikTok: ' . mb_substr($message, 0, 600));
    }
    return $decoded;
  }

  private function encrypt(string $value): string
  {
    if (!function_exists('openssl_encrypt') || $this->tokenKey === '') throw new RuntimeException('No está disponible el cifrado para guardar el token de TikTok.');
    $iv = random_bytes(12); $tag = '';
    $encrypted = openssl_encrypt($value, 'aes-256-gcm', hash('sha256', $this->tokenKey, true), OPENSSL_RAW_DATA, $iv, $tag);
    if ($encrypted === false) throw new RuntimeException('No fue posible cifrar el token de TikTok.');
    return base64_encode($iv . $tag . $encrypted);
  }

  private function decrypt(string $value): string
  {
    if (!function_exists('openssl_decrypt') || $this->tokenKey === '') throw new RuntimeException('No está disponible el cifrado para leer el token de TikTok.');
    $decoded = base64_decode($value, true);
    if ($decoded === false || strlen($decoded) < 29) throw new RuntimeException('El token guardado de TikTok no es válido. Reconecta la cuenta.');
    $plain = openssl_decrypt(substr($decoded, 28), 'aes-256-gcm', hash('sha256', $this->tokenKey, true), OPENSSL_RAW_DATA, substr($decoded, 0, 12), substr($decoded, 12, 16));
    if ($plain === false) throw new RuntimeException('No fue posible descifrar el token de TikTok. Revisa TIKTOK_TOKEN_KEY o reconecta.');
    return $plain;
  }

  private function assertConfigured(): void
  {
    if (!$this->isConfigured()) throw new RuntimeException('Configura TIKTOK_CLIENT_KEY, TIKTOK_CLIENT_SECRET y TIKTOK_REDIRECT_URI en .env.');
  }
}
