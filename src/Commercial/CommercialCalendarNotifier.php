<?php

declare(strict_types=1);

namespace SCM\Commercial;

use SCM\Core\Auth;
use SCM\Core\Database;
use SCM\Support\SchemaInspector;
use SCM\Support\SmsQueue;

/**
 * Encola los WhatsApp de una cita agendada desde el calendario comercial: al funcionario
 * asignado y al solicitante de la tarea. Usa las mismas plantillas que administrativo.
 */
final class CommercialCalendarNotifier
{
  private Database $db;
  private SchemaInspector $schema;
  private SmsQueue $queue;

  public function __construct(Database $db, ?SmsQueue $queue = null)
  {
    $this->db = $db;
    $this->schema = new SchemaInspector($db);
    $this->queue = $queue ?? new SmsQueue($db);
  }

  /**
   * @param array<int,mixed> $appointments
   * @return array{message:string,queued:int,skipped:int,errors:array<int,string>}
   */
  public function notify(array $appointments): array
  {
    $creator = $this->creatorContact();
    $queued = 0;
    $skipped = 0;
    $errors = [];
    $requesterDedupe = [];

    foreach ($appointments as $appointment) {
      if (!is_array($appointment)) {
        $skipped++;
        continue;
      }
      $isCita = strtolower(trim((string) ($appointment['es_cita'] ?? 'si'))) === 'si';
      $ticketLookup = trim(strip_tags((string) ($appointment['id_ticket'] ?? '')));
      $employeeId = trim(strip_tags((string) ($appointment['id_empleado'] ?? '')));
      if (!$isCita || $ticketLookup === '') {
        $skipped++;
        continue;
      }

      $ticket = $this->ticketRow($ticketLookup);
      $logicalTicket = trim((string) ($ticket['id_ticket'] ?? '')) ?: $ticketLookup;
      $category = trim(strip_tags((string) ($appointment['categoria'] ?? '')));
      if ($category === '') {
        $category = trim((string) ($ticket['tema_ayuda'] ?? $ticket['asunto'] ?? '')) ?: 'cita';
      }
      $location = trim(strip_tags((string) ($appointment['ubicacion'] ?? ''))) ?: trim((string) ($ticket['direccion'] ?? ''));
      $start = trim(strip_tags((string) ($appointment['fecha_inicio'] ?? '')));
      $end = trim(strip_tags((string) ($appointment['fecha_fin'] ?? '')));
      $dateLabel = $this->dateLabel($start);
      $timeLabel = $this->timeRangeLabel($start, $end);
      $baseMeta = [
        'categoria_mensaje' => 'informacion',
        'id_ticket' => $logicalTicket,
        'ticket_pk' => (string) ($ticket['_ID'] ?? $ticketLookup),
        'categoria_cita' => $category,
        'fecha_inicio' => $start,
        'fecha_fin' => $end,
        'creado_por' => $creator['name'],
        'creado_por_telefono' => $creator['phone'],
        'template_language' => 'es_CO',
      ];
      $button = ['type' => 'button', 'sub_type' => 'url', 'index' => '0', 'parameters' => [['type' => 'text', 'text' => $logicalTicket]]];

      if ($employeeId !== '') {
        $employee = $this->employeeRow($employeeId, 'id_empleado');
        $employeeName = $employee['name'] !== '' ? $employee['name'] : 'Funcionario';
        if ($employee['phone'] !== '') {
          $ok = $this->queue->enqueue($employee['phone'], $employeeName, $this->employeeMessage($employeeName, $category, $logicalTicket, $dateLabel, $timeLabel, $location), $baseMeta + [
            'source_module' => 'calendar_cita_funcionario',
            'campaign_tag' => 'calendar_cita_funcionario',
            'id_funcionario' => is_numeric($employeeId) ? (int) $employeeId : 0,
            'dedupe_key' => 'calendar_cita_funcionario:' . $logicalTicket . ':' . $employeeId . ':' . $start,
            'template_name' => 'scm_cita_funcionario_v1',
            'template_components' => [
              ['type' => 'body', 'parameters' => $this->textParameters([$employeeName, $category, $logicalTicket, $dateLabel, $timeLabel, $location !== '' ? $location : '-'])],
              $button,
            ],
          ]);
          if ($ok) {
            $queued++;
          } else {
            $detail = trim($this->queue->lastError());
            $errors[] = 'No se pudo encolar WhatsApp al funcionario de la tarea #' . $logicalTicket . ($detail !== '' ? ': ' . $detail : '.');
          }
        } else {
          $skipped++;
        }
      }

      $requesterName = trim((string) ($ticket['solicitante'] ?? '')) ?: 'Cliente';
      $requesterPhone = trim((string) ($ticket['celular_solicitante'] ?? ''));
      $requesterKey = $logicalTicket . ':' . $start . ':' . $requesterPhone;
      if ($requesterPhone === '') {
        $skipped++;
        continue;
      }
      if (isset($requesterDedupe[$requesterKey])) {
        continue;
      }
      $requesterDedupe[$requesterKey] = true;
      $creatorContact = $this->creatorLabel($creator);
      $ok = $this->queue->enqueue($requesterPhone, $requesterName, $this->requesterMessage($requesterName, $category, $logicalTicket, $dateLabel, $timeLabel, $location, $creatorContact), $baseMeta + [
        'source_module' => 'calendar_cita_solicitante',
        'campaign_tag' => 'calendar_cita_solicitante',
        'dedupe_key' => 'calendar_cita_solicitante:' . $logicalTicket . ':' . $start,
        'template_name' => 'scm_cita_solicitante_v1',
        'template_components' => [
          ['type' => 'body', 'parameters' => $this->textParameters([$requesterName, $category, $logicalTicket, $dateLabel, $timeLabel, $location !== '' ? $location : '-', $creatorContact])],
          $button,
        ],
      ]);
      if ($ok) {
        $queued++;
      } else {
        $detail = trim($this->queue->lastError());
        $errors[] = 'No se pudo encolar WhatsApp al solicitante de la tarea #' . $logicalTicket . ($detail !== '' ? ': ' . $detail : '.');
      }
    }

    return [
      'message' => $queued > 0 ? 'Notificaciones de cita encoladas.' : 'No había destinatarios con WhatsApp para notificar.',
      'queued' => $queued,
      'skipped' => $skipped,
      'errors' => $errors,
    ];
  }

