<?php

declare(strict_types=1);

namespace SCM\Commercial\Calls;

use SCM\Core\Database;
use SCM\Core\Settings;
use SCM\Support\EmailQueue;
use SCM\Support\EmailTemplate;

/**
 * Correos de la tarea creada desde una llamada, los mismos tres del formulario "Crear ticket comercial con llamadas".
 * Van por shared-notifications (EmailQueue) y se configuran en Configuración › Llamadas.
 */
final class CommercialCallsNotifier
{
  public const SETTING = 'commercial_calls_notifications';
  public const DEFAULTS = ['asignado' => true, 'solicitante' => true, 'administradores' => true, 'admin_emails' => []];

  private const BROCHURE = 'https://sucasainmobiliaria.com.co/brochure-skc-sucasa-inmobiliaria/';
  private const DEFAULT_TASK_URL = 'https://sucasainmobiliaria.com.co/ticket/';

  /** @var callable(string|list<string>,string,string,array<string,mixed>):void */
  private $enqueue;
  /** @var array{asignado:bool,solicitante:bool,administradores:bool,admin_emails:list<string>} */
  private array $settings;
  private string $taskUrl;

  /**
   * @param array<string,mixed> $config
   * @param (callable(string|list<string>,string,string,array<string,mixed>):void)|null $enqueue Por defecto, shared-notifications.
   */
  public function __construct(Database $db, Settings $settings, array $config, ?callable $enqueue = null)
  {
    $this->enqueue = $enqueue ?? [new EmailQueue($db), 'enqueue'];
    $this->settings = self::settings($settings);
    $this->taskUrl = trim((string) ($config['ticket_url'] ?? '')) ?: self::DEFAULT_TASK_URL;
  }

  /** @return array{asignado:bool,solicitante:bool,administradores:bool,admin_emails:list<string>} */
  public static function settings(Settings $settings): array
  {
    $raw = $settings->get(self::SETTING, []);
    $raw = is_array($raw) ? $raw : [];
    return [
      'asignado' => (bool) ($raw['asignado'] ?? self::DEFAULTS['asignado']),
      'solicitante' => (bool) ($raw['solicitante'] ?? self::DEFAULTS['solicitante']),
      'administradores' => (bool) ($raw['administradores'] ?? self::DEFAULTS['administradores']),
      'admin_emails' => array_values(array_filter(array_map('strval', (array) ($raw['admin_emails'] ?? [])), static fn(string $email): bool => filter_var($email, FILTER_VALIDATE_EMAIL) !== false)),
    ];
  }

  /**
   * @param array<string,mixed> $task Fila guardada de la tarea.
   * @param array<string,mixed> $branch Sucursal, para firmar el correo al solicitante.
   */
  public function taskCreated(int $id, array $task, array $branch): void
  {
    $e = static fn($value): string => EmailTemplate::e((string) $value);
    $topic = $e($task['tema_ayuda'] ?? '');
    $employee = $e($task['nombre_empleado'] ?? '');
    $url = $this->taskUrl . (str_contains($this->taskUrl, '?') ? '&' : '?') . 'id_ticket=' . $id;
    $buttons = [
      ['url' => $url, 'label' => 'Ver tarea'],
      ['url' => self::BROCHURE, 'label' => 'Ver brochure'],
      ['url' => function_exists('system_image') ? system_image('link_revista', 'https://sucasainmobiliaria.com.co/revista/') : 'https://sucasainmobiliaria.com.co/revista/', 'label' => 'Ver revista'],
    ];
    if ($this->settings['asignado']) {
      $this->send($task['correo_empleado'] ?? '', 'Nueva tarea #' . $id . ' asignada', 'Nueva tarea comercial',
        '<p>Estimado/a <strong>' . $employee . '</strong>, se te asignó una nueva tarea comercial de <strong>' . $topic . '</strong> sobre <strong>' . $e($task['asunto'] ?? '') . '</strong>.</p>',
        $buttons, 'llamadas:tarea:' . $id . ':asignado');
    }
    if ($this->settings['solicitante']) {
      $manager = trim((string) ($branch['gerente_comercial'] ?? ''));
      $signature = $manager !== ''
        ? '<p>Cordialmente:<br><strong>' . $e($manager) . '</strong> · Coordinación comercial' . (trim((string) ($branch['celular_ge_comercial'] ?? '')) !== '' ? ' · ' . $e($branch['celular_ge_comercial']) : '') . '</p>'
        : '<p>Cordialmente,<br><strong>Equipo comercial</strong></p>';
      $this->send($task['correo_solicitante'] ?? '', 'Solicitud #' . $id . ' agendada', 'Registramos tu solicitud',
        '<p>Estimado/a <strong>' . $e($task['solicitante'] ?? '') . '</strong>, registramos tu solicitud de <strong>' . $topic . '</strong> y la asignamos a <strong>' . $employee
        . '</strong>' . (trim((string) ($task['celular_empleado'] ?? '')) !== '' ? ' (' . $e($task['celular_empleado']) . ')' : '') . '.</p><p>Su atención será evaluada para garantizar un servicio óptimo. Si no cumple tus expectativas, puedes responder a este correo.</p>' . $signature,
        $buttons, 'llamadas:tarea:' . $id . ':solicitante', (string) ($task['correo_empleado'] ?? ''));
    }
    if ($this->settings['administradores'] && $this->settings['admin_emails'] !== []) {
      $this->send($this->settings['admin_emails'], 'Tarea comercial #' . $id . ' creada', 'Nueva tarea comercial',
        '<p>Se creó una tarea comercial de <strong>' . $topic . '</strong> desde el medio <strong>' . $e($task['medio'] ?? '') . '</strong>, asignada a <strong>' . $employee . '</strong>.</p>',
        $buttons, 'llamadas:tarea:' . $id . ':admin');
    }
  }

  /**
   * @param string|list<string> $to
   * @param list<array{url:string,label:string}> $buttons
   */
  private function send($to, string $subject, string $title, string $body, array $buttons, string $dedupe, string $replyTo = ''): void
  {
    // La plantilla compartida firma con otro nombre; aquí va el nombre corporativo.
    $html = str_replace('Su Casa Inmobiliaria', 'SKC SuCasa Inmobiliaria', EmailTemplate::render($title, $body . '<div style="margin-top:20px;text-align:center;">' . EmailTemplate::buttons($buttons) . '</div>'));
    $headers = $replyTo !== '' && filter_var($replyTo, FILTER_VALIDATE_EMAIL) ? ['Reply-To: ' . $replyTo] : [];
    ($this->enqueue)($to, $subject, $html, ['source_module' => 'llamadas', 'provider' => 'email_smtp', 'dedupe_key' => $dedupe, 'meta' => ['headers' => $headers]]);
  }
}
