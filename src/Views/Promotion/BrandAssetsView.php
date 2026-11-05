<?php

declare(strict_types=1);

namespace SCM\Views\Promotion;

use SCM\Commercial\CommercialAccessPolicy;
use SCM\Commercial\Promotion\Pieces\BrandAssetFiles;
use SCM\Commercial\Promotion\Pieces\BrandAssetService;
use SCM\Core\Database;

/**
 * Promoción › Banco de piezas. Copia/adaptación de dashboard-marketing views/branding.php con el diseño comercial: los
 * formularios y el detalle abren en ventanas internas (<dialog>) y guardan por AJAX. Crear, editar y vincular piden
 * piezas_gestionar; archivar y restaurar, piezas_archivar. Sin ellos los botones ni los datos para editar llegan al HTML.
 * Las publicaciones para vincular se piden al abrir la ventana (commercial_piece_posts), no con la página.
 */
final class BrandAssetsView
{
  private const VISIBILITY = ['0' => 'Archivadas', 'all' => 'Todas'];
  private const PLATFORMS = ['instagram' => 'Instagram', 'facebook' => 'Facebook'];

  /**
   * @param array<string,mixed> $input
   * @return array<string,mixed>
   */
  public static function context(array $input, CommercialAccessPolicy $policy, Database $db, ?BrandAssetService $service = null): array
  {
    $service ??= new BrandAssetService($db, BrandAssetFiles::runtime());
    $filters = [
      'q' => mb_substr(trim((string) ($input['pr_q'] ?? '')), 0, 80, 'UTF-8'),
      'category' => trim((string) ($input['pr_categoria'] ?? '')),
      'status' => trim((string) ($input['pr_estado'] ?? '')),
      'channel' => trim((string) ($input['pr_canal'] ?? '')),
      'from' => trim((string) ($input['pr_desde'] ?? '')),
      'to' => trim((string) ($input['pr_hasta'] ?? '')),
      'active' => isset(self::VISIBILITY[(string) ($input['pr_visibilidad'] ?? '')]) ? (string) $input['pr_visibilidad'] : '1',
    ];
    $context = ['filters' => $filters, 'available' => $service->available(), 'can_manage' => $policy->canAct('piezas_gestionar'), 'can_archive' => $policy->canAct('piezas_archivar')];
    return $context['available'] ? $context + $service->dashboard($filters) : $context;
  }

  /** @param array<string,mixed> $context */
  public static function render(array $context, string $baseUrl): string
  {
    $html = PromotionUi::hero('palette', 'Banco de piezas', 'Clasifica y sigue cada pieza de marca de SKC SuCasa Inmobiliaria, desde el arte final hasta su implementación e impacto comercial.')
      . '<div class="cpr-live" data-cpr-live role="status" aria-live="polite"></div>';
    if (!$context['available']) {
      return $html . '<p class="cpr-alert is-error">Las tablas del Banco de piezas (wp_marketing_brand_assets) no existen todavía en esta base.</p>';
    }
    if ($context['missing_files'] > 0) {
      $html .= '<p class="cpr-alert" data-piece-missing>' . (int) $context['missing_files'] . ' piezas de esta lista no tienen su archivo en este panel. Se copian con <code>php bin/pieces-migrate-files.php --apply</code>.</p>';
    }
    $summary = $context['summary'];
    $html .= '<div class="cpr-stats" data-piece-stats>'
      . PromotionUi::stat('Total cargadas', PromotionUi::number($summary['total']), 'Piezas activas', 'photo_library')
      . PromotionUi::stat('Aprobadas', PromotionUi::number($summary['approved']), 'Artes listos para uso', 'verified')
      . PromotionUi::stat('Implementadas', PromotionUi::number($summary['implemented']), $summary['progress'] . ' % del total', 'task_alt')
      . PromotionUi::stat('Pendientes', PromotionUi::number($summary['pending']), 'Diseño, revisión o ajuste', 'pending_actions')
      . PromotionUi::stat('Coherencia visual', $summary['consistency'] . ' %', 'Promedio de cumplimiento de marca', 'rule')
      . '</div>';
    if ($context['can_manage']) {
      $html .= '<div class="cpr-toolbar"><div class="cpr-toolbar__actions"><button type="button" class="cpr-primary" data-piece-open="form"><span class="material-symbols-outlined" aria-hidden="true">upload</span>Subir pieza</button></div></div>';
    }
    return $html . self::insights($context) . self::filters($context, $baseUrl) . self::library($context, $baseUrl) . self::dialogs($context);
  }

