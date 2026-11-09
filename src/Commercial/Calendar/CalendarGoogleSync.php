<?php

declare(strict_types=1);

namespace SCM\Commercial\Calendar;

use SCM\Core\Database;

/**
 * Conexión de cada funcionario con Google Calendar. Lógica traída de calendario/backend (helpers/google_sync.php y
 * models/GoogleIntegration.php): usa las mismas tablas y el mismo cifrado, así las cuentas ya conectadas siguen sirviendo si
 * CALENDARIO_GOOGLE_TOKEN_KEY es la misma.
 */
class CalendarGoogleSync
{
  private const ACCOUNTS = 'calendario_google_accounts';
  private const STATES = 'calendario_google_oauth_states';
  private const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
  private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
  private const CALENDAR_SCOPE = 'https://www.googleapis.com/auth/calendar.events';
  private const IDENTITY_SCOPE = 'openid email';
  private const TIMEZONE = 'America/Bogota';

  private Database $db;
  private CalendarStore $store;
  /** @var array<string,mixed> */
  private array $config;
  private ?string $lastError = null;

  /** @param array<string,mixed> $config */
  public function __construct(CalendarStore $store, array $config)
  {
    $this->store = $store;
    $this->db = $store->database();
    $this->config = $config;
  }

  public function lastError(): ?string
  {
    return $this->lastError;
  }

  public function isConfigured(): bool
  {
    return $this->clientId() !== '' && $this->clientSecret() !== '' && $this->redirectUri() !== '' && $this->tokenSecret() !== '';
  }

  public function isActive(): bool
  {
    return $this->isConfigured() && !empty($this->config['google_sync_enabled']);
  }

  /** @return array<string,mixed> */
  public function status(string $employeeId): array
  {
    if (!$this->isConfigured()) {
      return ['configured' => false, 'connected' => false, 'sync_enabled' => false];
    }
    $account = $this->account($employeeId);
    $verified = $this->accountVerified($employeeId, $account);
    return [
      'configured' => true,
      'connected' => $verified,
      'reconnect_required' => $account !== null && !$verified,
      'sync_enabled' => $this->isActive(),
      'google_email' => $account['google_email'] ?? null,
      'expires_at' => $account['expires_at'] ?? null,
    ];
  }

  public function authorizationUrl(string $employeeId, string $redirectAfter): string
  {
    if (!$this->isConfigured()) {
      throw new \RuntimeException('Google Calendar no está configurado en el servidor.');
    }
    $state = bin2hex(random_bytes(32));
    $this->db->insert(self::STATES, [
      'state' => $state,
      'id_empleado' => $employeeId,
      'redirect_after' => $redirectAfter !== '' ? $redirectAfter : null,
      'expires_at' => gmdate('Y-m-d H:i:s', time() + 600),
    ]);
    return self::AUTH_URL . '?' . http_build_query([
      'client_id' => $this->clientId(),
      'redirect_uri' => $this->redirectUri(),
      'response_type' => 'code',
      'scope' => self::CALENDAR_SCOPE . ' ' . self::IDENTITY_SCOPE,
      'access_type' => 'offline',
      'include_granted_scopes' => 'true',
      'prompt' => 'consent',
      'state' => $state,
    ]);
  }

  /** @return array{id_empleado:string,redirect_after:string} */
  public function completeAuthorization(string $code, string $state): array
  {
    $row = $this->db->getRow('SELECT * FROM `' . self::STATES . '` WHERE `state` = ? AND `expires_at` >= ? LIMIT 1', [$state, gmdate('Y-m-d H:i:s')]);
    $this->db->delete(self::STATES, ['state' => $state]);
    if ($row === null) {
      throw new \RuntimeException('La autorización de Google expiró o no es válida.');
    }
    $employeeId = (string) $row['id_empleado'];
    $tokens = $this->requestToken([
      'code' => $code,
      'client_id' => $this->clientId(),
      'client_secret' => $this->clientSecret(),
      'redirect_uri' => $this->redirectUri(),
      'grant_type' => 'authorization_code',
    ]);
    $identity = $this->httpRequest('GET', 'https://openidconnect.googleapis.com/v1/userinfo', ['Authorization: Bearer ' . $tokens['access_token']], [], false);
    $expected = $this->expectedEmail($employeeId);
    $actual = strtolower(trim((string) ($identity['email'] ?? '')));
    if ($expected === '' || $actual === '' || ($identity['email_verified'] ?? false) !== true || !hash_equals($expected, $actual)) {
      throw new \RuntimeException('La cuenta autorizada no coincide con el correo Google del funcionario. Debe usar correo_google o, si está vacío, su correo registrado en SKC.');
    }
    $tokens['google_email'] = $actual;
    $this->saveAccount($employeeId, $tokens);
    return ['id_empleado' => $employeeId, 'redirect_after' => (string) ($row['redirect_after'] ?? '')];
  }

