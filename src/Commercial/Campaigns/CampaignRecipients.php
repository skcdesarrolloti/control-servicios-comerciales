<?php

declare(strict_types=1);

namespace SCM\Commercial\Campaigns;

use SCM\Commercial\CommercialNotificationsService;
use SCM\Modules\AdministrativeNotifications\AdministrativeNotificationsService;

/**
 * Listas de destinatarios de las campañas (Notificaciones › Campañas). Reutiliza los tipos, la búsqueda y los filtros de
 * contrato de Notificaciones (propietarios, arrendatarios y copropiedades activos o no activos) y agrega las listas que
 * usaba dashboard-marketing (app/Actors/*.php): clientes, suscriptores, codeudores y contactos.
 *
 * A diferencia de Notificaciones (solo los contactos del funcionario si no ve todo el equipo), una campaña llega a la
 * lista completa: por eso enviar campañas pide su propio permiso (campanas_enviar).
 */
final class CampaignRecipients extends CommercialNotificationsService
{
  /** Más que el máximo de cualquier lista de hoy (clientes: ~3.600). */
  public const MAX_PER_SEGMENT = 20000;

  public function types(): array
  {
    $commercial = parent::types();
    $administrative = AdministrativeNotificationsService::types();
    $simple = fn(string $table, string $label, string $role, array $search): array => [
      'label' => $label, 'role' => $role, 'table' => $this->db->table($table),
      'name' => ['nombre'], 'email' => ['correo'], 'phone' => ['celular', 'telefono'], 'indicator' => ['indicativo'], 'search' => $search,
    ];
    // La lista completa, sin el filtro de contratos: muchos no tienen contratos registrados y las campañas del panel
    // anterior llegaban a todos.
    $all = static fn(array $config, string $label): array => ['label' => $label] + array_diff_key($config, ['contract_status_fixed' => true]);
    return [
      'propietarios_todos' => $all($commercial['propietarios_activos'], 'Propietarios (todos)'),
      'propietarios_activos' => $commercial['propietarios_activos'],
      'propietarios_no_activos' => $commercial['propietarios_no_activos'],
      'arrendatarios_todos' => $all($commercial['arrendatarios_activos'], 'Arrendatarios (todos)'),
      'arrendatarios_activos' => $commercial['arrendatarios_activos'],
      'arrendatarios_no_activos' => $commercial['arrendatarios_no_activos'],
      'copropiedades_todas' => $all($administrative['copropiedades_activas'], 'Copropiedades (todas)'),
      'copropiedades_activas' => $administrative['copropiedades_activas'],
      'copropiedades_no_activas' => $administrative['copropiedades_no_activas'],
      'clientes' => $simple('jet_cct_clientes', 'Clientes', 'Cliente', ['nombre', 'documento', 'correo', 'celular', 'tipo_cliente', 'ciudad']),
      'suscriptores' => $simple('jet_cct_newsletter', 'Suscriptores', 'Suscriptor', ['nombre', 'correo', 'celular', 'ciudad', 'estado'])
        + ['subscribed_only' => true],
      'codeudores' => $simple('jet_cct_codeudores', 'Codeudores', 'Codeudor', ['nombre', 'documento', 'correo', 'celular', 'ciudad']),
      'contactos' => $simple('jet_cct_contactos', 'Contactos', 'Contacto', ['nombre', 'documento', 'correo', 'celular', 'tipo_contacto']),
      'club_pph' => $commercial['club_pph'],
      'proveedores' => $administrative['proveedores'],
      'funcionarios' => $administrative['funcionarios'],
    ];
  }

  /** La lista completa (sin el alcance por funcionario de Notificaciones), sin papelera y solo suscriptores activos. */
  protected function baseWhere(array $config): string
  {
    $where = AdministrativeNotificationsService::baseWhere($config);
    $table = (string) $config['table'];
    if ($this->schema->columnExists($table, 'cct_status')) {
      $where .= " AND LOWER(COALESCE(`cct_status`, 'publish')) <> 'trash'";
    }
    if (!empty($config['subscribed_only']) && $this->schema->columnExists($table, 'suscrito')) {
      $where .= " AND LOWER(TRIM(COALESCE(`suscrito`, 'si'))) <> 'no'";
    }
    return $where;
  }

