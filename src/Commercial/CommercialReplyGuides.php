<?php

declare(strict_types=1);

namespace SCM\Commercial;

use SCM\Core\Database;
use SCM\Support\SchemaInspector;

/**
 * Banco de respuestas guía: qué responder y por qué según la situación y el estado de la tarea. Es la tabla
 * jet_cct_respuesta_de_ticket del plugin "Gestor de Respuestas de Tickets" de WordPress (shortcodes [gestor_respuestas] y
 * [btn_gestor_respuestas]), que comparte con el panel inmobiliario (GuideApiController). Este panel solo lee y edita la
 * categoría Comercial: las demás (Administrativo, Servicio al cliente, Cartera) no se tocan. Se muestran en el formulario
 * Responder del popup de la tarea y se editan en Configuración › Actividades comerciales › Respuestas guía.
 */
final class CommercialReplyGuides
{
  public const TABLE = 'jet_cct_respuesta_de_ticket';
  public const CATEGORY = 'Comercial';
  public const MAX_TEXT = 5000;

  private Database $db;
  private SchemaInspector $schema;

  public function __construct(Database $db)
  {
    $this->db = $db;
    $this->schema = new SchemaInspector($db);
  }

  public function ready(): bool
  {
    return $this->schema->tableExists($this->db->table(self::TABLE));
  }

  /** @return list<array{id:int,estado:string,situacion:string,respuesta:string}> */
  public function all(): array
  {
    if (!$this->ready()) {
      return [];
    }
    $rows = $this->db->getResults(
      'SELECT `_ID`, `estado`, `situacion`, `respuesta` FROM `' . $this->db->table(self::TABLE) . '` WHERE `categoria` = ? ORDER BY `estado` ASC, `situacion` ASC, `_ID` ASC',
      [self::CATEGORY]
    );
    return array_map(static fn(array $row): array => [
      'id' => (int) $row['_ID'],
      'estado' => trim((string) ($row['estado'] ?? '')),
      'situacion' => trim((string) ($row['situacion'] ?? '')),
      'respuesta' => trim((string) ($row['respuesta'] ?? '')),
    ], $rows);
  }

  /**
   * Las del estado de la tarea primero (marcadas), luego las demás. Sin respuesta escrita no sirven y no salen.
   * @return list<array{id:int,estado:string,situacion:string,respuesta:string,match:bool}>
   */
  public function forStatus(string $status): array
  {
    $key = self::key($status);
    $rows = [];
    foreach ($this->all() as $row) {
      if ($row['respuesta'] !== '') {
        $rows[] = $row + ['match' => $key !== '' && self::key($row['estado']) === $key];
      }
    }
    usort($rows, static fn(array $a, array $b): int => [$b['match'], $a['estado'], $a['situacion']] <=> [$a['match'], $b['estado'], $b['situacion']]);
    return $rows;
  }

  /**
   * Guarda la lista completa de la categoría Comercial: las filas con id se actualizan, las nuevas se crean y las que
   * faltan se eliminan solo con $confirmDelete (Configuración pide marcar la confirmación).
   * @param list<array<string,mixed>> $rows
   * @return array{added:int,updated:int,deleted:int}
   */
  public function save(array $rows, bool $confirmDelete, string $author): array
  {
    if (!$this->ready()) {
      throw new \RuntimeException('La tabla de respuestas guía no existe en esta base.');
    }
    $table = $this->db->table(self::TABLE);
    $existing = [];
    foreach ($this->all() as $row) {
      $existing[$row['id']] = $row;
    }
    $clean = [];
    foreach ($rows as $index => $row) {
      $item = [
        'id' => (int) ($row['id'] ?? 0),
        'estado' => mb_substr(trim(strip_tags((string) ($row['estado'] ?? ''))), 0, 100, 'UTF-8'),
        'situacion' => mb_substr(trim(strip_tags((string) ($row['situacion'] ?? ''))), 0, 255, 'UTF-8'),
        'respuesta' => trim(strip_tags(str_replace("\r\n", "\n", (string) ($row['respuesta'] ?? '')))),
      ];
      if ($item['id'] === 0 && $item['estado'] === '' && $item['situacion'] === '' && $item['respuesta'] === '') {
        continue;
      }
      if ($item['situacion'] === '' || $item['respuesta'] === '') {
        throw new \InvalidArgumentException('La fila ' . ($index + 1) . ' necesita la situación y la respuesta.');
      }
      if (mb_strlen($item['respuesta'], 'UTF-8') > self::MAX_TEXT) {
        throw new \InvalidArgumentException('La respuesta de "' . $item['situacion'] . '" no puede pasar de ' . number_format(self::MAX_TEXT, 0, ',', '.') . ' caracteres.');
      }
      if ($item['id'] > 0 && !isset($existing[$item['id']])) {
        throw new \InvalidArgumentException('La respuesta #' . $item['id'] . ' ya no existe o no es comercial. Vuelve a abrir la sección.');
      }
      $clean[] = $item;
    }
    $kept = array_flip(array_filter(array_column($clean, 'id')));
    $removed = array_diff_key($existing, $kept);
    if ($removed !== [] && !$confirmDelete) {
      throw new \InvalidArgumentException('Marca la confirmación para eliminar ' . count($removed) . ' respuesta(s).');
    }

    $now = date('Y-m-d H:i:s');
    $totals = ['added' => 0, 'updated' => 0, 'deleted' => 0];
    $pdo = $this->db->pdo();
    $pdo->beginTransaction();
    try {
      foreach ($clean as $item) {
        $data = ['estado' => $item['estado'], 'situacion' => $item['situacion'], 'respuesta' => $item['respuesta']];
        if ($item['id'] > 0) {
          $before = $existing[$item['id']];
          if ($before['estado'] === $item['estado'] && $before['situacion'] === $item['situacion'] && $before['respuesta'] === $item['respuesta']) {
            continue;
          }
          $this->db->update($table, $data + ['cct_modified' => $now, 'cct_author_id' => $author], ['_ID' => $item['id'], 'categoria' => self::CATEGORY]);
          $totals['updated']++;
          continue;
        }
        $this->db->insert($table, $data + ['categoria' => self::CATEGORY, 'cct_status' => 'publish', 'cct_author_id' => $author, 'cct_created' => $now, 'cct_modified' => $now]);
        $totals['added']++;
      }
      foreach (array_keys($removed) as $id) {
        $this->db->delete($table, ['_ID' => $id, 'categoria' => self::CATEGORY]);
        $totals['deleted']++;
      }
      $pdo->commit();
    } catch (\Throwable $exception) {
      if ($pdo->inTransaction()) {
        $pdo->rollBack();
      }
      throw $exception;
    }
    return $totals;
  }

  /** Comparación de estados sin tildes ni mayúsculas. */
  public static function key(string $status): string
  {
    $status = mb_strtolower(trim($status), 'UTF-8');
    return trim((string) preg_replace('/\s+/', ' ', str_replace(['á', 'é', 'í', 'ó', 'ú', 'ü', 'ñ'], ['a', 'e', 'i', 'o', 'u', 'u', 'n'], $status)));
  }
}