  public function disconnect(string $employeeId): void
  {
    $this->db->delete(self::ACCOUNTS, ['id_empleado' => $employeeId]);
  }

  /** @param array<string,mixed> $event */
  public function createEvent(array $event): ?string
  {
    return $this->insertRemote($event, null);
  }

  /** @param array<string,mixed> $reminder */
  public function createReminder(array $reminder): ?string
  {
    $this->lastError = null;
    if (!$this->isActive()) {
      $this->lastError = 'La sincronización con Google Calendar está desactivada en el servidor.';
      return null;
    }
    $start = (string) ($reminder['recordatorio_at'] ?? ($reminder['fecha_inicio'] ?? ''));
    if ($start === '') {
      return null;
    }
    return $this->insertRemote(array_merge($reminder, [
      'titulo' => 'Recordatorio: ' . (string) ($reminder['titulo'] ?? 'Calendario'),
      'descripcion' => (string) ($reminder['mensaje'] ?? ($reminder['descripcion'] ?? '')),
      'fecha_inicio' => $start,
      'fecha_fin' => self::addMinutes($start, 15),
      'source_table' => CalendarStore::REMINDERS,
    ]), self::reminderMinutes($reminder));
  }

  /** @param array<string,mixed> $event */
  public function updateEvent(array $event): bool
  {
    $token = $this->tokenFor($event);
    if ($token === null) {
      return false;
    }
    $done = strtolower(trim((string) ($event['estado'] ?? ''))) === 'si';
    $this->patch((string) $event['google_event_id'], $token, [
      'summary' => ($done ? '✓ ' : '') . (string) ($event['titulo'] ?? 'Evento'),
      'description' => (string) ($event['descripcion'] ?? '') . ($done ? "\n\nEstado SKC: Realizado" : ''),
      'location' => (string) ($event['ubicacion'] ?? ''),
      'start' => self::googleTime((string) ($event['fecha_inicio'] ?? '')),
      'end' => self::googleTime((string) ($event['fecha_fin'] ?? '')),
      'reminders' => $done ? ['useDefault' => false, 'overrides' => []] : ['useDefault' => true],
    ]);
    return true;
  }

  /** @param array<string,mixed> $reminder */
  public function updateReminder(array $reminder): bool
  {
    $token = $this->tokenFor($reminder);
    if ($token === null) {
      return false;
    }
    $status = strtolower(trim((string) ($reminder['estado'] ?? '')));
    $closed = in_array($status, ['finalizado', 'enviado', 'cancelado'], true);
    $title = 'Recordatorio: ' . (string) ($reminder['titulo'] ?? 'Calendario');
    if (in_array($status, ['enviado', 'finalizado'], true)) {
      $title = '✓ ' . $title;
    } elseif ($status === 'cancelado') {
      $title = '[Cancelado] ' . $title;
    }
    $start = (string) ($reminder['recordatorio_at'] ?? '');
    $this->patch((string) $reminder['google_event_id'], $token, [
      'summary' => $title,
      'description' => (string) ($reminder['mensaje'] ?? ''),
      'start' => self::googleTime($start),
      'end' => self::googleTime(self::addMinutes($start, 15)),
      'reminders' => $closed
        ? ['useDefault' => false, 'overrides' => []]
        : ['useDefault' => false, 'overrides' => [['method' => 'popup', 'minutes' => self::reminderMinutes($reminder)]]],
    ]);
    return true;
  }

  // ── Interno ────────────────────────────────────────

  /** @param array<string,mixed> $item */
  private function insertRemote(array $item, ?int $reminderMinutes): ?string
  {
    if (!$this->isActive() || empty($item['id_empleado']) || !empty($item['google_event_id'])) {
      return null;
    }
    $token = $this->accessToken((string) $item['id_empleado']);
    if ($token === null) {
      return null;
    }
    $response = $this->httpRequest('POST', $this->eventsUrl(), self::jsonHeaders($token), [
      'summary' => (string) ($item['titulo'] ?? 'Evento'),
      'description' => (string) ($item['descripcion'] ?? ''),
      'location' => (string) ($item['ubicacion'] ?? ''),
      'start' => self::googleTime((string) ($item['fecha_inicio'] ?? '')),
      'end' => self::googleTime((string) ($item['fecha_fin'] ?? ($item['fecha_inicio'] ?? ''))),
      'reminders' => $reminderMinutes !== null
        ? ['useDefault' => false, 'overrides' => [['method' => 'popup', 'minutes' => $reminderMinutes]]]
        : ['useDefault' => true],
      'extendedProperties' => ['private' => [
        'skc_source' => (string) ($item['source_table'] ?? CalendarStore::EVENTS),
        'skc_id' => (string) ($item['id'] ?? ''),
        'skc_origen_app' => (string) ($item['origen_app'] ?? CalendarStore::ORIGIN),
      ]],
    ], false);
    $id = $response['id'] ?? null;
    return is_string($id) ? $id : null;
  }

