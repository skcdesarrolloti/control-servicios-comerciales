<?php

declare(strict_types=1);

namespace SCM\Core;

/**
 * Autologin opcional mediante enlace firmado.
 *
 * Firma esperada (hex): HMAC-SHA256("usuario|expira", AUTO_LOGIN_SECRET).
 */
final class SignedAutoLogin
{
  /** @param array<string,mixed> $query */
  public static function attempt(Auth $auth, array $query, array $config): bool
  {
    if (Auth::isLoggedIn() || empty($config['auto_login_enabled'])) {
      return Auth::isLoggedIn();
    }

    $user = trim((string) ($query['auto_user'] ?? ''));
    $expires = (int) ($query['auto_expires'] ?? 0);
    $signature = strtolower(trim((string) ($query['auto_signature'] ?? '')));
    $secret = (string) ($config['auto_login_secret'] ?? '');
    $ttl = max(60, (int) ($config['auto_login_ttl'] ?? 300));
    $now = time();

    if ($user === '' || $expires <= 0 || $signature === '' || strlen($secret) < 32) {
      return false;
    }
    if ($expires < $now || $expires > ($now + $ttl)) {
      return false;
    }
    if (preg_match('/^[a-f0-9]{64}$/', $signature) !== 1) {
      return false;
    }

    $expected = hash_hmac('sha256', $user . '|' . $expires, $secret);
    if (!hash_equals($expected, $signature)) {
      return false;
    }

    return $auth->attemptTrustedUser($user);
  }
}
