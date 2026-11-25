<?php

declare(strict_types=1);

namespace SCM\Commercial\Mandates;

use SCM\Commercial\CommercialAccessPolicy;
use SCM\Core\Auth;
use SCM\Core\Database;

/**
 * Registros › Hojas de cierre y Mi portafolio › Mis cierres: el listado de wp_jet_cct_cierres (solo lectura).
 *
 * Copia de la consulta de ClosingSheetWorkflow::listing (control-servicios-inmobiliarios), con alcance por persona:
 * "Mis cierres" son las hojas que hizo la persona (id_empleado exacto). Cada hoja se abre en la ventana del panel con
 * las páginas del panel inmobiliario (MandateClosingSheet), que son las que crean, revisan, aprueban y desisten.
 */
final class ClosingSheetsListing
{
  public const VIEW = 'cierres';
  /** Estados de la hoja, en el orden del flujo (ClosingSheetWorkflow::LIST_STATES). */
  public const STATES = ['En verificacion de servicios', 'Esperando respuesta', 'No aprobada', 'Aprobada', 'Realizada', 'Desistida'];
  public const PER_PAGE = 20;

  private Database $db;
  private CommercialAccessPolicy $policy;

  public function __construct(Database $db, CommercialAccessPolicy $policy)
  {
    $this->db = $db;
    $this->policy = $policy;
  }

  private function table(): string
  {
    return $this->db->table('jet_cct_cierres');
  }

  /**
   * @param array<string,mixed> $input
   * @return array{rows:list<array<string,mixed>>,total:int,page:int,per_page:int,filters:array{q:string,estado:string,empleado:string},estados:array<string,int>,mine:bool,team:bool,can_task:bool}
   */
  public function listing(array $input): array
  {
    if (!$this->policy->canView(self::VIEW)) {
      throw new \RuntimeException('No tienes permiso para ver las hojas de cierre.');
    }
    $team = $this->policy->canSeeAll(self::VIEW);
    $mine = (string) ($input['portafolio'] ?? '') === '1' || !$team;
    $clean = static fn(string $key): string => mb_substr(trim((string) ($input[$key] ?? '')), 0, 120);
    $filters = ['q' => $clean('hc_q'), 'estado' => in_array($clean('hc_estado'), self::STATES, true) ? $clean('hc_estado') : '', 'empleado' => $mine ? '' : $clean('hc_empleado')];
    $employee = $mine ? trim(Auth::employeeId()) : null;
    $page = max(1, (int) ($input['hc_pag'] ?? 1));
    [$where, $args] = $this->where($filters, $employee, false);
    $total = (int) $this->db->getVar('SELECT COUNT(*) FROM `' . $this->table() . '` WHERE ' . $where, $args);
    $rows = $this->db->getResults('SELECT `_ID`, `fecha`, `estado`, `id_inmueble`, `direccion`, `propietario`, `arrendatario`, `creador`, `id_empleado`, `verificador`, `aprobador`, `id_contrato`, `id_ticket`'
      . ' FROM `' . $this->table() . '` WHERE ' . $where . ' ORDER BY `_ID` DESC LIMIT ' . self::PER_PAGE . ' OFFSET ' . (($page - 1) * self::PER_PAGE), $args);
    [$countWhere, $countArgs] = $this->where($filters, $employee, true);
    $counts = [];
    foreach ($this->db->getResults("SELECT TRIM(COALESCE(`estado`, '')) AS estado, COUNT(*) AS total FROM `" . $this->table() . '` WHERE ' . $countWhere . " GROUP BY TRIM(COALESCE(`estado`, ''))", $countArgs) as $row) {
      $counts[(string) $row['estado']] = (int) $row['total'];
    }
    return ['rows' => array_map([self::class, 'summary'], $rows), 'total' => $total, 'page' => $page, 'per_page' => self::PER_PAGE, 'filters' => $filters, 'estados' => $counts, 'mine' => $mine, 'team' => $team, 'can_task' => $this->policy->canAct('ver_ticket')];
  }

