<?php

declare(strict_types=1);

namespace SCM\Views;

use SCM\Commercial\Calls\CommercialCallsNotifier;
use SCM\Commercial\Quotes\QuoteComparison;
use SCM\Commercial\Quotes\QuotePdf;

/**
 * Cotización de inmuebles pública (public/cotizacion.php): la ve el cliente desde el enlace del correo o del WhatsApp.
 * Fotos de cada inmueble, sus datos, sus ventajas frente a los demás, la comparación completa y el contacto del asesor.
 * Funciona sin JavaScript (las galerías se deslizan con CSS) y no muestra datos internos del inmueble.
 */
final class PublicQuoteView
{
  private static function e($value): string
  {
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  }

  /**
   * @param array<string,mixed>|null $quote PublicQuote data de CommercialQuotesService::publicData().
   * @param string $pdfUrl Enlace firmado del PDF (PublicQuoteAccess::pdfUrl).
   */
  public static function render(?array $quote, string $css, string $logo, string $error = '', string $pdfUrl = ''): string
  {
    $title = $quote !== null ? 'Cotización de inmuebles #' . (int) $quote['id'] : 'Cotización no disponible';
    $html = '<!doctype html><html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow">'
      . '<title>' . self::e($title) . ' · SKC SuCasa Inmobiliaria</title><link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>'
      . '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&amp;display=swap"><link rel="stylesheet" href="' . self::e($css) . '"></head><body>'
      . '<header class="pq-top"><div class="pq-wrap pq-top-in"><img src="' . self::e(self::https($logo)) . '" alt="SKC SuCasa Inmobiliaria" class="pq-logo"><span class="pq-brand">SKC SuCasa Inmobiliaria</span></div></header><main>';
    if ($quote === null) {
      return $html . '<section class="pq-wrap pq-empty"><h1>' . self::e($title) . '</h1><p role="alert">' . self::e($error) . '</p></section></main></body></html>';
    }
    $items = $quote['inmuebles'];
    $count = count($items);
    $type = mb_strtolower((string) $quote['tipo'], 'UTF-8');
    $advisor = $quote['asesor'];
    $whatsapp = self::whatsapp((string) $advisor['celular']);

    $html .= '<section class="pq-hero"><div class="pq-wrap pq-hero-in"><div class="pq-hero-text">'
      . '<p class="pq-eyebrow">Cotización #' . (int) $quote['id'] . ($quote['fecha'] !== '' ? ' · ' . self::e($quote['fecha']) : '') . '</p>'
      . '<h1>Hola, ' . self::e($quote['cliente'] !== '' ? $quote['cliente'] : 'apreciado cliente') . '</h1>'
      . '<p>Seleccionamos ' . ($count === 1 ? 'este inmueble' : 'estos ' . $count . ' inmuebles') . ' en ' . self::e($type) . ' de acuerdo con lo que buscas. Revisa las fotos, ' . ($count > 1 ? 'compara sus ventajas ' : 'sus características ') . 'y escríbenos para visitarlos.</p>';
    if ($count > 1) {
      $html .= '<nav class="pq-jump" aria-label="Ir a"><a href="#comparacion">Ver la comparación</a>';
      foreach ($items as $index => $item) {
        $html .= '<a href="#inmueble-' . self::e($item['codigo']) . '">' . ($index + 1) . '. ' . self::e($item['tipo_inmueble']) . ' en ' . self::e($item['barrio'] ?: $item['ciudad']) . '</a>';
      }
      $html .= '</nav>';
    }
    $html .= '</div>' . self::advisorCard($advisor, $whatsapp, (int) $quote['id'], $pdfUrl) . '</div></section>';

    if ($items === []) {
      $html .= '<section class="pq-wrap pq-empty"><p>Los inmuebles de esta cotización ya no están disponibles. Escríbele a tu asesor para recibir nuevas opciones.</p></section>';
    }
    $html .= '<div class="pq-wrap pq-list">';
    foreach ($items as $index => $item) {
      $html .= self::property($item, $index, $count, $whatsapp, (int) $quote['id']);
    }
    $html .= '</div>';
    if ($count > 1) {
      $html .= self::comparison($items, $quote['comparacion']);
    }
    if (trim((string) $quote['observaciones']) !== '') {
      $html .= '<section class="pq-wrap pq-block"><h2>Observaciones de tu asesor</h2><p class="pq-pre">' . self::e($quote['observaciones']) . '</p></section>';
    }
    if ($quote['notas'] !== []) {
      $html .= '<section class="pq-wrap pq-block pq-notes"><h2>Notas a la presente cotización</h2>';
      foreach ($quote['notas'] as $note) {
        $html .= '<p class="pq-pre">' . self::e($note) . '</p>';
      }
      $html .= '</section>';
    }
    $manager = $quote['gerente'];
    $html .= '</main><footer class="pq-foot"><div class="pq-wrap"><p class="pq-motto">Construimos confianza, protegemos su patrimonio.</p>'
      . '<p>Te atiende <strong>' . self::e($advisor['nombre']) . '</strong>' . ($advisor['celular'] !== '' ? ' · ' . self::e($advisor['celular']) : '') . ($advisor['correo'] !== '' ? ' · ' . self::e($advisor['correo']) : '') . '</p>'
      . ($manager['nombre'] !== '' ? '<p>Gerencia comercial: ' . self::e($manager['nombre']) . ($manager['celular'] !== '' ? ' · ' . self::e($manager['celular']) : '') . '</p>' : '')
      . '<p class="pq-copy">© ' . date('Y') . ' SKC SuCasa Inmobiliaria</p></div></footer></body></html>';
    return $html;
  }

