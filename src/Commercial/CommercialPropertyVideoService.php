<?php
declare(strict_types=1);
namespace SCM\Commercial;

/** Authenticated bridge to our CPU-only renderer. Provider credentials never reach the browser. */
final class CommercialPropertyVideoService
{
  public function __construct(private array $config, private $transport = null) {}

  public function available(): bool
  {
    return trim((string) ($this->config['property_video_url'] ?? '')) !== '' && strlen((string) ($this->config['property_video_token'] ?? '')) >= 32;
  }

  public static function options(array $input, int $count): array
  {
    if ($count < 2 || $count > 12) throw new \InvalidArgumentException('Selecciona entre 2 y 12 fotografías para el video.');
    $options = [];
    foreach (['title'=>70,'operation'=>30,'location'=>80,'price'=>50,'contact'=>60] as $field=>$limit) {
      $value = $input[$field] ?? '';
      if (!is_string($value) || mb_strlen($value) > $limit || preg_match('/[\x00-\x1f]/u', $value)) throw new \InvalidArgumentException('Revisa los textos del video: hay un campo inválido o demasiado largo.');
      $options[$field] = trim($value);
    }
    if ($options['title'] === '') throw new \InvalidArgumentException('Escribe un título para el video.');
    $quality = $input['quality'] ?? '720'; $seconds = $input['seconds'] ?? 4;
    if (!in_array($quality, ['720','1080'], true) || !in_array($seconds, [2,3,4,5,6], true)) throw new \InvalidArgumentException('Elige calidad 720p/1080p y de 2 a 6 segundos por foto.');
    $captions = $input['captions'] ?? [];
    if (!is_array($captions) || count($captions) !== $count) throw new \InvalidArgumentException('Revisa los títulos de las fotografías.');
    foreach ($captions as $caption) if (!is_string($caption) || mb_strlen($caption) > 80 || preg_match('/[\x00-\x1f]/u', $caption)) throw new \InvalidArgumentException('Cada título de foto admite hasta 80 caracteres.');
    return $options + ['quality'=>$quality,'seconds'=>$seconds,'captions'=>array_values($captions)];
  }

