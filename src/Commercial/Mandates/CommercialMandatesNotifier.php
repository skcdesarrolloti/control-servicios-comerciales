<?php

declare(strict_types=1);

namespace SCM\Commercial\Mandates;

use SCM\Commercial\Calls\CommercialCallsNotifier;
use SCM\Commercial\CommercialInternalNotifications;
use SCM\Core\Database;
use SCM\Core\Settings;
use SCM\Support\EmailQueue;
use SCM\Support\EmailTemplate;
use SCM\Support\PublicMandateAccess;
use SCM\Support\SharedNotificationsBridge;

/**
 * Avisos de la revisión del mandato antes de la firma (por shared-notifications, plantillas fijas en el código):
 * - a cada propietario, por correo y WhatsApp, el enlace para revisar el contrato (public/mandato.php);
 * - a quien creó el mandato y a Configuración › Notificaciones internas cuando un propietario deja observaciones
 *   (mandato_observaciones) o aprueba el contrato (mandato_aprobado): correo y WhatsApp (TEMPLATE_STAFF, cada uno con
 *   su enlace individual firmado que inicia su sesión: PublicMandateAccess::staffLink); y la copia del envío
 *   (mandato_revision_enviada), solo por correo.
 * Quien bloqueó un canal en su ficha de propietario no lo recibe. Plantilla en docs/mandatos.md.
 */
final class CommercialMandatesNotifier
{
  public const TEMPLATE_REVIEW = 'scm_mandato_revision_propietario_v1';
  /** Al consultor/a (y Notificaciones internas): el propietario dejó una observación o aprobó el contrato. */
  public const TEMPLATE_STAFF = 'scm_mandato_respuesta_propietario_v1';
  public const EVENT_SENT = 'mandato_revision_enviada';
  public const EVENT_NOTE = 'mandato_observaciones';
  public const EVENT_APPROVED = 'mandato_aprobado';

  private CommercialInternalNotifications $internal;
  private CommercialMandatesRepository $repository;
  /** @var callable(string|list<string>,string,string,array<string,mixed>):void */
  private $enqueue;
  /** @var callable(string,string,list<array<string,mixed>>,array<string,mixed>):void */
  private $whatsapp;

  /**
   * @param (callable(string|list<string>,string,string,array<string,mixed>):void)|null $enqueue Correo; por defecto, shared-notifications.
   * @param (callable(string,string,list<array<string,mixed>>,array<string,mixed>):void)|null $whatsapp Por defecto, shared-notifications.
   */
  public function __construct(Database $db, Settings $settings, ?callable $enqueue = null, ?callable $whatsapp = null)
  {
    $this->enqueue = $enqueue ?? [new EmailQueue($db), 'enqueue'];
    $this->whatsapp = $whatsapp ?? static function (string $phone, string $template, array $components, array $options) use ($db): void {
      $bridge = new SharedNotificationsBridge($db);
      $queue = $bridge->queue();
      if ($queue !== null) {
        $queue->enqueueWhatsAppOfficialTemplate($phone, $template, $components, $options + ['project_code' => $bridge->projectCode(), 'created_by' => 'control-servicios-comerciales']);
      }
    };
    $this->internal = new CommercialInternalNotifications($db, $settings);
    $this->repository = new CommercialMandatesRepository($db);
  }

