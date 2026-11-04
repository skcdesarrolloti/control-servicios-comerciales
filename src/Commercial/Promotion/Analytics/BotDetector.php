<?php

declare(strict_types=1);

namespace SCM\Commercial\Promotion\Analytics;

/**
 * Reconoce robots, rastreadores, generadores de vista previa y clientes HTTP por su user agent.
 * dashboard-marketing no los separaba: cerca del 13 % de las visitas del tracker son de robots.
 * Un user agent vacío también cuenta como robot (los navegadores siempre lo envían).
 */
final class BotDetector
{
  /**
   * Patrón compartido por el rollup y el visor de registros. Sin delimitadores; se aplica sin distinguir mayúsculas.
   * Solo nombres de robots y herramientas: nada de palabras sueltas que también aparecen en navegadores reales
   * ("monitor", "preview", "uptime"), ni la marca de celulares CUBOT, ni los navegadores de DuckDuckGo y Telegram.
   */
  public const PATTERN = '(?<!cu)bot\b|(?<!cu)bot/|crawl|spider|slurp|scrap|archiver|facebookexternalhit|facebookcatalog|meta-externalagent|'
    . 'whatsapp/|skypeuripreview|embedly|google web preview|headless|phantomjs|puppeteer|playwright|selenium|'
    . 'python-|python/|curl/|wget/|go-http-client|java/|okhttp|axios/|node-fetch|undici|httpclient|libwww|guzzlehttp|'
    . 'lighthouse|pagespeed|gtmetrix|pingdom|uptimerobot|uptime-kuma|betteruptime|statuscake|site24x7|newrelic|datadog|hetrixtools|freshping|validator|'
    . 'ahrefs|semrush|mj12|petalbot|bytespider|gptbot|chatgpt|oai-searchbot|claude|anthropic|ccbot|perplexity|'
    . 'amazonbot|applebot|yandex|baiduspider|duckduckbot|duckassistbot|sogou|bingpreview|google-inspectiontool|google-read-aloud|adsbot|'
    . 'mediapartners|feedfetcher|zgrab|masscan|nmap|nikto|sqlmap|censys|expanse|netcraft';

  public static function isBot(?string $userAgent): bool
  {
    $userAgent = trim((string) $userAgent);
    if ($userAgent === '') {
      return true;
    }
    return preg_match('~' . self::PATTERN . '~i', $userAgent) === 1;
  }
}
