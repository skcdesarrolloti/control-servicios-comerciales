<?php

declare(strict_types=1);

namespace SCM\Views\Promotion;

use SCM\Commercial\CommercialAccessPolicy;
use SCM\Commercial\Promotion\Pop\PopInventoryService;
use SCM\Core\Database;
use SCM\Core\Settings;

/**
 * Promoción › Material POP. Copia/adaptación de dashboard-marketing views/inventory.php con el diseño comercial: tres
 * secciones (Existencias, Entregas y Movimientos), filtros que se aplican con un botón y los formularios en ventanas
 * internas (<dialog>) que guardan por AJAX sin salir de la pantalla. Los botones salen según los permisos
 * (pop_gestionar, pop_anular) y los precios y el valor del inventario solo con pop_ver_costos: sin él no llegan al HTML.
 */
final class MaterialPopView
{
  public const SECTIONS = ['existencias' => 'Existencias', 'entregas' => 'Entregas', 'movimientos' => 'Movimientos'];
  private const STOCK_STATES = ['bajo' => 'Stock bajo', 'agotados' => 'Agotados', 'archivados' => 'Archivados', 'todos' => 'Todos'];
  private const ORIGINS = ['comercial' => 'Panel comercial', 'dashboard' => 'Panel de marketing', 'pph' => 'Club PPH', 'api' => 'Integración'];

  /**
   * @param array<string,mixed> $input
   * @param PopInventoryService|null $service Para las pruebas; por defecto el del panel.
   * @return array<string,mixed>
   */
  public static function context(array $input, CommercialAccessPolicy $policy, Database $db, Settings $settings, ?PopInventoryService $service = null): array
  {
    $service ??= new PopInventoryService($db, $settings);
    $section = (string) ($input['pr_vista'] ?? '');
    $section = isset(self::SECTIONS[$section]) ? $section : 'existencias';
    $filters = [
      'q' => mb_substr(trim((string) ($input['pr_q'] ?? '')), 0, 80, 'UTF-8'),
      'estado' => trim((string) ($input['pr_estado'] ?? '')),
      'desde' => trim((string) ($input['pr_desde'] ?? '')),
      'hasta' => trim((string) ($input['pr_hasta'] ?? '')),
      'producto' => (string) max(0, (int) ($input['pr_producto'] ?? 0)),
      'origen' => trim((string) ($input['pr_origen'] ?? '')),
      'tipo' => trim((string) ($input['pr_tipo'] ?? '')),
    ];
    $context = [
      'section' => $section, 'filters' => $filters, 'available' => $service->available(), 'sync' => null, 'sync_error' => '',
      'can_manage' => $policy->canAct('pop_gestionar'), 'can_void' => $policy->canAct('pop_anular'), 'can_costs' => $policy->canAct('pop_ver_costos'),
    ];
    if (!$context['available']) {
      return $context;
    }
    try {
      $context['sync'] = $service->syncFromPph();
    } catch (\Throwable $exception) {
      error_log('[commercial-material-pop] sincronización PPH: ' . $exception->getMessage());
      $context['sync_error'] = 'No se pudo sincronizar con el Club PPH; se muestran los datos guardados.';
    }
    $stock = $service->stock($section === 'existencias' ? $filters : ['estado' => 'todos']);
    $context['summary'] = $stock['summary'];
    $context['products'] = $section === 'existencias' ? $stock['products'] : [];
    $context['product_options'] = $service->productOptions();
    $context['options'] = $service->filterOptions();
    $context['categories'] = $service->categories();
    $context['deliveries'] = $section === 'entregas' ? $service->deliveries($filters) : [];
    $context['movements'] = $section === 'movimientos' ? $service->movements($filters) : [];
    $context['employees'] = $context['can_manage'] ? self::employees($db) : [];
    if (!$context['can_costs']) {
      // Sin el permiso los precios no salen del servidor.
      $context['summary']['valor'] = null;
      $context['products'] = array_map(static fn(array $row): array => ['price' => null] + array_diff_key($row, ['price' => true]), $context['products']);
    }
    return $context;
  }