  /** @param array<string,mixed> $context */
  private static function insights(array $context): string
  {
    $bars = static function (array $counts, array $labels): string {
      $max = max(1, ...array_values($counts ?: [1]));
      $rows = '';
      foreach ($counts as $key => $count) {
        if ((int) $count > 0) {
          $rows .= '<div class="cpr-piece-bar"><span>' . PromotionUi::e($labels[$key] ?? $key) . '</span><strong>' . PromotionUi::e(PromotionUi::number((int) $count)) . '</strong><i style="--bar:' . (int) round((int) $count * 100 / $max) . '%" aria-hidden="true"></i></div>';
        }
      }
      return $rows !== '' ? $rows : '<p class="cpr-empty">Aparecerá cuando haya piezas activas.</p>';
    };
    $top = '';
    foreach ($context['top'] as $row) {
      $top .= '<li><span class="cpr-cell-main">' . PromotionUi::e($row['title']) . '</span><span class="cpr-cell-sub">' . PromotionUi::e(BrandAssetService::CATEGORIES[$row['category']] ?? $row['category']) . ' · ' . PromotionUi::e(PromotionUi::number((int) $row['impact_score'])) . ' de impacto</span></li>';
    }
    return '<div class="cpr-piece-insights">'
      . '<section class="cpr-card"><div class="cpr-card__head"><div><h2>Piezas por canal</h2><p>Una pieza puede estar en varios canales</p></div></div>' . $bars($context['channel_counts'], BrandAssetService::CHANNELS) . '</section>'
      . '<section class="cpr-card"><div class="cpr-card__head"><div><h2>Piezas por categoría</h2><p>Solo piezas activas</p></div></div>' . $bars($context['category_counts'], BrandAssetService::CATEGORIES) . '</section>'
      . '<section class="cpr-card"><div class="cpr-card__head"><div><h2>Mejor resultado</h2><p>Según alcance, clics, contactos y captaciones</p></div></div>' . ($top !== '' ? '<ol class="cpr-piece-top">' . $top . '</ol>' : '<p class="cpr-empty">Registra indicadores para ver las piezas más efectivas.</p>') . '</section>'
      . '</div>';
  }

  /** @param array<string,mixed> $context */
  private static function filters(array $context, string $baseUrl): string
  {
    $filters = $context['filters'];
    $date = static fn(string $name, string $label, string $value): string => '<label class="cpr-field"><span class="cpr-label">' . $label . '</span><input type="date" name="' . $name . '" value="' . PromotionUi::e($value) . '"></label>';
    return '<form class="cpr-card cpr-filters" data-cpr-filters data-tab="banco_piezas" action="' . PromotionUi::e(PromotionUi::url($baseUrl, ['tab' => 'banco_piezas'])) . '"><div class="cpr-filters__grid is-wide">'
      . '<label class="cpr-field"><span class="cpr-label">Buscar</span><input type="search" name="pr_q" value="' . PromotionUi::e($filters['q']) . '" maxlength="80" placeholder="Nombre, código, campaña o diseñador"></label>'
      . PromotionUi::select('pr_categoria', 'Categoría', array_keys(BrandAssetService::CATEGORIES), $filters['category'], 'Todas', BrandAssetService::CATEGORIES)
      . PromotionUi::select('pr_estado', 'Estado', array_keys(BrandAssetService::STATUSES), $filters['status'], 'Todos', BrandAssetService::STATUSES)
      . PromotionUi::select('pr_canal', 'Canal', array_keys(BrandAssetService::CHANNELS), $filters['channel'], 'Todos', BrandAssetService::CHANNELS)
      . $date('pr_desde', 'Desde', $filters['from']) . $date('pr_hasta', 'Hasta', $filters['to'])
      . PromotionUi::select('pr_visibilidad', 'Visibilidad', array_map('strval', array_keys(self::VISIBILITY)), $filters['active'] === '1' ? '' : $filters['active'], 'Activas', self::VISIBILITY)
      . '</div><div class="cpr-filters__actions"><a class="cpr-ghost" data-cpr-nav href="' . PromotionUi::e(PromotionUi::url($baseUrl, ['tab' => 'banco_piezas'])) . '">Limpiar</a>'
      . '<button type="submit" class="cpr-primary"><span class="material-symbols-outlined" aria-hidden="true">filter_alt</span>Aplicar</button></div>'
      . '<p class="cpr-filters__hint">Se muestran las ' . BrandAssetService::ASSET_LIMIT . ' más recientes con estos filtros.</p></form>';
  }

