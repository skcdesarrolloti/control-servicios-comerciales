<?php

declare(strict_types=1);

namespace SCM\Commercial\Mandates;

use SCM\Core\Database;

/**
 * Tasas del mandato y crédito de la garantía de servicios públicos.
 *
 * Copia de formularios/comun/configuracion.php (construir_config_aseguradoras) y formularios/comun/creditos.php
 * (credito_calcular, credito_cuota_fija y credito_monto_form): mantener iguales. El servidor recalcula el crédito con
 * las tasas de wp_jet_cct_confi_sistema y no confía en lo que calculó el navegador.
 */
final class MandateCalculator
{
  public const CREDIT_REASON = 'Garantía servicios públicos';
  /** Orden de las aseguradoras en el formulario. */
  private const INSURERS = ['El libertador', 'Unifianza', 'Suramericana', 'Fianza bogota', 'Afiancol', 'Zurich', 'Seguro propio'];

  /** @var array<string,string>|null */
  private ?array $config = null;
  private ?Database $db;

  /** @param array<string,string>|null $config Configuración ya leída (pruebas); si no, se lee de la base al usarla. */
  public function __construct(?Database $db, ?array $config = null)
  {
    $this->db = $db;
    $this->config = $config;
  }

  /** @return array<string,string> funcion => valor de wp_jet_cct_confi_sistema */
  public function config(): array
  {
    if ($this->config === null) {
      $this->config = [];
      if ($this->db !== null) {
        foreach ($this->db->getResults('SELECT funcion, valor FROM `' . $this->db->table('jet_cct_confi_sistema') . '` WHERE funcion IS NOT NULL') as $row) {
          $this->config[trim((string) $row['funcion'])] = (string) ($row['valor'] ?? '');
        }
      }
    }
    return $this->config;
  }

  /**
   * Lo que necesita el formulario para calcular: IVA, tasas de comisión, cláusula penal, Berkley, Seguros mundial,
   * préstamo y las tasas de cada aseguradora (de seguro y de servicios).
   * @return array<string,mixed>
   */
  public function parameters(): array
  {
    $c = $this->config();
    $n = static fn(string $key, float $default = 0.0): float => self::number($c[$key] ?? '') ?: $default;
    $insurers = $this->insurers();
    return [
      'iva' => $n('iva', 19.0),
      'tasa_comision' => $n('tasa_administracion_arriendo'),
      'tasa_clausula_penal' => $n('tasa_clausula_penal'),
      'tasa_berkley' => $n('tasa_berkley'),
      'expedicion_berkley' => (float) (preg_replace('/\D/', '', (string) ($c['gasto_expedicion_berkley'] ?? '')) ?: 0),
      'tasa_seguros_mundial' => $n('tasa_seguros_mundial'),
      'expedicion_seguros_mundial' => (float) (preg_replace('/\D/', '', (string) ($c['gasto_expedicion_seguros_mundial'] ?? '')) ?: 0),
      'tasa_prestamo' => $n('tasa_prestamo_garantias_servicios'),
      'aseguradoras' => $insurers['aseguradoras'],
      'tasas_seguro' => array_map([self::class, 'number'], $insurers['tasas_seguro']),
      'tasas_servicios' => array_map([self::class, 'number'], $insurers['tasas_servicios']),
    ];
  }

  /**
   * Aseguradoras con sus tasas (tasa_<slug>_seguro y tasa_<slug>_servicios), como construir_config_aseguradoras.
   * @return array{aseguradoras:list<string>,tasas_seguro:array<string,string>,tasas_servicios:array<string,string>}
   */
  public function insurers(): array
  {
    $seguro = [];
    $servicios = [];
    $names = [];
    foreach ($this->config() as $key => $value) {
      // tasa_prestamo_garantias_servicios es la tasa del préstamo, no una aseguradora.
      if ($key === 'tasa_prestamo_garantias_servicios') {
        continue;
      }
      if (preg_match('/^tasa_(.+)_seguro$/', $key, $m) === 1) {
        $label = self::insurerLabel($m[1]);
        $names[$label] = true;
        $seguro[$label] = $value;
      } elseif (preg_match('/^tasa_(.+)_servicios$/', $key, $m) === 1) {
        $label = self::insurerLabel($m[1]);
        $names[$label] = true;
        $servicios[$label] = $value;
      }
    }
    $labels = $names === [] ? self::INSURERS : array_keys($names);
    $order = array_flip(self::INSURERS);
    usort($labels, static fn(string $a, string $b): int => [$order[$a] ?? 999, strtolower($a)] <=> [$order[$b] ?? 999, strtolower($b)]);
    return ['aseguradoras' => $labels, 'tasas_seguro' => $seguro, 'tasas_servicios' => $servicios];
  }

  private static function insurerLabel(string $slug): string
  {
    $slug = strtolower(trim($slug));
    $map = ['libertador' => 'El libertador', 'unifianza' => 'Unifianza', 'suramericana' => 'Suramericana', 'fianza_bogota' => 'Fianza bogota', 'afiancol' => 'Afiancol', 'zurich' => 'Zurich', 'seguro_propio' => 'Seguro propio'];
    return $map[$slug] ?? ucwords((string) preg_replace('/\s+/', ' ', str_replace('_', ' ', $slug)));
  }