  /** @param array<string,mixed> $context */
  public static function render(array $context, string $baseUrl): string
  {
    $html = PromotionUi::hero('inventory_2', 'Material POP', 'Souvenirs, merchandising y premios del Club PPH de SKC SuCasa Inmobiliaria: existencias, entregas con evidencia y el historial de cada movimiento.');
    $html .= '<div class="cpr-live" data-cpr-live role="status" aria-live="polite"></div>';
    if (!$context['available']) {
      return $html . '<p class="cpr-alert is-error">Las tablas del inventario (wp_marketing_inventory_*) no existen todavía en esta base.</p>';
    }
    if ($context['sync_error'] !== '') {
      $html .= '<p class="cpr-alert is-error">' . PromotionUi::e($context['sync_error']) . '</p>';
    } elseif (is_array($context['sync']) && array_sum($context['sync']) > 0) {
      $sync = $context['sync'];
      $html .= '<p class="cpr-note"><span class="material-symbols-outlined" aria-hidden="true">sync</span>Club PPH al día: ' . (int) $sync['products_created'] . ' productos nuevos, '
        . (int) $sync['products_updated'] . ' actualizados y ' . (int) $sync['deliveries_imported'] . ' entregas importadas.</p>';
    }
    $summary = $context['summary'];
    $html .= '<div class="cpr-stats" data-pop-stats>'
      . PromotionUi::stat('Unidades disponibles', PromotionUi::number($summary['unidades']), 'En productos activos', 'inventory')
      . PromotionUi::stat('Productos activos', PromotionUi::number($summary['activos']), 'De ' . PromotionUi::number($summary['total']) . ' registrados', 'category')
      . PromotionUi::stat('Stock bajo', PromotionUi::number($summary['bajos']), 'En su mínimo o por debajo', 'trending_down')
      . PromotionUi::stat('Agotados', PromotionUi::number($summary['agotados']), 'Sin unidades', 'remove_shopping_cart')
      . ($summary['valor'] !== null ? PromotionUi::stat('Valor del inventario', '$' . PromotionUi::number((float) $summary['valor']), 'Existencias × precio', 'payments') : '')
      . '</div>';
    $html .= self::toolbar($context, $baseUrl);
    $html .= match ($context['section']) {
      'entregas' => self::deliveries($context, $baseUrl),
      'movimientos' => self::movements($context, $baseUrl),
      default => self::stock($context, $baseUrl),
    };
    return $html . self::dialogs($context);
  }

  /** @param array<string,mixed> $context */
  private static function toolbar(array $context, string $baseUrl): string
  {
    $tabs = '';
    foreach (self::SECTIONS as $key => $label) {
      $active = $context['section'] === $key;
      $tabs .= '<a class="cpr-tab' . ($active ? ' is-active' : '') . '" data-cpr-nav href="' . PromotionUi::e(PromotionUi::url($baseUrl, ['tab' => 'material_pop', 'pr_vista' => $key === 'existencias' ? '' : $key])) . '"' . ($active ? ' aria-current="page"' : '') . '>' . PromotionUi::e($label) . '</a>';
    }
    $actions = '';
    if ($context['can_manage']) {
      $deliverable = array_filter($context['product_options'], static fn(array $row): bool => (int) $row['active'] === 1 && (int) $row['quantity'] > 0) !== [];
      $actions = '<button type="button" class="cpr-ghost" data-pop-sync><span class="material-symbols-outlined" aria-hidden="true">sync</span>Sincronizar PPH</button>'
        . '<button type="button" class="cpr-ghost" data-pop-open="product"><span class="material-symbols-outlined" aria-hidden="true">add</span>Agregar producto</button>'
        . '<button type="button" class="cpr-primary" data-pop-open="delivery"' . ($deliverable ? '' : ' disabled title="No hay productos con existencias"') . '><span class="material-symbols-outlined" aria-hidden="true">local_shipping</span>Nueva entrega</button>';
    }
    return '<div class="cpr-toolbar"><nav class="cpr-tabs" aria-label="Secciones de Material POP">' . $tabs . '</nav>' . ($actions !== '' ? '<div class="cpr-toolbar__actions">' . $actions . '</div>' : '') . '</div>';
  }

