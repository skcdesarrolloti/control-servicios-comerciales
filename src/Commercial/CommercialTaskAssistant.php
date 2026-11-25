<?php

declare(strict_types=1);

namespace SCM\Commercial;

final class CommercialTaskAssistant
{
  private MiniMaxChat $chat;

  public function __construct(string $apiKey, string $baseUrl = 'https://api.minimax.io/v1', string $model = 'MiniMax-M3', int $timeout = 45)
  {
    $this->chat = new MiniMaxChat($apiKey, $baseUrl, $model, $timeout);
  }

  public function isConfigured(): bool
  {
    return $this->chat->isConfigured();
  }

  /**
   * @param array{ticket:array<string,mixed>,timeline:array<int,array<string,mixed>>} $detail
   * @return array<string,mixed>
   */
  public function analyze(array $detail): array
  {
    if (!$this->isConfigured()) {
      throw new \RuntimeException('Configura MINIMAX_API_KEY en el .env para usar el asistente.');
    }

    $context = $this->buildContext($detail);
    $content = $this->requestCompletion($context);
    return $this->normalizeAnalysis($content);
  }

  /**
   * @param array{ticket:array<string,mixed>,timeline:array<int,array<string,mixed>>} $detail
   * @return array<string,mixed>
   */
  private function buildContext(array $detail): array
  {
    $ticket = is_array($detail['ticket'] ?? null) ? $detail['ticket'] : [];
    $property = is_array($ticket['_scm_inmueble_data'] ?? null) ? $ticket['_scm_inmueble_data'] : [];
    $timeline = is_array($detail['timeline'] ?? null) ? $detail['timeline'] : [];

    return [
      'tarea' => $this->normalizeMap($ticket, 1200),
      'inmueble' => [
        'id_inmueble' => $this->text($ticket['id_inmueble'] ?? ''),
        'codigo' => $this->text($property['codigo'] ?? $ticket['inmueble'] ?? ''),
        'tipo' => $this->text($property['tipo_inmueble'] ?? $ticket['tipo_inmueble'] ?? ''),
        'barrio' => $this->text($property['barrio'] ?? $ticket['barrio'] ?? ''),
        'ciudad' => $this->text($property['ciudad'] ?? ''),
        'direccion' => $this->text($property['direccion'] ?? $ticket['direccion'] ?? ''),
        'url' => $this->text($ticket['_scm_inmueble_url'] ?? ''),
      ],
      'actividad' => array_map(fn(array $item): array => $this->normalizeTimelineItem($item), array_slice($timeline, 0, 100)),
      'conteo_actividad' => [
        'total' => count($timeline),
        'respuestas' => $this->countTimelineType($timeline, 'respuesta'),
        'seguimientos' => $this->countTimelineType($timeline, 'seguimiento'),
        'notas' => $this->countTimelineType($timeline, 'nota'),
      ],
    ];
  }

  /** @param array<string,mixed> $item @return array<string,mixed> */
  private function normalizeTimelineItem(array $item): array
  {
    return [
      'tipo' => $this->text($item['type'] ?? ''),
      'autor' => $this->text($item['nombre'] ?? ''),
      'id_autor' => $this->text($item['actor_id'] ?? ''),
      'correo_autor' => $this->text($item['actor_email'] ?? ''),
      'fecha' => $this->formatDate($item['_timestamp'] ?? $item['fecha'] ?? $item['cct_created'] ?? ''),
      'mensaje' => $this->text($item['message'] ?? '', 2500),
      'tiene_evidencia' => trim((string) ($item['image'] ?? '')) !== '',
      'tiene_documentos' => trim((string) ($item['documents'] ?? '')) !== '',
    ];
  }

  /**
   * @param array<string,mixed> $values
   * @return array<string,string>
   */
  private function normalizeMap(array $values, int $limit): array
  {
    $normalized = [];
    foreach ($values as $key => $value) {
      if (is_scalar($value) || $value === null) {
        $clean = $this->text($value, $limit);
        if ($clean !== '') {
          $normalized[(string) $key] = $clean;
        }
      }
    }
    return $normalized;
  }

  /** @param array<int,array<string,mixed>> $timeline */
  private function countTimelineType(array $timeline, string $type): int
  {
    $count = 0;
    foreach ($timeline as $item) {
      if ((string) ($item['type'] ?? '') === $type) {
        $count++;
      }
    }
    return $count;
  }

  /**
   * Limpia lo que va y viene del modelo; también se aplica a los análisis ya guardados.
   * - Marcadores de plantilla sin reemplazar (${solicitante}, {{1}}): no son datos, ni se mandan ni se muestran.
   * - Palabras de otros alfabetos (가치, 价值) que el modelo a veces mete: el panel solo muestra español.
   */
  public static function stripNoise(string $text): string
  {
    $text = preg_replace('/\s*[-–—:]?\s*(?:\$\{[^}]*\}|\{\{[^}]*\}\})/u', '', $text) ?? $text;
    $text = preg_replace('/[\p{Han}\p{Hangul}\p{Hiragana}\p{Katakana}\p{Cyrillic}\p{Arabic}\p{Hebrew}\p{Thai}\p{Devanagari}]+/u', '', $text) ?? $text;
    $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
    $text = preg_replace('/\s+([,.;:!?])/u', '$1', $text) ?? $text;
    return trim($text);
  }