  /**
   * Una persona sin "todo el equipo" solo ve sus hojas; nunca se adivina quién es (sin id_empleado no ve nada).
   * @param array{q:string,estado:string,empleado:string} $filters
   * @return array{0:string,1:list<string>}
   */
  private function where(array $filters, ?string $employee, bool $withoutState): array
  {
    $where = ['1=1'];
    $args = [];
    if ($employee !== null) {
      $where[] = 'TRIM(`id_empleado`) = ?';
      $args[] = $employee !== '' ? $employee : '__nadie__';
    } elseif ($filters['empleado'] !== '') {
      $where[] = 'TRIM(`id_empleado`) = ?';
      $args[] = $filters['empleado'];
    }
    if (!$withoutState && $filters['estado'] !== '') {
      $where[] = "TRIM(COALESCE(`estado`, '')) = ?";
      $args[] = $filters['estado'];
    }
    if ($filters['q'] !== '') {
      $like = '%' . addcslashes($filters['q'], '%_\\') . '%';
      $where[] = '(CAST(`_ID` AS CHAR) = ? OR TRIM(`id_inmueble`) = ? OR TRIM(`id_contrato`) = ? OR `direccion` LIKE ? OR `arrendatario` LIKE ? OR `propietario` LIKE ?)';
      array_push($args, $filters['q'], $filters['q'], $filters['q'], $like, $like, $like);
    }
    return [implode(' AND ', $where), $args];
  }

  /** @param array<string,mixed> $row @return array<string,mixed> */
  public static function summary(array $row): array
  {
    $date = trim((string) ($row['fecha'] ?? ''));
    $time = ctype_digit($date) ? (int) $date : (int) strtotime($date);
    return [
      'id' => (int) $row['_ID'],
      'fecha' => $time > 0 ? date('d/m/Y', $time) : '',
      'estado' => trim((string) ($row['estado'] ?? '')),
      'inmueble' => trim((string) ($row['id_inmueble'] ?? '')),
      'direccion' => trim((string) ($row['direccion'] ?? '')),
      'propietario' => trim((string) ($row['propietario'] ?? '')),
      'arrendatario' => trim((string) ($row['arrendatario'] ?? '')),
      'consultor' => trim((string) ($row['creador'] ?? '')),
      'empleado' => trim((string) ($row['id_empleado'] ?? '')),
      'verificador' => trim((string) ($row['verificador'] ?? '')),
      'aprobador' => trim((string) ($row['aprobador'] ?? '')),
      'mandato' => (int) preg_replace('/\D/', '', (string) ($row['id_contrato'] ?? '')),
      'tarea' => (int) preg_replace('/\D/', '', (string) ($row['id_ticket'] ?? '')),
    ];
  }

  /** La hoja con quién la hizo, o null. @return array<string,mixed>|null */
  public function find(int $id): ?array
  {
    $row = $id > 0 ? $this->db->getRow('SELECT `_ID`, `estado`, `id_empleado`, `id_contrato` FROM `' . $this->table() . '` WHERE `_ID` = ? LIMIT 1', [$id]) : null;
    return is_array($row) ? $row : null;
  }

  /** Puede abrir la hoja desde estas vistas: la vista Hojas de cierre y que sea suya, o ver todo el equipo. @param array<string,mixed> $sheet */
  public function canOpen(array $sheet): bool
  {
    if (!$this->policy->canView(self::VIEW)) {
      return false;
    }
    if ($this->policy->canSeeAll(self::VIEW)) {
      return true;
    }
    $me = trim(Auth::employeeId());
    return $me !== '' && trim((string) ($sheet['id_empleado'] ?? '')) === $me;
  }

  /** @return array<string,string> id_empleado => nombre de quien hizo hojas de cierre */
  public function creators(): array
  {
    $out = [];
    foreach ($this->db->getResults("SELECT TRIM(`id_empleado`) AS id, MAX(TRIM(`creador`)) AS nombre FROM `" . $this->table() . "` WHERE TRIM(COALESCE(`id_empleado`, '')) <> '' GROUP BY TRIM(`id_empleado`) ORDER BY nombre") as $row) {
      $out[(string) $row['id']] = (string) ($row['nombre'] ?: $row['id']);
    }
    return $out;
  }
}