  /** @param array<string,mixed> $item */
  private function tokenFor(array $item): ?string
  {
    if (!$this->isActive() || empty($item['google_event_id'])) {
      return null;
    }
    return $this->accessToken((string) ($item['id_empleado'] ?? ''));
  }

  /** @param array<string,mixed> $body */
  private function patch(string $googleEventId, string $token, array $body): void
  {
    $this->httpRequest('PATCH', $this->eventsUrl() . '/' . rawurlencode($googleEventId) . '?sendUpdates=none', self::jsonHeaders($token), $body, false);
  }

  private function eventsUrl(): string
  {
    $calendar = trim((string) ($this->config['google_calendar_id'] ?? ''));
    return 'https://www.googleapis.com/calendar/v3/calendars/' . rawurlencode($calendar !== '' ? $calendar : 'primary') . '/events';
  }

  /** @return array<int,string> */
  private static function jsonHeaders(string $token): array
  {
    return ['Authorization: Bearer ' . $token, 'Content-Type: application/json'];
  }

  private function accessToken(string $employeeId): ?string
  {
    try {
      $account = $this->account($employeeId);
      if (!$this->accountVerified($employeeId, $account)) {
        $this->lastError = 'El funcionario debe conectar su propia cuenta de Google registrada en SKC.';
        return null;
      }
      $expires = !empty($account['expires_at']) ? (int) strtotime($account['expires_at'] . ' UTC') : 0;
      if (!empty($account['access_token']) && $expires > time() + 90) {
        return (string) $account['access_token'];
      }
      if (empty($account['refresh_token'])) {
        $this->lastError = 'El permiso de Google no se puede renovar. Vuelve a conectar la cuenta del funcionario.';
        return null;
      }
      $tokens = $this->requestToken([
        'client_id' => $this->clientId(),
        'client_secret' => $this->clientSecret(),
        'refresh_token' => $account['refresh_token'],
        'grant_type' => 'refresh_token',
      ]);
      $tokens['refresh_token'] = $tokens['refresh_token'] ?? $account['refresh_token'];
      $tokens['google_email'] = $account['google_email'];
      $this->saveAccount($employeeId, $tokens);
      return (string) $tokens['access_token'];
    } catch (\Throwable $exception) {
      error_log('[commercial-calendar-google] token: ' . $exception->getMessage());
      $this->lastError = 'No se pudo renovar el acceso a Google. Vuelve a conectar la cuenta del funcionario.';
      return null;
    }
  }

  /** @param array<string,mixed>|null $account */
  private function accountVerified(string $employeeId, ?array $account): bool
  {
    if ($account === null || empty($account['google_email'])) {
      return false;
    }
    $expected = $this->expectedEmail($employeeId);
    return $expected !== '' && hash_equals($expected, strtolower(trim((string) $account['google_email'])));
  }

  private function expectedEmail(string $employeeId): string
  {
    $employee = $this->store->employee($employeeId) ?? [];
    $email = trim((string) ($employee['correo_google'] ?? ''));
    return strtolower($email !== '' ? $email : trim((string) ($employee['correo'] ?? '')));
  }

  /** @return array<string,mixed>|null */
  private function account(string $employeeId): ?array
  {
    $row = $this->db->getRow('SELECT * FROM `' . self::ACCOUNTS . '` WHERE `id_empleado` = ? LIMIT 1', [trim($employeeId)]);
    if ($row === null) {
      return null;
    }
    $row['access_token'] = $this->decrypt((string) ($row['access_token_enc'] ?? ''));
    $row['refresh_token'] = $this->decrypt((string) ($row['refresh_token_enc'] ?? ''));
    unset($row['access_token_enc'], $row['refresh_token_enc']);
    return $row;
  }