  /** @param mixed $value */
  private function text($value, int $limit = 1200): string
  {
    $text = self::stripNoise(html_entity_decode(strip_tags((string) $value), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    if (mb_strlen($text) > $limit) {
      return mb_substr($text, 0, $limit - 1) . '…';
    }
    return $text;
  }

  /** @param mixed $value */
  private function formatDate($value): string
  {
    if (is_numeric($value) && (int) $value > 0) {
      return date('d/m/Y h:i a', (int) $value);
    }
    $parsed = strtotime((string) $value);
    return $parsed !== false ? date('d/m/Y h:i a', $parsed) : $this->text($value);
  }

  /** @param array<string,mixed> $context */
  private function requestCompletion(array $context): string
  {
    $contextJson = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    if (!is_string($contextJson) || $contextJson === '') {
      throw new \RuntimeException('No fue posible preparar la información de la tarea.');
    }

    $payload = [
      'messages' => [
        [
          'role' => 'system',
          'content' => 'Eres un asistente comercial inmobiliario de SuCasa. Analiza tareas comerciales con criterio práctico, sin inventar datos. Responde siempre en español claro para un consultor, solo con alfabeto latino: nunca escribas palabras ni caracteres de otros idiomas (chino, coreano, japonés u otros). Si un dato aparece como marcador de plantilla (${...} o {{...}}), trátalo como dato faltante y no lo copies. En el mensaje sugerido, trata al cliente de usted y llama "consultor/a" a quien lo atiende. Si falta información, indícalo como dato faltante. No reveles razonamiento interno.',
        ],
        [
          'role' => 'user',
          'content' => "Analiza esta tarea comercial. Usa la información de la tarea, inmueble, historial, respuestas, seguimientos, notas y adjuntos disponibles. Devuelve únicamente JSON válido con estas llaves: resumen, cliente, estado_actual, riesgos, oportunidades, recomendaciones, proximos_pasos, mensaje_sugerido, datos_faltantes. Las llaves riesgos, oportunidades, recomendaciones, proximos_pasos y datos_faltantes deben ser arreglos de textos breves.\n\nContexto:\n{$contextJson}",
        ],
      ],
    ];

    return $this->chat->complete($payload['messages'], 1800);
  }

  /** @return array<string,mixed> */
  private function normalizeAnalysis(string $content): array
  {
    $decoded = $this->decodeJsonContent($content);
    if (!is_array($decoded)) {
      $decoded = ['resumen' => $content];
    }

    return [
      'resumen' => $this->text($decoded['resumen'] ?? 'No fue posible generar un resumen claro.', 1600),
      'cliente' => $this->textValue($decoded['cliente'] ?? '', 900),
      'estado_actual' => $this->textValue($decoded['estado_actual'] ?? '', 900),
      'riesgos' => $this->normalizeList($decoded['riesgos'] ?? []),
      'oportunidades' => $this->normalizeList($decoded['oportunidades'] ?? []),
      'recomendaciones' => $this->normalizeList($decoded['recomendaciones'] ?? []),
      'proximos_pasos' => $this->normalizeList($decoded['proximos_pasos'] ?? []),
      'mensaje_sugerido' => $this->text($decoded['mensaje_sugerido'] ?? '', 1800),
      'datos_faltantes' => $this->normalizeList($decoded['datos_faltantes'] ?? []),
      'model' => $this->chat->model(),
      'generated_at' => date('d/m/Y h:i a'),
    ];
  }

  /** @return mixed */
  private function decodeJsonContent(string $content)
  {
    $clean = trim($content);
    $clean = preg_replace('/^```(?:json)?\s*/i', '', $clean) ?? $clean;
    $clean = preg_replace('/\s*```$/', '', $clean) ?? $clean;
    $decoded = json_decode($clean, true);
    if (is_array($decoded)) {
      return $decoded;
    }

    $start = strpos($clean, '{');
    $end = strrpos($clean, '}');
    if ($start !== false && $end !== false && $end > $start) {
      return json_decode(substr($clean, $start, $end - $start + 1), true);
    }
    return null;
  }

  /** @param mixed $value */
  private function textValue($value, int $limit): string
  {
    if (is_array($value)) {
      $parts = [];
      foreach ($value as $key => $item) {
        if (is_array($item)) {
          $nested = $this->textValue($item, $limit);
          if ($nested !== '') {
            $parts[] = is_string($key) ? ($key . ': ' . $nested) : $nested;
          }
          continue;
        }
        $text = $this->text($item, $limit);
        if ($text !== '') {
          $parts[] = is_string($key) ? ($key . ': ' . $text) : $text;
        }
      }
      return $this->text(implode(' · ', $parts), $limit);
    }

    return $this->text($value, $limit);
  }

  /**
   * @param mixed $value
   * @return array<int,string>
   */
  private function normalizeList($value): array
  {
    if (is_string($value)) {
      $value = preg_split('/\r?\n|•|- /', $value) ?: [$value];
    }
    if (!is_array($value)) {
      return [];
    }

    $items = [];
    foreach ($value as $item) {
      if (is_array($item)) {
        $encoded = json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $item = is_string($encoded) ? $encoded : '';
      }
      $text = $this->text($item, 280);
      if ($text !== '') {
        $items[] = $text;
      }
    }
    return array_slice(array_values(array_unique($items)), 0, 8);
  }
}
