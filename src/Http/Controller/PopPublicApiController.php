<?php

declare(strict_types=1);

namespace SCM\Http\Controller;

use SCM\Commercial\Promotion\Pop\PopConfig;
use SCM\Commercial\Promotion\Pop\PopInventoryService;
use SCM\Core\Settings;
use SCM\Support\FileRateLimiter;

/**
 * API de Material POP para formularios y sistemas externos (public/inventory-api.php). Reemplaza /api/inventory de
 * dashboard-marketing (app/Router.php inventoryApi) con las mismas respuestas ({ok, products} y {ok, delivery}):
 *   GET  inventory-api.php/products     Productos activos con existencias (sin precios).
 *   POST inventory-api.php/deliveries   Registra una entrega y descuenta; con external_id no se duplica.
 * También ?resource=products|deliveries, para servidores que no pasan la ruta después del .php.
 *
 * Pensada para formularios (JetFormBuilder, Elementor, Google Forms con Apps Script...): acepta JSON o campos de
 * formulario, con los nombres en inglés o en español (nombre, documento, producto, cantidad...), un solo producto o
 * varios, el producto por id o por SKU, y el token en el encabezado (Authorization: Bearer / X-Inventory-Token) o en el
 * campo "token" del cuerpo, porque muchos webhooks de formularios no dejan poner encabezados. Nunca en la URL.
 * Se activa y se genera el token en Configuración › Material POP. Sin CORS: la llamada debe salir del servidor del
 * formulario, no del navegador (si no, el token quedaría a la vista).
 */
final class PopPublicApiController
{
  /** Nombre en el panel => otros nombres que acepta (formularios en español). */
  private const ALIASES = [
    'recipient_name' => ['nombre', 'destinatario', 'name', 'nombre_completo'],
    'recipient_document' => ['documento', 'cedula', 'tarjeta', 'document'],
    'recipient_type' => ['tipo', 'tipo_destinatario'],
    'recipient_id' => ['id_destinatario'],
    'recipient_source' => ['origen', 'formulario'],
    'notes' => ['observaciones', 'notas', 'comentarios'],
    'delivered_at' => ['fecha', 'fecha_entrega'],
    'external_id' => ['id_externo', 'id_envio', 'submission_id'],
    'items' => ['productos'],
    'evidence_urls' => ['evidencias', 'fotos'],
  ];

  private PopInventoryService $service;
  private Settings $settings;
  private FileRateLimiter $limiter;

  public function __construct(PopInventoryService $service, Settings $settings, FileRateLimiter $limiter)
  {
    $this->service = $service;
    $this->settings = $settings;
    $this->limiter = $limiter;
  }

  /**
   * @param array<string,mixed> $post $_POST
   * @param array<string,string> $headers Encabezados en minúsculas (authorization, x-inventory-token).
   */
  public function handle(string $method, string $resource, array $post, string $body, string $contentType, array $headers, string $ip): never
  {
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
    $method = strtoupper($method);
    if ($method === 'OPTIONS') {
      http_response_code(204);
      exit;
    }
    if (!in_array($resource, ['products', 'deliveries'], true)) {
      self::json(['ok' => false, 'message' => 'Recurso desconocido: usa products o deliveries.'], 404);
    }
    if (!$this->limiter->consume('pop-api|' . $ip, 120, 600)) {
      self::json(['ok' => false, 'message' => 'Demasiadas solicitudes. Espera unos minutos.'], 429);
    }
    $input = $post;
    if (str_contains(strtolower($contentType), 'application/json')) {
      $decoded = json_decode($body, true);
      if (!is_array($decoded)) {
        self::json(['ok' => false, 'message' => 'El cuerpo JSON no es válido.'], 400);
      }
      $input = $decoded;
    }
    if ($this->limiter->retryAfter('pop-api-auth|' . $ip, 10, 900) > 0) {
      self::json(['ok' => false, 'message' => 'Demasiados intentos con un token inválido. Espera 15 minutos.'], 429);
    }
    if (!PopConfig::validToken($this->settings, self::token($headers, $input))) {
      $this->limiter->consume('pop-api-auth|' . $ip, 10, 900);
      self::json(['ok' => false, 'message' => 'Token de inventario inválido o no configurado.'], 401);
    }
    if (!PopConfig::read($this->settings)['api_enabled']) {
      self::json(['ok' => false, 'message' => 'La API de Material POP está desactivada. Actívala en Configuración › Material POP.'], 503);
    }
    if (!$this->service->available()) {
      self::json(['ok' => false, 'message' => 'El inventario no está disponible.'], 503);
    }
    try {
      if ($resource === 'products' && $method === 'GET') {
        self::json(['ok' => true, 'products' => $this->service->apiProducts()]);
      }
      if ($resource === 'deliveries' && $method === 'POST') {
        $result = $this->service->createApiDelivery($this->normalize($input));
        self::json(['ok' => true, 'delivery' => $result], $result['duplicate'] ? 200 : 201);
      }
      self::json(['ok' => false, 'message' => 'Método no permitido: products es GET y deliveries es POST.'], 405);
    } catch (\InvalidArgumentException $exception) {
      self::json(['ok' => false, 'message' => $exception->getMessage()], 422);
    } catch (\Throwable $exception) {
      $reference = bin2hex(random_bytes(6));
      error_log('[material-pop-api:' . $reference . '] ' . $exception->getMessage());
      self::json(['ok' => false, 'message' => 'No fue posible registrar la operación. Referencia: ' . $reference], 500);
    }
  }

