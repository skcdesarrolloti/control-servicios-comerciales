<?php

declare(strict_types=1);

namespace SCM\Commercial;

final class CommercialTaskAssistant
{
  private string $apiKey;
  private string $baseUrl;
  private string $model;
  private int $timeout;

  public function __construct(string $apiKey, string $baseUrl = 'https://api.minimax.io/v1', string $model = 'MiniMax-M3', int $timeout = 45)
  {
    $this->apiKey = trim($apiKey);
    $this->baseUrl = rtrim(trim($baseUrl), '/');
    $this->model = trim($model) !== '' ? trim($model) : 'MiniMax-M3';
    $this->timeout = max(10, min(55, $timeout));
  }

  public function isConfigured(): bool
  {
    return $this->apiKey !== '' && $this->baseUrl !== '';
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

  /** @param mixed $value */
  private function text($value, int $limit = 1200): string
  {
    $text = trim(html_entity_decode(strip_tags((string) $value), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
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
      'model' => $this->model,
      'temperature' => 0.2,
      'max_completion_tokens' => 1800,
      'thinking' => ['type' => 'disabled'],
      'messages' => [
        [
          'role' => 'system',
          'content' => 'Eres un asistente comercial inmobiliario de SuCasa. Analiza tareas comerciales con criterio práctico, sin inventar datos. Responde siempre en español claro para un consultor. Si falta información, indícalo como dato faltante. No reveles razonamiento interno.',
        ],
        [
          'role' => 'user',
          'content' => "Analiza esta tarea comercial. Usa la información de la tarea, inmueble, historial, respuestas, seguimientos, notas y adjuntos disponibles. Devuelve únicamente JSON válido con estas llaves: resumen, cliente, estado_actual, riesgos, oportunidades, recomendaciones, proximos_pasos, mensaje_sugerido, datos_faltantes. Las llaves riesgos, oportunidades, recomendaciones, proximos_pasos y datos_faltantes deben ser arreglos de textos breves.\n\nContexto:\n{$contextJson}",
        ],
      ],
    ];

    $response = $this->postJson($this->baseUrl . '/chat/completions', $payload);
    $content = $response['choices'][0]['message']['content'] ?? '';
    if (is_array($content)) {
      $content = implode("\n", array_map(static fn($part): string => is_array($part) ? (string) ($part['text'] ?? '') : (string) $part, $content));
    }
    $content = trim((string) $content);
    if ($content === '') {
      throw new \RuntimeException('MiniMax no devolvió contenido para esta tarea.');
    }
    return $content;
  }

  /**
   * @param array<string,mixed> $payload
   * @return array<string,mixed>
   */
  private function postJson(string $url, array $payload): array
  {
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($body)) {
      throw new \RuntimeException('No fue posible preparar la solicitud a MiniMax.');
    }

    if (function_exists('curl_init')) {
      $raw = $this->postJsonWithCurl($url, $body);
    } else {
      $raw = $this->postJsonWithStreams($url, $body);
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
      throw new \RuntimeException('MiniMax devolvió una respuesta no válida.');
    }
    if (isset($decoded['error'])) {
      $message = is_array($decoded['error']) ? (string) ($decoded['error']['message'] ?? 'MiniMax rechazó la solicitud.') : 'MiniMax rechazó la solicitud.';
      throw new \RuntimeException($message);
    }
    return $decoded;
  }

  private function postJsonWithCurl(string $url, string $body): string
  {
    $handle = curl_init($url);
    if ($handle === false) {
      throw new \RuntimeException('No fue posible iniciar la conexión con MiniMax.');
    }
    curl_setopt_array($handle, [
      CURLOPT_POST => true,
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_CONNECTTIMEOUT => 10,
      CURLOPT_TIMEOUT => $this->timeout,
      CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . $this->apiKey,
        'Content-Type: application/json',
      ],
      CURLOPT_POSTFIELDS => $body,
    ]);
    $raw = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $error = curl_error($handle);
    curl_close($handle);

    if (!is_string($raw) || $raw === '') {
      throw new \RuntimeException($error !== '' ? ('No fue posible consultar MiniMax: ' . $error) : 'MiniMax no respondió.');
    }
    if ($status >= 400) {
      $this->throwHttpError($raw, $status);
    }
    return $raw;
  }

  private function postJsonWithStreams(string $url, string $body): string
  {
    $context = stream_context_create([
      'http' => [
        'method' => 'POST',
        'timeout' => $this->timeout,
        'ignore_errors' => true,
        'header' => "Authorization: Bearer {$this->apiKey}\r\nContent-Type: application/json\r\n",
        'content' => $body,
      ],
    ]);
    $raw = @file_get_contents($url, false, $context);
    if (!is_string($raw) || $raw === '') {
      throw new \RuntimeException('No fue posible consultar MiniMax.');
    }

    $status = 0;
    foreach (($http_response_header ?? []) as $header) {
      if (preg_match('/^HTTP\/\S+\s+(\d+)/', (string) $header, $matches)) {
        $status = (int) $matches[1];
        break;
      }
    }
    if ($status >= 400) {
      $this->throwHttpError($raw, $status);
    }
    return $raw;
  }

  private function throwHttpError(string $raw, int $status): never
  {
    $decoded = json_decode($raw, true);
    $message = is_array($decoded) && is_array($decoded['error'] ?? null)
      ? (string) ($decoded['error']['message'] ?? '')
      : '';
    throw new \RuntimeException($message !== '' ? $message : "MiniMax respondió con estado HTTP {$status}.");
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
      'model' => $this->model,
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
