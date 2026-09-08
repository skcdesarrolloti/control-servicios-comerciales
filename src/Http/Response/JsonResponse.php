<?php

declare(strict_types=1);

namespace SCM\Http\Response;

final class JsonResponse
{
  /** @param array<string,mixed> $data */
  public static function success(array $data, int $status = 200): never
  {
    self::send(['success' => true, 'data' => $data], $status);
  }

  public static function error(string $message, int $status = 400): never
  {
    self::send(['success' => false, 'data' => ['message' => $message]], $status);
  }

  /** @param array<string,mixed> $payload */
  private static function send(array $payload, int $status): never
  {
    while (ob_get_level() > 0) {
      ob_end_clean();
    }

    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    $json = json_encode(
      $payload,
      JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR
    );
    if (!is_string($json)) {
      $json = '{"success":false,"data":{"message":"No fue posible codificar la respuesta JSON."}}';
    }
    echo $json;
    exit;
  }
}
