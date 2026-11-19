<?php

declare(strict_types=1);

namespace SCM\Commercial\Quotes;

use SCM\Support\HtmlPdfRenderer;

/**
 * PDF de la cotización de inmuebles: lo que ve el cliente en public/cotizacion.php, en hojas carta con el membrete. Se
 * genera con Chromium (HtmlPdfRenderer, Gotenberg del VPS) al crear la cotización y se guarda en
 * storage/uploads/cotizaciones: va adjunto al correo, como documento en el WhatsApp y se descarga desde la vista pública.
 * Junto al PDF se guarda la huella de los datos y del diseño con que se hizo: si el inmueble cambia (precio, área, fotos…)
 * o cambia el diseño, el PDF se vuelve a generar al pedirlo, así siempre coincide con la vista en línea.
 */
final class QuotePdf
{
  /** Más grande no se adjunta al correo (los servidores de correo rechazan adjuntos grandes): queda el enlace. */
  public const MAX_EMAIL_BYTES = 15 * 1024 * 1024;
  /** Con más inmuebles, solo la foto de portada de cada uno para que el PDF no pese demasiado. */
  private const MANY_PROPERTIES = 6;
  /** Columnas por tabla de comparación: más no caben a lo ancho de una hoja carta. */
  private const COLUMNS = 4;

  private string $directory;
  /** @var callable(string,string,array<string,mixed>):?string */
  private $renderer;

  /** @param (callable(string,string,array<string,mixed>):?string)|null $renderer Por defecto, HtmlPdfRenderer::render. */
  public function __construct(string $directory, ?callable $renderer = null)
  {
    $this->directory = rtrim($directory, '/\\');
    $this->renderer = $renderer ?? [HtmlPdfRenderer::class, 'render'];
  }

  public static function fromRuntime(): ?self
  {
    return defined('SCM_UPLOAD_PATH') ? new self((string) SCM_UPLOAD_PATH . '/cotizaciones') : null;
  }

  public static function filename(int $id): string
  {
    return 'cotizacion-inmuebles-' . $id . '.pdf';
  }

  public function path(int $id): string
  {
    return $this->directory . '/cotizacion-' . $id . '.pdf';
  }

  /** Ruta del PDF guardado, o null si todavía no existe (aunque esté desactualizado). */
  public function existing(int $id): ?string
  {
    $path = $this->path($id);
    return $id > 0 && is_file($path) && filesize($path) > 0 ? $path : null;
  }

  /**
   * Ruta del PDF guardado si se hizo con estos mismos datos y este diseño; null si falta o quedó desactualizado.
   * @param array<string,mixed> $quote CommercialQuotesService::publicData()
   */
  public function current(int $id, array $quote): ?string
  {
    $path = $this->existing($id);
    return $path !== null && @file_get_contents($path . '.huella') === self::fingerprint($quote) ? $path : null;
  }

  /**
   * Huella de lo que muestra el PDF: los datos de la cotización y la hoja de estilos. Cambia si cambia un inmueble o el diseño.
   * @param array<string,mixed> $quote
   */
  public static function fingerprint(array $quote): string
  {
    return hash('sha256', (string) json_encode($quote, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) . '|' . (string) @hash_file('sha256', self::stylesheet()) . '|' . (string) @hash_file('sha256', __FILE__));
  }

  private static function stylesheet(): string
  {
    return dirname(__DIR__, 3) . '/public/assets/css/quote-document.css';
  }

  /**
   * Genera y guarda el PDF; devuelve su ruta o null si Chromium no respondió.
   * @param array<string,mixed> $quote CommercialQuotesService::publicData()
   */
  public function store(int $id, array $quote, string $viewUrl): ?string
  {
    $bytes = ($this->renderer)(self::html($quote, $viewUrl), 'Cotización de inmuebles #' . $id, [
      // Poppins de Google Fonts como en el panel; si el servicio de PDF no la descarga, usa Noto Sans incluida en el PDF.
      'stylesheets' => [self::stylesheet()],
      'external_fonts' => true, 'body_class' => 'qd-page', 'wrapper_class' => 'qd-root', 'container_class' => 'qd-main',
    ]);
    if (!is_string($bytes) || !str_starts_with($bytes, '%PDF-')) {
      return null;
    }
    if (!is_dir($this->directory) && !@mkdir($this->directory, 0750, true) && !is_dir($this->directory)) {
      return null;
    }
    $path = $this->path($id);
    $temporary = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
    if (file_put_contents($temporary, $bytes, LOCK_EX) === false || !rename($temporary, $path)) {
      @unlink($temporary);
      return null;
    }
    file_put_contents($path . '.huella', self::fingerprint($quote), LOCK_EX);
    return $path;
  }

