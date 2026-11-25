<?php

declare(strict_types=1);

namespace SCM\Commercial;

/**
 * Conexión con MiniMax (API compatible con OpenAI) que comparten el asistente de tareas y el llenado inteligente de
 * llamadas. Se configura con MINIMAX_API_KEY, MINIMAX_BASE_URL, MINIMAX_MODEL y MINIMAX_TIMEOUT en el .env.
 */
final class MiniMaxChat
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

  /** @param array<string,mixed> $config La configuración de la app (config/app.php). */
  public static function fromConfig(array $config): self
  {
    return new self(
      (string) ($config['minimax_api_key'] ?? ''),
      (string) ($config['minimax_base_url'] ?? 'https://api.minimax.io/v1'),
      (string) ($config['minimax_model'] ?? 'MiniMax-M3'),
      (int) ($config['minimax_timeout'] ?? 45)
    );
  }

  public function isConfigured(): bool
  {
    return $this->apiKey !== '' && $this->baseUrl !== '';
  }

  public function model(): string
  {
    return $this->model;
  }

  /**
   * Envía la conversación y devuelve el texto de la respuesta.
   * @param list<array{role:string,content:string}> $messages
   */
  public function complete(array $messages, int $maxTokens = 1800, float $temperature = 0.2): string
  {
    if (!$this->isConfigured()) {
      throw new \RuntimeException('Configura MINIMAX_API_KEY en el .env para usar el asistente.');
    }
    $response = $this->postJson($this->baseUrl . '/chat/completions', [
      'model' => $this->model,
      'temperature' => $temperature,
      'max_completion_tokens' => $maxTokens,
      'thinking' => ['type' => 'disabled'],
      'messages' => $messages,
    ]);
    $content = $response['choices'][0]['message']['content'] ?? '';
    if (is_array($content)) {
      $content = implode("\n", array_map(static fn($part): string => is_array($part) ? (string) ($part['text'] ?? '') : (string) $part, $content));
    }
    $content = trim((string) $content);
    if ($content === '') {
      throw new \RuntimeException('MiniMax no devolvió contenido.');
    }
    return $content;
  }

  /** Saca el objeto JSON de una respuesta (con o sin ```json). @return array<string,mixed>|null */
  public static function decodeJson(string $content): ?array
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
      $decoded = json_decode(substr($clean, $start, $end - $start + 1), true);
      return is_array($decoded) ? $decoded : null;
    }
    return null;
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

    $raw = function_exists('curl_init') ? $this->postJsonWithCurl($url, $body) : $this->postJsonWithStreams($url, $body);

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
}
