<?php

declare(strict_types=1);

namespace SCM\Commercial\Calls;

use SCM\Commercial\CommercialStatusCatalog;
use SCM\Core\Database;
use SCM\Support\StoredFileService;

/**
 * Respuestas entre el cliente y el funcionario de una tarea comercial (public/tarea.php). El mensaje del cliente, con sus
 * fotos y PDF, queda en el historial de la tarea marcado con id_empleado = cliente para que el funcionario lo vea en el
 * panel; la vista pública muestra ese historial con PublicTaskData (nunca las notas ni los seguimientos). La tabla
 * scm_tarea_mensajes guarda la huella de cada envío para no repetir un doble clic.
 * Validación de adjuntos copiada de control-servicios-inmobiliarios/src/Support/PublicCaseReply.php (mantener ambas iguales).
 */
final class PublicTaskConversation
{
  public const CLIENT_MARK = 'cliente';
  public const MAX_LENGTH = 2000;
  public const MAX_IMAGES = 5;
  public const MAX_DOCUMENTS = 3;
  public const MAX_FILE_BYTES = 10 * 1024 * 1024;
  public const MAX_TOTAL_BYTES = 20 * 1024 * 1024;
  private const DUPLICATE_SECONDS = 600;

  private Database $db;
  private CommercialCallsNotifier $notifier;
  private CommercialCallsRepository $repository;
  private ?StoredFileService $storage;