  /** @param array<string,mixed> $context */
  private static function library(array $context, string $baseUrl): string
  {
    $cards = '';
    foreach ($context['assets'] as $asset) {
      $cards .= self::card($asset, $context, $baseUrl);
    }
    return '<section class="cpr-card" data-piece-library><div class="cpr-card__head"><div><h2>Piezas registradas</h2><p>' . count($context['assets']) . ' resultados</p></div></div>'
      . ($cards === '' ? '<p class="cpr-empty">No hay piezas con estos filtros.</p>' : '<div class="cpr-piece-grid">' . $cards . '</div>') . '</section>';
  }

  /** @return array{0:string,1:string} URL para ver y para descargar. */
  private static function fileUrls(int $id, string $baseUrl): array
  {
    $base = rtrim($baseUrl, '/') . '/piece-file.php?id=' . $id;
    return [$base, $base . '&download=1'];
  }

  /** @param array<string,mixed> $asset */
  private static function kind(array $asset): string
  {
    $mime = (string) ($asset['mime_type'] ?? '');
    return match (true) {
      str_starts_with($mime, 'image/') => 'image',
      str_starts_with($mime, 'video/') => 'video',
      $mime === 'application/pdf' => 'pdf',
      default => 'file',
    };
  }

  private static function extension(string $name): string
  {
    return strtoupper(pathinfo($name, PATHINFO_EXTENSION)) ?: 'ARTE';
  }

