<?php

declare(strict_types=1);

namespace SCM\Commercial\Promotion\Pop;

use SCM\Core\Database;
use SCM\Core\Settings;
use SCM\Support\EmailQueue;

/**
 * Alerta de stock bajo de Material POP: cuando una salida deja un producto en su stock mínimo (o lo agota) y antes
 * estaba por encima, encola un correo en shared-notifications para los funcionarios elegidos en Configuración ›
 * Material POP. Una sola alerta por producto y día (dedupe_key).
 */
class PopStockAlert
{
  private Database $db;
  private Settings $settings;

  public function __construct(Database $db, Settings $settings)
  {
    $this->db = $db;
    $this->settings = $settings;
  }

  /** Si pasar de $before a $after cruza el mínimo del producto (o lo agota). */
  public static function crosses(int $before, int $after, int $minimum): bool
  {
    if ($after >= $before) {
      return false;
    }
    return ($after === 0 && $before > 0) || ($minimum > 0 && $after <= $minimum && $before > $minimum);
  }

  /**
   * @param array<string,mixed> $product Fila del producto (name, unit, minimum_stock).
   * @return int Correos encolados.
   */
  public function notify(array $product, int $before, int $after): int
  {
    $minimum = (int) ($product['minimum_stock'] ?? 0);
    if (!self::crosses($before, $after, $minimum)) {
      return 0;
    }
    $config = PopConfig::read($this->settings);
    if (!$config['alert_enabled'] || $config['alert_employees'] === []) {
      return 0;
    }
    $emails = $this->emails($config['alert_employees']);
    if ($emails === []) {
      return 0;
    }
    $name = trim((string) ($product['name'] ?? 'Producto'));
    $unit = trim((string) ($product['unit'] ?? '')) ?: 'unidad';
    $state = $after === 0 ? 'se agotó' : 'llegó a su stock mínimo';
    $subject = 'Material POP: ' . $name . ' ' . $state;
    $e = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    $html = '<p>Hola,</p><p>El producto <strong>' . $e($name) . '</strong> ' . $e($state) . ' en el inventario de Material POP de SKC SuCasa Inmobiliaria.</p>'
      . '<ul><li>Existencias: <strong>' . $after . ' ' . $e($unit) . '</strong> (antes ' . $before . ')</li>'
      . ($minimum > 0 ? '<li>Stock mínimo: ' . $minimum . ' ' . $e($unit) . '</li>' : '') . '</ul>'
      . '<p>Revísalo en el panel comercial › Promoción › Material POP.</p>';
    $this->send($emails, $subject, $html, [
      'source_module' => 'control-servicios-comerciales-material-pop',
      'dedupe_key' => 'material-pop-stock:' . (int) ($product['id'] ?? 0) . ':' . date('Y-m-d'),
      'meta' => ['categoria_mensaje' => 'alerta', 'producto_id' => (int) ($product['id'] ?? 0), 'existencias' => $after],
    ]);
    return count($emails);
  }

  /**
   * @param list<string> $emails
   * @param array<string,mixed> $options
   */
  protected function send(array $emails, string $subject, string $html, array $options): void
  {
    (new EmailQueue($this->db))->enqueue($emails, $subject, $html, $options);
  }

  /**
   * Correos de los funcionarios activos elegidos.
   * @param list<string> $employeeIds
   * @return list<string>
   */
  private function emails(array $employeeIds): array
  {
    $table = $this->db->table('jet_cct_funcionarios');
    $rows = $this->db->getResults(
      "SELECT TRIM(COALESCE(correo, '')) AS correo, activo FROM `{$table}` WHERE id_empleado IN (" . implode(',', array_fill(0, count($employeeIds), '?')) . ')',
      $employeeIds
    );
    $out = [];
    foreach ($rows as $row) {
      $email = (string) ($row['correo'] ?? '');
      if (strtolower(trim((string) ($row['activo'] ?? ''))) === 'si' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $out[strtolower($email)] = $email;
      }
    }
    return array_values($out);
  }
}