  /**
   * El enlace de revisión a cada propietario, por correo y WhatsApp.
   * @param array<string,mixed> $mandate
   * @param array{id:string,nombre:string,correo:string,celular:string} $actor
   * @return list<array{nombre:string,correo:bool,whatsapp:bool}>
   */
  public function sendToOwners(int $id, array $mandate, array $actor, int $round): array
  {
    $e = static fn($value): string => EmailTemplate::e((string) $value);
    $creator = $this->repository->creator($mandate);
    $contact = CommercialCallsNotifier::contactLine(['nombre_empleado' => $creator['nombre'] ?: $actor['nombre'], 'correo_empleado' => $creator['correo'] ?: $actor['correo'], 'celular_empleado' => $creator['celular'] ?: $actor['celular']]);
    $place = self::place($mandate);
    $url = PublicMandateAccess::url($id);
    $results = [];
    foreach (MandateDocument::owners($mandate) as $i => $owner) {
      if ($owner['nombre'] === '') {
        continue;
      }
      $preferences = $this->repository->owner($owner['id']);
      $sent = ['nombre' => $owner['nombre'], 'correo' => false, 'whatsapp' => false];
      $email = $owner['correo'];
      if ((int) ($preferences['bloqueo_email'] ?? 0) !== 1 && filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
        $sent['correo'] = $this->send($email, 'Revise su contrato de mandato #' . $id . ' antes de firmarlo - SKC SuCasa Inmobiliaria', 'Revise su contrato de mandato',
          '<p>Apreciado/a <strong>' . $e($owner['nombre']) . '</strong>, preparamos el contrato de mandato <strong>#' . $id . '</strong> de su inmueble <strong>' . $e($place) . '</strong>.</p>'
          . '<p>Antes de enviárselo para la firma digital, revíselo en el enlace. Si algo debe cambiar, déjenos sus observaciones en la sección que corresponda; si todo está correcto, apruébelo.</p>'
          . '<p><strong>Su consultor/a asignado es</strong> ' . $e($contact) . '.</p>',
          [['url' => $url, 'label' => 'Revisar mi contrato']], 'mandatos:' . $id . ':revision:' . $round . ':' . $i, $creator['correo'] ?: $actor['correo']);
      }
      if ((int) ($preferences['bloqueo_whatsapp'] ?? 0) !== 1) {
        $sent['whatsapp'] = $this->whatsappTo($owner['celular'], $owner['indicativo'] !== '' ? $owner['indicativo'] : '57', [$owner['nombre'], (string) $id, $place, $contact], PublicMandateAccess::reference($id), 'mandatos:' . $id . ':revision:' . $round . ':' . $i . ':whatsapp');
      }
      $results[] = $sent;
    }

    $lines = array_map(static function (array $result) use ($e): string {
      $channels = array_keys(array_filter(['correo' => $result['correo'], 'WhatsApp' => $result['whatsapp']]));
      return '<li><strong>' . $e($result['nombre']) . '</strong>: ' . ($channels === [] ? 'no se le pudo avisar (revisa su correo y su celular)' : 'por ' . implode(' y ', $channels)) . '</li>';
    }, $results);
    $this->toStaff(array_merge([$actor['correo'], $creator['correo']], $this->internal->emails(self::EVENT_SENT)), 'Mandato #' . $id . ' enviado al propietario para revisión',
      'Mandato enviado al propietario',
      '<p><strong>' . $e($actor['nombre']) . '</strong> envió el contrato de mandato <strong>#' . $id . '</strong> (' . $e($place) . ') a revisión del propietario antes de la firma.</p><ul>' . implode('', $lines) . '</ul>',
      $id, 'mandatos:' . $id . ':revision:' . $round . ':equipo');
    return $results;
  }

  /** @param array<string,mixed> $mandate */
  public function ownerNote(int $id, array $mandate, string $name, string $section, string $message): void
  {
    $e = static fn($value): string => EmailTemplate::e((string) $value);
    $creator = $this->repository->creator($mandate);
    $this->toStaff(array_merge([$creator['correo']], $this->internal->emails(self::EVENT_NOTE)), 'Observación del propietario en el mandato #' . $id . ' · ' . (MandateDocument::SECTIONS[$section] ?? 'General'),
      'El propietario pidió un cambio en el mandato',
      '<p><strong>' . $e($name) . '</strong> dejó una observación en el contrato de mandato <strong>#' . $id . '</strong> (' . $e(self::place($mandate)) . '), sección <strong>' . $e(MandateDocument::SECTIONS[$section] ?? 'General') . '</strong>:</p>'
      . '<blockquote style="margin:12px 0;padding:10px 14px;border-left:4px solid #f8cf4a;background:#fffbeb;">' . nl2br($e($message)) . '</blockquote>'
      . '<p>Corrige el mandato si corresponde, responde la observación y no lo envíes a firma hasta resolverla.</p>',
      $id, 'mandatos:' . $id . ':observacion:' . hash('crc32b', $name . $section . $message));
    $this->staffWhatsApp($id, $mandate, self::EVENT_NOTE, $name,
      'Dejó una observación en ' . (MandateDocument::SECTIONS[$section] ?? 'Comentario general') . ': «' . mb_substr(trim((string) preg_replace('/\s+/u', ' ', $message)), 0, 120) . '»',
      'mandatos:' . $id . ':observacion:' . hash('crc32b', $name . $section . $message));
  }

  /** @param array<string,mixed> $mandate */
  public function ownerApproved(int $id, array $mandate, string $name): void
  {
    $e = static fn($value): string => EmailTemplate::e((string) $value);
    $creator = $this->repository->creator($mandate);
    $this->toStaff(array_merge([$creator['correo']], $this->internal->emails(self::EVENT_APPROVED)), 'Mandato #' . $id . ' aprobado por el propietario',
      'El propietario aprobó el mandato',
      '<p><strong>' . $e($name) . '</strong> revisó y aprobó el contrato de mandato <strong>#' . $id . '</strong> (' . $e(self::place($mandate)) . '). Ya puede enviarse a firma.</p>',
      $id, 'mandatos:' . $id . ':aprobado:' . date('YmdHi'));
    $this->staffWhatsApp($id, $mandate, self::EVENT_APPROVED, $name, 'Aprobó el contrato. Ya puede enviarse a firma', 'mandatos:' . $id . ':aprobado:' . date('YmdHi'));
  }

