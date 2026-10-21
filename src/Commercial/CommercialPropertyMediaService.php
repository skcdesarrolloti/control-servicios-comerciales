<?php

declare(strict_types=1);

namespace SCM\Commercial;

use SCM\Core\Auth;
use SCM\Core\Database;

/** WordPress attachments and JetEngine media, without the retired portal synchronization. */
final class CommercialPropertyMediaService
{
  public function __construct(private Database $db, private CommercialAccessPolicy $policy, private array $config = []) {}

  private function property(int $taskId, int $code = 0): array
  {
    $task = (new CommercialPropertyEntryService($this->db, $this->policy, $this->config))->task($taskId);
    $linked = trim((string) ($task['id_inmueble'] ?? ''), " \t\n\r\0\x0B\"'[]");
    if ($code <= 0 && ctype_digit($linked)) $code = (int) $linked;
    $property = $code > 0 ? $this->db->getRow('SELECT * FROM `' . $this->db->table('jet_cct_inmuebles') . '` WHERE codigo=?', [$code]) : null;
    if (!$property && $code <= 0 && (int) ($task['id_inmueble_data'] ?? 0) > 0) $property = $this->db->getRow('SELECT * FROM `' . $this->db->table('jet_cct_inmuebles') . '` WHERE _ID=?', [(int) $task['id_inmueble_data']]);
    if (!$property) throw new \InvalidArgumentException('Ingresa primero el inmueble para añadir su multimedia.');
    if ((string) $property['codigo'] !== $linked && (int) $property['_ID'] !== (int) ($task['id_inmueble_data'] ?? 0)) throw new CommercialSignAccessDenied('El inmueble no pertenece a esta tarea.');
    $post = $this->db->getRow('SELECT ID FROM `' . $this->db->table('posts') . '` WHERE ID=? AND post_type=? AND post_status<>?', [$property['codigo'],'inmuebles','trash']);
    if (!$post) throw new \InvalidArgumentException('No se encontró el inmueble en WordPress.');
    return $property;
  }

  private function meta(int $id, string $key): string
  {
    return (string) $this->db->getVar('SELECT meta_value FROM `' . $this->db->table('postmeta') . '` WHERE post_id=? AND meta_key=? ORDER BY meta_id DESC LIMIT 1', [$id,$key]);
  }

  private function putMeta(int $id, string $key, string $value): void
  {
    $this->db->delete($this->db->table('postmeta'), ['post_id'=>$id,'meta_key'=>$key]);
    $this->db->insert($this->db->table('postmeta'), ['post_id'=>$id,'meta_key'=>$key,'meta_value'=>$value]);
  }

  public static function ids(string $raw): array
  {
    $decoded = @unserialize($raw, ['allowed_classes'=>false]);
    if (!is_array($decoded)) $decoded = json_decode($raw, true);
    if (!is_array($decoded)) $decoded = explode(',', $raw);
    $ids = [];
    foreach ($decoded as $item) {
      if (is_array($item)) $item = $item['id'] ?? $item['rawValue'] ?? '';
      if (is_scalar($item) && ctype_digit((string) $item) && (int) $item > 0) $ids[] = (int) $item;
    }
    return array_values(array_unique($ids));
  }

  private function uploadLocation(): array
  {
    $path = rtrim((string) ($this->config['wordpress_upload_path'] ?? ''), '/\\');
    if ($path === '') {
      $root = defined('SCM_ROOT') ? SCM_ROOT : dirname(__DIR__, 2);
      $path = dirname($root) . '/wp-content/uploads';
      if (!is_dir($path)) throw new \RuntimeException('No se encontró la carpeta de medios de WordPress. Configura WORDPRESS_UPLOAD_PATH en el servidor.');
    }
    $url = rtrim((string) ($this->config['wordpress_upload_url'] ?? ''), '/');
    if ($url === '') {
      $options = $this->db->table('options');
      $url = rtrim((string) $this->db->getVar('SELECT option_value FROM `' . $options . '` WHERE option_name=?', ['upload_url_path']), '/');
      if ($url === '') $url = rtrim((string) $this->db->getVar('SELECT option_value FROM `' . $options . '` WHERE option_name=?', ['siteurl']), '/') . '/wp-content/uploads';
    }
    if (!filter_var($url, FILTER_VALIDATE_URL) || !in_array(parse_url($url, PHP_URL_SCHEME), ['http','https'], true)) throw new \RuntimeException('Configura una URL válida en WORDPRESS_UPLOAD_URL.');
    return [$path,$url];
  }

