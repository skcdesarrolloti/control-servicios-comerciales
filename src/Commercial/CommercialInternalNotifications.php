<?php

declare(strict_types=1);

namespace SCM\Commercial;

use SCM\Core\Auth;
use SCM\Core\Database;
use SCM\Core\Settings;

/**
 * Notificaciones internas del panel comercial: qué funcionarios reciben copia de cada aviso. Misma idea de
 * control-servicios-inmobiliarios (`internal_admin_notifications`, internalNotificationActionCatalog y
 * src/Support/InternalNotificationRecipients.php), con su propia lista porque cada panel guarda su configuración aparte.
 *
 * Para que un módulo nuevo avise a funcionarios internos: agregar su evento a CATALOG y pedir los destinatarios con
 * recipients() o emails(), sin correos fijos en el código. El evento aparece solo en Configuración › Notificaciones internas.
 */
final class CommercialInternalNotifications
{
  public const SETTING = 'internal_admin_notifications';

  public const CATALOG = [
    'llamadas' => [
      'label' => 'Llamadas y tareas',
      'items' => [
        'llamada_tarea_creada' => ['label' => 'Tarea creada desde una llamada', 'description' => 'Copia del aviso de la tarea nueva. El responsable asignado la recibe siempre.', 'channel' => 'Correo y WhatsApp'],
        'llamada_respuesta_cliente' => ['label' => 'El cliente respondió una tarea', 'description' => 'Copia del mensaje que el cliente escribe desde su enlace. El responsable lo recibe siempre.', 'channel' => 'Correo y WhatsApp'],
      ],
    ],
    'precaptacion' => [
      'label' => 'Precaptación',
      'items' => [
        'precaptacion_creada' => ['label' => 'Precaptación registrada', 'description' => 'Aviso de una precaptación nueva. Mientras no marques a nadie sigue llegando a los correos de siempre.', 'channel' => 'Correo'],
        'precaptacion_tarea_creada' => ['label' => 'Tarea creada desde una precaptación', 'description' => 'Copia del aviso de la tarea. Mientras no marques a nadie sigue llegando a los correos de siempre.', 'channel' => 'Correo'],
      ],
    ],
  ];

  private Database $db;
  private Settings $settings;

  public function __construct(Database $db, Settings $settings)
  {
    $this->db = $db;
    $this->settings = $settings;
  }

  /** @return list<string> */
  public static function events(): array
  {
    $out = [];
    foreach (self::CATALOG as $group) {
      array_push($out, ...array_keys($group['items']));
    }
    return $out;
  }

  /** @return array<string,list<string>> evento => id_empleado de quienes reciben copia */
  public function settings(): array
  {
    $raw = $this->settings->get(self::SETTING, []);
    return self::clean(is_array($raw) ? $raw : []);
  }

  /** @param array<string,mixed> $raw */
  public function save(array $raw): void
  {
    $this->settings->set(self::SETTING, self::clean($raw), Auth::userId());
    $this->settings->refresh();
  }

  /**
   * Solo eventos conocidos y una vez cada funcionario.
   * @param array<string,mixed> $raw
   * @return array<string,list<string>>
   */
  private static function clean(array $raw): array
  {
    $out = [];
    foreach (self::events() as $event) {
      $ids = [];
      foreach ((array) ($raw[$event] ?? []) as $id) {
        $id = is_scalar($id) ? trim((string) $id) : '';
        if ($id !== '' && preg_match('/^[0-9]{1,20}$/D', $id) === 1) {
          $ids[$id] = $id;
        }
      }
      if ($ids !== []) {
        $out[$event] = array_values($ids);
      }
    }
    return $out;
  }

  /** @return list<array{id:string,nombre:string,correo:string,celular:string}> Funcionarios activos. */
  public function employees(): array
  {
    $out = [];
    foreach ($this->db->getResults("SELECT `id_empleado`, `nombre`, `correo`, `celular` FROM `" . $this->db->table('jet_cct_funcionarios') . "` WHERE `activo` = 'Si' ORDER BY `nombre` ASC") as $row) {
      $id = trim((string) ($row['id_empleado'] ?? ''));
      if ($id !== '') {
        $out[] = ['id' => $id, 'nombre' => trim((string) ($row['nombre'] ?? '')), 'correo' => trim((string) ($row['correo'] ?? '')), 'celular' => trim((string) ($row['celular'] ?? ''))];
      }
    }
    return $out;
  }

  /** @return list<array{id:string,nombre:string,correo:string,celular:string}> Funcionarios activos marcados en ese evento. */
  public function recipients(string $event): array
  {
    $selected = array_flip($this->settings()[$event] ?? []);
    return $selected === [] ? [] : array_values(array_filter($this->employees(), static fn(array $employee): bool => isset($selected[$employee['id']])));
  }

  /** @return list<string> */
  public function emails(string $event): array
  {
    $emails = [];
    foreach ($this->recipients($event) as $recipient) {
      if (filter_var($recipient['correo'], FILTER_VALIDATE_EMAIL) !== false) {
        $emails[strtolower($recipient['correo'])] = $recipient['correo'];
      }
    }
    return array_values($emails);
  }
}