  /** @param array{nombre:string,correo:string,celular:string} $advisor */
  private static function advisorCard(array $advisor, string $whatsapp, int $id, string $pdfUrl): string
  {
    $html = '<aside class="pq-advisor" aria-label="Tu asesor"><p class="pq-eyebrow">Tu asesor</p><p class="pq-advisor-name">' . self::e($advisor['nombre']) . '</p><div class="pq-actions">';
    if ($whatsapp !== '') {
      $html .= '<a class="pq-btn pq-btn-wa" href="' . self::e($whatsapp . '?text=' . rawurlencode('Hola ' . $advisor['nombre'] . ', vi la cotización #' . $id . ' y quiero más información.')) . '" target="_blank" rel="noopener">Escribir por WhatsApp</a>';
    }
    if ($advisor['celular'] !== '') {
      $html .= '<a class="pq-btn pq-btn-ghost" href="tel:' . self::e(preg_replace('/[^0-9+]/', '', $advisor['celular'])) . '">Llamar · ' . self::e($advisor['celular']) . '</a>';
    }
    if ($advisor['correo'] !== '') {
      $html .= '<a class="pq-btn pq-btn-ghost" href="mailto:' . self::e($advisor['correo']) . '?subject=' . rawurlencode('Cotización #' . $id) . '">' . self::e($advisor['correo']) . '</a>';
    }
    if ($pdfUrl !== '') {
      $html .= '<a class="pq-btn pq-btn-ghost" href="' . self::e($pdfUrl) . '" download="' . self::e(QuotePdf::filename($id)) . '">Descargar en PDF</a>';
    }
    return $html . '</div></aside>';
  }