  private function attachment(int $id): ?array
  {
    if ($id <= 0) return null;
    $post = $this->db->getRow('SELECT ID,guid,post_parent,post_mime_type FROM `' . $this->db->table('posts') . '` WHERE ID=? AND post_type=? AND post_status<>?', [$id,'attachment','trash']);
    if (!$post || !in_array($post['post_mime_type'], ['image/jpeg','image/png'], true)) return null;
    $relative = $this->meta($id, '_wp_attached_file');
    [, $base] = $this->uploadLocation();
    $url = $relative !== '' && !str_contains($relative, '..') && !str_contains($relative, ':') && !str_starts_with($relative, '/') ? $base . '/' . str_replace('%2F', '/', rawurlencode($relative)) : (string) $post['guid'];
    if (!filter_var($url, FILTER_VALIDATE_URL) || !in_array(parse_url($url, PHP_URL_SCHEME), ['http','https'], true)) return null;
    return ['id'=>$id,'url'=>$url,'parent'=>(int) $post['post_parent']];
  }

  public function context(int $taskId, int $code = 0): array
  {
    $property = $this->property($taskId, $code);
    return $this->state($taskId, $property);
  }

  public function draftContext(int $taskId, int $code): array
  {
    (new CommercialPropertyEntryService($this->db, $this->policy, $this->config))->task($taskId);
    $post = $this->db->getRow('SELECT ID,post_author FROM `' . $this->db->table('posts') . '` WHERE ID=? AND post_type=? AND post_status<>?', [$code,'inmuebles','trash']);
    if (!$post) throw new \InvalidArgumentException('No se encontró el código adelantado.');
    $property = $this->db->getRow('SELECT * FROM `' . $this->db->table('jet_cct_inmuebles') . '` WHERE codigo=?', [$code]) ?? ['codigo'=>$code];
    $state = $this->meta($code, 'estado') ?: (string) ($property['estado'] ?? '');
    if (!in_array($state, ['','En borrador','Codigo adelantado','Código adelantado'], true)) throw new \InvalidArgumentException('Solo se pueden completar códigos adelantados o en borrador.');
    $assigned = $this->meta($code, 'id_empleado') ?: (string) ($property['id_funcionario'] ?? $post['post_author']);
    if (!$this->policy->canManage() && $assigned !== Auth::employeeId()) throw new CommercialSignAccessDenied('El código adelantado está asignado a otro funcionario.');
    return $this->state($taskId, $property);
  }

  /** Resolve only this property's selected gallery attachments; never fetch arbitrary URLs. */
  public function videoPhotoPaths(int $taskId, int $code, array $ids): array
  {
    try { $state = $this->context($taskId, $code); }
    catch (CommercialSignAccessDenied | \InvalidArgumentException $exception) { $state = $this->draftContext($taskId, $code); }
    $allowed = array_column($state['galeria'], 'id');
    if ($state['portada']) $allowed[] = $state['portada']['id'];
    [$directory] = $this->uploadLocation();
    $base = realpath($directory);
    if (!$base) throw new \RuntimeException('No se encontró la carpeta de fotos de WordPress.');
    $paths = [];
    foreach ($ids as $id) {
      if (!is_int($id) || !in_array($id, $allowed, true)) throw new CommercialSignAccessDenied('Selecciona solamente fotos de la galería de este inmueble.');
      $relative = $this->meta($id, '_wp_attached_file');
      $path = $relative !== '' ? realpath($directory . '/' . $relative) : false;
      $prefix = rtrim(str_replace('\\', '/', $base), '/') . '/';
      if (!$path || !str_starts_with(str_replace('\\', '/', $path), $prefix) || !is_file($path)) throw new \InvalidArgumentException('No se encontró una foto seleccionada en la carpeta de WordPress.');
      $paths[$id] = $path;
    }
    return $paths;
  }

