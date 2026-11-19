<?php

declare(strict_types=1);

namespace SCM\Commercial\Calls;

use SCM\Core\Database;
use SCM\Support\StoredFileService;

/**
 * Datos que la vista pública de una tarea (public/tarea.php) puede mostrarle al cliente: lista explícita de campos.
 * Copia adaptada de control-servicios-inmobiliarios/src/Support/PublicCaseData.php (mantener ambas iguales).
 *
 * El historial sale de jet_cct_historial_del_ticket (las respuestas, que el panel marca "Respuesta al cliente"); las notas
 * internas (jet_cct_notas_ticket) y los seguimientos (jet_cct_seguimiento_ticket) nunca llegan aquí. La descripción de la
 * tarea tampoco: la escribe el consultor/a durante la llamada y puede llevar observaciones internas.
 */
final class PublicTaskData
{
  /**
   * @param array<string,mixed> $ticket
   * @return array{id:int,asunto:string,motivo:string,estado:string,inmueble:string,fecha:string,solicitante:string,consultor:string,celular_consultor:string,correo_consultor:string}
   */
  public static function fromTicket(array $ticket): array
  {
    $text = static fn(string $field): string => trim(strip_tags((string) ($ticket[$field] ?? '')));
    $estado = $text('estado_comercial') !== '' ? $text('estado_comercial') : $text('estado');
    $inmueble = $text('id_inmueble');
    return [
      'id' => (int) ($ticket['_ID'] ?? 0),
      'asunto' => $text('asunto'),
      'motivo' => $text('tema_ayuda'),
      'estado' => $estado,
      'inmueble' => $inmueble !== '' && $inmueble !== '0' ? $inmueble : '',
      'fecha' => self::date($ticket['fecha'] ?? '', $ticket['cct_created'] ?? ''),
      'solicitante' => $text('solicitante'),
      'consultor' => $text('nombre_empleado') !== '' ? $text('nombre_empleado') : $text('empleado'),
      'celular_consultor' => $text('celular_empleado'),
      'correo_consultor' => $text('correo_empleado'),
    ];
  }

  /**
   * Historial de la tarea para el cliente: autor, fecha, texto y adjuntos permitidos.
   * Nunca publica correo, celular ni identificadores internos de la fila.
   * @param array<string,mixed> $ticket
   * @return list<array{author:string,date:string,text:string,external:bool,images:list<string>,documents:list<array{url:string,name:string}>}>
   */
  public static function history(Database $db, array $ticket, int $limit = 100): array
  {
    $keys = array_values(array_unique(array_filter([trim((string) ($ticket['_ID'] ?? '')), trim((string) ($ticket['id_ticket'] ?? ''))], static fn(string $key): bool => $key !== '')));
    if ($keys === []) {
      return [];
    }
    $rows = $db->getResults(
      'SELECT `nombre`, `fecha`, `cct_created`, `respuesta`, `imagen`, `archivos`, `id_empleado` FROM `' . $db->table('jet_cct_historial_del_ticket') . '`'
      . ' WHERE CAST(`id_ticket` AS CHAR) IN (' . implode(',', array_fill(0, count($keys), '?')) . ") AND (`cct_status` IS NULL OR `cct_status` <> 'trash')"
      . ' ORDER BY `_ID` DESC LIMIT ' . max(1, $limit),
      $keys
    );
    return self::historyFromRows(array_reverse($rows));
  }