  /** @param array<string,mixed> $context $fields: campos ya armados. */
  private static function filtersForm(array $context, string $baseUrl, string $fields, string $hint): string
  {
    $section = $context['section'];
    return '<form class="cpr-card cpr-filters" data-cpr-filters data-tab="material_pop" action="' . PromotionUi::e(PromotionUi::url($baseUrl, ['tab' => 'material_pop'])) . '">'
      . ($section !== 'existencias' ? '<input type="hidden" name="pr_vista" value="' . PromotionUi::e($section) . '">' : '')
      . '<div class="cpr-filters__grid is-wide">' . $fields . '</div><div class="cpr-filters__actions"><a class="cpr-ghost" data-cpr-nav href="'
      . PromotionUi::e(PromotionUi::url($baseUrl, ['tab' => 'material_pop', 'pr_vista' => $section === 'existencias' ? '' : $section])) . '">Limpiar</a>'
      . '<button type="submit" class="cpr-primary"><span class="material-symbols-outlined" aria-hidden="true">filter_alt</span>Aplicar</button></div>'
      . '<p class="cpr-filters__hint">' . PromotionUi::e($hint) . '</p></form>';
  }

  private static function search(string $value, string $placeholder): string
  {
    return '<label class="cpr-field"><span class="cpr-label">Buscar</span><input type="search" name="pr_q" value="' . PromotionUi::e($value) . '" maxlength="80" placeholder="' . PromotionUi::e($placeholder) . '"></label>';
  }

  /** @param array<string,mixed> $context */
  private static function productSelect(array $context): string
  {
    $options = [];
    $labels = [];
    foreach ($context['product_options'] as $row) {
      $options[] = (string) $row['id'];
      $labels[(string) $row['id']] = (string) $row['name'] . ((int) $row['active'] === 1 ? '' : ' (archivado)');
    }
    return PromotionUi::select('pr_producto', 'Producto', $options, $context['filters']['producto'] === '0' ? '' : $context['filters']['producto'], 'Todos los productos', $labels);
  }

  /** @param array<string,mixed> $context */
  private static function dates(array $context): string
  {
    $field = static fn(string $name, string $label, string $value): string => '<label class="cpr-field"><span class="cpr-label">' . $label . '</span><input type="date" name="' . $name . '" value="' . PromotionUi::e($value) . '"></label>';
    return $field('pr_desde', 'Desde', $context['filters']['desde']) . $field('pr_hasta', 'Hasta', $context['filters']['hasta']);
  }

  /** @param list<string> $values @return array<string,string> */
  private static function originLabels(array $values): array
  {
    $labels = [];
    foreach ($values as $value) {
      $labels[$value] = self::ORIGINS[$value] ?? strtoupper($value);
    }
    return $labels;
  }

  /** @param array<string,mixed> $context */
  private static function stock(array $context, string $baseUrl): string
  {
    $filters = $context['filters'];
    $html = self::filtersForm($context, $baseUrl, self::search($filters['q'], 'Nombre, categoría o SKU')
      . PromotionUi::select('pr_estado', 'Estado', array_keys(self::STOCK_STATES), $filters['estado'], 'Activos', self::STOCK_STATES), 'Por defecto se muestran los productos activos.');
    $cards = '';
    foreach ($context['products'] as $product) {
      $cards .= self::productCard($product, $context, $baseUrl);
    }
    return $html . '<section class="cpr-card" data-pop-products><div class="cpr-card__head"><div><h2>Existencias</h2><p>' . count($context['products']) . ' productos</p></div></div>'
      . ($cards === '' ? '<p class="cpr-empty">No hay productos con estos filtros.</p>' : '<div class="cpr-pop-grid">' . $cards . '</div>') . '</section>';
  }

