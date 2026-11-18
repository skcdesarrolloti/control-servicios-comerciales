<?php

declare(strict_types=1);

namespace SCM\Http\Controller;

use SCM\Commercial\CommercialAccessPolicy;
use SCM\Commercial\Quotes\CommercialQuotesRepository;
use SCM\Commercial\Quotes\CommercialQuotesService;
use SCM\Core\Database;
use SCM\Core\Settings;
use SCM\Support\FileRateLimiter;
use SCM\Support\PublicQuoteAccess;
use SCM\Views\PublicQuoteView;

/**
 * public/cotizacion.php: sin sesión. El permiso es el enlace firmado (PublicQuoteAccess) y hay límite por IP. El panel la
 * muestra dentro de un popup, por eso permite que la enmarque el mismo sitio.
 */
final class PublicQuotePageController
{
  private const INVALID = 'Este enlace no es válido o venció. Pide a tu asesor de SKC SuCasa Inmobiliaria un enlace nuevo.';

  private CommercialQuotesService $service;
  private FileRateLimiter $limiter;

  public function __construct(Database $db, Settings $settings, FileRateLimiter $limiter)
  {
    // La vista pública no usa permisos de cargo: el enlace firmado es el permiso.
    $this->service = new CommercialQuotesService(new CommercialQuotesRepository($db), $settings, new CommercialAccessPolicy($settings, $db, []));
    $this->limiter = $limiter;
  }

  /** @param array<string,mixed> $query */
  public function handle(array $query, string $method, string $ip): void
  {
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-store, private');
    header('Referrer-Policy: no-referrer');
    header('X-Content-Type-Options: nosniff');
    header('X-Robots-Tag: noindex, nofollow');
    header("Content-Security-Policy: default-src 'none'; style-src 'self'; img-src 'self' https:; base-uri 'none'; form-action 'none'; frame-ancestors 'self'");

    if (!in_array($method, ['GET', 'HEAD'], true)) {
      $this->page(405, null, 'Método no permitido.');
      return;
    }
    if (!$this->limiter->consume('public-quote:' . $ip, 120, 60)) {
      header('Retry-After: 60');
      $this->page(429, null, 'Espera un momento y vuelve a abrir el enlace.');
      return;
    }
    $id = PublicQuoteAccess::verify(is_string($query['c'] ?? null) ? $query['c'] : '');
    $quote = $id > 0 ? $this->service->publicData($id) : null;
    $this->page($quote === null ? 403 : 200, $quote, $quote === null ? self::INVALID : '');
  }

  /** @param array<string,mixed>|null $quote */
  private function page(int $status, ?array $quote, string $error): void
  {
    http_response_code($status);
    $css = rtrim((string) SCM_BASE_URL, '/') . '/assets/css/public-quote.css?v=' . substr((string) @hash_file('sha256', SCM_PUBLIC_PATH . '/assets/css/public-quote.css'), 0, 12);
    echo PublicQuoteView::render($quote, $css, function_exists('system_image') ? system_image('portal_logo_url') : '', $error);
  }
}