  /** @param array<int,string> $values @return array<int,array{type:string,text:string}> */
  private function textParameters(array $values): array
  {
    return array_map(static fn(string $text): array => ['type' => 'text', 'text' => $text], $values);
  }

  /** @return array<string,mixed> */
  private function ticketRow(string $ticketLookup): array
  {
    $table = $this->db->table('jet_cct_tickets');
    if (!$this->schema->tableExists($table)) {
      return [];
    }
    $select = array_values(array_filter(
      ['_ID', 'id_ticket', 'tema_ayuda', 'asunto', 'direccion', 'solicitante', 'celular_solicitante'],
      fn(string $column): bool => $this->schema->columnExists($table, $column)
    ));
    if (!in_array('_ID', $select, true) || !ctype_digit($ticketLookup)) {
      return [];
    }
    $row = $this->db->getRow(
      'SELECT `' . implode('`, `', $select) . "` FROM `{$table}` WHERE `_ID` = ? LIMIT 1",
      [(int) $ticketLookup]
    );
    return is_array($row) ? $row : [];
  }

  /** @return array{name:string,phone:string} */
  private function employeeRow(string $lookup, string $mode): array
  {
    $empty = ['name' => '', 'phone' => ''];
    $table = $this->db->table('jet_cct_funcionarios');
    $whereColumn = $mode === 'internal' ? '_ID' : 'id_empleado';
    if (!$this->schema->tableExists($table) || !$this->schema->columnExists($table, $whereColumn)) {
      return $empty;
    }
    $nameColumn = $this->schema->detectFirstExistingColumn($table, ['nombre', 'empleado', 'nombre_empleado', 'nombre_funcionario']);
    $phoneColumn = $this->schema->detectFirstExistingColumn($table, ['celular_empleado', 'celular', 'telefono', 'whatsapp', 'phone']);
    $row = $this->db->getRow(
      'SELECT ' . ($nameColumn !== '' ? "TRIM(COALESCE(`{$nameColumn}`, ''))" : "''") . ' AS nombre, '
        . ($phoneColumn !== '' ? "TRIM(COALESCE(`{$phoneColumn}`, ''))" : "''") . " AS telefono
         FROM `{$table}` WHERE TRIM(CAST(`{$whereColumn}` AS CHAR)) = ? LIMIT 1",
      [trim($lookup)]
    );
    if (!is_array($row)) {
      return $empty;
    }
    return ['name' => trim((string) ($row['nombre'] ?? '')), 'phone' => trim((string) ($row['telefono'] ?? ''))];
  }

  /** @return array{name:string,phone:string} */
  private function creatorContact(): array
  {
    $creator = $this->employeeRow((string) Auth::userId(), 'internal');
    $name = $creator['name'] !== '' ? $creator['name'] : Auth::user();
    return ['name' => $name !== '' ? $name : 'Funcionario de Su Casa', 'phone' => $creator['phone']];
  }

  /** @param array{name:string,phone:string} $creator */
  private function creatorLabel(array $creator): string
  {
    $name = $creator['name'] !== '' ? $creator['name'] : 'la persona que agendó la cita';
    return $creator['phone'] !== '' ? $name . ' - ' . $creator['phone'] : $name;
  }

  private function dateLabel(string $start): string
  {
    $ts = strtotime($start);
    return $ts > 0 ? date('d/m/Y', $ts) : '-';
  }

  private function timeRangeLabel(string $start, string $end): string
  {
    $startTs = strtotime($start);
    $endTs = strtotime($end);
    $startLabel = $startTs > 0 ? $this->hourLabel($startTs) : '-';
    return $endTs > 0 ? $startLabel . ' a ' . $this->hourLabel($endTs) : $startLabel;
  }

  private function hourLabel(int $ts): string
  {
    return str_replace(['am', 'pm'], ['a. m.', 'p. m.'], date('g:i a', $ts));
  }

  private function employeeMessage(string $name, string $category, string $ticket, string $date, string $time, string $location): string
  {
    return "Hola {$name}, se te ha agendado una cita de {$category}.\n\nTarea: #{$ticket}\nFecha: {$date}\nHora: {$time}\nDirección: " . ($location !== '' ? $location : '-');
  }

  private function requesterMessage(string $name, string $category, string $ticket, string $date, string $time, string $location, string $creatorContact): string
  {
    return "Hola {$name}, se te ha agendado una cita de {$category}.\n\nTarea: #{$ticket}\nFecha: {$date}\nHora: {$time}\nLugar: " . ($location !== '' ? $location : '-') . "\n\nSi no puedes atenderla, contacta a {$creatorContact}.";
  }
}
