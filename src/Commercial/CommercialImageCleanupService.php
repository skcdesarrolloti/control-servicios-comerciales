<?php
declare(strict_types=1);
namespace SCM\Commercial;

/** Optional masked editing. Credentials and provider requests stay on the server. */
final class CommercialImageCleanupService
{
  public function __construct(private array $config, private $transport = null) {}

  public function available(): bool { return trim((string) ($this->config['clipdrop_api_key'] ?? '')) !== ''; }

  public function cleanup(string $image, string $mask): array
  {
    if (!$this->available()) throw new \RuntimeException('La eliminación con IA no está activada. Puedes usar el retoque local.');
    $info = self::image($image, 2 * 1024 * 1024);
    $maskInfo = self::image($mask, 1024 * 1024);
    if ($maskInfo['mime'] !== 'image/png' || $info[0] !== $maskInfo[0] || $info[1] !== $maskInfo[1]) throw new \InvalidArgumentException('La máscara PNG debe tener las mismas dimensiones que la fotografía.');
    $result = $this->transport ? ($this->transport)($image,$mask) : $this->send($image,$mask,$info['mime']);
    $status = (int) ($result['status'] ?? 0);
    if ($status !== 200) throw new \RuntimeException(match ($status) { 402=>'El servicio de edición con IA no tiene créditos disponibles.', 429=>'El servicio de IA está ocupado. Inténtalo de nuevo en unos momentos.', 401,403=>'Revisa la configuración del servicio de edición con IA.', default=>'El servicio de IA no pudo procesar la imagen. Puedes reintentar o usar el retoque local.' });
    $bytes = (string) ($result['body'] ?? '');
    $output = strlen($bytes) <= 8 * 1024 * 1024 ? @getimagesizefromstring($bytes) : false;
    if (!$output || $output['mime'] !== 'image/png' || $output[0] !== $info[0] || $output[1] !== $info[1]) throw new \RuntimeException('El servicio de IA devolvió una imagen inválida.');
    return ['image_base64'=>base64_encode($bytes)];
  }

  private static function image(string $file, int $limit): array
  {
    $size = is_file($file) ? filesize($file) : false;
    if (!$size || $size > $limit) throw new \InvalidArgumentException('La imagen o máscara supera el tamaño permitido.');
    $info = @getimagesize($file);
    if (!$info || !in_array($info['mime'], ['image/jpeg','image/png'], true) || $info[0] < 500 || $info[1] < 500 || $info[0] > 1700 || $info[1] > 1200) throw new \InvalidArgumentException('Usa una imagen JPG/PNG de 500 × 500 a 1700 × 1200 px.');
    return $info;
  }

  private function send(string $image, string $mask, string $mime): array
  {
    if (!extension_loaded('curl')) throw new \RuntimeException('El servicio de edición con IA requiere cURL en el servidor.');
    $curl = curl_init('https://clipdrop-api.co/cleanup/v1');
    if ($curl === false) throw new \RuntimeException('No se pudo iniciar la edición con IA.');
    $body = '';
    try {
      curl_setopt_array($curl, [CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>['image_file'=>new \CURLFile($image,$mime,'foto.' . ($mime === 'image/png' ? 'png' : 'jpg')),'mask_file'=>new \CURLFile($mask,'image/png','mascara.png'),'mode'=>'quality'],CURLOPT_HTTPHEADER=>['x-api-key: ' . $this->config['clipdrop_api_key']],CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>45,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_WRITEFUNCTION=>static function ($handle, string $chunk) use (&$body): int { if (strlen($body) + strlen($chunk) > 8 * 1024 * 1024) return 0; $body .= $chunk; return strlen($chunk); }]);
      if (curl_exec($curl) === false) throw new \RuntimeException('No se pudo contactar con el servicio de edición con IA.');
      return ['status'=>(int) curl_getinfo($curl,CURLINFO_HTTP_CODE),'body'=>$body];
    } finally { curl_close($curl); }
  }
}
