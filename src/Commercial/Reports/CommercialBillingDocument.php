<?php

declare(strict_types=1);

namespace SCM\Commercial\Reports;

use SCM\Support\HtmlPdfRenderer;

/**
 * Cuenta de cobro comercial en PDF (con el membrete institucional) y Excel. Lógica traída de
 * control-servicios-inmobiliarios/src/Modules/AdministrativeReports/BillingAccountDocument.php.
 */
final class CommercialBillingDocument
{
  /** @param array<string,mixed> $account @param array{nombre:string,documento:string,correo:string} $employee */
  public static function pdf(array $account, array $employee): string
  {
    $css = dirname(__DIR__, 3) . '/public/assets/css/';
    $bytes = HtmlPdfRenderer::render(self::html($account, $employee), 'Cuenta de cobro ' . $account['numero'], [
      'stylesheets' => [$css . 'ticket-completion-document.css', $css . 'public-services-document.css'],
      'external_fonts' => false, 'body_class' => 'scm-services-page scm-services-pdf',
      'wrapper_class' => 'scm-services-pdf-root', 'container_class' => 'scm-services-public-root', 'allow_fallback' => false,
    ]);
    if ($bytes === null) {
      throw new \DomainException('No se pudo generar el PDF de la cuenta de cobro. Verifica el servicio de PDF e intenta nuevamente.');
    }
    return $bytes;
  }

  private static function e(mixed $value): string
  {
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  }

  private static function money(int $value): string
  {
    return '$' . number_format($value, 0, ',', '.');
  }

  private static function date(string $value): string
  {
    $ts = $value !== '' ? strtotime($value) : false;
    return $ts ? date('d/m/Y', $ts) : $value;
  }

