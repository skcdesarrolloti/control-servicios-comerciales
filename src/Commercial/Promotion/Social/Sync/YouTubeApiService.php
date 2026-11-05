<?php

declare(strict_types=1);

namespace SCM\Commercial\Promotion\Social\Sync;

use RuntimeException;

/**
 * Conexión OAuth y llamadas a las API de YouTube. Tokens cifrados con AES-256-GCM.
 * Copia de dashboard-marketing app/YouTubeApiService.php (mismas llamadas y tablas); el acceso a datos pasa por SocialDb y
 * las tablas ya existen, así que aquí no se crean.
 */
final class YouTubeApiService
{
  private YouTubeRepository $repo;
  private string $clientId;
  private string $clientSecret;
  private string $redirectUri;
  private string $configuredChannelId;
  private string $tokenKey;

  public function __construct(?YouTubeRepository $repo = null)
  {
    $this->repo = $repo ?? new YouTubeRepository();
    $this->clientId = trim((string) SocialDb::config('youtube.client_id', ''));
    $this->clientSecret = trim((string) SocialDb::config('youtube.client_secret', ''));
    $this->redirectUri = trim((string) SocialDb::config('youtube.redirect_uri', ''));
    $this->configuredChannelId = trim((string) SocialDb::config('youtube.channel_id', ''));
    $this->tokenKey = trim((string) SocialDb::config('youtube.token_key', '')) ?: $this->clientSecret;
  }

  public function isConfigured(): bool
  {
    return $this->clientId !== '' && $this->clientSecret !== '' && $this->redirectUri !== '';
  }

  public function configuredChannelId(): string
  {
    return $this->configuredChannelId;
  }

  public function connectionMatchesConfiguration(?array $connection = null): bool
  {
    if ($this->configuredChannelId === '') {
      return true;
    }
    $connection ??= $this->repo->connection();
    if (!$connection || (int) ($connection['connected'] ?? 0) !== 1) {
      return true;
    }
    return (string) ($connection['channel_id'] ?? '') === $this->configuredChannelId;
  }

