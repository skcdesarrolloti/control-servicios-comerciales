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
   * @param array{ticket:array<string,mixed>,timeline:array<int,array<string,mixed>>,gestion?:array<string,mixed>} $detail
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
   * @param array{ticket:array<string,mixed>,timeline:array<int,array<string,mixed>>,gestion?:array<string,mixed>} $detail
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
      'postergada_hasta' => (int) ($ticket['fecha_retomar'] ?? 0) > 0 ? date('d/m/Y', (int) $ticket['fecha_retomar']) : '',
      'gestion_operativa' => self::operationalContext(is_array($detail['gestion'] ?? null) ? $detail['gestion'] : []),
    ];
  }

  /**
   * Gestión operativa de la tarea en el panel: cotizaciones → estudio de aseguradora → contrato de mandato → hoja de
   * cierre. La etapa sale de los registros, no del estado comercial escrito, para que el análisis no se quede atrás.
   * @param array<string,mixed> $gestion cotizaciones, estudios, mandatos, hoja_cierre y ocultos (lo que el cargo no ve)
   * @return array<string,mixed>
   */
  public static function operationalContext(array $gestion): array
  {
    $list = static fn(string $key): array => array_values(array_filter(is_array($gestion[$key] ?? null) ? $gestion[$key] : [], 'is_array'));
    $value = static function (array $row, string ...$keys): string {
      foreach ($keys as $key) {
        $text = is_scalar($row[$key] ?? null) ? self::stripNoise(html_entity_decode(strip_tags((string) $row[$key]), ENT_QUOTES | ENT_HTML5, 'UTF-8')) : '';
        if ($text !== '') {
          return mb_substr($text, 0, 400);
        }
      }
      return '';
    };
    $date = static function ($raw): string {
      if (is_numeric($raw) && (int) $raw > 0) {
        return date('d/m/Y', (int) $raw);
      }
      $parsed = is_string($raw) && trim($raw) !== '' ? strtotime($raw) : false;
      return $parsed !== false ? date('d/m/Y', $parsed) : '';
    };
    $clean = static fn(array $row): array => array_filter($row, static fn($item): bool => $item !== '' && $item !== []);

    $quotes = array_map(static fn(array $quote): array => $clean([
      'numero' => (string) ($quote['_ID'] ?? ''),
      'fecha' => $date($quote['cct_created'] ?? $quote['fecha'] ?? ''),
      'tipo' => $value($quote, 'tipo'),
      'inmuebles' => \SCM\Commercial\Quotes\CommercialQuotesRepository::codes($quote['inmuebles'] ?? ''),
      'enviada_a' => $value($quote, 'destinatario'),
      'observaciones' => $value($quote, 'observaciones'),
      'consultor' => $value($quote, 'creador'),
    ]), array_slice($list('cotizaciones'), 0, 10));

    $studies = array_map(static fn(array $study): array => $clean([
      'numero' => (string) ($study['_ID'] ?? ''),
      'solicitud' => $value($study, 'num_solicitud'),
      'fecha' => $date($study['cct_created'] ?? $study['fecha'] ?? ''),
      'aseguradora' => $value($study, 'aseguradora'),
      'estado' => $value($study, 'estado'),
      'estado_contrato' => $value($study, 'estado_contrato'),
      'fecha_respuesta' => $date($study['fecha_respuesta'] ?? ''),
      'faltante' => $value($study, 'faltante'),
      'razon_desistido' => $value($study, 'razon_desistido'),
      'inmueble' => $value($study, 'id_inmueble'),
      'canon' => $value($study, 'valor'),
      'posible_entrega' => $date($study['fecha_posible_entrega'] ?? ''),
    ]), array_slice($list('estudios'), 0, 10));

    $mandates = array_map(static fn(array $mandate): array => $clean([
      'numero' => (string) ($mandate['_ID'] ?? ''),
      'fecha' => $date($mandate['cct_created'] ?? $mandate['fecha'] ?? ''),
      'estado' => $value($mandate, 'estado'),
      'tipo' => $value($mandate, 'tipo_contrato', 'tipo'),
      'inmueble' => $value($mandate, 'id_inmueble'),
      'canon' => $value($mandate, 'canon', 'valor'),
      'firma_pendiente' => (int) ($mandate['rev_firma_pendiente'] ?? 0) > 0 ? 'sí' : '',
      'observaciones_pendientes' => (int) ($mandate['rev_pendientes'] ?? 0) > 0 ? (string) (int) $mandate['rev_pendientes'] : '',
    ]), array_slice($list('mandatos'), 0, 10));

    $closing = is_array($gestion['hoja_cierre'] ?? null) ? $gestion['hoja_cierre'] : [];
    $closingState = trim((string) ($closing['estado'] ?? ''));
    $approved = array_filter($studies, static fn(array $study): bool => ($study['estado'] ?? '') === 'Aprobado' && mb_strtolower((string) ($study['estado_contrato'] ?? ''), 'UTF-8') !== 'desistido');

    if ($closingState !== '') {
      $stage = 'Hoja de cierre (' . $closingState . ')';
      $next = 'Completar la hoja de cierre y la entrega del inmueble.';
    } elseif ($mandates !== []) {
      $stage = 'Contrato de mandato';
      $next = 'Lograr la firma del mandato y abrir la hoja de cierre.';
    } elseif ($approved !== []) {
      $stage = 'Estudio aprobado';
      $next = 'Elaborar y enviar el contrato de mandato.';
    } elseif ($studies !== []) {
      $stage = 'Estudio de aseguradora en curso';
      $next = 'Hacer seguimiento al estudio y completar lo que falte para que lo aprueben.';
    } elseif ($quotes !== []) {
      $stage = 'Cotización enviada';
      $next = 'Confirmar el interés del cliente en un inmueble cotizado y, si sigue, radicar el estudio de aseguradora.';
    } else {
      $stage = 'Sin cotización';
      $next = 'Enviar una cotización con inmuebles que se ajusten a lo que pide el cliente.';
    }

    $hidden = array_values(array_filter(is_array($gestion['ocultos'] ?? null) ? $gestion['ocultos'] : [], 'is_string'));
    return $clean([
      'flujo' => 'Cotización → Estudio de aseguradora → Contrato de mandato (con estudio aprobado) → Hoja de cierre',
      'etapa' => $stage,
      'siguiente_paso_del_flujo' => $next,
      'cotizaciones' => $quotes,
      'estudios_aseguradora' => $studies,
      'contratos_mandato' => $mandates,
      'sin_acceso' => $hidden,
    ]) + ['conteo' => ['cotizaciones' => count($quotes), 'estudios' => count($studies), 'estudios_aprobados' => count($approved), 'mandatos' => count($mandates)]];
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
          'content' => "Analiza esta tarea comercial. Usa la información de la tarea, inmueble, historial, respuestas, seguimientos, notas, adjuntos y la gestión operativa (cotizaciones, estudios de aseguradora, contratos de mandato y hoja de cierre). La gestión operativa es la fuente de verdad del avance: en estado_actual di en qué etapa del flujo va según gestion_operativa.etapa, y basa los próximos pasos en siguiente_paso_del_flujo y en el detalle de cada registro (aseguradora, estado del estudio, lo que falta, firma pendiente u observaciones del mandato). No recomiendes un paso que ya se hizo ni saltarte uno del flujo. Si la tarea está postergada (postergada_hasta), tenlo en cuenta. Si algo aparece en sin_acceso, no supongas su contenido. Devuelve únicamente JSON válido con estas llaves: resumen, cliente, estado_actual, riesgos, oportunidades, recomendaciones, proximos_pasos, mensaje_sugerido, datos_faltantes. Las llaves riesgos, oportunidades, recomendaciones, proximos_pasos y datos_faltantes deben ser arreglos de textos breves.\n\nContexto:\n{$contextJson}",
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
