<?php

declare(strict_types=1);

namespace SCM\Views;

use SCM\Commercial\Mandates\ClosingSheetsListing;
use SCM\Commercial\Mandates\MandateClosingSheet;

/**
 * Registros › Hojas de cierre y Mi portafolio › Mis cierres. Mismo diseño que Mandatos (clases de cotizaciones y
 * estudios). Cada hoja se abre en la ventana del panel con la página del panel inmobiliario (hoja-cierre.php?numero=).
 */
final class CommercialClosingSheetsView
{
  private const TONES = ['En verificacion de servicios' => 'wait', 'Esperando respuesta' => 'warn', 'No aprobada' => 'off', 'Aprobada' => 'ok', 'Realizada' => 'done', 'Desistida' => 'off'];
  private const LABELS = ['En verificacion de servicios' => 'En verificación de servicios'];

  private static function e($value): string
  {
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  }

  private static function icon(string $name): string
  {
    return '<span class="material-symbols-outlined" aria-hidden="true">' . $name . '</span>';
  }

  private static function label(string $state): string
  {
    return self::LABELS[$state] ?? $state;
  }

  /**
   * Abrir, editar, desistir e imprimir: todo en la ventana del panel con la hoja del panel inmobiliario (allí se aplican
   * sus permisos; editar y desistir solo en los estados que allá lo permiten).
   * @param array<string,mixed> $row
   */
  private static function actions(array $row): string
  {
    $id = (int) $row['id'];
    $title = 'Hoja de cierre #' . $id . ($row['mandato'] > 0 ? ' · Mandato #' . $row['mandato'] : '');
    $button = static fn(string $class, string $action, string $icon, string $label, string $prefix): string => '<button type="button" class="' . $class . ' cqt-small" data-cma-closing-sheet="' . $id . '"'
      . ($action !== '' ? ' data-cma-closing-action="' . $action . '"' : '') . ' data-cma-closing-title="' . self::e($prefix . $title) . '">' . self::icon($icon) . $label . '</button>';
    $open = in_array($row['estado'], MandateClosingSheet::EDITABLE, true);
    return $button(in_array($row['estado'], ['Esperando respuesta', 'No aprobada', 'En verificacion de servicios'], true) ? 'cqt-primary' : 'cqt-secondary', '', 'handshake', 'Abrir hoja', '')
      . ($open ? $button('cqt-secondary', 'editar', 'edit', 'Editar', 'Editar · ') : '')
      . $button('cqt-secondary', 'imprimir', 'print', 'Imprimir', '')
      . ($open ? $button('cqt-secondary cma-danger', 'desistir', 'block', 'Desistir', 'Desistir negocio · ') : '');
  }

  /** @param array<string,mixed> $input */
  public static function page(array $input, ClosingSheetsListing $service): string
  {
    $listing = $service->listing($input);
    $f = $listing['filters'];
    $html = '<section class="cqt cqt-page cst" data-ccs-page>'
      . '<header class="cqt-head"><span class="scm-head-icon" aria-hidden="true"><span class="material-symbols-outlined">handshake</span></span><div><p class="cqt-eyebrow">' . ($listing['mine'] ? 'Mi portafolio' : 'Actividades comerciales · Registros') . '</p><h1>' . ($listing['mine'] ? 'Mis cierres' : 'Hojas de cierre') . '</h1><p>'
      . ($listing['mine'] ? 'Las hojas de cierre que has hecho.' : 'Las hojas de cierre de todo el equipo.')
      . ' Se crean desde el mandato (Mandatos o la tarea). La revisión de servicios, la aprobación y lo demás se hacen en la misma hoja, con los permisos y políticas del panel inmobiliario.</p></div></header>'
      . '<form class="cqt-filters cqt-list-filters" data-ccs-list-filters aria-label="Filtros de hojas de cierre">'
      . ($listing['mine'] ? '<input type="hidden" name="portafolio" value="1">' : '')
      . '<input type="hidden" name="hc_estado" value="' . self::e($f['estado']) . '">'
      . '<label class="cqt-field cqt-wide"><span>Buscar</span><input type="search" name="hc_q" value="' . self::e($f['q']) . '" placeholder="Número de hoja, mandato, inmueble, dirección, propietario o arrendatario"></label>';
    if ($listing['team'] && !$listing['mine']) {
      $html .= '<label class="cqt-field"><span>Consultor/a</span><select name="hc_empleado"><option value="">Todo el equipo</option>';
      foreach ($service->creators() as $id => $name) {
        $html .= '<option value="' . self::e($id) . '"' . ((string) $id === $f['empleado'] ? ' selected' : '') . '>' . self::e($name) . '</option>';
      }
      $html .= '</select></label>';
    }
    return $html . '<div class="cqt-filter-actions"><button type="submit" class="cqt-primary">Filtrar</button><button type="button" class="cqt-secondary" data-ccs-list-clear>Limpiar</button></div>'
      . '</form><div data-ccs-list aria-live="polite">' . self::results($listing) . '</div></section>';
  }

