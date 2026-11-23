<?php

declare(strict_types=1);

namespace SCM\Commercial;

use SCM\Commercial\Calls\PublicTaskConversation;
use SCM\Core\Database;
use SCM\Support\SchemaInspector;

/**
 * Editar y eliminar entradas de la cronología de una tarea (respuestas, seguimientos y notas internas).
 * - Solo su autor (por id_empleado exacto) o un administrador; los mensajes del cliente no se tocan.
 * - Cada entrada se edita hasta MAX_EDITS veces (veces_editada / fecha_edicion, bin/add-timeline-edit-fields.php) y
 *   no se le reenvía al cliente: queda marcada «Editada».
 * - Eliminar deja una nota interna de constancia con quién, cuándo y qué decía; esa constancia no se edita ni se borra.
 */
final class CommercialTimelineEditor
{
  public const MAX_EDITS = 2;
  public const MAX_TEXT = 10000;
  public const TRACE_PREFIX = 'Constancia de eliminación:';

  /** Tipo => [tabla, columna del texto, columnas del autor en orden, nombre visible]. */
  public const TYPES = [
    'respuesta' => ['jet_cct_historial_del_ticket', 'respuesta', ['id_empleado', 'cct_author_id'], 'la respuesta al cliente'],
    'seguimiento' => ['jet_cct_seguimiento_ticket', 'observacion', ['id_empleado', 'id_coordinador', 'cct_author_id'], 'el seguimiento'],
    'nota' => ['jet_cct_notas_ticket', 'observacion', ['id_empleado', 'cct_author_id'], 'la nota interna'],
  ];

  private SchemaInspector $schema;

  public function __construct(private Database $db)
  {
    $this->schema = new SchemaInspector($db);
  }

  /** Si la tabla ya tiene los campos que cuentan las ediciones. */
  public function editable(string $type): bool
  {
    $table = $this->table($type);
    return $this->schema->columnExists($table, 'veces_editada') && $this->schema->columnExists($table, 'fecha_edicion');
  }

  /**
   * Qué puede hacer la persona con una entrada ya cargada de la cronología.
   * @param array<string,mixed> $item fila de la cronología (type, actor_id, message, veces_editada, edit_ready)
   * @return array{edit:bool,delete:bool,edits:int}
   */
  public static function permissions(array $item, string $me, bool $admin, bool $canAct): array
  {
    $edits = (int) ($item['veces_editada'] ?? 0);
    $type = (string) ($item['type'] ?? '');
    $actor = trim((string) ($item['actor_id'] ?? ''));
    $mine = $admin || ($me !== '' && $actor === $me);
    $allowed = $canAct && $mine && isset(self::TYPES[$type]) && $actor !== PublicTaskConversation::CLIENT_MARK
      && !self::isTrace($type, (string) ($item['message'] ?? ''));
    return [
      'edit' => $allowed && (string) ($item['edit_ready'] ?? '0') === '1' && $edits < self::MAX_EDITS,
      'delete' => $allowed,
      'edits' => $edits,
    ];
  }

  /** @return array{message:string,edits:int} */
  public function edit(int $ticketPk, string $type, int $id, string $text, string $me, bool $admin): array
  {
    $text = trim(str_replace("\r\n", "\n", wp_strip_all_tags($text, false)));
    if ($text === '') {
      throw new \InvalidArgumentException('Escribe el texto de la entrada.');
    }
    if (mb_strlen($text, 'UTF-8') > self::MAX_TEXT) {
      throw new \InvalidArgumentException('El texto supera los ' . self::MAX_TEXT . ' caracteres.');
    }
    if (!$this->editable($type)) {
      throw new \RuntimeException('Editar el historial aún no está activo: falta crear los campos con php bin/add-timeline-edit-fields.php --aplicar.');
    }
    $row = $this->owned($ticketPk, $type, $id, $me, $admin);
    $edits = (int) ($row['veces_editada'] ?? 0);
    if ($edits >= self::MAX_EDITS) {
      throw new \RuntimeException('Esta entrada ya se editó ' . self::MAX_EDITS . ' veces. Si hay que corregir algo más, agrega una entrada nueva.');
    }
    $column = self::TYPES[$type][1];
    $now = time();
    $this->db->update($this->table($type), $this->only($this->table($type), [
      $column => $text,
      'veces_editada' => (string) ($edits + 1),
      'fecha_edicion' => $now,
      'cct_modified' => date('Y-m-d H:i:s', $now),
    ]), ['_ID' => $id]);
    $left = self::MAX_EDITS - $edits - 1;
    return [
      'message' => 'Entrada editada. ' . ($left > 0 ? 'Puede editarse ' . $left . ' vez más.' : 'Ya no se puede editar de nuevo.'),
      'edits' => $edits + 1,
    ];
  }