  /**
   * @param array<int,array<string,mixed>> $rows En orden cronológico.
   * @return list<array{author:string,date:string,text:string,external:bool,images:list<string>,documents:list<array{url:string,name:string}>}>
   */
  public static function historyFromRows(array $rows): array
  {
    $out = [];
    foreach ($rows as $row) {
      $text = preg_replace('/<\/(p|div|li|h[1-6])>|<br\s*\/?\s*>/i', "\n", (string) ($row['respuesta'] ?? '')) ?? '';
      $text = trim(html_entity_decode(strip_tags(preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/is', '', $text) ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
      $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;
      $images = [];
      $rawImages = (string) ($row['imagen'] ?? '');
      $list = str_starts_with($rawImages, 'a:') ? @unserialize($rawImages, ['allowed_classes' => false]) : explode(',', $rawImages);
      foreach (is_array($list) ? $list : [] as $url) {
        $url = self::safeAttachment(trim((string) $url), ['jpg', 'jpeg', 'png', 'webp']);
        if ($url !== '') {
          $images[] = $url;
        }
      }
      $documents = [];
      $rawDocuments = is_string($row['archivos'] ?? null) ? @unserialize($row['archivos'], ['allowed_classes' => false]) : [];
      foreach (is_array($rawDocuments) ? $rawDocuments : [] as $document) {
        if (!is_array($document)) {
          continue;
        }
        $url = self::safeAttachment((string) ($document['media_archivo'] ?? $document['archivo'] ?? ''), ['pdf']);
        if ($url !== '') {
          $documents[] = ['url' => $url, 'name' => trim(strip_tags((string) ($document['nombre_archivo'] ?? ''))) ?: 'Documento PDF'];
        }
      }
      if ($text === '' && $images === [] && $documents === []) {
        continue;
      }
      $author = trim(strip_tags((string) ($row['nombre'] ?? '')));
      $out[] = [
        'author' => $author !== '' ? $author : 'SKC SuCasa Inmobiliaria',
        'date' => self::date($row['fecha'] ?? '', $row['cct_created'] ?? ''),
        'text' => $text,
        // Lo que el cliente escribió desde su enlace (PublicTaskConversation::clientReply).
        'external' => trim((string) ($row['id_empleado'] ?? '')) === PublicTaskConversation::CLIENT_MARK,
        'images' => array_slice(array_values(array_unique($images)), 0, 10),
        'documents' => array_slice($documents, 0, 10),
      ];
    }
    return $out;
  }

  /** @param mixed $timestamp @param mixed $created */
  private static function date($timestamp, $created): string
  {
    $raw = trim((string) $timestamp);
    $time = ctype_digit($raw) && (int) $raw > 0 ? (int) $raw : (strtotime((string) $created) ?: 0);
    return $time > 0 ? date('d/m/Y h:i a', $time) : '';
  }

  /** @param list<string> $extensions */
  private static function safeAttachment(string $url, array $extensions): string
  {
    $url = html_entity_decode($url, ENT_QUOTES, 'UTF-8');
    $parts = parse_url($url);
    if (!is_array($parts) || !in_array($parts['scheme'] ?? '', ['https', 'http'], true) || isset($parts['user']) || isset($parts['pass'])) {
      return '';
    }
    // Los archivos subidos al panel ya van firmados por StoredFileService: aquí nunca se firma una URL guardada.
    $base = rtrim((string) SCM_BASE_URL, '/');
    if (str_starts_with($url, $base . '/file.php?')) {
      parse_str($parts['query'] ?? '', $query);
      if (!is_string($query['n'] ?? null) || !is_string($query['s'] ?? null)) {
        return '';
      }
      if (!in_array(strtolower(pathinfo($query['n'], PATHINFO_EXTENSION)), $extensions, true)) {
        return '';
      }
      return StoredFileService::fromRuntime()->isValidSignature($query['n'], $query['s']) ? $url : '';
    }
    // Los medios de WordPress ya son públicos: solo los de la empresa, nunca otros orígenes.
    $host = strtolower((string) ($parts['host'] ?? ''));
    return $parts['scheme'] === 'https' && in_array($host, ['sucasainmobiliaria.com.co', 'www.sucasainmobiliaria.com.co'], true)
      && str_starts_with($parts['path'] ?? '', '/wp-content/uploads/')
      && in_array(strtolower(pathinfo($parts['path'], PATHINFO_EXTENSION)), $extensions, true) ? $url : '';
  }
}