  private function state(int $taskId, array $property): array
  {
    $code = (int) $property['codigo'];
    $gallery = self::ids($this->meta($code, 'galeria-del-inmueble') ?: $this->meta($code, 'galeria') ?: (string) ($property['galeria'] ?? ''));
    $types = $this->db->getCol('SELECT t.name FROM `' . $this->db->table('terms') . '` t JOIN `' . $this->db->table('term_taxonomy') . '` tt ON t.term_id=tt.term_id JOIN `' . $this->db->table('term_relationships') . '` tr ON tr.term_taxonomy_id=tt.term_taxonomy_id WHERE tr.object_id=? AND tt.taxonomy=?', [$code,'tipo-de-inmueble']);
    $short = array_intersect(array_map(static fn($type): string => mb_strtolower(trim((string) $type)), $types), ['local','locales','lote','lotes','bodega','bodegas']);
    return ['ticket_pk'=>$taskId,'id_inmueble'=>$code,'portada'=>$this->attachment((int) ($this->meta($code, '_thumbnail_id') ?: ($property['foto_portada'] ?? 0))), 'galeria'=>array_values(array_filter(array_map(fn(int $id): ?array => $this->attachment($id), $gallery))), 'video_del_inmueble'=>$this->meta($code, 'video_del_inmueble') ?: (string) ($property['video'] ?? ''), 'max_gallery'=>max($short ? 6 : 12, count($gallery)), 'baseline_gallery'=>count($gallery)];
  }

  public static function validateImage(string $path): array
  {
    $size = is_file($path) ? filesize($path) : false;
    if (!$size || $size > 1048576) throw new \InvalidArgumentException('Cada imagen debe pesar como máximo 1 MB.');
    $info = @getimagesize($path);
    if (!$info || !in_array($info['mime'], ['image/jpeg','image/png'], true)) throw new \InvalidArgumentException('Solo se permiten imágenes JPG y PNG.');
    if ($info[0] < 500 || $info[1] < 500 || $info[0] > 1700 || $info[1] > 1200) throw new \InvalidArgumentException('Las imágenes deben medir entre 500 × 500 y 1700 × 1200 píxeles.');
    return ['width'=>$info[0],'height'=>$info[1],'mime'=>$info['mime'],'filesize'=>$size];
  }

