<?php

declare(strict_types=1);

namespace SCM\Views;

use SCM\Commercial\Mandates\CommercialMandatesRepository;
use SCM\Commercial\Mandates\CommercialMandatesService;
use SCM\Support\PublicMandateAccess;

/**
 * Contratos de mandato en el panel (Actividades comerciales › Registros › Mandatos y Mi portafolio › Mis mandatos): el
 * listado con el estado del mandato y de la revisión del propietario, y el popup con la vista interna de mandato.php
 * (enviar al propietario, responder observaciones, editar y enviar a firma). Usa las clases de cotizaciones y estudios.
 */
final class CommercialMandatesView
{
  private const STATE_TONES = ['No enviado' => 'wait', 'Enviado' => 'warn', 'Firmado' => 'done'];

  private static function e($value): string
  {
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  }

  private static function icon(string $name): string
  {
    return '<span class="material-symbols-outlined" aria-hidden="true">' . $name . '</span>';
  }

  /** @param array<string,mixed> $input */
  public static function page(array $input, CommercialMandatesService $service): string
  {
    $listing = $service->listing($input);
    $f = $listing['filters'];
    $mine = $listing['mine'] || !$listing['team'];
    $html = '<section class="cqt cqt-page cst" data-cma-page>'
      . '<header class="cqt-head"><div><p class="cqt-eyebrow">Actividades comerciales · Registros</p><h1>' . ($listing['mine'] ? 'Mis mandatos' : 'Contratos de mandato') . '</h1><p>'
      . ($mine ? 'Los contratos de mandato que has creado.' : 'Los contratos de mandato de todo el equipo.')
      . ' Antes de enviarlo a firma, envía el contrato al propietario: lo revisa, deja observaciones por sección o lo aprueba. Se crean y se editan en los formularios de WordPress mientras se migran al panel.</p></div></header>'
      . '<form class="cqt-filters cqt-list-filters" data-cma-list-filters aria-label="Filtros de mandatos">'
      . ($listing['mine'] ? '<input type="hidden" name="portafolio" value="1">' : '')
      . '<input type="hidden" name="ma_estado" value="' . self::e($f['estado']) . '">'
      . '<label class="cqt-field cqt-wide"><span>Buscar</span><input type="search" name="ma_q" value="' . self::e($f['q']) . '" placeholder="Número de mandato, propietario, documento, inmueble o dirección"></label>';
    if ($listing['team'] && !$listing['mine']) {
      $html .= '<label class="cqt-field"><span>Consultor/a</span><select name="ma_empleado"><option value="">Todo el equipo</option>';
      foreach ($service->creators() as $id => $name) {
        $html .= '<option value="' . self::e($id) . '"' . ((string) $id === $f['empleado'] ? ' selected' : '') . '>' . self::e($name) . '</option>';
      }
      $html .= '</select></label>';
    }
    return $html . '<div class="cqt-filter-actions"><button type="submit" class="cqt-primary">Filtrar</button><button type="button" class="cqt-secondary" data-cma-list-clear>Limpiar</button></div>'
      . '</form><div data-cma-list aria-live="polite">' . self::results($listing) . '</div></section>';
  }

