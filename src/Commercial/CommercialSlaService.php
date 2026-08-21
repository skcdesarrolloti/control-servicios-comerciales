<?php

declare(strict_types=1);

namespace SCM\Commercial;

use SCM\Core\Database;

final class CommercialSlaService
{
  private const CONFIG_KEY = 'control_tickets_sla_config';

  private Database $db;

  /** @var array{version:int,topics:array<string,array{warning_from:int,overdue_after:int}>}|null */
  private ?array $config = null;

  public function __construct(Database $db)
  {
    $this->db = $db;
  }

  /** @param array<string,mixed> $ticket @return array<string,mixed> */
  public function decorateTicket(array $ticket): array
  {
    $timestamp = $this->attentionTimestamp($ticket);
    $days = $timestamp > 0 ? max(0, (int) floor((time() - $timestamp) / 86400)) : 0;
    $state = $this->calculate($this->topicForTicket($ticket), $days);

    $ticket['scm_attention_days'] = $days;
    $ticket['scm_attention_since'] = $timestamp;
    $ticket['scm_sla_status'] = $state['status'];
    $ticket['scm_sla_label'] = $state['label'];
    $ticket['scm_sla_is_overdue'] = $state['is_overdue'] ? '1' : '0';
    $ticket['scm_sla_due_days'] = $state['due_days'];
    $ticket['scm_sla_warning_from'] = $state['warning_from'];
    $ticket['scm_sla_percent'] = $state['percent'];
    $ticket['scm_sla_priority'] = $state['priority'];

    return $ticket;
  }

  /**
   * @param array<int,array<string,mixed>> $tickets
   * @return array{total:int,al_dia:int,atrasados:int,porcentaje_cumplimiento:int,porcentaje_atraso:int,visible_total:int}
   */
  public function summary(array $tickets, int $visibleTotal = 0): array
  {
    $total = count($tickets);
    $overdue = 0;
    foreach ($tickets as $ticket) {
      if ((string) ($ticket['scm_sla_is_overdue'] ?? '') === '1') {
        $overdue++;
      }
    }
    $onTime = max(0, $total - $overdue);

    return [
      'total' => $total,
      'al_dia' => $onTime,
      'atrasados' => $overdue,
      'porcentaje_cumplimiento' => $total > 0 ? (int) round(($onTime / $total) * 100) : 0,
      'porcentaje_atraso' => $total > 0 ? (int) round(($overdue / $total) * 100) : 0,
      'visible_total' => $visibleTotal > 0 ? $visibleTotal : $total,
    ];
  }

  /** @param array<string,mixed> $ticket */
  private function topicForTicket(array $ticket): string
  {
    foreach (['tema_ayuda', 'asunto', 'estado_comercial', 'medio'] as $column) {
      $value = trim((string) ($ticket[$column] ?? ''));
      if ($value !== '') {
        return $value;
      }
    }
    return '';
  }

  /** @param array<string,mixed> $ticket */
  private function attentionTimestamp(array $ticket): int
  {
    foreach (['cct_modified', 'cct_created'] as $column) {
      $value = trim((string) ($ticket[$column] ?? ''));
      if ($value !== '' && strpos($value, '0000') === false) {
        $timestamp = strtotime($value);
        if ($timestamp !== false) {
          return $timestamp;
        }
      }
    }

    foreach (['fecha_actualizacion', 'fecha'] as $column) {
      $timestamp = (int) ($ticket[$column] ?? 0);
      if ($timestamp > 0) {
        return $timestamp;
      }
    }

    return 0;
  }

  /** @return array{status:string,label:string,priority:int,is_overdue:bool,days:int,warning_from:int,due_days:int,percent:int} */
  private function calculate(string $topic, int $days): array
  {
    $rule = $this->findRule($topic);
    $due = max(1, (int) $rule['overdue_after']);
    $warning = max(0, min($due, (int) $rule['warning_from']));
    $overdue = $days > $due;

    return [
      'status' => $overdue ? 'atrasado' : 'al_dia',
      'label' => $overdue ? 'Atrasado' : 'Al día',
      'priority' => $overdue ? 2 : 1,
      'is_overdue' => $overdue,
      'days' => $days,
      'warning_from' => $warning,
      'due_days' => $due,
      'percent' => min(999, (int) floor(($days / $due) * 100)),
    ];
  }