  /** @param array<string,mixed> $product @param array<string,mixed> $context */
  private static function productCard(array $product, array $context, string $baseUrl): string
  {
    $e = static fn($value): string => PromotionUi::e($value);
    $quantity = (int) $product['quantity'];
    $minimum = (int) $product['minimum_stock'];
    $active = (int) $product['active'] === 1;
    [$state, $label] = !$active ? ['archived', 'Archivado'] : ($quantity === 0 ? ['out', 'Agotado'] : ($minimum > 0 && $quantity <= $minimum ? ['low', 'Stock bajo'] : ['ok', 'Disponible']));
    $unit = (string) ($product['unit'] ?: 'unidad');
    $image = trim((string) ($product['image_url'] ?? ''));
    $editor = ['id' => (int) $product['id'], 'name' => (string) $product['name'], 'type' => (string) $product['type'], 'sku' => (string) ($product['sku'] ?? ''),
      'unit' => $unit, 'minimum_stock' => $minimum, 'points' => $product['points'] ?? '', 'description' => (string) ($product['description'] ?? ''),
      'sync_pph' => !empty($product['sync_pph']), 'image_url' => $image, 'quantity' => $quantity];
    if ($context['can_costs']) {
      $editor['price'] = $product['price'] ?? '';
    }
    $meta = '<dl class="cpr-pop-meta"><div><dt>Existencias</dt><dd>' . $e(PromotionUi::number($quantity) . ' ' . $unit) . '</dd></div>'
      . '<div><dt>Mínimo</dt><dd>' . ($minimum > 0 ? $e(PromotionUi::number($minimum)) : 'Sin alerta') . '</dd></div>'
      . '<div><dt>SKU</dt><dd>' . $e(($product['sku'] ?? '') ?: 'Sin SKU') . '</dd></div>'
      . '<div><dt>Origen</dt><dd>' . (!empty($product['pph_product_id']) ? (!empty($product['sync_pph']) ? 'Club PPH' : 'PPH sin sincronizar') : 'Solo inventario') . '</dd></div>'
      . ($context['can_costs'] ? '<div><dt>Precio</dt><dd>' . ($product['price'] !== null && $product['price'] !== '' ? '$' . $e(PromotionUi::number((float) $product['price'])) : 'Sin precio') . '</dd></div>' : '')
      . ($product['points'] !== null && $product['points'] !== '' ? '<div><dt>Puntos PPH</dt><dd>' . $e(PromotionUi::number((int) $product['points'])) . '</dd></div>' : '')
      . '</dl>';
    $actions = '<a class="cpr-ghost is-small" data-cpr-nav href="' . $e(PromotionUi::url($baseUrl, ['tab' => 'material_pop', 'pr_vista' => 'movimientos', 'pr_producto' => (int) $product['id']])) . '">Movimientos</a>';
    $data = ' data-product="' . $e((string) json_encode($editor, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . '"';
    if ($context['can_manage'] && $active) {
      $actions .= '<button type="button" class="cpr-ghost is-small" data-pop-open="product"' . $data . '>Editar</button>'
        . '<button type="button" class="cpr-ghost is-small" data-pop-open="adjust"' . $data . '>Ajustar</button>';
    }
    if ($context['can_void']) {
      $actions .= '<button type="button" class="cpr-ghost is-small' . ($active ? ' is-danger' : '') . '" data-pop-open="' . ($active ? 'archive' : 'restore') . '"' . $data . '>' . ($active ? 'Archivar' : 'Reactivar') . '</button>';
    }
    return '<article class="cpr-pop-card is-' . $state . '" data-pop-product="' . (int) $product['id'] . '">'
      . '<div class="cpr-pop-card__image">' . ($image !== '' ? '<img src="' . $e($image) . '" alt="" loading="lazy" width="96" height="96">' : '<span aria-hidden="true">' . $e(mb_strtoupper(mb_substr((string) $product['name'], 0, 2, 'UTF-8'), 'UTF-8')) . '</span>') . '</div>'
      . '<div class="cpr-pop-card__body"><div class="cpr-pop-card__title"><span class="cpr-pop-card__type">' . $e($product['type']) . '</span><h3>' . $e($product['name']) . '</h3></div>'
      . '<span class="cpr-chip is-' . $state . '">' . $label . '</span>' . $meta . '<div class="cpr-pop-card__actions">' . $actions . '</div></div></article>';
  }

  /** @param array<string,mixed> $context */
  private static function deliveries(array $context, string $baseUrl): string
  {
    $filters = $context['filters'];
    $e = static fn($value): string => PromotionUi::e($value);
    $html = self::filtersForm($context, $baseUrl, self::search($filters['q'], 'Persona, documento o producto') . self::dates($context) . self::productSelect($context)
      . PromotionUi::select('pr_origen', 'Origen', $context['options']['delivery_sources'], $filters['origen'], 'Todos los orígenes', self::originLabels($context['options']['delivery_sources']))
      . PromotionUi::select('pr_estado', 'Estado', ['delivered', 'cancelled'], $filters['estado'], 'Todos', ['delivered' => 'Entregada', 'cancelled' => 'Anulada']), 'Se muestran las ' . PopInventoryService::HISTORY_LIMIT . ' más recientes con estos filtros.');
    $rows = '';
    foreach ($context['deliveries'] as $row) {
      $products = implode('', array_map(static fn(array $item): string => '<li>' . PromotionUi::e($item['product_name']) . ' × ' . (int) $item['quantity'] . '</li>', $row['items']));
      $who = trim(implode(' · ', array_filter([(string) ($row['recipient_type'] ?? ''), (string) ($row['recipient_document'] ?? '')])));
      $cancelled = (string) $row['status'] === 'cancelled';
      $state = $cancelled ? '<span class="cpr-chip is-out">Anulada</span>' . ($row['cancellation_reason'] ? '<span class="cpr-cell-sub">' . $e($row['cancellation_reason']) . '</span>' : '') : '<span class="cpr-chip is-ok">Entregada</span>';
      $action = !$cancelled && $context['can_void'] && (string) $row['source'] !== 'pph'
        ? '<button type="button" class="cpr-ghost is-small is-danger" data-pop-open="cancel" data-delivery="' . (int) $row['id'] . '" data-label="' . $e($row['recipient_name'] . ' · ' . PromotionUi::day((string) $row['delivered_at'], true)) . '">Anular</button>' : '';
      $rows .= '<tr data-pop-delivery="' . (int) $row['id'] . '"><td>' . $e(PromotionUi::day((string) $row['delivered_at'], true)) . '</td>'
        . '<td><span class="cpr-cell-main">' . $e($row['recipient_name']) . '</span>' . ($who !== '' ? '<span class="cpr-cell-sub">' . $e($who) . '</span>' : '') . '</td>'
        . '<td>' . ($products !== '' ? '<ul class="cpr-pop-items">' . $products . '</ul>' : 'Sin detalle') . ($row['notes'] ? '<span class="cpr-cell-sub">' . $e($row['notes']) . '</span>' : '') . '</td>'
        . '<td>' . $e(self::ORIGINS[$row['source']] ?? strtoupper((string) $row['source'])) . '</td><td>' . self::evidence($row['evidence']) . '</td><td>' . $state . '</td><td>' . $action . '</td></tr>';
    }
    return $html . '<section class="cpr-card" data-pop-deliveries><div class="cpr-card__head"><div><h2>Entregas</h2><p>' . count($context['deliveries']) . ' resultados</p></div></div>'
      . ($rows === '' ? '<p class="cpr-empty">No hay entregas con estos filtros.</p>' : '<div class="cpr-table-wrap"><table class="cpr-table"><thead><tr><th>Fecha</th><th>Destinatario</th><th>Productos</th><th>Origen</th><th>Evidencias</th><th>Estado</th><th><span class="sr-only">Acción</span></th></tr></thead><tbody>' . $rows . '</tbody></table></div>')
      . '</section>';
  }

  /** @param array<string,mixed> $context */
  private static function movements(array $context, string $baseUrl): string
  {
    $filters = $context['filters'];
    $e = static fn($value): string => PromotionUi::e($value);
    $types = $context['options']['movement_types'];
    $html = self::filtersForm($context, $baseUrl, self::search($filters['q'], 'Producto, destinatario o motivo') . self::dates($context) . self::productSelect($context)
      . PromotionUi::select('pr_origen', 'Origen', $context['options']['movement_sources'], $filters['origen'], 'Todos los orígenes', self::originLabels($context['options']['movement_sources']))
      . PromotionUi::select('pr_tipo', 'Movimiento', $types, $filters['tipo'], 'Todos', array_intersect_key(PopInventoryService::MOVEMENT_LABELS, array_flip($types))), 'Se muestran los ' . PopInventoryService::MOVEMENT_LIMIT . ' más recientes con estos filtros.');
    $rows = '';
    foreach ($context['movements'] as $row) {
      $delta = (int) $row['quantity_delta'];
      $rows .= '<tr><td>' . $e(PromotionUi::day((string) $row['created_at'], true)) . '</td><td><span class="cpr-cell-main">' . $e($row['product_name']) . '</span></td>'
        . '<td>' . $e(PopInventoryService::MOVEMENT_LABELS[$row['movement_type']] ?? ucfirst(str_replace('_', ' ', (string) $row['movement_type']))) . '</td>'
        . '<td class="is-num"><strong class="' . ($delta > 0 ? 'cpr-pop-plus' : ($delta < 0 ? 'cpr-pop-minus' : '')) . '">' . ($delta > 0 ? '+' : '') . $delta . '</strong></td>'
        . '<td class="is-num">' . (int) $row['stock_before'] . ' → <strong>' . (int) $row['stock_after'] . '</strong></td>'
        . '<td>' . $e(self::ORIGINS[$row['source']] ?? strtoupper((string) $row['source'])) . '</td><td>' . $e($row['reason']) . '</td><td>' . self::evidence($row['evidence']) . '</td></tr>';
    }
    return $html . '<section class="cpr-card" data-pop-movements><div class="cpr-card__head"><div><h2>Movimientos</h2><p>' . count($context['movements']) . ' resultados</p></div></div>'
      . ($rows === '' ? '<p class="cpr-empty">No hay movimientos con estos filtros.</p>' : '<div class="cpr-table-wrap"><table class="cpr-table"><thead><tr><th>Fecha</th><th>Producto</th><th>Movimiento</th><th class="is-num">Cambio</th><th class="is-num">Saldo</th><th>Origen</th><th>Motivo</th><th>Evidencias</th></tr></thead><tbody>' . $rows . '</tbody></table></div>')
      . '</section>';
  }

  /** @param list<array{url:string}> $evidence */
  private static function evidence(array $evidence): string
  {
    if ($evidence === []) {
      return '<span class="cpr-cell-sub">Sin evidencia</span>';
    }
    $links = '';
    foreach ($evidence as $index => $item) {
      $links .= $item['url'] !== ''
        ? '<button type="button" class="cpr-pop-photo" data-pop-photo="' . PromotionUi::e($item['url']) . '">Foto ' . ($index + 1) . '</button>'
        : '<span class="cpr-cell-sub" title="Guardada en el panel de marketing anterior">Foto ' . ($index + 1) . '</span>';
    }
    return '<span class="cpr-pop-photos">' . $links . '</span>';
  }

  /** @param array<string,mixed> $context */
  private static function dialogs(array $context): string
  {
    $e = static fn($value): string => PromotionUi::e($value);
    $html = '<dialog class="cpr-dialog" data-pop-dialog="photo"><div class="cpr-dialog__head"><h2>Evidencia</h2><button type="button" class="cpr-ghost is-small" data-pop-close>Cerrar</button></div><img src="" alt="Evidencia" data-pop-photo-img></dialog>';
    $evidence = static fn(string $label): string => '<label class="cpr-field is-wide"><span class="cpr-label">' . $label . '</span><input type="file" name="evidence[]" accept="image/jpeg,image/png,image/webp" multiple data-pop-max="5"><span class="cpr-help">Opcional. Hasta 5 fotos JPG, PNG o WebP.</span></label>';
    $foot = static fn(string $submit, string $class = 'cpr-primary'): string => '<p class="cpr-alert is-error" data-pop-error hidden></p><div class="cpr-dialog__foot"><button type="button" class="cpr-ghost" data-pop-close>Cancelar</button><button type="submit" class="' . $class . '">' . $submit . '</button></div>';
    if ($context['can_manage']) {
      $categories = implode('', array_map(static fn(string $category): string => '<option value="' . PromotionUi::e($category) . '">' . PromotionUi::e($category) . '</option>', $context['categories']));
      $html .= '<dialog class="cpr-dialog is-wide" data-pop-dialog="product"><form method="dialog" data-pop-form="commercial_pop_product_save" enctype="multipart/form-data"><div class="cpr-dialog__head"><h2 data-pop-title>Agregar producto</h2><button type="button" class="cpr-ghost is-small" data-pop-close>Cerrar</button></div>'
        . '<input type="hidden" name="id" value="0"><div class="cpr-dialog__grid">'
        . '<label class="cpr-field is-wide"><span class="cpr-label">Nombre *</span><input name="name" required maxlength="255" autocomplete="off"></label>'
        . '<label class="cpr-field"><span class="cpr-label">Categoría *</span><select name="type" required>' . $categories . '</select><span class="cpr-help">Se administran en Configuración › Material POP.</span></label>'
        . '<label class="cpr-field"><span class="cpr-label">SKU</span><input name="sku" maxlength="100" autocomplete="off"></label>'
        . '<label class="cpr-field"><span class="cpr-label">Unidad</span><input name="unit" value="unidad" maxlength="50"></label>'
        . '<label class="cpr-field" data-pop-create-only><span class="cpr-label">Cantidad inicial</span><input type="number" name="initial_quantity" min="0" value="0"></label>'
        . '<label class="cpr-field"><span class="cpr-label">Stock mínimo (alerta)</span><input type="number" name="minimum_stock" min="0" value="0"></label>'
        . ($context['can_costs'] ? '<label class="cpr-field"><span class="cpr-label">Precio</span><input name="price" inputmode="decimal" placeholder="40000"></label>' : '')
        . '<label class="cpr-field"><span class="cpr-label">Puntos PPH</span><input type="number" name="points" min="0"></label>'
        . '<label class="cpr-field is-wide"><span class="cpr-label">Imagen del producto</span><input type="file" name="product_image" accept="image/jpeg,image/png,image/webp" data-pop-max="1"><span class="cpr-help" data-pop-image-hint>JPG, PNG o WebP. Si no eliges otra, se conserva la actual.</span></label>'
        . '<label class="cpr-field is-wide"><span class="cpr-label">Descripción</span><textarea name="description" rows="2" maxlength="2000"></textarea></label>'
        . '<div class="is-wide" data-pop-create-only>' . $evidence('Evidencia del ingreso inicial') . '</div>'
        . '<label class="cpr-check is-wide"><input type="checkbox" name="sync_pph" value="1"><span>Publicar y sincronizar con el Club PPH<small>Nombre, imagen, precio, puntos y existencias quedan iguales en los dos inventarios.</small></span></label>'
        . '</div>' . $foot('Guardar producto') . '</form></dialog>';

      $html .= '<dialog class="cpr-dialog" data-pop-dialog="adjust"><form method="dialog" data-pop-form="commercial_pop_stock_adjust" enctype="multipart/form-data"><div class="cpr-dialog__head"><h2>Ajustar existencias</h2><button type="button" class="cpr-ghost is-small" data-pop-close>Cerrar</button></div>'
        . '<input type="hidden" name="product_id"><p class="cpr-dialog__lead" data-pop-lead></p><div class="cpr-dialog__grid">'
        . '<label class="cpr-field"><span class="cpr-label">Cantidad *</span><input type="number" name="delta" required step="1" placeholder="+10 o -2"><span class="cpr-help">Positiva para entradas y negativa para salidas.</span></label>'
        . '<label class="cpr-field"><span class="cpr-label">Motivo *</span><input name="reason" required maxlength="500" placeholder="Compra, corrección, daño..."></label>'
        . '<div class="is-wide">' . $evidence('Evidencia') . '</div></div>' . $foot('Guardar ajuste') . '</form></dialog>';

      $products = '';
      foreach ($context['product_options'] as $row) {
        if ((int) $row['active'] === 1) {
          $products .= '<option value="' . (int) $row['id'] . '" data-stock="' . (int) $row['quantity'] . '"' . ((int) $row['quantity'] <= 0 ? ' disabled' : '') . '>' . $e($row['name']) . ' — ' . (int) $row['quantity'] . ' disponibles</option>';
        }
      }
      $people = implode('', array_map(static fn(array $person): string => '<option value="' . PromotionUi::e($person['nombre']) . '" data-id="' . PromotionUi::e($person['id']) . '"></option>', $context['employees']));
      $types = implode('', array_map(static fn(string $type): string => '<option value="' . PromotionUi::e($type) . '">' . PromotionUi::e($type) . '</option>', PopInventoryService::RECIPIENT_TYPES));
      $html .= '<dialog class="cpr-dialog is-wide" data-pop-dialog="delivery"><form method="dialog" data-pop-form="commercial_pop_delivery_create" enctype="multipart/form-data"><div class="cpr-dialog__head"><h2>Registrar entrega</h2><button type="button" class="cpr-ghost is-small" data-pop-close>Cerrar</button></div>'
        . '<p class="cpr-dialog__lead">Descuenta los productos y guarda la evidencia. Si un producto no alcanza, no se descuenta ninguno.</p><div class="cpr-dialog__grid">'
        . '<label class="cpr-field"><span class="cpr-label">Fecha y hora *</span><input type="datetime-local" name="delivered_at" required data-pop-now></label>'
        . '<label class="cpr-field"><span class="cpr-label">Tipo de destinatario</span><select name="recipient_type" data-pop-recipient-type>' . $types . '</select></label>'
        . '<label class="cpr-field"><span class="cpr-label">Quién recibe *</span><input name="recipient_name" required maxlength="255" autocomplete="off" list="cpr-pop-people" data-pop-recipient><span class="cpr-help">Con tipo Funcionario, elige de la lista.</span></label>'
        . '<label class="cpr-field"><span class="cpr-label">Documento o tarjeta</span><input name="recipient_document" maxlength="120"></label>'
        . '<input type="hidden" name="recipient_id"><input type="hidden" name="items">'
        . '<fieldset class="cpr-pop-lines is-wide" data-pop-lines><legend>Productos *</legend><template data-pop-line-template><div class="cpr-pop-line" data-pop-line>'
        . '<label class="cpr-field"><span class="cpr-label">Producto</span><select data-pop-line-product required><option value="">Elige un producto</option>' . $products . '</select></label>'
        . '<label class="cpr-field"><span class="cpr-label">Cantidad</span><input type="number" min="1" value="1" required data-pop-line-quantity></label>'
        . '<button type="button" class="cpr-ghost is-small is-danger" data-pop-line-remove aria-label="Quitar producto">Quitar</button></div></template>'
        . '<div data-pop-line-list></div><button type="button" class="cpr-ghost is-small" data-pop-line-add><span class="material-symbols-outlined" aria-hidden="true">add</span>Otro producto</button></fieldset>'
        . '<div class="is-wide">' . $evidence('Fotos de la entrega') . '</div>'
        . '<label class="cpr-field is-wide"><span class="cpr-label">Observaciones</span><textarea name="notes" rows="2" maxlength="2000"></textarea></label>'
        . '</div><datalist id="cpr-pop-people">' . $people . '</datalist>' . $foot('Registrar y descontar') . '</form></dialog>';
    }
    if ($context['can_void']) {
      $html .= '<dialog class="cpr-dialog" data-pop-dialog="confirm"><form method="dialog" data-pop-form=""><div class="cpr-dialog__head"><h2 data-pop-title></h2><button type="button" class="cpr-ghost is-small" data-pop-close>Cerrar</button></div>'
        . '<input type="hidden" name="product_id"><input type="hidden" name="delivery_id"><input type="hidden" name="active"><p class="cpr-dialog__lead" data-pop-lead></p>'
        . '<label class="cpr-field is-wide"><span class="cpr-label">Motivo *</span><input name="reason" required maxlength="500"></label>'
        . '<label class="cpr-check is-wide"><input type="checkbox" name="confirm" value="1" required><span data-pop-confirm-text></span></label>'
        . $foot('Confirmar', 'cpr-primary is-danger') . '</form></dialog>';
    }
    return $html;
  }

  /** Funcionarios activos para elegir el destinatario. @return list<array{id:string,nombre:string}> */
  private static function employees(Database $db): array
  {
    $table = $db->table('jet_cct_funcionarios');
    $rows = $db->getResults("SELECT id_empleado AS id, TRIM(nombre) AS nombre FROM `{$table}` WHERE LOWER(TRIM(COALESCE(activo, ''))) = 'si' AND TRIM(COALESCE(nombre, '')) <> '' ORDER BY nombre");
    return array_map(static fn(array $row): array => ['id' => (string) $row['id'], 'nombre' => (string) $row['nombre']], $rows);
  }
}