  /** @return array<string,string> clave => etiqueta */
  public function typeLabels(): array
  {
    return array_map(static fn(array $config): string => (string) $config['label'], $this->types());
  }

  /**
   * IDs de un segmento (tipo + búsqueda opcional), con los mismos filtros de contrato de Notificaciones.
   * @return list<int>
   */
  public function segmentIds(string $type, string $query = ''): array
  {
    if (!isset($this->types()[$type])) {
      throw new \InvalidArgumentException('Lista de destinatarios no válida.');
    }
    return $this->idsForFilter($type, mb_substr(trim($query), 0, 150, 'UTF-8'), self::MAX_PER_SEGMENT);
  }

  /**
   * Filas completas de esos IDs con nombre, correo, celular e indicativo normalizados (todas las columnas quedan
   * disponibles como {{variable}} en las plantillas). De a 500 para no armar consultas enormes.
   * @param list<int> $ids
   * @return list<array<string,mixed>>
   */
  public function rows(string $type, array $ids): array
  {
    $config = $this->types()[$type] ?? null;
    if ($config === null || $ids === []) {
      return [];
    }
    $table = (string) $config['table'];
    $columns = [];
    foreach (['name', 'email', 'phone', 'indicator'] as $key) {
      $columns[$key] = '';
      foreach ((array) $config[$key] as $candidate) {
        if ($this->schema->columnExists($table, (string) $candidate)) {
          $columns[$key] = (string) $candidate;
          break;
        }
      }
    }
    $out = [];
    foreach (array_chunk($ids, 500) as $chunk) {
      $rows = $this->db->getResults("SELECT * FROM `{$table}` WHERE `_ID` IN (" . implode(',', array_fill(0, count($chunk), '?')) . ')', $chunk);
      foreach ($rows as $row) {
        $row['nombre'] = trim((string) ($columns['name'] !== '' ? ($row[$columns['name']] ?? '') : ''));
        $row['correo'] = trim((string) ($columns['email'] !== '' ? ($row[$columns['email']] ?? '') : ''));
        $row['celular'] = trim((string) ($columns['phone'] !== '' ? ($row[$columns['phone']] ?? '') : ''));
        $row['indicativo'] = trim((string) ($columns['indicator'] !== '' ? ($row[$columns['indicator']] ?? '') : ''));
        $row['tipo_actor'] = $type;
        $row['tipo_label'] = (string) $config['label'];
        $row['rol_persona'] = (string) $config['role'];
        $out[] = $row;
      }
    }
    return $out;
  }

  /** Correo o celular válido del destinatario para el canal ('' si no tiene). @param array<string,mixed> $recipient */
  public function destinationFor(array $recipient, string $channel): string
  {
    return $this->destination($recipient, $channel);
  }

  /**
   * Regla de preferencias de dashboard-marketing (ActorPreferences::permite): los funcionarios siempre; los demás no si
   * bloquearon el canal; si la lista tiene la columna permite_<categoría>_<canal>, solo con 1; sin esa columna, las
   * categorías promocional y marketing no se envían.
   * @param array<string,mixed> $recipient
   */
  public static function allows(array $recipient, string $category, string $channel): bool
  {
    if (($recipient['tipo_actor'] ?? '') === 'funcionarios') {
      return true;
    }
    if ((int) ($recipient['bloqueo_' . $channel] ?? 0) === 1) {
      return false;
    }
    $field = 'permite_' . $category . '_' . $channel;
    if (array_key_exists($field, $recipient)) {
      return (int) $recipient[$field] === 1;
    }
    return !in_array($category, ['promo', 'marketing'], true);
  }
}