  public function __construct(Database $db, CommercialCallsNotifier $notifier, ?StoredFileService $storage = null)
  {
    $this->db = $db;
    $this->notifier = $notifier;
    $this->storage = $storage;
    $this->repository = new CommercialCallsRepository($db);
    $mysql = $db->pdo()->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql';
    $key = $mysql ? 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    $db->pdo()->exec('CREATE TABLE IF NOT EXISTS `' . $this->table() . "` (id {$key}, ticket_id BIGINT NOT NULL, autor VARCHAR(20) NOT NULL, nombre VARCHAR(190) NOT NULL, id_empleado VARCHAR(40) NOT NULL DEFAULT '', mensaje TEXT NOT NULL, huella CHAR(64) NOT NULL, created_at VARCHAR(19) NOT NULL" . ($mysql ? ', KEY ticket_id (ticket_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : ')'));
  }

  private function table(): string
  {
    return $this->db->table('scm_tarea_mensajes');
  }

  /** @return array<string,mixed>|null */
  public function task(int $id): ?array
  {
    return $this->db->getRow('SELECT * FROM `' . $this->db->table('jet_cct_tickets') . '` WHERE `_ID` = ? LIMIT 1', [$id]);
  }

  /** @param array<string,mixed> $task */
  public static function isClosed(array $task): bool
  {
    return in_array(trim((string) ($task['estado_comercial'] ?? '')), CommercialStatusCatalog::statusesForBucket('cerrados'), true);
  }

  /** @return list<array{autor:string,nombre:string,mensaje:string,created_at:string}> */
  public function messages(int $id): array
  {
    return $this->db->getResults('SELECT `autor`, `nombre`, `mensaje`, `created_at` FROM `' . $this->table() . '` WHERE `ticket_id` = ? ORDER BY `id` ASC LIMIT 200', [$id]);
  }

  /**
   * Guarda el mensaje del cliente (con fotos y PDF opcionales) y avisa al responsable. Un reenvío idéntico (doble clic)
   * no se repite. No cambia el estado de la tarea.
   * @param array<string,mixed> $files $_FILES con imagenes[] y archivos[]
   */
  public function clientReply(int $id, string $message, array $files = []): void
  {
    $task = $this->task($id);
    if ($task === null) {
      throw new \InvalidArgumentException('La solicitud no está disponible.');
    }
    if (self::isClosed($task)) {
      throw new \InvalidArgumentException('Esta solicitud ya terminó. Si necesitas algo más, comunícate con nosotros.');
    }
    $text = self::clean($message);
    if ($text === '') {
      throw new \InvalidArgumentException('Escribe tu mensaje antes de enviarlo.');
    }
    if (mb_strlen($text) > self::MAX_LENGTH) {
      throw new \InvalidArgumentException('El mensaje puede tener hasta ' . self::MAX_LENGTH . ' caracteres.');
    }
    $counts = self::validateFiles($files);
    $name = trim((string) ($task['solicitante'] ?? '')) ?: 'Cliente';
    $stored = ['images' => [], 'documents' => []];
    $storage = null;
    $pdo = $this->db->pdo();
    $pdo->beginTransaction();
    try {
      if (!$this->record($id, self::CLIENT_MARK, $name, '', $text, self::filesPrint($files))) {
        $pdo->commit();
        return;
      }
      if ($counts['imagenes'] + $counts['archivos'] > 0) {
        $storage = $this->storage ?? StoredFileService::fromRuntime();
        if ($counts['imagenes'] > 0) {
          $stored['images'] = $storage->storeImages('imagenes', self::MAX_IMAGES);
        }
        if ($counts['archivos'] > 0) {
          // Mismo índice que $_FILES: StoredFileService empareja cada título con su archivo.
          $titles = array_map(static fn($title): string => mb_substr(basename((string) $title), 0, 120), array_values((array) ($files['archivos']['name'] ?? [])));
          $stored['documents'] = $storage->storeDocuments('archivos', $titles, self::MAX_DOCUMENTS);
        }
        if (count($stored['images']) !== $counts['imagenes'] || count($stored['documents']) !== $counts['archivos']) {
          throw new \RuntimeException('No se pudieron guardar todos los adjuntos. Intenta nuevamente.');
        }
      }
      $now = date('Y-m-d H:i:s');
      $history = [
        'cct_status' => 'publish', 'cct_author_id' => 0, 'cct_created' => $now, 'cct_modified' => $now, 'id_ticket' => $id, 'fecha' => time(),
        'nombre' => $name, 'correo' => (string) ($task['correo_solicitante'] ?? ''), 'celular' => (string) ($task['celular_solicitante'] ?? ''),
        'respuesta' => $text, 'id_empleado' => self::CLIENT_MARK, 'fue_editada' => 'No',
      ];
      if (count($stored['images']) === 1) {
        $history['imagen'] = $stored['images'][0];
      } elseif (count($stored['images']) > 1) {
        $history['imagen'] = serialize($stored['images']);
      }
      if ($stored['documents'] !== []) {
        $history['archivos'] = serialize($stored['documents']);
      }
      $this->repository->insertTaskHistory($history);
      $pdo->commit();
    } catch (\Throwable $exception) {
      if ($pdo->inTransaction()) {
        $pdo->rollBack();
      }
      if ($storage !== null) {
        $storage->removeImages(array_merge($stored['images'], array_column($stored['documents'], 'archivo')));
      }
      throw $exception;
    }
    $attached = count($stored['images']) + count($stored['documents']);
    $this->notifier->clientReplied($id, $task, $text . ($attached > 0 ? ' · Adjuntó ' . $attached . ($attached === 1 ? ' archivo.' : ' archivos.') : ''));
  }

  /**
   * Revisa cantidad, peso y formato de los adjuntos antes de guardar nada.
   * @param array<string,mixed> $files
   * @return array{imagenes:int,archivos:int}
   */
  public static function validateFiles(array $files, bool $requireUpload = true): array
  {
    $counts = ['imagenes' => 0, 'archivos' => 0];
    $total = 0;
    foreach (array_keys($counts) as $field) {
      $group = $files[$field] ?? [];
      if ($group === []) {
        continue;
      }
      if (!is_array($group) || !is_array($group['name'] ?? null) || !is_array($group['error'] ?? null)) {
        throw new \InvalidArgumentException('Formato de adjuntos inválido.');
      }
      foreach ($group['name'] as $index => $fileName) {
        $error = (int) ($group['error'][$index] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_NO_FILE) {
          continue;
        }
        if ($error !== UPLOAD_ERR_OK) {
          throw new \InvalidArgumentException('No se pudo cargar ' . basename((string) $fileName) . '. Revisa el tamaño e inténtalo nuevamente.');
        }
        $size = (int) ($group['size'][$index] ?? 0);
        $tmp = (string) ($group['tmp_name'][$index] ?? '');
        if ($size < 1 || $size > self::MAX_FILE_BYTES || ($requireUpload && !is_uploaded_file($tmp))) {
          throw new \InvalidArgumentException('Cada adjunto debe pesar máximo 10 MB.');
        }
        if ($field === 'imagenes') {
          $image = @getimagesize($tmp);
          if (!$image || !in_array($image['mime'],['image/jpeg', 'image/png', 'image/webp'], true) || $image[0] * $image[1] > 16000000) {
            throw new \InvalidArgumentException('Usa fotos JPG, PNG o WebP.');
          }
        } else {
          $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($tmp);
          if (strtolower(pathinfo((string) $fileName, PATHINFO_EXTENSION)) !== 'pdf' || $mime !== 'application/pdf') {
            throw new \InvalidArgumentException('Los documentos deben estar en formato PDF.');
          }
        }
        $counts[$field]++;
        $total += $size;
      }
    }
    if ($counts['imagenes'] > self::MAX_IMAGES) {
      throw new \InvalidArgumentException('Adjunta máximo ' . self::MAX_IMAGES . ' fotos.');
    }
    if ($counts['archivos'] > self::MAX_DOCUMENTS) {
      throw new \InvalidArgumentException('Adjunta máximo ' . self::MAX_DOCUMENTS . ' documentos PDF.');
    }
    if ($total > self::MAX_TOTAL_BYTES) {
      throw new \InvalidArgumentException('Los adjuntos pueden sumar máximo 20 MB.');
    }
    return $counts;
  }

  /** La respuesta que el funcionario decidió enviarle al cliente: queda en su vista y le llega el aviso con el enlace. */
  public function officerReply(int $id, string $message, string $author, string $employee): void
  {
    $task = $this->task($id);
    $text = self::clean($message);
    if ($task === null || $text === '') {
      return;
    }
    $this->record($id, 'funcionario', $author, $employee, $text);
    $this->notifier->officerReplied($id, $task, $text, $author, $employee);
  }

  private function record(int $id, string $author, string $name, string $employee, string $text, string $files = ''): bool
  {
    $print = hash('sha256', $id . '|' . $author . '|' . $text . ($files !== '' ? '|' . $files : ''));
    $recent = (int) $this->db->getVar('SELECT COUNT(*) FROM `' . $this->table() . '` WHERE `ticket_id` = ? AND `huella` = ? AND `created_at` >= ?', [$id, $print, date('Y-m-d H:i:s', time() - self::DUPLICATE_SECONDS)]);
    if ($recent > 0) {
      return false;
    }
    $this->db->insert($this->table(), ['ticket_id' => $id, 'autor' => $author, 'nombre' => mb_substr($name, 0, 190), 'id_empleado' => $employee, 'mensaje' => $text, 'huella' => $print, 'created_at' => date('Y-m-d H:i:s')]);
    return true;
  }

  /** @param array<string,mixed> $files Nombre y peso de cada adjunto: el mismo texto con otros archivos no es un doble clic. */
  private static function filesPrint(array $files): string
  {
    $parts = [];
    foreach (['imagenes', 'archivos'] as $field) {
      $group = is_array($files[$field] ?? null) ? $files[$field] : [];
      foreach ((array) ($group['name'] ?? []) as $index => $fileName) {
        if ((int) ($group['error'][$index] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
          $parts[] = $field . ':' . (string) $fileName . ':' . (int) ($group['size'][$index] ?? 0);
        }
      }
    }
    return implode('|', $parts);
  }

  private static function clean(string $message): string
  {
    return trim(strip_tags(str_replace("\r\n", "\n", $message)));
  }
}