  /** @param array<string,mixed> $quote CommercialQuotesService::publicData() */
  public static function html(array $quote, string $viewUrl): string
  {
    $items = $quote['inmuebles'];
    $count = count($items);
    $advisor = $quote['asesor'];
    $type = mb_strtolower((string) $quote['tipo'], 'UTF-8');
    $header = dirname(__DIR__, 3) . '/public/assets/img/membrete-sucasa-header.jpg';

    $html = '<article class="qd">'
      . (is_readable($header) ? '<div class="qd-letterhead"><img src="data:image/jpeg;base64,' . base64_encode((string) file_get_contents($header)) . '" alt="SKC SuCasa Inmobiliaria"></div>' : '')
      . '<header class="qd-head"><div><p class="qd-eyebrow">Cotización de inmuebles #' . (int) $quote['id'] . ($quote['fecha'] !== '' ? ' · ' . self::e($quote['fecha']) : '') . '</p>'
      . '<h1>' . self::e($quote['cliente'] !== '' ? $quote['cliente'] : 'Apreciado cliente') . '</h1>'
      . '<p>Seleccionamos ' . ($count === 1 ? 'este inmueble' : 'estos ' . $count . ' inmuebles') . ' en ' . self::e($type) . ' de acuerdo con lo que buscas'
      . ($count > 1 ? '. Al final encontrarás la comparación entre ellos.' : '.') . '</p></div>'
      . '<aside class="qd-advisor"><span>Te atiende</span><strong>' . self::e($advisor['nombre']) . '</strong>'
      . ($advisor['celular'] !== '' ? '<em>Celular ' . self::e($advisor['celular']) . '</em>' : '')
      . ($advisor['correo'] !== '' ? '<em>' . self::e($advisor['correo']) . '</em>' : '') . '</aside></header>'
      . '<p class="qd-online">Ver en línea, con todas las fotos: <a href="' . self::e($viewUrl) . '">' . self::e(self::shortUrl($viewUrl)) . '</a></p>';

    if ($items === []) {
      $html .= '<p class="qd-empty">Los inmuebles de esta cotización ya no están disponibles. Escríbele a tu asesor para recibir nuevas opciones.</p>';
    }
    $photos = $count > self::MANY_PROPERTIES ? 1 : 3;
    foreach ($items as $index => $item) {
      $html .= self::property($item, $index, $count, $photos);
    }
    if ($count > 1) {
      $html .= self::comparison($items, $quote['comparacion']);
    }
    if (trim((string) $quote['observaciones']) !== '') {
      $html .= '<section class="qd-block"><h2>Observaciones de tu asesor</h2><p class="qd-pre">' . self::e($quote['observaciones']) . '</p></section>';
    }
    if ($quote['notas'] !== []) {
      $html .= '<section class="qd-block qd-notes"><h2>Notas a la presente cotización</h2>';
      foreach ($quote['notas'] as $note) {
        $html .= '<p class="qd-pre">' . self::e($note) . '</p>';
      }
      $html .= '</section>';
    }
    $manager = $quote['gerente'];
    return $html . '<footer class="qd-foot"><p class="qd-motto">Construimos confianza, protegemos su patrimonio.</p>'
      . '<p>Te atiende <strong>' . self::e($advisor['nombre']) . '</strong>' . ($advisor['celular'] !== '' ? ' · ' . self::e($advisor['celular']) : '') . ($advisor['correo'] !== '' ? ' · ' . self::e($advisor['correo']) : '') . '</p>'
      . ($manager['nombre'] !== '' ? '<p>Gerencia comercial: ' . self::e($manager['nombre']) . ($manager['celular'] !== '' ? ' · ' . self::e($manager['celular']) : '') . '</p>' : '')
      . '<p>SKC SuCasa Inmobiliaria · NIT 900623242-4</p></footer></article>';
  }