  /**
   * Crédito de la garantía (o null si no se financia), como credito_calcular:
   * - Por defecto: con garantía de servicios en más de 4 cuotas. Prima = valor asegurado × tasa de servicios.
   * - Seguros mundial: siempre. Prima = ((canon + admin + IVA del canon) × 12 × tasa + expedición) + IVA.
   * @param array<string,mixed> $post Campos del formulario.
   * @return array<string,string>|null columnas de wp_jet_cct_creditos (sin fecha ni ids)
   */
  public function credit(array $post): ?array
  {
    $v = static fn(string $key): string => is_scalar($post[$key] ?? null) ? trim((string) $post[$key]) : '';
    $type = $v('tipo_contrato');
    if ($type === 'Por defecto') {
      if ($v('incluye_garantia_servicios') !== 'Si') {
        return null;
      }
      $installments = (int) $v('forma_pago_garantia_servicios');
      if ($installments <= 4) {
        return null;
      }
      $insurer = $v('aseguradora');
      $rates = $this->insurers()['tasas_servicios'];
      if ($insurer === '' || !isset($rates[$insurer])) {
        throw new \InvalidArgumentException('Para financiar la garantía de servicios públicos en ' . $installments . ' cuotas hace falta la aseguradora (en "Garantía inmobiliaria") y que tenga tasa de servicios configurada.');
      }
      $principal = round(self::money($v('valor_asegurado_garantia_servicios')) * self::number($rates[$insurer]) / 100, 2);
      if ($principal <= 0) {
        throw new \InvalidArgumentException('Escribe el valor asegurado de la garantía de servicios públicos para calcular su financiación.');
      }
      $reason = self::CREDIT_REASON . ' (' . $insurer . ')';
    } elseif ($type === 'Seguros mundial') {
      $c = $this->config();
      $installments = max(1, (int) $v('forma_pago_seguros_mundial'));
      $iva = self::number($c['iva'] ?? '19');
      $canon = self::money($v('precio_canon'));
      $canonTotal = round($canon + self::money($v('precio_administracion')));
      $ivaCanon = $v('tiene_iva_canon') === 'Si' ? round($canon * $iva / 100) : 0;
      $premium = ($canonTotal + $ivaCanon) * 12 * self::number($c['tasa_seguros_mundial'] ?? '0') / 100;
      if ($premium <= 0) {
        throw new \InvalidArgumentException('Para Seguros mundial hacen falta el canon y la tasa de Seguros mundial en la configuración.');
      }
      $issue = (float) (preg_replace('/\D/', '', (string) ($c['gasto_expedicion_seguros_mundial'] ?? '')) ?: 0);
      $withoutIva = $premium + $issue;
      $principal = round($withoutIva + round($withoutIva * $iva / 100, 2), 2);
      $reason = self::CREDIT_REASON . ' (seguros mundial)';
    } else {
      return null;
    }
    $loan = self::fixedInstallment($principal, $installments, self::number($this->config()['tasa_prestamo_garantias_servicios'] ?? '0'));
    return [
      'cuotas' => (string) $loan['cuotas'],
      'valor' => number_format($loan['total'], 2, '.', ''),
      'valor_mes' => number_format($loan['cuota'], 2, '.', ''),
      'tasa' => number_format($loan['tasa'], 5, '.', ''),
      'factor_tasa' => number_format($loan['factor'], 5, '.', ''),
      'intereses_generados' => number_format($loan['intereses'], 2, '.', ''),
      'motivo' => $reason,
    ];
  }

  /** @return array{cuotas:int,tasa:float,factor:float,cuota:float,total:float,intereses:float} */
  public static function fixedInstallment(float $principal, int $installments, float $monthlyRatePct): array
  {
    $i = $monthlyRatePct / 100;
    $factor = $i > 0 ? $i * (1 + $i) ** $installments / ((1 + $i) ** $installments - 1) : 1 / $installments;
    $installment = round($principal * $factor, 2);
    $total = round($installment * $installments, 2);
    return ['cuotas' => $installments, 'tasa' => round($i, 5), 'factor' => round($factor, 5), 'cuota' => $installment, 'total' => $total, 'intereses' => round($total - $principal, 2)];
  }

  /** Monto escrito en el formulario (es-CO: punto de miles, coma decimal). "1.800.000" → 1800000, "45.678,9" → 45678.9 */
  public static function money($value): float
  {
    $text = str_replace(',', '.', (string) preg_replace('/[^\d,\-]/', '', str_replace('.', '', (string) $value)));
    return is_numeric($text) ? (float) $text : 0.0;
  }

  /** Número de la configuración ("2.5", "2,5" o "50000"). */
  public static function number($value): float
  {
    $text = str_replace(',', '.', trim((string) $value));
    return is_numeric($text) ? (float) $text : 0.0;
  }
}
