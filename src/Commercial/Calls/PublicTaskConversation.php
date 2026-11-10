<?php

declare(strict_types=1);

namespace SCM\Commercial\Calls;

use SCM\Commercial\CommercialStatusCatalog;
use SCM\Core\Database;

/**
 * Conversación entre el cliente y el funcionario de una tarea comercial. La vista pública solo lee esta tabla: las notas,
 * los seguimientos y lo demás del historial de la tarea nunca llegan al cliente. El mensaje del cliente se copia también
 * al historial de la tarea, marcado con id_empleado = cliente, para que el funcionario lo vea en el panel.
 */
final class PublicTaskConversation
{
  public const CLIENT_MARK = 'cliente';
  public const MAX_LENGTH = 2000;
  private const DUPLICATE_SECONDS = 600;

  private Database $db;
  private CommercialCallsNotifier $notifier;
  private CommercialCallsRepository $repository;

  public function __construct(Database $db, CommercialCallsNotifier $notifier)
  {
    $this->db = $db;
    $this->notifier = $notifier;
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

  /** Guarda el mensaje del cliente y avisa al responsable. Un reenvío idéntico (doble clic) no se repite. */
  public function clientReply(int $id, string $message): void
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
    $name = trim((string) ($task['solicitante'] ?? '')) ?: 'Cliente';
    $pdo = $this->db->pdo();
    $pdo->beginTransaction();
    try {
      if (!$this->record($id, self::CLIENT_MARK, $name, '', $text)) {
        $pdo->commit();
        return;
      }
      $now = date('Y-m-d H:i:s');
      $this->repository->insertTaskHistory([
        'cct_status' => 'publish', 'cct_author_id' => 0, 'cct_created' => $now, 'cct_modified' => $now, 'id_ticket' => $id, 'fecha' => time(),
        'nombre' => $name, 'correo' => (string) ($task['correo_solicitante'] ?? ''), 'celular' => (string) ($task['celular_solicitante'] ?? ''),
        'respuesta' => $text, 'id_empleado' => self::CLIENT_MARK, 'fue_editada' => 'No',
      ]);
      $pdo->commit();
    } catch (\Throwable $exception) {
      if ($pdo->inTransaction()) {
        $pdo->rollBack();
      }
      throw $exception;
    }
    $this->notifier->clientReplied($id, $task, $text);
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
    $this->notifier->officerReplied($id, $task, $text, $author);
  }

  private function record(int $id, string $author, string $name, string $employee, string $text): bool
  {
    $print = hash('sha256', $id . '|' . $author . '|' . $text);
    $recent = (int) $this->db->getVar('SELECT COUNT(*) FROM `' . $this->table() . '` WHERE `ticket_id` = ? AND `huella` = ? AND `created_at` >= ?', [$id, $print, date('Y-m-d H:i:s', time() - self::DUPLICATE_SECONDS)]);
    if ($recent > 0) {
      return false;
    }
    $this->db->insert($this->table(), ['ticket_id' => $id, 'autor' => $author, 'nombre' => mb_substr($name, 0, 190), 'id_empleado' => $employee, 'mensaje' => $text, 'huella' => $print, 'created_at' => date('Y-m-d H:i:s')]);
    return true;
  }

  private static function clean(string $message): string
  {
    return trim(strip_tags(str_replace("\r\n", "\n", $message)));
  }
}