  /** @param array<string,mixed> $item */
  private static function property(array $item, int $index, int $count, int $maxPhotos): string
  {
    $code = (string) $item['codigo'];
    $place = implode(', ', array_filter([$item['barrio'], $item['ciudad']]));
    $photos = array_slice(array_values(array_filter(array_map([self::class, 'https'], $item['fotos']), static fn(string $url): bool => str_starts_with($url, 'https://'))), 0, $maxPhotos);
    // Con pocas opciones, cada inmueble empieza en su hoja; con muchas van seguidas para no gastar hojas.
    $html = '<section class="qd-card' . ($index > 0 && $maxPhotos > 1 ? ' qd-break' : '') . '">';
    if ($photos !== []) {
      $html .= '<div class="qd-photos qd-photos-' . count($photos) . '">';
      foreach ($photos as $photo) {
        $html .= '<img src="' . self::e($photo) . '" alt="">';
      }
      $html .= '</div>';
    }
    $html .= '<div class="qd-card-head"><div><p class="qd-eyebrow">' . ($count > 1 ? 'Opción ' . ($index + 1) . ' · ' : '') . 'Código ' . self::e($code) . '</p>'
      . '<h2>' . self::e($item['tipo_inmueble']) . ($place !== '' ? ' en ' . self::e($place) : '') . '</h2>'
      . ($item['copropiedad'] !== '' ? '<p class="qd-sub">' . self::e($item['copropiedad']) . '</p>' : '')
      . '<p class="qd-portal">Ver en la página web: <a href="' . self::e($item['url_portal']) . '">' . self::e(self::shortUrl((string) $item['url_portal'])) . '</a></p></div><div class="qd-price">';
    if ($item['precio'] > 0) {
      $html .= '<span>' . self::e($item['precio_tipo'] === 'Arriendo' ? 'Canon mensual' : 'Precio de venta') . '</span><strong>' . self::e(QuoteComparison::money((float) $item['precio'])) . '</strong>';
      if ($item['admin'] > 0) {
        $html .= '<em>Administración ' . self::e(QuoteComparison::money((float) $item['admin'])) . '</em>';
      }
      $other = $item['precio_tipo'] === 'Arriendo' ? $item['precio_venta'] : $item['precio_arriendo'];
      if ($other > 0) {
        $html .= '<em>También en ' . ($item['precio_tipo'] === 'Arriendo' ? 'venta' : 'arriendo') . ': ' . self::e(QuoteComparison::money((float) $other)) . '</em>';
      }
    } else {
      $html .= '<strong>Precio a consultar</strong>';
    }
    $html .= '</div></div>';
    if (!$item['disponible']) {
      $html .= '<p class="qd-unavailable">Este inmueble ya no está disponible. Pregúntale a tu asesor por opciones similares.</p>';
    }
    $specs = [];
    if ($item['area'] > 0) $specs[] = ['Área', QuoteComparison::area((float) $item['area'])];
    if ($item['habitaciones'] > 0) $specs[] = ['Habitaciones', (string) $item['habitaciones']];
    if ($item['banos'] > 0) $specs[] = ['Baños', (string) $item['banos']];
    if ($item['parqueaderos'] > 0) $specs[] = ['Parqueaderos', (string) $item['parqueaderos']];
    if ($item['estrato'] > 0) $specs[] = ['Estrato', (string) $item['estrato']];
    if ($item['edad'] !== null) $specs[] = ['Antigüedad', $item['edad'] === 0 ? 'Nuevo' : $item['edad'] . ' años'];
    if ($item['precio'] > 0 && $item['area'] > 0) $specs[] = [$item['precio_tipo'] === 'Arriendo' ? 'Canon por m²' : 'Precio por m²', QuoteComparison::money((float) $item['precio'] / (float) $item['area'])];
    if ($specs !== []) {
      $html .= '<dl class="qd-specs">';
      foreach ($specs as [$label, $value]) {
        $html .= '<div><dt>' . self::e($label) . '</dt><dd>' . self::e($value) . '</dd></div>';
      }
      $html .= '</dl>';
    }
    if ($item['ventajas'] !== []) {
      $html .= '<div class="qd-adv"><h3>Sus ventajas frente a las otras opciones</h3><ul>';
      foreach ($item['ventajas'] as $advantage) {
        $html .= '<li>' . self::e($advantage) . '</li>';
      }
      $html .= '</ul></div>';
    }
    if ($item['descripcion'] !== '') {
      $description = (string) $item['descripcion'];
      if (mb_strlen($description, 'UTF-8') > 900) {
        $description = rtrim(mb_substr($description, 0, 900, 'UTF-8')) . '…';
      }
      $html .= '<div class="qd-text"><h3>Descripción</h3><p class="qd-pre">' . self::e($description) . '</p></div>';
    }
    foreach (QuoteComparison::FEATURE_GROUPS as $group => $label) {
      $features = $item['features'][$group] ?? [];
      if ($features !== []) {
        $html .= '<p class="qd-features"><strong>' . self::e($label) . ':</strong> ' . self::e(implode(' · ', $features)) . '</p>';
      }
    }
    return $html . '</section>';
  }