  public function authorizationUrl(): string
  {
    $this->assertConfigured();
    $state = bin2hex(random_bytes(32));
    $_SESSION['youtube_oauth_state'] = ['value' => $state, 'expires_at' => time() + 900];

    return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
      'client_id' => $this->clientId,
      'redirect_uri' => $this->redirectUri,
      'response_type' => 'code',
      'scope' => implode(' ', [
        'https://www.googleapis.com/auth/youtube.readonly',
        'https://www.googleapis.com/auth/yt-analytics.readonly',
      ]),
      'access_type' => 'offline',
      // Fuerza una selección explícita para no reutilizar silenciosamente
      // la cuenta/canal que quedó activo en una autorización anterior.
      'prompt' => 'select_account consent',
      'include_granted_scopes' => 'true',
      'state' => $state,
    ], '', '&', PHP_QUERY_RFC3986);
  }

  public function completeAuthorization(string $code, string $state): array
  {
    $this->assertConfigured();
    $stored = $_SESSION['youtube_oauth_state'] ?? [];
    unset($_SESSION['youtube_oauth_state']);
    if (!is_array($stored)
      || empty($stored['value'])
      || (int) ($stored['expires_at'] ?? 0) < time()
      || !hash_equals((string) $stored['value'], $state)) {
      throw new RuntimeException('La autorización de YouTube expiró o no corresponde a esta sesión. Intenta conectar nuevamente.');
    }
    if ($code === '') {
      throw new RuntimeException('Google no devolvió el código de autorización de YouTube.');
    }

    $token = $this->requestJson('POST', 'https://oauth2.googleapis.com/token', [
      'code' => $code,
      'client_id' => $this->clientId,
      'client_secret' => $this->clientSecret,
      'redirect_uri' => $this->redirectUri,
      'grant_type' => 'authorization_code',
    ]);
    $accessToken = trim((string) ($token['access_token'] ?? ''));
    $refreshToken = trim((string) ($token['refresh_token'] ?? ''));
    if ($accessToken === '') {
      throw new RuntimeException('Google no entregó el token de acceso de YouTube.');
    }

    $channels = $this->authorizedRequest(
      'https://www.googleapis.com/youtube/v3/channels',
      ['part' => 'snippet,contentDetails,statistics', 'mine' => 'true', 'maxResults' => 50],
      $accessToken
    );
    $channel = $this->selectChannel((array) ($channels['items'] ?? []));
    $mapped = $this->mapChannel($channel);
    $mapped['access_token_enc'] = $this->encrypt($accessToken);
    $mapped['refresh_token_enc'] = $refreshToken !== '' ? $this->encrypt($refreshToken) : '';
    $mapped['token_expires_at'] = date('Y-m-d H:i:s', time() + max(60, (int) ($token['expires_in'] ?? 3600)));
    $mapped['scopes'] = trim((string) ($token['scope'] ?? ''));

    return $this->repo->saveConnection($mapped);
  }

  public function accessToken(): string
  {
    $this->assertConfigured();
    $connection = $this->repo->connection();
    if (!$connection || (int) ($connection['connected'] ?? 0) !== 1) {
      throw new RuntimeException('Conecta primero el canal de YouTube.');
    }
    if (!$this->connectionMatchesConfiguration($connection)) {
      $connectedTitle = trim((string) ($connection['channel_title'] ?? 'otro canal'));
      throw new RuntimeException(
        'Está conectado "' . $connectedTitle . '", pero no es el canal configurado. Desconecta YouTube y vuelve a autorizar seleccionando el canal correcto.'
      );
    }

    $expiresAt = strtotime((string) ($connection['token_expires_at'] ?? '')) ?: 0;
    $encryptedAccessToken = trim((string) ($connection['access_token_enc'] ?? ''));
    if ($encryptedAccessToken !== '' && $expiresAt > time() + 90) {
      return $this->decrypt($encryptedAccessToken);
    }

    $encryptedRefreshToken = trim((string) ($connection['refresh_token_enc'] ?? ''));
    if ($encryptedRefreshToken === '') {
      throw new RuntimeException('La autorización de YouTube no contiene un refresh token. Desconecta y conecta nuevamente el canal.');
    }
    $refreshToken = $this->decrypt($encryptedRefreshToken);
    $token = $this->requestJson('POST', 'https://oauth2.googleapis.com/token', [
      'client_id' => $this->clientId,
      'client_secret' => $this->clientSecret,
      'refresh_token' => $refreshToken,
      'grant_type' => 'refresh_token',
    ]);
    $accessToken = trim((string) ($token['access_token'] ?? ''));
    if ($accessToken === '') {
      throw new RuntimeException('Google no pudo renovar la autorización de YouTube. Reconecta el canal.');
    }
    $expires = date('Y-m-d H:i:s', time() + max(60, (int) ($token['expires_in'] ?? 3600)));
    $this->repo->updateTokens(
      (string) $connection['channel_id'],
      $this->encrypt($accessToken),
      $encryptedRefreshToken,
      $expires
    );

    return $accessToken;
  }

  public function data(string $resource, array $params): array
  {
    return $this->authorizedRequest(
      'https://www.googleapis.com/youtube/v3/' . ltrim($resource, '/'),
      $params,
      $this->accessToken()
    );
  }

  public function analytics(array $params): array
  {
    return $this->authorizedRequest(
      'https://youtubeanalytics.googleapis.com/v2/reports',
      $params,
      $this->accessToken()
    );
  }

  public function disconnect(): void
  {
    $this->repo->disconnect();
  }

  public function mapChannel(array $channel): array
  {
    $snippet = (array) ($channel['snippet'] ?? []);
    $statistics = (array) ($channel['statistics'] ?? []);
    $contentDetails = (array) ($channel['contentDetails'] ?? []);
    $thumbnails = (array) ($snippet['thumbnails'] ?? []);

    return [
      'channel_id' => (string) ($channel['id'] ?? ''),
      'channel_title' => (string) ($snippet['title'] ?? 'Canal de YouTube'),
      'channel_handle' => (string) ($snippet['customUrl'] ?? ''),
      'thumbnail_url' => (string) (($thumbnails['high']['url'] ?? null) ?: ($thumbnails['medium']['url'] ?? null) ?: ($thumbnails['default']['url'] ?? '')),
      'uploads_playlist_id' => (string) ($contentDetails['relatedPlaylists']['uploads'] ?? ''),
      'subscriber_count' => (int) ($statistics['subscriberCount'] ?? 0),
      'view_count' => (int) ($statistics['viewCount'] ?? 0),
      'video_count' => (int) ($statistics['videoCount'] ?? 0),
    ];
  }

  private function selectChannel(array $channels): array
  {
    if ($channels === []) {
      throw new RuntimeException('La cuenta autorizada no administra ningún canal de YouTube.');
    }
    if ($this->configuredChannelId === '') {
      return (array) $channels[0];
    }
    foreach ($channels as $channel) {
      if ((string) ($channel['id'] ?? '') === $this->configuredChannelId) {
        return (array) $channel;
      }
    }
    $available = array_values(array_filter(array_map(
      static fn (array $channel): string => trim((string) ($channel['snippet']['title'] ?? '')),
      $channels
    )));
    $suffix = $available !== [] ? ' Google autorizó: ' . implode(', ', $available) . '.' : '';
    throw new RuntimeException(
      'La autorización no corresponde al canal configurado en GOOGLE_YOUTUBE_CHANNEL_ID.' . $suffix . ' Cambia al canal correcto en YouTube y vuelve a conectar.'
    );
  }

  private function authorizedRequest(string $url, array $params, string $accessToken): array
  {
    if ($params !== []) {
      $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }
    return $this->requestJson('GET', $url, [], ['Authorization: Bearer ' . $accessToken]);
  }

  private function requestJson(string $method, string $url, array $params = [], array $headers = []): array
  {
    $method = strtoupper($method);
    if ($method === 'POST') {
      $headers[] = 'Content-Type: application/x-www-form-urlencoded';
    }
    $headers[] = 'Accept: application/json';
    $body = '';
    $status = 0;

    if (function_exists('curl_init')) {
      $curl = curl_init($url);
      $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT => 'SKC Dashboard Marketing/1.0',
        CURLOPT_HTTPHEADER => $headers,
      ];
      if ($method === 'POST') {
        $options[CURLOPT_POST] = true;
        $options[CURLOPT_POSTFIELDS] = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
      }
      curl_setopt_array($curl, $options);
      $response = curl_exec($curl);
      $error = curl_error($curl);
      $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
      curl_close($curl);
      if ($response === false || $error !== '') {
        throw new RuntimeException('No fue posible conectar con Google: ' . $error);
      }
      $body = (string) $response;
    } else {
      $context = stream_context_create(['http' => [
        'method' => $method,
        'header' => implode("\r\n", $headers),
        'content' => $method === 'POST' ? http_build_query($params, '', '&', PHP_QUERY_RFC3986) : '',
        'timeout' => 60,
        'ignore_errors' => true,
      ]]);
      $response = @file_get_contents($url, false, $context);
      $body = $response === false ? '' : (string) $response;
      foreach ($http_response_header ?? [] as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $matches)) {
          $status = (int) $matches[1];
        }
      }
    }

    $decoded = json_decode($body, true);
    if (!is_array($decoded)) {
      throw new RuntimeException('Google devolvió una respuesta que no se pudo interpretar.');
    }
    if ($status >= 400 || isset($decoded['error'])) {
      $error = $decoded['error'] ?? [];
      $message = is_array($error)
        ? (string) ($error['message'] ?? ($error['status'] ?? 'Solicitud rechazada por Google.'))
        : (string) $error;
      throw new RuntimeException('YouTube: ' . mb_substr($message, 0, 600));
    }

    return $decoded;
  }

  private function encrypt(string $value): string
  {
    if ($value === '') {
      return '';
    }
    if (!function_exists('openssl_encrypt') || $this->tokenKey === '') {
      throw new RuntimeException('No está disponible el cifrado necesario para guardar el token de YouTube.');
    }
    $key = hash('sha256', $this->tokenKey, true);
    $iv = random_bytes(12);
    $tag = '';
    $encrypted = openssl_encrypt($value, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    if ($encrypted === false) {
      throw new RuntimeException('No fue posible cifrar el token de YouTube.');
    }

    return base64_encode($iv . $tag . $encrypted);
  }

  private function decrypt(string $value): string
  {
    if (!function_exists('openssl_decrypt') || $this->tokenKey === '') {
      throw new RuntimeException('No está disponible el cifrado necesario para leer el token de YouTube.');
    }
    $decoded = base64_decode($value, true);
    if ($decoded === false || strlen($decoded) < 29) {
      throw new RuntimeException('El token guardado de YouTube no es válido. Reconecta el canal.');
    }
    $iv = substr($decoded, 0, 12);
    $tag = substr($decoded, 12, 16);
    $encrypted = substr($decoded, 28);
    $plain = openssl_decrypt($encrypted, 'aes-256-gcm', hash('sha256', $this->tokenKey, true), OPENSSL_RAW_DATA, $iv, $tag);
    if ($plain === false) {
      throw new RuntimeException('No fue posible descifrar el token de YouTube. Revisa GOOGLE_YOUTUBE_TOKEN_KEY o reconecta el canal.');
    }

    return $plain;
  }

  private function assertConfigured(): void
  {
    if (!$this->isConfigured()) {
      throw new RuntimeException('Configura GOOGLE_YOUTUBE_CLIENT_ID, GOOGLE_YOUTUBE_CLIENT_SECRET y GOOGLE_YOUTUBE_REDIRECT_URI en .env.');
    }
  }
}