  /** @param array<string,mixed> $account @param array{nombre:string,documento:string,correo:string} $employee */
  public static function html(array $account, array $employee): string
  {
    $assets = dirname(__DIR__, 3) . '/public/assets/img/';
    $top = 'data:image/jpeg;base64,' . base64_encode((string) file_get_contents($assets . 'membrete-sucasa-header.jpg'));
    $bottom = 'data:image/jpeg;base64,' . base64_encode((string) file_get_contents($assets . 'membrete-sucasa-footer.jpg'));
    $name = $employee['nombre'] !== '' ? $employee['nombre'] : (string) $account['funcionario'];
    $document = $employee['documento'] !== '' ? $employee['documento'] : (string) $account['documento'];
    $rows = '';
    foreach ((array) $account['reportes'] as $report) {
      $rows .= '<tr><td>#' . (int) $report['id'] . '</td><td>' . ($report['fecha'] > 0 ? date('d/m/Y', (int) $report['fecha']) : '') . '</td><td>' . self::e($report['tipo']) . '</td><td>'
        . self::e($report['id_ticket'] !== '' ? '#' . $report['id_ticket'] : '') . '</td><td>' . self::e($report['id_inmueble']) . '</td><td>' . self::e(self::money((int) $report['valor'])) . '</td></tr>';
    }
    return '<style>@page{size:letter;margin:12mm 18mm 14mm;@bottom-left{content:none}@bottom-center{content:none}}'
      . '.scm-billing-detail{width:100%;border-collapse:collapse;font-size:8.5pt}.scm-billing-detail th,.scm-billing-detail td{border:1px solid #d7deea;padding:4px 5px;text-align:left}'
      . '.scm-billing-detail thead th{background:#f1f4f9}.scm-billing-detail tr{break-inside:avoid;page-break-inside:avoid}'
      . '.scm-billing-table{width:100%;border-collapse:collapse;font-size:10pt}.scm-billing-table th,.scm-billing-table td{border:1px solid #d7deea;padding:5px 7px;text-align:left}'
      . '.scm-billing-total{margin:10px 0 0;padding:10px 12px;border:1px solid #061d49;border-radius:6px;text-align:center;font-weight:700;font-size:11pt}'
      . '.scm-billing-sign{margin-top:28px;break-inside:avoid;page-break-inside:avoid}.scm-billing-sign span{display:block;width:62mm;border-top:1px solid #061d49;margin-bottom:4px}</style>'
      . '<article class="scm-acta-receipt scm-services-document"><div class="scm-services-letterhead-top"><img src="' . $top . '" alt="Membrete institucional"></div>'
      . '<h1>Cuenta de cobro No. ' . self::e($account['numero']) . '</h1>'
      . '<p class="scm-acta-receipt-date">Cartagena de Indias, ' . self::e(self::date((string) $account['fecha_creacion'])) . '</p>'
      . '<section class="scm-acta-receipt-section"><h2>1. Debe a</h2><table class="scm-billing-table"><tr><th>SKC SuCasa Inmobiliaria</th><td>' . self::e(CommercialReportsService::COMPANY_NAME) . '</td></tr><tr><th>NIT</th><td>' . self::e(CommercialReportsService::COMPANY_NIT) . '</td></tr></table></section>'
      . '<section class="scm-acta-receipt-section"><h2>2. A favor de</h2><table class="scm-billing-table"><tr><th>Nombre</th><td>' . self::e($name) . '</td></tr><tr><th>Documento</th><td>' . self::e($document) . '</td></tr></table></section>'
      . '<section class="scm-acta-receipt-section"><h2>3. Concepto</h2><p>Transporte y actividades comerciales del período del ' . self::e(self::date((string) $account['fecha_inicio'])) . ' al ' . self::e(self::date((string) $account['fecha_fin'])) . ', con ' . count((array) $account['reportes']) . ' reporte(s).</p>'
      . '<p class="scm-billing-total">VALOR TOTAL A COBRAR: ' . self::e(self::money((int) $account['total'])) . ' COP</p></section>'
      . ($rows !== '' ? '<section class="scm-acta-receipt-section"><h2>4. Detalle de reportes</h2><table class="scm-billing-detail"><thead><tr><th>Consecutivo</th><th>Fecha</th><th>Tipo</th><th>Tarea</th><th>Inmueble</th><th>Valor</th></tr></thead><tbody>' . $rows . '</tbody></table></section>' : '')
      . '<div class="scm-billing-sign"><p>Firma:</p><br><br><span></span><strong>' . self::e(mb_strtoupper($name, 'UTF-8')) . '</strong><p>Documento: ' . self::e($document) . '</p></div>'
      . '<div class="scm-services-letterhead-bottom"><img src="' . $bottom . '" alt=""></div></article>';
  }

  /** Detalle en Excel (HTML tabular .xls). Neutraliza fórmulas en lo que escribieron los funcionarios. */
  public static function excel(array $account, array $employee): string
  {
    $cell = static function (mixed $value): string {
      $text = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string) $value), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
      if ($text !== '' && preg_match('/^[=+\-@]/', $text) === 1) {
        $text = "'" . $text;
      }
      return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    };
    $out = "\xEF\xBB\xBF" . '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>table{border-collapse:collapse}th,td{border:1px solid #b7b7b7;padding:6px}th{background:#f1f4f9}</style></head><body>';
    $out .= '<table><tr><th colspan="6">CUENTA DE COBRO ' . $cell($account['numero']) . ' · ' . $cell($employee['nombre'] !== '' ? $employee['nombre'] : $account['funcionario']) . '</th></tr><tr>';
    foreach (['Consecutivo', 'Fecha', 'Tipo', 'Tarea', 'Inmueble', 'Valor'] as $heading) {
      $out .= '<th>' . $heading . '</th>';
    }
    $out .= '</tr>';
    foreach ((array) $account['reportes'] as $report) {
      $out .= '<tr>';
      foreach ([$report['id'], $report['fecha'] > 0 ? date('d-m-Y', (int) $report['fecha']) : '', $report['tipo'], $report['id_ticket'], $report['id_inmueble'], self::money((int) $report['valor'])] as $value) {
        $out .= '<td>' . $cell($value) . '</td>';
      }
      $out .= '</tr>';
    }
    return $out . '<tr><th colspan="5">TOTAL</th><th>' . self::money((int) $account['total']) . '</th></tr></table></body></html>';
  }
}