  /**
   * WhatsApp a quien creó el mandato y a los de Notificaciones internas del evento, cada uno con su enlace firmado.
   * Variables: {{1}} nombre del funcionario, {{2}} propietario, {{3}} número del mandato, {{4}} inmueble, {{5}} detalle.
   * @param array<string,mixed> $mandate
   */
  private function staffWhatsApp(int $id, array $mandate, string $event, string $ownerName, string $detail, string $dedupe): void
  {
    $people = [];
    $creatorId = trim((string) ($mandate['id_empleado'] ?? ''));
    if ($creatorId !== '') {
      $creator = $this->repository->creator($mandate);
      $people[$creatorId] = ['id' => $creatorId, 'nombre' => $creator['nombre'], 'celular' => $creator['celular']];
    }
    foreach ($this->internal->recipients($event) as $person) {
      $people[$person['id']] ??= ['id' => $person['id'], 'nombre' => $person['nombre'], 'celular' => $person['celular']];
    }
    foreach ($people as $person) {
      try {
        $reference = PublicMandateAccess::staffReference($id, $person['id']);
      } catch (\InvalidArgumentException) {
        continue;
      }
      $first = trim((string) strtok($person['nombre'], ' ')) ?: 'equipo';
      $this->whatsappTo($person['celular'], '57', [$first, $ownerName !== '' ? $ownerName : 'del mandato', (string) $id, self::place($mandate), $detail], $reference, $dedupe . ':whatsapp:' . $person['id'], self::TEMPLATE_STAFF);
    }
  }

  /** Inmueble del mandato para los avisos: dirección o barrio y su código. @param array<string,mixed> $mandate */
  public static function place(array $mandate): string
  {
    $where = MandateDocument::text($mandate['direccion'] ?? '') ?: MandateDocument::text($mandate['barrio'] ?? '');
    $code = MandateDocument::text($mandate['id_inmueble'] ?? '');
    return trim(($where !== '' ? $where : 'Inmueble') . ($code !== '' ? ' (código ' . $code . ')' : ''));
  }

  /** @param list<string> $emails Se quitan repetidos y vacíos; reciben el enlace interno del mandato. */
  private function toStaff(array $emails, string $subject, string $title, string $body, int $id, string $dedupe): void
  {
    $to = [];
    foreach ($emails as $email) {
      $email = trim((string) $email);
      if (filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
        $to[strtolower($email)] = $email;
      }
    }
    if ($to !== []) {
      $this->send(array_values($to), $subject, $title, $body, [['url' => PublicMandateAccess::staffUrl($id), 'label' => 'Ver mandato']], $dedupe);
    }
  }

  /** @param list<string> $values Variables del cuerpo: Meta no acepta vacíos, saltos de línea ni textos muy largos. */
  private function whatsappTo(string $rawPhone, string $country, array $values, string $buttonValue, string $dedupe, string $template = self::TEMPLATE_REVIEW): bool
  {
    $phone = CommercialCallsNotifier::phone($rawPhone, (string) preg_replace('/\D+/', '', $country) ?: '57');
    if ($phone === '') {
      return false;
    }
    $parameters = array_map(static function ($value): array {
      $text = trim((string) preg_replace('/\s+/u', ' ', (string) $value));
      return ['type' => 'text', 'text' => $text !== '' ? mb_substr(rtrim($text, '.'), 0, 180) : '-'];
    }, $values);
    $components = [
      ['type' => 'body', 'parameters' => $parameters],
      ['type' => 'button', 'sub_type' => 'url', 'index' => '0', 'parameters' => [['type' => 'text', 'text' => $buttonValue]]],
    ];
    try {
      ($this->whatsapp)($phone, $template, $components, ['source_module' => 'mandatos-whatsapp', 'dedupe_key' => $dedupe, 'priority' => 100, 'template_language' => CommercialCallsNotifier::TEMPLATE_LANGUAGE]);
      return true;
    } catch (\Throwable $exception) {
      error_log('[mandatos:whatsapp] ' . $exception->getMessage());
      return false;
    }
  }

  /**
   * @param string|list<string> $to
   * @param list<array{url:string,label:string}> $buttons
   */
  private function send($to, string $subject, string $title, string $body, array $buttons, string $dedupe, string $replyTo = ''): bool
  {
    if ($to === '' || $to === []) {
      return false;
    }
    $html = str_replace('Su Casa Inmobiliaria', 'SKC SuCasa Inmobiliaria', EmailTemplate::render($title, $body . '<div style="margin-top:20px;text-align:center;">' . EmailTemplate::buttons($buttons) . '</div>'));
    $payload = array_filter(['reply_to' => $replyTo !== '' && filter_var($replyTo, FILTER_VALIDATE_EMAIL) ? $replyTo : '']);
    try {
      ($this->enqueue)($to, $subject, $html, ['source_module' => 'mandatos', 'provider' => 'email_smtp', 'dedupe_key' => $dedupe, 'payload' => $payload]);
      return true;
    } catch (\Throwable $exception) {
      error_log('[mandatos:correo] ' . $exception->getMessage());
      return false;
    }
  }
}