  /** @param array<string,mixed> $tokens */
  private function saveAccount(string $employeeId, array $tokens): void
  {
    $current = $this->account($employeeId);
    $refresh = trim((string) ($tokens['refresh_token'] ?? ''));
    $email = strtolower(trim((string) ($tokens['google_email'] ?? ($current['google_email'] ?? ''))));
    if ($email !== '') {
      $other = $this->db->getVar('SELECT `id_empleado` FROM `' . self::ACCOUNTS . '` WHERE `google_email` = ? AND `id_empleado` <> ? LIMIT 1', [$email, $employeeId]);
      if ($other !== null) {
        throw new \RuntimeException('Esta cuenta de Google ya está vinculada a otro funcionario.');
      }
    }
    if ($refresh === '' && $current !== null && $email !== '' && hash_equals($email, strtolower(trim((string) ($current['google_email'] ?? ''))))) {
      $refresh = (string) ($current['refresh_token'] ?? '');
    }
    if ($refresh === '') {
      throw new \RuntimeException('Google no entregó un permiso renovable. Vuelve a conectar la cuenta.');
    }
    $data = [
      'google_email' => $email !== '' ? $email : null,
      'access_token_enc' => $this->encrypt((string) ($tokens['access_token'] ?? '')),
      'refresh_token_enc' => $this->encrypt($refresh),
      'scope' => trim((string) ($tokens['scope'] ?? '')) ?: null,
      'expires_at' => gmdate('Y-m-d H:i:s', time() + max(60, (int) ($tokens['expires_in'] ?? 3600))),
    ];
    if ($current !== null) {
      $this->db->update(self::ACCOUNTS, $data, ['id_empleado' => $employeeId]);
      return;
    }
    $this->db->insert(self::ACCOUNTS, ['id_empleado' => $employeeId] + $data);
  }

  private function tokenSecret(): string
  {
    return trim((string) ($this->config['google_token_key'] ?? ''));
  }

  private function encrypt(string $value): ?string
  {
    if ($value === '') {
      return null;
    }
    $iv = random_bytes(16);
    $cipher = openssl_encrypt($value, 'AES-256-CBC', hash('sha256', $this->tokenSecret(), true), OPENSSL_RAW_DATA, $iv);
    if ($cipher === false) {
      throw new \RuntimeException('No se pudo cifrar el token de Google.');
    }
    return base64_encode($iv . $cipher);
  }

  private function decrypt(string $value): ?string
  {
    $binary = base64_decode(trim($value), true);
    if ($binary === false || strlen($binary) <= 16 || $this->tokenSecret() === '') {
      return null;
    }
    $plain = openssl_decrypt(substr($binary, 16), 'AES-256-CBC', hash('sha256', $this->tokenSecret(), true), OPENSSL_RAW_DATA, substr($binary, 0, 16));
    return $plain === false ? null : $plain;
  }

  /** @param array<string,mixed> $payload @return array<string,mixed> */
  private function requestToken(array $payload): array
  {
    $response = $this->httpRequest('POST', self::TOKEN_URL, [], $payload, true);
    if (empty($response['access_token'])) {
      throw new \RuntimeException('Google no devolvió access_token.');
    }
    return $response;
  }

  /**
   * @param array<int,string> $headers
   * @param array<string,mixed> $body
   * @return array<string,mixed>
   */
  protected function httpRequest(string $method, string $url, array $headers, array $body, bool $formEncoded): array
  {
    $payload = $formEncoded ? http_build_query($body) : (string) json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($formEncoded) {
      $headers[] = 'Content-Type: application/x-www-form-urlencoded';
    }
    $curl = curl_init($url);
    $options = [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => strtoupper($method), CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 20];
    if (strtoupper($method) !== 'GET') {
      $options[CURLOPT_POSTFIELDS] = $payload;
    }
    curl_setopt_array($curl, $options);
    $raw = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $error = curl_error($curl);
    if (!is_string($raw)) {
      throw new \RuntimeException('Error HTTP Google: ' . $error);
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
      throw new \RuntimeException('Google respondió un cuerpo inválido.');
    }
    if ($status >= 400) {
      $message = $decoded['error_description'] ?? ($decoded['error']['message'] ?? 'Error Google');
      throw new \RuntimeException(is_string($message) ? $message : 'Error Google');
    }
    return $decoded;
  }

  /** @param array<string,mixed> $reminder */
  private static function reminderMinutes(array $reminder): int
  {
    return max(0, min(1440, (int) ($reminder['meta']['aviso_minutos_antes'] ?? 30)));
  }

  /** @return array{dateTime:string,timeZone:string} */
  private static function googleTime(string $date): array
  {
    $date = trim($date);
    $value = $date === '' ? gmdate('c') : (new \DateTimeImmutable(str_replace('T', ' ', $date), new \DateTimeZone(self::TIMEZONE)))->format('c');
    return ['dateTime' => $value, 'timeZone' => self::TIMEZONE];
  }

  private static function addMinutes(string $date, int $minutes): string
  {
    return (new \DateTimeImmutable(str_replace('T', ' ', $date), new \DateTimeZone(self::TIMEZONE)))->modify('+' . $minutes . ' minutes')->format('Y-m-d H:i:s');
  }

  private function clientId(): string
  {
    return trim((string) ($this->config['google_client_id'] ?? ''));
  }

  private function clientSecret(): string
  {
    return trim((string) ($this->config['google_client_secret'] ?? ''));
  }

  private function redirectUri(): string
  {
    return trim((string) ($this->config['google_redirect_uri'] ?? ''));
  }
}