  /** @param array<string,mixed> $item */
  private static function property(array $item, int $index, int $count, string $whatsapp, int $id): string
  {
    $code = (string) $item['codigo'];
    $photos = array_map([self::class, 'https'], $item['fotos']);
    $place = implode(', ', array_filter([$item['barrio'], $item['ciudad']]));
    $html = '<article class="pq-card" id="inmueble-' . self::e($code) . '" aria-labelledby="pq-title-' . self::e($code) . '">';
    if ($photos !== []) {
      $html .= '<div class="pq-gallery"><div class="pq-strip" tabindex="0" aria-label="Fotos del inmueble ' . self::e($code) . '">';
      foreach ($photos as $photoIndex => $photo) {
        $html .= '<img src="' . self::e($photo) . '" alt="' . self::e($item['tipo_inmueble'] . ' código ' . $code . ', foto ' . ($photoIndex + 1)) . '"' . ($photoIndex > 0 || $index > 0 ? ' loading="lazy"' : '') . ' decoding="async">';
      }
      $html .= '</div>' . (count($photos) > 1 ? '<span class="pq-count">' . count($photos) . ' fotos · desliza para verlas</span>' : '') . '</div>';
    } else {
      $html .= '<div class="pq-gallery pq-nophoto">Sin fotos por ahora</div>';
    }
    $html .= '<div class="pq-card-body"><div class="pq-card-head"><div>'
      . '<p class="pq-eyebrow">' . ($count > 1 ? 'Opción ' . ($index + 1) . ' · ' : '') . 'Código ' . self::e($code) . '</p>'
      . '<h2 id="pq-title-' . self::e($code) . '">' . self::e($item['tipo_inmueble']) . ($place !== '' ? ' en ' . self::e($place) : '') . '</h2>'
      . ($item['copropiedad'] !== '' ? '<p class="pq-sub">' . self::e($item['copropiedad']) . '</p>' : '')
      . '</div><div class="pq-price">';
    if ($item['precio'] > 0) {
      $html .= '<span class="pq-price-label">' . self::e($item['precio_tipo'] === 'Arriendo' ? 'Canon mensual' : 'Precio de venta') . '</span><strong>' . self::e(QuoteComparison::money((float) $item['precio'])) . '</strong>';
      if ($item['admin'] > 0) {
        $html .= '<span class="pq-price-extra">Administración ' . self::e(QuoteComparison::money((float) $item['admin'])) . '</span>';
      }
      $other = $item['precio_tipo'] === 'Arriendo' ? $item['precio_venta'] : $item['precio_arriendo'];
      if ($other > 0) {
        $html .= '<span class="pq-price-extra">También en ' . ($item['precio_tipo'] === 'Arriendo' ? 'venta' : 'arriendo') . ': ' . self::e(QuoteComparison::money((float) $other)) . '</span>';
      }
    } else {
      $html .= '<strong class="pq-price-ask">Precio a consultar</strong>';
    }
    $html .= '</div></div>';
    if (!$item['disponible']) {
      $html .= '<p class="pq-unavailable" role="status">Este inmueble ya no está disponible. Pregúntale a tu asesor por opciones similares.</p>';
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
      $html .= '<dl class="pq-specs">';
      foreach ($specs as [$label, $value]) {
        $html .= '<div><dt>' . self::e($label) . '</dt><dd>' . self::e($value) . '</dd></div>';
      }
      $html .= '</dl>';
    }
    if ($item['ventajas'] !== []) {
      $html .= '<div class="pq-adv"><h3>Sus ventajas frente a las otras opciones</h3><ul>';
      foreach ($item['ventajas'] as $advantage) {
        $html .= '<li>' . self::e($advantage) . '</li>';
      }
      $html .= '</ul></div>';
    }
    if ($item['descripcion'] !== '') {
      $html .= '<details class="pq-details"' . (mb_strlen($item['descripcion'], 'UTF-8') <= 420 ? ' open' : '') . '><summary>Descripción</summary><p class="pq-pre">' . self::e($item['descripcion']) . '</p></details>';
    }
    foreach (QuoteComparison::FEATURE_GROUPS as $group => $label) {
      $features = $item['features'][$group] ?? [];
      if ($features !== []) {
        $html .= '<details class="pq-details"><summary>' . self::e($label) . ' <span>(' . count($features) . ')</span></summary><ul class="pq-tags">';
        foreach ($features as $feature) {
          $html .= '<li>' . self::e($feature) . '</li>';
        }
        $html .= '</ul></details>';
      }
    }
    $html .= '<div class="pq-actions">';
    if ($whatsapp !== '' && $item['disponible']) {
      $html .= '<a class="pq-btn pq-btn-wa" href="' . self::e($whatsapp . '?text=' . rawurlencode('Hola, me interesa el inmueble código ' . $code . ' de la cotización #' . $id . '. ¿Podemos agendar una visita?')) . '" target="_blank" rel="noopener">Me interesa: agendar visita</a>';
    }
    $html .= '<a class="pq-btn pq-btn-ghost" href="' . self::e($item['url_portal']) . '" target="_blank" rel="noopener">Ver en la página web</a>';
    if ($item['video'] !== '') {
      $html .= '<a class="pq-btn pq-btn-ghost" href="' . self::e($item['video']) . '" target="_blank" rel="noopener">Ver video</a>';
    }
    return $html . '</div></div></article>';
  }