  /** Borra la entrada y deja la nota interna de constancia. */
  public function delete(int $ticketPk, string $type, int $id, string $me, bool $admin, string $actorName): string
  {
    $row = $this->owned($ticketPk, $type, $id, $me, $admin);
    [, $column, , $label] = self::TYPES[$type];
    $written = (int) ($row['fecha'] ?? 0) ?: (strtotime((string) ($row['cct_created'] ?? '')) ?: 0);
    $author = trim((string) ($row['nombre'] ?? '')) ?: (self::author($row, $type) ?: 'sin autor registrado');
    $trace = self::TRACE_PREFIX . ' ' . ($actorName !== '' ? $actorName : 'Funcionario ' . $me) . ' eliminó ' . $label
      . ($written > 0 ? ' del ' . date('d/m/Y g:i a', $written) : '') . ' escrita por ' . $author . '. Decía: «'
      . mb_strimwidth(trim(wp_strip_all_tags((string) ($row[$column] ?? ''), true)), 0, 1500, '…', 'UTF-8') . '»';

    $notes = $this->table('nota');
    $now = time();
    $pdo = $this->db->pdo();
    $pdo->beginTransaction();
    try {
      $this->db->delete($this->table($type), ['_ID' => $id]);
      $this->db->insert($notes, $this->only($notes, [
        'cct_status' => 'publish',
        'id_ticket' => (string) $ticketPk,
        'id_empleado' => $me,
        'fecha' => $now,
        'nombre' => $actorName,
        'observacion' => $trace,
        'cct_author_id' => $me,
        'cct_created' => date('Y-m-d H:i:s', $now),
        'cct_modified' => date('Y-m-d H:i:s', $now),
      ]));
      $pdo->commit();
    } catch (\Throwable $exception) {
      if ($pdo->inTransaction()) {
        $pdo->rollBack();
      }
      throw $exception;
    }
    return 'Entrada eliminada. Quedó una nota interna de constancia en el historial.';
  }

  public static function isTrace(string $type, string $message): bool
  {
    return $type === 'nota' && str_starts_with(trim(wp_strip_all_tags($message, true)), self::TRACE_PREFIX);
  }

  /** @param array<string,mixed> $row */
  private static function author(array $row, string $type): string
  {
    foreach (self::TYPES[$type][2] as $column) {
      $value = trim((string) ($row[$column] ?? ''));
      if ($value !== '') {
        return $value;
      }
    }
    return '';
  }

  /**
   * La entrada de esa tarea que la persona puede cambiar.
   * @return array<string,mixed>
   */
  private function owned(int $ticketPk, string $type, int $id, string $me, bool $admin): array
  {
    if (!isset(self::TYPES[$type]) || $ticketPk <= 0 || $id <= 0) {
      throw new \InvalidArgumentException('Entrada del historial inválida.');
    }
    $ticket = $this->db->getRow("SELECT `_ID`, `id_ticket` FROM `{$this->db->table('jet_cct_tickets')}` WHERE `_ID` = ? LIMIT 1", [$ticketPk]);
    $row = $this->db->getRow("SELECT * FROM `{$this->table($type)}` WHERE `_ID` = ? LIMIT 1", [$id]);
    $keys = array_filter([(string) $ticketPk, trim((string) ($ticket['id_ticket'] ?? ''))], static fn(string $key): bool => $key !== '');
    if (!is_array($ticket) || !is_array($row) || !in_array(trim((string) ($row['id_ticket'] ?? '')), $keys, true)) {
      throw new \RuntimeException('La entrada ya no existe en esta tarea. Vuelve a abrir la tarea.');
    }
    $author = self::author($row, $type);
    $message = (string) ($row[self::TYPES[$type][1]] ?? '');
    if ($author === PublicTaskConversation::CLIENT_MARK) {
      throw new \InvalidArgumentException('Los mensajes del cliente no se editan ni se eliminan.');
    }
    if (self::isTrace($type, $message)) {
      throw new \InvalidArgumentException('Las constancias de eliminación no se editan ni se eliminan.');
    }
    if (!$admin && ($me === '' || $author !== $me)) {
      throw new \InvalidArgumentException('Solo quien escribió esta entrada o un administrador puede cambiarla.');
    }
    return $row;
  }

  /**
   * Solo las columnas que existen en la tabla (las del CCT pueden variar entre instalaciones).
   * @param array<string,mixed> $data
   * @return array<string,mixed>
   */
  private function only(string $table, array $data): array
  {
    return array_filter($data, fn(string $column): bool => $this->schema->columnExists($table, $column), ARRAY_FILTER_USE_KEY);
  }

  private function table(string $type): string
  {
    return $this->db->table(self::TYPES[$type][0] ?? 'jet_cct_notas_ticket');
  }
}