  public function upload(int $taskId, int $code, array $file): array
  {
    $property = $this->property($taskId, $code);
    $code = (int) $property['codigo'];
    if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) ($file['tmp_name'] ?? ''))) throw new \InvalidArgumentException('No se recibió la imagen completa. Vuelve a seleccionarla.');
    $info = self::validateImage($file['tmp_name']);
    [$directory,$url] = $this->uploadLocation();
    $month = date('Y/m');
    $folder = $directory . '/' . $month;
    if (!is_dir($folder) && !@mkdir($folder, 0755, true) && !is_dir($folder)) throw new \RuntimeException('No se pudo crear la carpeta de imágenes.');
    $name = 'inmueble-' . $code . '-' . bin2hex(random_bytes(12)) . ($info['mime'] === 'image/png' ? '.png' : '.jpg');
    $path = $folder . '/' . $name;
    $pdo = $this->db->pdo(); $pdo->beginTransaction();
    try {
      if (!move_uploaded_file($file['tmp_name'], $path)) throw new \RuntimeException('No se pudo guardar la imagen.');
      @chmod($path, 0644);
      $now = date('Y-m-d H:i:s'); $gmt = gmdate('Y-m-d H:i:s');
      $this->db->insert($this->db->table('posts'), ['post_author'=>Auth::employeeId(),'post_date'=>$now,'post_date_gmt'=>$gmt,'post_content'=>'','post_title'=>'Inmueble ' . $code,'post_excerpt'=>'','post_status'=>'inherit','comment_status'=>'closed','ping_status'=>'closed','post_password'=>'','post_name'=>pathinfo($name, PATHINFO_FILENAME),'to_ping'=>'','pinged'=>'','post_modified'=>$now,'post_modified_gmt'=>$gmt,'post_content_filtered'=>'','post_parent'=>$code,'guid'=>$url . '/' . $month . '/' . $name,'menu_order'=>0,'post_type'=>'attachment','post_mime_type'=>$info['mime'],'comment_count'=>0]);
      $id = (int) $this->db->lastInsertId();
      $this->putMeta($id, '_wp_attached_file', $month . '/' . $name);
      $this->putMeta($id, '_wp_attachment_metadata', serialize(['width'=>$info['width'],'height'=>$info['height'],'file'=>$month . '/' . $name,'filesize'=>$info['filesize'],'sizes'=>[],'image_meta'=>[]]));
      $result = $this->attachment($id);
      if (!$result) throw new \RuntimeException('No se pudo registrar la imagen.');
      $pdo->commit();
      return $result;
    } catch (\Throwable $exception) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      if (is_file($path)) @unlink($path);
      throw $exception;
    }
  }

  public static function validVideo(string $video): bool
  {
    if ($video === '') return true;
    $parts = parse_url($video);
    if (!$parts || ($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) return false;
    $host = strtolower($parts['host'] ?? ''); $path = $parts['path'] ?? '';
    if ($host === 'youtu.be') return preg_match('~^/[a-zA-Z0-9_-]{11}/?$~D', $path) === 1;
    if (!in_array($host, ['youtube.com','www.youtube.com','m.youtube.com'], true)) return false;
    if (preg_match('~^/(?:embed|shorts|live)/[a-zA-Z0-9_-]{11}/?$~D', $path)) return true;
    parse_str($parts['query'] ?? '', $query);
    return $path === '/watch' && is_string($query['v'] ?? null) && preg_match('/^[a-zA-Z0-9_-]{11}$/D', $query['v']) === 1;
  }

  public function save(int $taskId, int $code, array $input): array
  {
    $pdo = $this->db->pdo(); $pdo->beginTransaction();
    try {
      // task() locks the task on MySQL; recheck association after taking the lock.
      $property = $this->property($taskId, $code);
      $code = (int) $property['codigo'];
      $context = $this->context($taskId, $code);
      $cover = filter_var($input['portada_id'] ?? '', FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
      $gallery = $input['galeria_ids'] ?? [];
      if (!$cover || !is_array($gallery) || count($gallery) < 1 || count($gallery) > $context['max_gallery']) throw new \InvalidArgumentException('Selecciona una portada y entre 1 y ' . $context['max_gallery'] . ' imágenes de galería.');
      foreach ($gallery as $id) if (!is_scalar($id) || !ctype_digit((string) $id) || (int) $id < 1) throw new \InvalidArgumentException('La galería contiene una imagen inválida.');
      $gallery = array_values(array_unique(array_map('intval', $gallery)));
      $existing = array_merge(array_column($context['galeria'], 'id'), [$context['portada']['id'] ?? 0]);
      [$directory] = $this->uploadLocation();
      foreach (array_unique([$cover,...$gallery]) as $id) {
        $image = $this->attachment($id);
        if (!$image || ($image['parent'] !== $code && !in_array($id, $existing, true))) throw new CommercialSignAccessDenied('Una imagen no pertenece a este inmueble.');
        // Preserve existing images accepted by the original form, including historical dimensions.
        if (!in_array($id, $existing, true)) {
          $relative = $this->meta($id, '_wp_attached_file');
          if (!preg_match('~^\d{4}/\d{2}/inmueble-' . $code . '-[a-f0-9]{24}\.(?:jpg|png)$~D', $relative)) throw new CommercialSignAccessDenied('Imagen no recibida por este formulario.');
          self::validateImage($directory . '/' . $relative);
        }
      }
      if (!is_scalar($input['video_del_inmueble'] ?? '')) throw new \InvalidArgumentException('Video inválido.');
      $video = trim((string) ($input['video_del_inmueble'] ?? ''));
      if (strlen($video) > 1000 || !self::validVideo($video)) throw new \InvalidArgumentException('Ingresa una URL HTTPS de un video de YouTube.');
      $this->putMeta($code, '_thumbnail_id', (string) $cover);
      $this->putMeta($code, 'galeria-del-inmueble', serialize($gallery));
      $this->putMeta($code, 'video_del_inmueble', $video);
      $this->db->update($this->db->table('jet_cct_inmuebles'), ['foto_portada'=>(string) $cover,'galeria'=>implode(',', $gallery),'video'=>$video,'cct_modified'=>date('Y-m-d H:i:s')], ['_ID'=>$property['_ID']]);
      $this->db->update($this->db->table('posts'), ['post_modified'=>date('Y-m-d H:i:s'),'post_modified_gmt'=>gmdate('Y-m-d H:i:s')], ['ID'=>$code]);
      $pdo->commit();
      return ['id_inmueble'=>$code,'message'=>'Multimedia del inmueble #' . $code . ' guardada correctamente.'];
    } catch (\Throwable $exception) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $exception; }
  }
}