  /**
   * @param list<array<string,mixed>> $items
   * @param array{rows:list<array{key:string,label:string,values:list<string>,best:list<int>}>,features:list<array{group:string,label:string,items:list<array{name:string,has:list<bool>}>}>,advantages:list<list<string>>} $comparison
   */
  private static function comparison(array $items, array $comparison): string
  {
    $head = '<thead><tr><th scope="col"><span class="pq-sr">Dato</span></th>';
    foreach ($items as $index => $item) {
      $photo = self::https((string) ($item['fotos'][0] ?? ''));
      $head .= '<th scope="col"><a href="#inmueble-' . self::e($item['codigo']) . '">' . ($photo !== '' ? '<img src="' . self::e($photo) . '" alt="" loading="lazy">' : '') . '<span>Opción ' . ($index + 1) . '</span><small>Código ' . self::e($item['codigo']) . '</small></a></th>';
    }
    $head .= '</tr></thead>';
    $html = '<section class="pq-wrap pq-block" id="comparacion" aria-labelledby="pq-compare-title"><h2 id="pq-compare-title">Comparación de los inmuebles</h2>'
      . '<p class="pq-hint"><span class="pq-best-key">Mejor</span> marca la opción que gana en cada dato.</p>'
      . '<div class="pq-table-wrap" tabindex="0" role="region" aria-label="Tabla de comparación"><table class="pq-table">' . $head . '<tbody>';
    foreach ($comparison['rows'] as $row) {
      $html .= '<tr><th scope="row">' . self::e($row['label']) . '</th>';
      foreach ($row['values'] as $index => $value) {
        $best = in_array($index, $row['best'], true);
        $html .= '<td' . ($best ? ' class="is-best"' : '') . '>' . self::e($value) . ($best ? ' <span class="pq-best-key">Mejor</span>' : '') . '</td>';
      }
      $html .= '</tr>';
    }
    $html .= '</tbody></table></div>';
    if ($comparison['features'] !== []) {
      $html .= '<h3 class="pq-h3">Lo que tiene cada uno</h3>';
      foreach ($comparison['features'] as $group) {
        $html .= '<details class="pq-details pq-feature-group"><summary>' . self::e($group['label']) . ' <span>(' . count($group['items']) . ')</span></summary><div class="pq-table-wrap" tabindex="0" role="region" aria-label="' . self::e($group['label']) . ' de cada inmueble"><table class="pq-table pq-check">' . $head . '<tbody>';
        foreach ($group['items'] as $feature) {
          $html .= '<tr><th scope="row">' . self::e($feature['name']) . '</th>';
          foreach ($feature['has'] as $has) {
            $html .= '<td class="' . ($has ? 'is-yes' : 'is-no') . '">' . ($has ? '<span aria-hidden="true">✓</span><span class="pq-sr">Sí</span>' : '<span aria-hidden="true">–</span><span class="pq-sr">No</span>') . '</td>';
          }
          $html .= '</tr>';
        }
        $html .= '</tbody></table></div></details>';
      }
    }
    return $html . '</section>';
  }

  /** Enlace de WhatsApp del asesor, o '' si su celular no sirve. */
  private static function whatsapp(string $phone): string
  {
    $international = CommercialCallsNotifier::phone($phone);
    return $international !== '' ? 'https://wa.me/' . ltrim($international, '+') : '';
  }

  /** Las fotos de WordPress pueden venir con http: la página es https. */
  private static function https(string $url): string
  {
    return (string) preg_replace('#^http://#i', 'https://', trim($url));
  }
}