  /** @return array{warning_from:int,overdue_after:int} */
  private function findRule(string $topic): array
  {
    $needle = $this->normalizeTopic($topic);
    foreach ($this->config()['topics'] as $configuredTopic => $rule) {
      $configured = $this->normalizeTopic($configuredTopic);
      if ($configured === $needle || ($needle !== '' && str_contains($needle, $configured)) || ($configured !== '' && str_contains($configured, $needle))) {
        return $rule;
      }
    }

    return ['warning_from' => 4, 'overdue_after' => 5];
  }

  private function normalizeTopic(string $topic): string
  {
    $topic = mb_strtolower(trim($topic), 'UTF-8');
    $topic = str_replace(['á', 'é', 'í', 'ó', 'ú', 'ü', 'ñ'], ['a', 'e', 'i', 'o', 'u', 'u', 'n'], $topic);
    $topic = preg_replace('/\s+/', ' ', $topic) ?? '';
    $topic = str_replace(' arriendo y venta', ' arriendo o venta', ' ' . $topic);
    return trim($topic);
  }

  /** @return array{version:int,topics:array<string,array{warning_from:int,overdue_after:int}>} */
  private function config(): array
  {
    if (is_array($this->config)) {
      return $this->config;
    }

    $raw = '';
    try {
      $table = $this->db->table('jet_cct_confi_sistema');
      $raw = (string) ($this->db->getVar("SELECT `valor` FROM `{$table}` WHERE `funcion` = ? ORDER BY `_ID` DESC LIMIT 1", [self::CONFIG_KEY]) ?? '');
    } catch (\Throwable $exception) {
      $raw = '';
    }

    $decoded = $raw !== '' ? json_decode($raw, true) : null;
    $this->config = $this->normalizeConfig(is_array($decoded) ? $decoded : self::defaultConfig());
    return $this->config;
  }

  /**
   * @param array<string,mixed> $config
   * @return array{version:int,topics:array<string,array{warning_from:int,overdue_after:int}>}
   */
  private function normalizeConfig(array $config): array
  {
    $topics = [];
    $source = is_array($config['topics'] ?? null) ? $config['topics'] : [];
    foreach ($source as $topic => $rule) {
      if (!is_array($rule)) {
        continue;
      }
      $name = trim((string) $topic);
      if ($name === '') {
        continue;
      }
      $due = max(1, (int) ($rule['overdue_after'] ?? 5));
      $warning = max(0, min($due, (int) ($rule['warning_from'] ?? $due)));
      $topics[$name] = ['warning_from' => $warning, 'overdue_after' => $due];
    }

    if ($topics === []) {
      $topics = self::defaultConfig()['topics'];
    }

    return ['version' => 1, 'topics' => $topics];
  }

  /** @return array{version:int,topics:array<string,array{warning_from:int,overdue_after:int}>} */
  private static function defaultConfig(): array
  {
    return [
      'version' => 1,
      'topics' => [
        'Avaluo' => ['warning_from' => 4, 'overdue_after' => 5],
        'Avalúo' => ['warning_from' => 4, 'overdue_after' => 5],
        'Actualizacion' => ['warning_from' => 4, 'overdue_after' => 5],
        'Actualización' => ['warning_from' => 4, 'overdue_after' => 5],
        'Captacion' => ['warning_from' => 6, 'overdue_after' => 7],
        'Captación' => ['warning_from' => 6, 'overdue_after' => 7],
        'Recaptacion' => ['warning_from' => 6, 'overdue_after' => 7],
        'Recaptación' => ['warning_from' => 6, 'overdue_after' => 7],
        'Arriendo' => ['warning_from' => 2, 'overdue_after' => 2],
        'Arrendar' => ['warning_from' => 2, 'overdue_after' => 2],
        'Arrendamiento' => ['warning_from' => 2, 'overdue_after' => 2],
        'Venta' => ['warning_from' => 2, 'overdue_after' => 2],
        'Vender' => ['warning_from' => 2, 'overdue_after' => 2],
        'Arriendo o venta' => ['warning_from' => 2, 'overdue_after' => 2],
        'Arriendo y venta' => ['warning_from' => 2, 'overdue_after' => 2],
        'Entrega de inmuebles' => ['warning_from' => 2, 'overdue_after' => 2],
        'Revision preventiva' => ['warning_from' => 8, 'overdue_after' => 10],
        'Revisión preventiva' => ['warning_from' => 8, 'overdue_after' => 10],
        'Recibo de inmuebles' => ['warning_from' => 2, 'overdue_after' => 2],
        'Ruta' => ['warning_from' => 2, 'overdue_after' => 2],
        'Retoque' => ['warning_from' => 7, 'overdue_after' => 8],
        'Otros servicios' => ['warning_from' => 5, 'overdue_after' => 5],
      ],
    ];
  }
}