  /** @param array<string,mixed> $listing */
  public static function results(array $listing): string
  {
    $current = $listing['filters']['estado'];
    $html = '<div class="cst-pills" role="group" aria-label="Estado de la hoja de cierre"><button type="button" class="cst-pill" data-ccs-state="" aria-pressed="' . ($current === '' ? 'true' : 'false') . '">Todas <strong>' . number_format(array_sum($listing['estados']), 0, ',', '.') . '</strong></button>';
    foreach (ClosingSheetsListing::STATES as $state) {
      $html .= '<button type="button" class="cst-pill cst-pill--' . self::TONES[$state] . '" data-ccs-state="' . self::e($state) . '" aria-pressed="' . ($current === $state ? 'true' : 'false') . '">' . self::e(self::label($state)) . ' <strong>' . number_format((int) ($listing['estados'][$state] ?? 0), 0, ',', '.') . '</strong></button>';
    }
    $html .= '</div>';
    if ($listing['rows'] === []) {
      return $html . '<p class="cqt-empty">No hay hojas de cierre con estos filtros.</p>';
    }
    $html .= '<div class="cqt-table-wrap"><table class="cqt-table"><thead><tr><th>Hoja</th><th>Tarea</th><th>Propietario y arrendatario</th><th>Inmueble</th><th>Revisión y respuesta</th><th>Consultor/a</th><th><span class="sr-only">Acciones</span></th></tr></thead><tbody>';
    foreach ($listing['rows'] as $row) {
      $id = (int) $row['id'];
      $html .= '<tr>'
        . '<td data-label="Hoja"><strong>#' . $id . '</strong><small>' . self::e($row['fecha']) . ($row['mandato'] > 0 ? ' · Mandato #' . $row['mandato'] : '') . '</small>'
        . '<span class="cst-state cst-state--' . (self::TONES[$row['estado']] ?? 'wait') . '">' . self::e(self::label($row['estado']) ?: 'Sin estado') . '</span></td>'
        . '<td data-label="Tarea">' . ($row['tarea'] > 0 ? (!empty($listing['can_task']) ? '<button type="button" class="cqt-link" data-commercial-open-case="' . $row['tarea'] . '">#' . $row['tarea'] . '</button>' : '#' . $row['tarea']) : '<span class="cqt-muted">Sin tarea</span>') . '</td>'
        . '<td data-label="Propietario"><strong>' . self::e($row['propietario'] ?: 'Sin propietario') . '</strong><small>Arrendatario: ' . self::e($row['arrendatario'] ?: 'sin definir') . '</small></td>'
        . '<td data-label="Inmueble">' . ($row['inmueble'] !== ''
          ? '<button type="button" class="cqt-link cst-property-link" data-commercial-property-detail="' . self::e($row['inmueble']) . '" title="Ver la ficha del inmueble">' . self::icon('home') . '#' . self::e($row['inmueble']) . '</button>'
          : '<span class="cqt-muted">Sin inmueble</span>') . '<small>' . self::e($row['direccion']) . '</small></td>'
        . '<td data-label="Revisión">' . ($row['verificador'] !== '' ? '<small>Revisó servicios: ' . self::e($row['verificador']) . '</small>' : '<small>Sin revisión de servicios</small>')
        . ($row['aprobador'] !== '' ? '<small>Respondió: ' . self::e($row['aprobador']) . '</small>' : '') . '</td>'
        . '<td data-label="Consultor/a">' . self::e($row['consultor'] ?: 'Sin consultor/a') . '</td>'
        . '<td data-label="Acciones"><div class="cqt-row-actions cst-row-actions">'
        . self::actions($row)
        . '</div></td></tr>';
    }
    $html .= '</tbody></table></div>';
    $pages = (int) ceil($listing['total'] / $listing['per_page']);
    if ($pages > 1) {
      $page = $listing['page'];
      $html .= '<nav class="cqt-pager" aria-label="Páginas de hojas de cierre"><button type="button" class="cqt-secondary" data-ccs-list-page="' . ($page - 1) . '"' . ($page <= 1 ? ' disabled' : '') . '>Anterior</button><span>Página ' . $page . ' de ' . $pages . '</span><button type="button" class="cqt-secondary" data-ccs-list-page="' . ($page + 1) . '"' . ($page >= $pages ? ' disabled' : '') . '>Siguiente</button></nav>';
    }
    return $html;
  }
}