  /**
   * La comparación en tablas de a cuatro inmuebles: así cabe en el ancho de la hoja sin letra diminuta.
   * @param list<array<string,mixed>> $items
   * @param array{rows:list<array{key:string,label:string,values:list<string>,best:list<int>}>,features:list<array{group:string,label:string,items:list<array{name:string,has:list<bool>}>}>,advantages:list<list<string>>} $comparison
   */
  private static function comparison(array $items, array $comparison): string
  {
    $html = '<section class="qd-block qd-break"><h2>Comparación de los inmuebles</h2><p class="qd-hint"><span class="qd-best">Mejor</span> marca la opción que gana en cada dato.</p>';
    foreach (array_chunk(array_keys($items), self::COLUMNS) as $columns) {
      $head = '<thead><tr><th></th>';
      foreach ($columns as $index) {
        $head .= '<th>Opción ' . ($index + 1) . '<small>Código ' . self::e($items[$index]['codigo']) . '</small></th>';
      }
      $head .= '</tr></thead>';
      $html .= '<table class="qd-table">' . $head . '<tbody>';
      foreach ($comparison['rows'] as $row) {
        $html .= '<tr><th>' . self::e($row['label']) . '</th>';
        foreach ($columns as $index) {
          $best = in_array($index, $row['best'], true);
          $html .= '<td' . ($best ? ' class="is-best"' : '') . '>' . self::e($row['values'][$index] ?? '') . ($best ? ' <span class="qd-best">Mejor</span>' : '') . '</td>';
        }
        $html .= '</tr>';
      }
      $html .= '</tbody></table>';
      foreach ($comparison['features'] as $group) {
        $rows = '';
        foreach ($group['items'] as $feature) {
          $cells = '';
          $any = false;
          foreach ($columns as $index) {
            $has = !empty($feature['has'][$index]);
            $any = $any || $has;
            $cells .= '<td class="' . ($has ? 'is-yes' : 'is-no') . '">' . ($has ? '✓' : '–') . '</td>';
          }
          if ($any) {
            $rows .= '<tr><th>' . self::e($feature['name']) . '</th>' . $cells . '</tr>';
          }
        }
        if ($rows !== '') {
          $html .= '<table class="qd-table qd-check"><thead><tr><th>' . self::e($group['label']) . '</th>';
          foreach ($columns as $index) {
            $html .= '<th>Opción ' . ($index + 1) . '</th>';
          }
          $html .= '</tr></thead><tbody>' . $rows . '</tbody></table>';
        }
      }
    }
    return $html . '</section>';
  }

  private static function shortUrl(string $url): string
  {
    $short = (string) preg_replace('#^https?://(www\.)?#i', '', $url);
    return mb_strlen($short, 'UTF-8') > 70 ? mb_substr($short, 0, 67, 'UTF-8') . '…' : $short;
  }

  private static function https(string $url): string
  {
    return (string) preg_replace('#^http://#i', 'https://', trim($url));
  }

  private static function e($value): string
  {
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  }
}
