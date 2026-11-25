<?php

declare(strict_types=1);

namespace SCM\Commercial\Mandates;

/**
 * Texto completo del contrato de mandato (cláusulas y anexos) para leerlo antes de la firma, con los mismos valores
 * que recibe eSignatures (MandateSignature::values, ownersTable y agreementsTable).
 *
 * La plantilla se lee de eSignatures (GET templates/{id}/content, MandateSignature::templateMarkdown) y se guarda una
 * hora en storage/data/mandatos-plantillas/: así lo que lee el propietario es siempre el texto vigente de eSignatures.
 * Si eSignatures no responde, se usa la última copia guardada o, si no hay, la de resources/mandatos/.
 * Del Markdown de eSignatures se entiende: párrafos, **negrita**, "# título", tablas "| a | b |" y las marcas
 * `{"…"}` (alineación, casilla de aceptación), que se quitan.
 */
final class MandateContractText
{
  public const FILES = ['Por defecto' => 'por-defecto.md', 'Berkley' => 'berkley.md', 'Seguros mundial' => 'seguros-mundial.md'];
  public const CACHE_SECONDS = 3600;
  /** Campos que llegan como Markdown (tablas) y no como texto. */
  private const MARKDOWN_FIELDS = ['tabla_propietarios', 'tabla_acuerdos'];

  /**
   * El contrato del mandato en HTML, o '' si su tipo no tiene plantilla.
   * @param array<string,mixed> $mandate fila de wp_jet_cct_contrato_mandato
   */
  public static function html(array $mandate, ?MandateCalculator $calculator = null, ?MandateSignature $signature = null, ?string $cacheDirectory = null): string
  {
    $post = MandateFields::toForm($mandate);
    $template = self::template(trim((string) ($post['tipo_contrato'] ?? '')), $signature, $cacheDirectory);
    if ($template === '') {
      return '';
    }
    try {
      $credit = $calculator?->credit($post);
    } catch (\InvalidArgumentException) {
      $credit = null;
    }
    return self::render($template, MandateSignature::values($post, $credit), [
      'tabla_propietarios' => MandateSignature::ownersTable($post),
      'tabla_acuerdos' => MandateSignature::agreementsTable($post),
    ]);
  }

  /** Markdown vigente de la plantilla: eSignatures (con caché de una hora), la última copia o la de resources/. */
  public static function template(string $type, ?MandateSignature $signature = null, ?string $cacheDirectory = null): string
  {
    $file = self::FILES[$type] ?? '';
    if ($file === '') {
      return '';
    }
    $cache = rtrim($cacheDirectory ?? dirname(__DIR__, 3) . '/storage/data/mandatos-plantillas', '/\\') . '/' . $file;
    if ($signature !== null && (!is_file($cache) || filemtime($cache) < time() - self::CACHE_SECONDS)) {
      try {
        $markdown = $signature->templateMarkdown($type);
        if (trim($markdown) !== '') {
          if (!is_dir(dirname($cache))) {
            @mkdir(dirname($cache), 0775, true);
          }
          @file_put_contents($cache, $markdown);
          return $markdown;
        }
      } catch (\Throwable $error) {
        error_log('[mandatos:plantilla] No se pudo leer la plantilla ' . $type . ' de eSignatures: ' . $error->getMessage());
        @touch($cache); // No volver a intentarlo en cada visita durante la próxima hora.
      }
    }
    foreach ([$cache, dirname(__DIR__, 3) . '/resources/mandatos/' . $file] as $path) {
      if (is_file($path) && filesize($path) > 0) {
        return (string) file_get_contents($path);
      }
    }
    return '';
  }

  /**
   * @param array<string,string> $values {{campo}} => texto
   * @param array<string,string> $markdown {{campo}} => Markdown (tablas)
   */
  public static function render(string $template, array $values, array $markdown = []): string
  {
    $template = str_replace(["\r\n", "\r"], "\n", $template);
    foreach (self::MARKDOWN_FIELDS as $field) {
      $template = str_replace('{{' . $field . '}}', "\n" . ($markdown[$field] ?? MandateSignature::NOT_APPLICABLE) . "\n", $template);
    }
    // Los demás {{campo}} se cambian por marcas y se llenan al final, ya escapados (un valor con | o ** no rompe nada).
    $template = (string) preg_replace_callback('/\{\{\s*([\w-]+)\s*\}\}/u', static fn(array $m): string => "\u{E000}" . $m[1] . "\u{E001}", $template);
    $html = '';
    /** @var list<string> $table filas de la tabla en curso */
    $table = [];
    foreach (explode("\n", $template) as $line) {
      $checkbox = str_contains($line, '"input_type"');
      $line = trim((string) preg_replace('/`\{[^`]*\}`/u', '', $line));
      if ($line === '') {
        $html .= self::flush($table);
        continue;
      }
      if (str_starts_with($line, '|')) {
        $cells = array_map('trim', explode('|', trim($line, '|')));
        if (preg_match('/^\|?\s*:?-{3,}/', $line) === 1) {
          // Lo que va antes del separador es el encabezado de la tabla, como en eSignatures.
          if (count($table) === 1) {
            $table[0] = '<tr class="pm-contract-head">' . substr($table[0], 4);
          }
          continue;
        }
        if (implode('', $cells) === '') {
          continue;
        }
        $row = '';
        foreach ($cells as $index => $cell) {
          $row .= '<td' . ($index === 0 && count($cells) > 1 ? ' class="pm-contract-key"' : '') . '>' . self::inline($cell, $values) . '</td>';
        }
        $table[] = '<tr>' . $row . '</tr>';
        continue;
      }
      $html .= self::flush($table);
      if (str_starts_with($line, '#')) {
        $html .= '<h3>' . self::inline(ltrim($line, '# '), $values) . '</h3>';
      } elseif ($checkbox) {
        $html .= '<p class="pm-contract-accept">☐ ' . self::inline($line, $values) . '</p>';
      } else {
        $html .= '<p>' . self::inline($line, $values) . '</p>';
      }
    }
    $html .= self::flush($table);
    return $html;
  }

  /** Cierra la tabla en curso. @param list<string> $table */
  private static function flush(array &$table): string
  {
    $html = $table === [] ? '' : '<div class="pm-contract-table"><table>' . implode('', $table) . '</table></div>';
    $table = [];
    return $html;
  }

  /** @param array<string,string> $values */
  private static function inline(string $text, array $values): string
  {
    $html = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $html = (string) preg_replace('/\*\*(.+?)\*\*/su', '<strong>$1</strong>', $html);
    $html = str_replace('**', '', $html);
    return (string) preg_replace_callback('/\x{E000}([\w-]+)\x{E001}/u', static function (array $m) use ($values): string {
      $value = trim((string) ($values[$m[1]] ?? ''));
      return '<span class="pm-contract-value">' . htmlspecialchars($value !== '' ? $value : MandateSignature::NOT_APPLICABLE, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span>';
    }, $html);
  }
}