  /**
   * Campos con los nombres del panel: alias en español, un solo producto (product_id|producto|sku + quantity|cantidad)
   * y productos por SKU.
   * @param array<string,mixed> $input
   * @return array<string,mixed>
   */
  public function normalize(array $input): array
  {
    unset($input['token']);
    foreach (self::ALIASES as $field => $aliases) {
      if (self::blank($input[$field] ?? null)) {
        foreach ($aliases as $alias) {
          if (!self::blank($input[$alias] ?? null)) {
            $input[$field] = $input[$alias];
            break;
          }
        }
      }
    }
    $items = $input['items'] ?? [];
    if (is_string($items)) {
      $decoded = json_decode($items, true);
      $items = is_array($decoded) ? $decoded : [];
    }
    if (!is_array($items) || $items === []) {
      // Formulario con un solo producto.
      $product = $input['product_id'] ?? $input['producto'] ?? $input['sku'] ?? null;
      $quantity = $input['quantity'] ?? $input['cantidad'] ?? 1;
      $items = self::blank($product) ? [] : [['product' => $product, 'quantity' => $quantity]];
    }
    $resolved = [];
    foreach ($items as $key => $row) {
      if (!is_array($row)) {
        $row = ['product' => $key, 'quantity' => $row];
      }
      $product = $row['product_id'] ?? $row['id'] ?? $row['producto'] ?? $row['sku'] ?? $row['product'] ?? null;
      $quantity = (int) ($row['quantity'] ?? $row['cantidad'] ?? 1);
      $id = $this->productId($product);
      if ($id === 0) {
        throw new \InvalidArgumentException('No existe un producto activo con id o SKU "' . (is_scalar($product) ? (string) $product : '') . '".');
      }
      $resolved[] = ['product_id' => $id, 'quantity' => $quantity];
    }
    $input['items'] = $resolved;
    return $input;
  }

  /** Id del producto a partir de un número o de un SKU. @param mixed $value */
  private function productId($value): int
  {
    $value = trim(is_scalar($value) ? (string) $value : '');
    if ($value === '') {
      return 0;
    }
    foreach ($this->service->apiProducts() as $product) {
      if ((ctype_digit($value) && $product['id'] === (int) $value) || ($product['sku'] !== '' && strcasecmp($product['sku'], $value) === 0)) {
        return $product['id'];
      }
    }
    return 0;
  }

  /** @param array<string,string> $headers @param array<string,mixed> $input */
  private static function token(array $headers, array $input): string
  {
    $token = trim($headers['x-inventory-token'] ?? '');
    if ($token === '' && preg_match('/^Bearer\s+(.+)$/i', trim($headers['authorization'] ?? ''), $match) === 1) {
      $token = trim($match[1]);
    }
    return $token !== '' ? $token : trim(is_scalar($input['token'] ?? null) ? (string) $input['token'] : '');
  }

  /** @param mixed $value */
  private static function blank($value): bool
  {
    return $value === null || (is_string($value) && trim($value) === '') || $value === [];
  }

  /** @param array<string,mixed> $payload */
  private static function json(array $payload, int $status = 200): never
  {
    while (ob_get_level() > 0) {
      ob_end_clean();
    }
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
  }
}