  /** @param array<string,mixed> $listing */
  public static function results(array $listing): string
  {
    $current = $listing['filters']['estado'];
    $html = '<div class="cst-pills" role="group" aria-label="Estado del mandato"><button type="button" class="cst-pill" data-cma-state="" aria-pressed="' . ($current === '' ? 'true' : 'false') . '">Todos <strong>' . number_format(array_sum($listing['estados']), 0, ',', '.') . '</strong></button>';
    foreach (CommercialMandatesRepository::STATES as $state) {
      $html .= '<button type="button" class="cst-pill cst-pill--' . self::STATE_TONES[$state] . '" data-cma-state="' . self::e($state) . '" aria-pressed="' . ($current === $state ? 'true' : 'false') . '">' . self::e($state === 'Enviado' ? 'Enviado a firma' : $state) . ' <strong>' . number_format((int) ($listing['estados'][$state] ?? 0), 0, ',', '.') . '</strong></button>';
    }
    $html .= '</div>';
    if ($listing['rows'] === []) {
      return $html . '<p class="cqt-empty">No hay mandatos con estos filtros.</p>';
    }
    $html .= '<div class="cqt-table-wrap"><table class="cqt-table"><thead><tr><th>Mandato</th><th>Propietario</th><th>Inmueble</th><th>Revisión del propietario</th><th>Consultor/a</th><th><span class="sr-only">Acciones</span></th></tr></thead><tbody>';
    foreach ($listing['rows'] as $row) {
      $id = (int) $row['id'];
      [$label, $tone] = CommercialMandatesService::REVIEW_STATES[$row['revision']] ?? ['', 'wait'];
      $html .= '<tr>'
        . '<td data-label="Mandato"><strong>#' . $id . '</strong><small>' . self::e($row['fecha']) . ($row['tipo'] !== '' ? ' · ' . self::e($row['tipo']) : '') . '</small>'
        . '<span class="cst-state cst-state--' . (self::STATE_TONES[$row['estado']] ?? 'wait') . '">' . self::e($row['estado'] === 'Enviado' ? 'Enviado a firma' : $row['estado']) . '</span></td>'
        . '<td data-label="Propietario"><strong>' . self::e($row['propietarios'] !== [] ? implode(', ', $row['propietarios']) : 'Sin nombre') . '</strong><small>' . self::e($row['documento']) . '</small></td>'
        . '<td data-label="Inmueble">' . ($row['inmueble'] !== ''
          ? '<button type="button" class="cqt-link cst-property-link" data-commercial-property-detail="' . self::e($row['inmueble']) . '" title="Ver la ficha del inmueble">' . self::icon('home') . '#' . self::e($row['inmueble']) . '</button>'
          : '<span class="cqt-muted">Sin inmueble</span>') . '<small>' . self::e($row['direccion']) . '</small>' . ($row['canon'] !== '' ? '<small>Canon ' . self::e($row['canon']) . '</small>' : '') . '</td>'
        . '<td data-label="Revisión"><span class="cst-state cst-state--' . self::e($tone) . '">' . self::e($label) . '</span>'
        . ($row['pendientes'] > 0 ? '<small>' . $row['pendientes'] . ' observación(es) pendiente(s)</small>' : '') . ($row['envios'] > 1 ? '<small>Enviado ' . $row['envios'] . ' veces</small>' : '') . '</td>'
        . '<td data-label="Consultor/a">' . self::e($row['consultor'] ?: 'Sin consultor/a') . '</td>'
        . '<td data-label="Acciones"><div class="cqt-row-actions cst-row-actions">'
        . '<button type="button" class="' . ($row['revision'] === 'observaciones' || $row['revision'] === 'sin_enviar' ? 'cqt-primary' : 'cqt-secondary') . ' cqt-small" data-cma-view="' . $id . '">' . self::icon($row['revision'] === 'observaciones' ? 'rate_review' : 'visibility') . ($row['revision'] === 'observaciones' ? 'Revisar' : 'Ver') . '</button>'
        . ($row['pdf'] !== '' ? '<a class="cqt-secondary cqt-small" href="' . self::e($row['pdf']) . '" target="_blank" rel="noopener">' . self::icon('picture_as_pdf') . 'PDF firmado</a>' : '')
        . '</div></td></tr>';
    }
    $html .= '</tbody></table></div>';
    $pages = (int) ceil($listing['total'] / $listing['per_page']);
    if ($pages > 1) {
      $page = $listing['page'];
      $html .= '<nav class="cqt-pager" aria-label="Páginas de mandatos"><button type="button" class="cqt-secondary" data-cma-list-page="' . ($page - 1) . '"' . ($page <= 1 ? ' disabled' : '') . '>Anterior</button><span>Página ' . $page . ' de ' . $pages . '</span><button type="button" class="cqt-secondary" data-cma-list-page="' . ($page + 1) . '"' . ($page >= $pages ? ' disabled' : '') . '>Siguiente</button></nav>';
    }
    return $html;
  }

  /** Popup con la vista interna de mandato.php (revisión del propietario y acciones del equipo). */
  public static function viewer(int $id): string
  {
    $staff = PublicMandateAccess::staffUrl($id);
    return '<div class="cqt-viewer-box" role="document"><header class="cqt-viewer-head"><h2 id="cma-viewer-title">Contrato de mandato #' . $id . '</h2><div class="cqt-viewer-actions">'
      . '<a class="cqt-secondary" href="' . self::e($staff) . '" target="_blank" rel="noopener">' . self::icon('open_in_new') . 'Abrir</a>'
      . '<button type="button" class="cqt-icon" data-cma-viewer-close aria-label="Cerrar">' . self::icon('close') . '</button></div></header>'
      . '<iframe class="cqt-frame" src="' . self::e($staff . '&embed=1') . '" title="Contrato de mandato #' . $id . '"></iframe></div>';
  }
}