  public function create(int $task, array $options, array $photos, ?array $audio = null): array
  {
    $options = self::options($options, count($photos));
    $fields = ['manifest'=>json_encode($options, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)];
    foreach (array_values($photos) as $index=>$path) {
      $info = is_string($path) && is_file($path) ? @getimagesize($path) : false;
      if (!$info || !in_array($info['mime'], ['image/jpeg','image/png'], true) || filesize($path) > 1048576 || min($info[0],$info[1]) < 500 || max($info[0],$info[1]) > 4000 || $info[0] * $info[1] > 12000000) throw new \InvalidArgumentException('Cada foto del video debe ser JPG/PNG, medir al menos 500 × 500 px y pesar hasta 1 MB.');
      // Indexed fields are converted to repeated "images" multipart names in send().
      $fields['images[' . $index . ']'] = new \CURLFile($path,$info['mime'],'foto-' . $index . ($info['mime'] === 'image/png' ? '.png' : '.jpg'));
    }
    if ($audio) {
      $path = (string) ($audio['tmp_name'] ?? '');
      $mime = is_file($path) ? (new \finfo(FILEINFO_MIME_TYPE))->file($path) : false;
      if (($audio['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !$mime || !in_array($mime,['audio/mpeg','audio/wav','audio/x-wav','audio/mp4','video/mp4','audio/x-m4a'],true) || filesize($path) > 12 * 1048576) throw new \InvalidArgumentException('La música debe ser MP3, M4A o WAV y pesar hasta 12 MB.');
      $fields['audio'] = new \CURLFile($path,$mime,'musica');
    }
    $result = $this->call('POST','/jobs',$fields);
    $id = $result['job_id'] ?? '';
    if (!is_string($id) || !preg_match('/^[a-f0-9]{32}$/D',$id)) throw new \RuntimeException('El generador no devolvió un identificador válido.');
    $expiry = time() + 86400;
    return ['job_id'=>$id,'expires'=>$expiry,'receipt'=>$this->signature($task,$id,$expiry),'status'=>'queued','progress'=>0];
  }

  private function signature(int $task, string $id, int $expiry): string
  {
    return hash_hmac('sha256', 'property-video|' . $task . '|' . $id . '|' . $expiry, (string) ($this->config['property_video_token'] ?? ''));
  }

  public function verify(int $task, array $input): string
  {
    $id = $input['job_id'] ?? ''; $expiry = (int) ($input['expires'] ?? 0); $receipt = $input['receipt'] ?? '';
    if (!is_string($id) || !preg_match('/^[a-f0-9]{32}$/D',$id) || !is_string($receipt) || $expiry < time() || $expiry > time() + 86400 || !hash_equals($this->signature($task,$id,$expiry),$receipt)) throw new CommercialSignAccessDenied('El enlace del video expiró o no pertenece a esta tarea.');
    return $id;
  }

  public function status(int $task, array $input, bool $cancel = false): array
  {
    $result = $this->call($cancel ? 'DELETE' : 'GET','/jobs/' . $this->verify($task,$input));
    $status = $result['status'] ?? '';
    if (!in_array($status,['queued','rendering','ready','failed','cancelled'],true)) throw new \RuntimeException('El generador devolvió un estado inválido.');
    return ['status'=>$status,'progress'=>max(0,min(100,(int) ($result['progress'] ?? 0))),'message'=>match ($status) {'failed'=>'No se pudo crear el video. Prueba con menos fotos, calidad 720p u otra música.','cancelled'=>'Generación cancelada.',default=>''}];
  }

  /** Download to a temporary file, avoiding a 100 MB PHP string. Caller removes it. */
  public function download(int $task, array $input): string
  {
    $id = $this->verify($task,$input);
    $file = tempnam(sys_get_temp_dir(),'scm-video-');
    if (!$file) throw new \RuntimeException('No se pudo preparar la descarga.');
    try {
      $this->call('GET','/jobs/' . $id . '/file',[], $file);
      $handle = fopen($file,'rb');
      $header = $handle ? fread($handle,32) : false;
      if ($handle) fclose($handle);
      if (!$header || substr($header,4,4) !== 'ftyp') throw new \RuntimeException('El generador no devolvió un MP4 válido.');
      return $file;
    } catch (\Throwable $exception) { @unlink($file); throw $exception; }
  }

  private function call(string $method, string $path, array $fields = [], ?string $output = null): array
  {
    if (!$this->available()) throw new \RuntimeException('El generador de videos aún no está conectado. Configura el servicio Python en el VPS.');
    if ($this->transport) return ($this->transport)($method,$path,$fields,$output);
    return $this->send($method,$path,$fields,$output);
  }

  private function send(string $method, string $path, array $fields, ?string $output): array
  {
    if (!extension_loaded('curl')) throw new \RuntimeException('El generador de videos requiere cURL en PHP.');
    $base = rtrim((string) $this->config['property_video_url'],'/');
    $parts = parse_url($base);
    if (!$parts || !in_array($parts['scheme'] ?? '',['https','http'],true) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']) || ($parts['scheme'] === 'http' && !in_array($parts['host'] ?? '',['127.0.0.1','localhost','property-video'],true))) throw new \RuntimeException('Configura el generador con HTTPS o una dirección interna local.');
    $curl = curl_init($base . $path);
    if (!$curl) throw new \RuntimeException('No se pudo conectar con el generador.');
    $body = ''; $size = 0; $handle = $output ? fopen($output,'wb') : false;
    try {
      // PHP cURL cannot repeat keys: use a MIME multipart body with a bounded local stream.
      $stream = false; $headers = ['Authorization: Bearer ' . $this->config['property_video_token']];
      if ($fields) {
        $boundary = 'scm-' . bin2hex(random_bytes(16));
        $stream = tmpfile();
        if (!$stream) throw new \RuntimeException('No se pudo preparar el video.');
        foreach ($fields as $name=>$value) {
          $name = str_starts_with($name,'images[') ? 'images' : $name;
          fwrite($stream,'--' . $boundary . "\r\nContent-Disposition: form-data; name=\"" . $name . '"');
          if ($value instanceof \CURLFile) {
            fwrite($stream,'; filename="' . $value->getPostFilename() . '"' . "\r\nContent-Type: " . $value->getMimeType() . "\r\n\r\n");
            $source = fopen($value->getFilename(),'rb');
            if (!$source) throw new \RuntimeException('No se pudo leer una foto del video.');
            try { stream_copy_to_stream($source,$stream); } finally { fclose($source); }
          } else fwrite($stream,"\r\n\r\n" . (string) $value);
          fwrite($stream,"\r\n");
        }
        fwrite($stream,'--' . $boundary . "--\r\n");
        $length = ftell($stream); rewind($stream);
        $headers[] = 'Content-Type: multipart/form-data; boundary=' . $boundary;
        $headers[] = 'Content-Length: ' . $length;
        curl_setopt_array($curl,[CURLOPT_UPLOAD=>true,CURLOPT_INFILE=>$stream,CURLOPT_INFILESIZE=>$length]);
      }
      curl_setopt_array($curl,[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$headers,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>45,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTP | CURLPROTO_HTTPS,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_WRITEFUNCTION=>static function ($curl, string $chunk) use (&$body,&$size,$handle,$output): int {
        $size += strlen($chunk);
        if ($size > ($output ? 100 * 1048576 : 65536)) return 0;
        if ($handle) return (int) fwrite($handle,$chunk);
        $body .= $chunk; return strlen($chunk);
      }]);
      if (curl_exec($curl) === false) throw new \RuntimeException('No se pudo contactar con el generador de videos. Intenta de nuevo.');
      $status = (int) curl_getinfo($curl,CURLINFO_HTTP_CODE);
      if ($status < 200 || $status >= 300) throw new \RuntimeException(match ($status) { 401,403=>'Revisa la configuración del generador de videos.',404=>'El video expiró. Vuelve a generarlo.',409=>'El video aún no está listo.',429,503=>'El generador está ocupado. Intenta de nuevo en unos minutos.',422=>'El generador rechazó las fotos, los textos o la música. Revisa los archivos seleccionados.',default=>'El generador de videos no pudo completar la solicitud.' });
      if ($output) return [];
      $data = json_decode($body,true);
      if (!is_array($data)) throw new \RuntimeException('El generador devolvió una respuesta inválida.');
      return $data;
    } finally { curl_close($curl); if (is_resource($handle)) fclose($handle); if (is_resource($stream)) fclose($stream); }
  }
}