  /** @param array<string,mixed> $asset @param array<string,mixed> $context */
  private static function card(array $asset, array $context, string $baseUrl): string
  {
    $e = static fn($value): string => PromotionUi::e($value);
    $id = (int) $asset['id'];
    $active = (int) $asset['active'] === 1;
    $ready = !empty($asset['file_ready']);
    [$url, $download] = self::fileUrls($id, $baseUrl);
    $kind = self::kind($asset);
    $title = (string) $asset['title'];
    $preview = !$ready ? '<span class="cpr-piece-file">Archivo sin copiar</span>' : match ($kind) {
      'image' => '<img src="' . $e($url) . '" alt="Vista previa de ' . $e($title) . '" loading="lazy" width="600" height="400">',
      'video' => '<video src="' . $e($url) . '" preload="metadata" muted playsinline aria-label="Vista previa de video: ' . $e($title) . '"></video>',
      default => '<span class="cpr-piece-file">' . $e(self::extension((string) ($asset['original_name'] ?? ''))) . '</span>',
    };
    $channels = implode('', array_map(static fn(string $channel): string => '<span class="cpr-chip">' . PromotionUi::e(BrandAssetService::CHANNELS[$channel] ?? $channel) . '</span>', $asset['channels']));
    $linked = $asset['linked_posts'];
    $kpis = $asset['social_kpis'];
    $metric = static fn(string $label, int|string $value): string => '<div><dt>' . $label . '</dt><dd>' . PromotionUi::e(PromotionUi::number((int) $value)) . '</dd></div>';
    $numbers = $linked !== []
      ? $metric('Alcance', $kpis['reach']) . $metric('Vistas', $kpis['views']) . $metric('Interacciones', $kpis['interactions']) . $metric('Clics', $kpis['clicks'])
      : $metric('Alcance', $asset['reach_count'] ?? 0) . $metric('Clics', $asset['clicks_count'] ?? 0) . $metric('Leads', $asset['leads_count'] ?? 0);
    $actions = '<button type="button" class="cpr-ghost is-small" data-piece-open="detail" data-id="' . $id . '" data-title="' . $e($title) . '">Ver detalles</button>';
    if ($ready) {
      $actions .= '<a class="cpr-ghost is-small" href="' . $e($download) . '">Descargar</a>';
    }
    if ($context['can_manage'] && $active) {
      $actions .= '<button type="button" class="cpr-ghost is-small" data-piece-open="form" data-piece="' . $e((string) json_encode(self::editor($asset), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . '">Editar</button>'
        . '<button type="button" class="cpr-ghost is-small" data-piece-open="links" data-id="' . $id . '" data-title="' . $e($title) . '" data-linked="' . $e((string) json_encode(array_map(static fn(array $post): int => (int) $post['_ID'], $linked))) . '">' . ($linked === [] ? 'Vincular publicaciones' : 'Administrar vínculos') . '</button>';
    }
    if ($context['can_archive']) {
      $actions .= '<button type="button" class="cpr-ghost is-small' . ($active ? ' is-danger' : '') . '" data-piece-open="' . ($active ? 'archive' : 'restore') . '" data-id="' . $id . '" data-title="' . $e($title) . '">' . ($active ? 'Archivar' : 'Restaurar') . '</button>';
    }
    return '<article class="cpr-piece-card' . ($active ? '' : ' is-archived') . '" data-piece-card="' . $id . '"><div class="cpr-piece-card__preview">' . $preview . '<span class="cpr-chip is-' . self::statusTone((string) $asset['status']) . '">' . $e(BrandAssetService::STATUSES[$asset['status']] ?? $asset['status']) . '</span></div>'
      . '<div class="cpr-piece-card__body"><span class="cpr-pop-card__type">' . $e(BrandAssetService::CATEGORIES[$asset['category']] ?? $asset['category']) . ($active ? '' : ' · Archivada') . '</span><h3>' . $e($title) . '</h3>'
      . ($asset['description'] ? '<p class="cpr-piece-card__text">' . $e($asset['description']) . '</p>' : '')
      . '<div class="cpr-piece-chips">' . $channels . '</div>'
      . '<p class="cpr-cell-sub">' . ($linked !== [] ? count($linked) . (count($linked) === 1 ? ' publicación vinculada' : ' publicaciones vinculadas') : 'Indicadores registrados a mano') . '</p>'
      . '<dl class="cpr-pop-meta is-kpis">' . $numbers . '</dl><div class="cpr-pop-card__actions">' . $actions . '</div></div></article>' . self::detail($asset, $url, $download, $ready);
  }

  private static function statusTone(string $status): string
  {
    return match ($status) {
      'implemented', 'published', 'approved' => 'ok',
      'adjustment' => 'low',
      default => 'neutral',
    };
  }

  /** Datos para el formulario de edición. @param array<string,mixed> $asset @return array<string,mixed> */
  private static function editor(array $asset): array
  {
    $keys = ['id', 'title', 'asset_code', 'description', 'category', 'status', 'campaign_name', 'designer_name', 'approver_name', 'implementer_name', 'creation_date', 'approval_date', 'publication_date', 'implementation_date', 'brand_compliance', 'views_count', 'reach_count', 'clicks_count', 'leads_count', 'referrals_count', 'acquisitions_count', 'observations', 'original_name'];
    return array_intersect_key($asset, array_flip($keys)) + ['channels' => $asset['channels']];
  }

  /** Contenido de la ventana de detalle: la pantalla lo copia al abrirla y carga el archivo en ese momento. @param array<string,mixed> $asset */
  private static function detail(array $asset, string $url, string $download, bool $ready): string
  {
    $e = static fn($value): string => PromotionUi::e($value);
    $id = (int) $asset['id'];
    $title = (string) $asset['title'];
    $day = static fn($value): string => trim((string) $value) !== '' ? PromotionUi::day((string) $value) : 'Sin fecha';
    $field = static fn(string $label, $value, string $empty = 'Sin dato'): string => '<div><dt>' . $label . '</dt><dd>' . PromotionUi::e(trim((string) $value) !== '' ? $value : $empty) . '</dd></div>';
    $kind = self::kind($asset);
    $media = !$ready ? '<p class="cpr-empty">El archivo todavía no se ha copiado a este panel.</p>' : match ($kind) {
      'image' => '<img data-piece-src="' . $e($url) . '" alt="Arte final de ' . $e($title) . '">',
      'video' => '<video data-piece-src="' . $e($url) . '" controls preload="metadata" playsinline aria-label="Video de ' . $e($title) . '"></video>',
      'pdf' => '<iframe data-piece-src="' . $e($url) . '" title="PDF de ' . $e($title) . '"></iframe>',
      default => '<p class="cpr-empty">Este formato no tiene vista previa en el navegador.</p>',
    };
    $number = static fn(string $label, $value): string => '<div><dt>' . $label . '</dt><dd>' . PromotionUi::e(PromotionUi::number((int) $value)) . '</dd></div>';
    $posts = '';
    foreach ($asset['linked_posts'] as $post) {
      $link = (string) $post['permalink'];
      $posts .= '<li><span class="cpr-cell-main">' . $e($post['title'] ?: 'Publicación sin texto') . '</span><span class="cpr-cell-sub">' . $e(self::PLATFORMS[$post['platform']] ?? 'Meta') . ' · ' . $e($day($post['post_date'] ?? '')) . ' · ' . $e(PromotionUi::number((int) $post['reach'])) . ' de alcance</span>'
        . ($link !== '' ? '<a class="cpr-link" href="' . $e($link) . '" target="_blank" rel="noopener noreferrer">Ver publicación</a>' : '') . '</li>';
    }
    $kpis = $asset['social_kpis'];
    return '<template data-piece-detail="' . $id . '"><div class="cpr-piece-detail"><div class="cpr-piece-detail__media">' . $media
      . '<p class="cpr-cell-sub">' . $e($asset['original_name'] ?: 'Arte final') . ' · ' . $e(BrandAssetFiles::sizeLabel((int) ($asset['file_size'] ?? 0))) . '</p>'
      . ($ready ? '<div class="cpr-pop-card__actions">' . ($kind !== 'file' ? '<a class="cpr-ghost is-small" href="' . $e($url) . '" target="_blank" rel="noopener">Abrir aparte</a>' : '') . '<a class="cpr-primary" href="' . $e($download) . '">Descargar archivo</a></div>' : '') . '</div>'
      . '<div class="cpr-piece-detail__info">' . ($asset['description'] ? '<p>' . $e($asset['description']) . '</p>' : '')
      . '<h3>Datos de la pieza</h3><dl class="cpr-pop-meta">' . $field('Estado', BrandAssetService::STATUSES[$asset['status']] ?? $asset['status']) . $field('Categoría', BrandAssetService::CATEGORIES[$asset['category']] ?? $asset['category'])
      . $field('Código interno', $asset['asset_code'], 'Sin código') . $field('Campaña', $asset['campaign_name'], 'Sin campaña') . $field('Coherencia con la marca', (int) $asset['brand_compliance'] . ' %')
      . $field('Canales', implode(', ', array_map(static fn(string $channel): string => BrandAssetService::CHANNELS[$channel] ?? $channel, $asset['channels']))) . '</dl>'
      . '<h3>Fechas</h3><dl class="cpr-pop-meta">' . $field('Creación', $day($asset['creation_date'])) . $field('Aprobación', $day($asset['approval_date'] ?? '')) . $field('Publicación', $day($asset['publication_date'] ?? '')) . $field('Implementación', $day($asset['implementation_date'] ?? '')) . '</dl>'
      . '<h3>Responsables</h3><dl class="cpr-pop-meta">' . $field('Diseñó', $asset['designer_name'], 'Sin asignar') . $field('Aprobó', $asset['approver_name'], 'Sin asignar') . $field('Implementó', $asset['implementer_name'], 'Sin asignar') . '</dl>'
      . '<h3>Indicadores registrados</h3><dl class="cpr-pop-meta">' . $number('Visualizaciones', $asset['views_count'] ?? 0) . $number('Alcance', $asset['reach_count'] ?? 0) . $number('Clics', $asset['clicks_count'] ?? 0) . $number('Leads', $asset['leads_count'] ?? 0) . $number('Referidos', $asset['referrals_count'] ?? 0) . $number('Captaciones', $asset['acquisitions_count'] ?? 0) . '</dl>'
      . ($posts !== '' ? '<h3>Publicaciones vinculadas</h3><dl class="cpr-pop-meta">' . $number('Alcance', $kpis['reach']) . $number('Vistas', $kpis['views']) . $number('Interacciones', $kpis['interactions']) . $number('Clics', $kpis['clicks']) . '</dl><ul class="cpr-piece-posts">' . $posts . '</ul>' : '')
      . '<h3>Observaciones</h3><p>' . $e($asset['observations'] ?: 'No hay observaciones registradas para esta pieza.') . '</p></div></div></template>';
  }

  /** @param array<string,mixed> $context */
  private static function dialogs(array $context): string
  {
    $html = '<dialog class="cpr-dialog is-full" data-piece-dialog="detail"><div class="cpr-dialog__head"><h2 data-piece-title>Detalle de la pieza</h2><button type="button" class="cpr-ghost is-small" data-piece-close>Cerrar</button></div><div class="cpr-camp-detail" data-piece-detail-body></div></dialog>';
    $foot = static fn(string $submit, string $class = 'cpr-primary'): string => '<p class="cpr-alert is-error" data-piece-error hidden></p><div class="cpr-dialog__foot"><button type="button" class="cpr-ghost" data-piece-close>Cancelar</button><button type="submit" class="' . $class . '">' . $submit . '</button></div>';
    if ($context['can_manage']) {
      $html .= self::formDialog($foot('Guardar pieza'));
      $html .= '<dialog class="cpr-dialog is-wide" data-piece-dialog="links"><form method="dialog" data-piece-links-form><div class="cpr-dialog__head"><h2>Vincular publicaciones</h2><button type="button" class="cpr-ghost is-small" data-piece-close>Cerrar</button></div>'
        . '<input type="hidden" name="id"><p class="cpr-dialog__lead">Elige las publicaciones que usaron <strong data-piece-links-name></strong>. Sus indicadores se suman a la pieza y se actualizan con cada sincronización.</p>'
        . '<div class="cpr-dialog__grid"><label class="cpr-field"><span class="cpr-label">Buscar publicación</span><input type="search" data-piece-links-search placeholder="Texto, fecha o tipo" autocomplete="off"></label>'
        . '<label class="cpr-field"><span class="cpr-label">Red social</span><select data-piece-links-platform><option value="">Facebook e Instagram</option><option value="instagram">Instagram</option><option value="facebook">Facebook</option></select></label></div>'
        . '<p class="cpr-cell-sub" data-piece-links-count></p><div class="cpr-piece-links" data-piece-links-list></div>' . $foot('Guardar vínculos') . '</form></dialog>';
    }
    if ($context['can_archive']) {
      $html .= '<dialog class="cpr-dialog" data-piece-dialog="archive"><form method="dialog" data-piece-form="commercial_piece_active"><div class="cpr-dialog__head"><h2 data-piece-title></h2><button type="button" class="cpr-ghost is-small" data-piece-close>Cerrar</button></div>'
        . '<input type="hidden" name="id"><input type="hidden" name="active"><p class="cpr-dialog__lead" data-piece-lead></p>'
        . '<label class="cpr-check is-wide"><input type="checkbox" name="confirm" value="1" required><span data-piece-confirm-text></span></label>' . $foot('Confirmar', 'cpr-primary is-danger') . '</form></dialog>';
    }
    return $html;
  }

  private static function formDialog(string $foot): string
  {
    $input = static fn(string $name, string $label, int $max, string $extra = ''): string => '<label class="cpr-field"><span class="cpr-label">' . $label . '</span><input name="' . $name . '" maxlength="' . $max . '" autocomplete="off"' . $extra . '></label>';
    $date = static fn(string $name, string $label, string $extra = ''): string => '<label class="cpr-field"><span class="cpr-label">' . $label . '</span><input type="date" name="' . $name . '"' . $extra . '></label>';
    $number = static fn(string $name, string $label): string => '<label class="cpr-field"><span class="cpr-label">' . $label . '</span><input type="number" name="' . $name . '" min="0" inputmode="numeric" value="0"></label>';
    $options = static function (array $values): string {
      $html = '';
      foreach ($values as $key => $label) {
        $html .= '<option value="' . PromotionUi::e($key) . '">' . PromotionUi::e($label) . '</option>';
      }
      return $html;
    };
    $channels = '';
    foreach (BrandAssetService::CHANNELS as $key => $label) {
      $channels .= '<label><input type="checkbox" name="channels[]" value="' . PromotionUi::e($key) . '"><span>' . PromotionUi::e($label) . '</span></label>';
    }
    $max = BrandAssetFiles::maxBytes();
    return '<dialog class="cpr-dialog is-wide" data-piece-dialog="form"><form method="dialog" data-piece-form="commercial_piece_save" enctype="multipart/form-data"><div class="cpr-dialog__head"><h2 data-piece-title>Subir pieza</h2><button type="button" class="cpr-ghost is-small" data-piece-close>Cerrar</button></div>'
      . '<input type="hidden" name="id" value="0"><div class="cpr-dialog__grid">'
      . '<h3 class="is-wide">Identificación</h3>'
      . $input('title', 'Nombre de la pieza *', 255, ' required') . $input('asset_code', 'Código interno', 80, ' placeholder="BR-2026-001"')
      . '<label class="cpr-field"><span class="cpr-label">Categoría *</span><select name="category" required><option value="">Selecciona una categoría</option>' . $options(BrandAssetService::CATEGORIES) . '</select></label>'
      . $input('campaign_name', 'Campaña o iniciativa', 191)
      . '<label class="cpr-field is-wide"><span class="cpr-label">Descripción</span><textarea name="description" rows="2" maxlength="3000"></textarea></label>'
      . '<label class="cpr-field is-wide"><span class="cpr-label" data-piece-file-label>Arte final *</span><input type="file" name="asset_file" accept="' . PromotionUi::e(BrandAssetFiles::ACCEPT) . '" data-piece-file data-piece-max="' . $max . '"><span class="cpr-help" data-piece-file-hint>Imagen, PDF, video o archivo de diseño. Máximo ' . PromotionUi::e(BrandAssetFiles::sizeLabel($max)) . '.</span></label>'
      . '<h3 class="is-wide">Flujo y responsables</h3>'
      . '<label class="cpr-field"><span class="cpr-label">Estado *</span><select name="status" required>' . $options(BrandAssetService::STATUSES) . '</select></label>'
      . $date('creation_date', 'Fecha de creación *', ' required') . $input('designer_name', 'Diseñó', 191) . $input('approver_name', 'Aprobó', 191) . $input('implementer_name', 'Implementó', 191)
      . $date('approval_date', 'Fecha de aprobación') . $date('publication_date', 'Fecha de publicación') . $date('implementation_date', 'Fecha de implementación')
      . '<fieldset class="cpr-piece-channels is-wide"><legend>Canales de uso *</legend>' . $channels . '</fieldset>'
      . '<h3 class="is-wide">Impacto y coherencia</h3>'
      . $number('views_count', 'Visualizaciones') . $number('reach_count', 'Alcance') . $number('clicks_count', 'Clics') . $number('leads_count', 'Leads o contactos') . $number('referrals_count', 'Referidos') . $number('acquisitions_count', 'Captaciones')
      . '<label class="cpr-field is-wide"><span class="cpr-label">Cumplimiento del manual de marca: <output data-piece-compliance-output>0 %</output></span><input type="range" name="brand_compliance" min="0" max="100" step="5" value="0" data-piece-compliance><span class="cpr-help">Tipografía, paleta, logo, composición y tono visual.</span></label>'
      . '<label class="cpr-field is-wide"><span class="cpr-label">Observaciones</span><textarea name="observations" rows="3" maxlength="5000"></textarea></label>'
      . '</div>' . $foot . '</form></dialog>';
  }
}
