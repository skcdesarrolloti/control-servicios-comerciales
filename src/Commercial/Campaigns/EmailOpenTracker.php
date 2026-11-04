<?php

declare(strict_types=1);

namespace SCM\Commercial\Campaigns;

use SCM\Core\Database;

/**
 * Aperturas de los correos de campaña (public/track-email.php). Copia de dashboard-marketing Router::trackEmail sobre
 * la misma tabla jet_cct_email_tracking: cada token es de un mensaje; se cuenta cada apertura y se guarda la primera y la
 * última. La IP se guarda sin su último bloque (como en la analítica: no hace falta la exacta).
 */
final class EmailOpenTracker
{
  /** GIF transparente de 1×1. */
  public const PIXEL = 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';

  private Database $db;

  public function __construct(Database $db)
  {
    $this->db = $db;
  }

  /** @return bool Si el token existía. */
  public function record(string $token, string $ip, string $userAgent, ?string $now = null): bool
  {
    if (preg_match('/^[a-f0-9]{32}$/', $token) !== 1) {
      return false;
    }
    $now = $now ?? gmdate('Y-m-d H:i:s');
    $table = $this->db->table('jet_cct_email_tracking');
    $statement = $this->db->pdo()->prepare("UPDATE `{$table}` SET open_count = COALESCE(open_count, 0) + 1, opened_at = COALESCE(opened_at, ?), last_opened_at = ?, last_ip = ?, last_user_agent = ? WHERE tracking_token = ?");
    $statement->execute([$now, $now, self::maskIp($ip), mb_substr($userAgent, 0, 255, 'UTF-8'), $token]);
    return $statement->rowCount() > 0;
  }

  public static function maskIp(string $ip): string
  {
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
      return (string) preg_replace('/\.\d+$/', '.0', $ip);
    }
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
      return implode(':', array_slice(explode(':', $ip), 0, 3)) . '::';
    }
    return '';
  }
}
