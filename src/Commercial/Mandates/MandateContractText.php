<?php

declare(strict_types=1);

namespace SCM\Commercial\Mandates;

/**
 * Texto completo del contrato de mandato (cláusulas y anexos) para leerlo antes de la firma, con los mismos valores
 * que recibe eSignatures (MandateSignature::values, ownersTable y agreementsTable).
 *
 * Las plantillas son copia de las de eSignatures (MandateSignature::TEMPLATES), exportadas en Markdown a
 * resources/mandatos/: si se cambia una plantilla en eSignatures, reemplazar también su archivo aquí.
 * Del Markdown de eSignatures se entiende: párrafos, **negrita**, "# título", tablas "| a | b |" y las marcas
 * `{"…"}` (alineación, casilla de aceptación), que se quitan.
 */
final class MandateContractText
{
  public const FILES = ['Por defecto' => 'por-defecto.md', 'Berkley' => 'berkley.md', 'Seguros mundial' => 'seguros-mundial.md'];
  /** Campos que llegan como Markdown (tablas) y no como texto. */
  private const MARKDOWN_FIELDS = ['tabla_propietarios', 'tabla_acuerdos'];

  /**
   * El contrato del mandato en HTML, o '' si su tipo no tiene plantilla.
   * @param array<string,mixed> $mandate fila de wp_jet_cct_contrato_mandato
   */
  public static function html(array $mandate, ?MandateCalculator $calculator = null, ?string $directory = null): string
  {
    $post = MandateFields::toForm($mandate);
    $file = self::FILES[trim((string) ($post['tipo_contrato'] ?? ''))] ?? '';
    $path = rtrim($directory ?? dirname(__DIR__, 3) . '/resources/mandatos', '/\\') . '/' . $file;
    if ($file === '' || !is_file($path)) {
      return '';
    }
    try {
      $credit = $calculator?->credit($post);
    } catch (\InvalidArgumentException) {
      $credit = null;
    }
    return self::render((string) file_get_contents($path), MandateSignature::values($post, $credit), [
      'tabla_propietarios' => MandateSignature::ownersTable($post),
      'tabla_acuerdos' => MandateSignature::agreementsTable($post),
    ]);
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
