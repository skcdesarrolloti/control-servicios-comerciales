<?php

declare(strict_types=1);

namespace SCM\Commercial\Quotes;

use SCM\Commercial\Calls\CommercialCallsNotifier;
use SCM\Commercial\Calls\CommercialCallsRepository;
use SCM\Commercial\CommercialInternalNotifications;
use SCM\Core\Database;
use SCM\Core\Settings;
use SCM\Support\EmailQueue;
use SCM\Support\EmailTemplate;
use SCM\Support\PublicQuoteAccess;
use SCM\Support\SharedNotificationsBridge;

/**
 * Envío de una cotización de inmuebles: correo (con el PDF adjunto) y WhatsApp (con el PDF como documento) al cliente,
 * ambos con el enlace público, y correo al asesor que la hizo con copia a los funcionarios de Configuración ›
 * Notificaciones internas (evento cotizacion_creada). Todo va por shared-notifications, siempre activo y con la plantilla
 * fija en el código (docs/plantillas-whatsapp-tareas-comerciales.md). Quien bloqueó un canal en su ficha no lo recibe.
 */
final class CommercialQuotesNotifier
{
  /** Encabezado de documento: el PDF de la cotización. */
  public const TEMPLATE = 'scm_cotizacion_inmuebles_pdf_v1';
  public const EVENT = 'cotizacion_creada';

  private const BROCHURE = 'https://sucasainmobiliaria.com.co/brochure-skc-sucasa-inmobiliaria/';

  private CommercialInternalNotifications $internal;
  private CommercialCallsRepository $clients;
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
    $this->clients = new CommercialCallsRepository($db);
  }

  /**
   * @param array<string,mixed> $quote Fila guardada (destinatario, email, celular, tipo, id_ticket…).
   * @param array{nombre:string,correo:string,celular:string,id:string} $advisor Quien la hizo.
   * @param list<array<string,mixed>> $properties Inmuebles normalizados (codigo, tipo_inmueble, barrio, precio).
   * @param string|null $pdf Ruta del PDF guardado (QuotePdf); sin él, el correo lleva solo el enlace.
   * @return array{correo:bool,whatsapp:bool}
   */
  public function quoteCreated(int $id, array $quote, array $advisor, array $properties, string $indicativo = '57', ?string $pdf = null): array
  {
    $e = static fn($value): string => EmailTemplate::e((string) $value);
    $url = PublicQuoteAccess::url($id);
    $client = trim((string) ($quote['destinatario'] ?? ''));
    $type = trim((string) ($quote['tipo'] ?? ''));
    $summary = self::summary(count($properties), $type);
    $contact = CommercialCallsNotifier::contactLine(['nombre_empleado' => $advisor['nombre'], 'correo_empleado' => $advisor['correo'], 'celular_empleado' => $advisor['celular']]);
    $list = '<ul style="margin:12px 0;padding-left:18px;">';
    foreach ($properties as $property) {
      $list .= '<li style="margin:4px 0;"><strong>' . $e($property['tipo_inmueble']) . '</strong> en ' . $e(implode(', ', array_filter([$property['barrio'], $property['ciudad']])) ?: 'Cartagena')
        . ($property['precio'] > 0 ? ' · ' . $e(QuoteComparison::money((float) $property['precio'])) : '') . ' · Código ' . $e($property['codigo']) . '</li>';
    }
    $list .= '</ul>';
    $preferences = $this->clients->clientRow(CommercialCallsRepository::digits((string) ($quote['celular'] ?? '')));
    $sent = ['correo' => false, 'whatsapp' => false];
    $attachments = $pdf !== null && is_file($pdf) && filesize($pdf) <= QuotePdf::MAX_EMAIL_BYTES ? [['path' => $pdf, 'name' => QuotePdf::filename($id)]] : [];

    if ((int) ($preferences['bloqueo_email'] ?? 0) !== 1 && filter_var((string) ($quote['email'] ?? ''), FILTER_VALIDATE_EMAIL) !== false) {
      $sent['correo'] = $this->send((string) $quote['email'], $client . ', tu cotización de inmuebles #' . $id . ' - SKC SuCasa Inmobiliaria', 'Tu cotización de inmuebles',
        '<p>Apreciado/a <strong>' . $e($client) . '</strong>, te compartimos la cotización <strong>#' . $id . '</strong> con ' . $e($summary) . ', de acuerdo con tu requerimiento e interés:</p>' . $list
        . '<p>' . ($attachments !== [] ? 'Te adjuntamos la cotización en PDF. ' : '') . 'En el enlace verás todas las fotos de cada inmueble, sus características y una comparación para que tomes la mejor decisión.</p>'
        . '<p>Cordialmente,<br><strong>' . $e($advisor['nombre']) . '</strong>' . ($advisor['celular'] !== '' ? ' · ' . $e($advisor['celular']) : '') . '</p>',
        $this->buttons($url, 'Ver cotización'), 'cotizaciones:' . $id . ':cliente', $advisor['correo'], $attachments);
    }
    if ((int) ($preferences['bloqueo_whatsapp'] ?? 0) !== 1) {
      $sent['whatsapp'] = $this->whatsappTo((string) ($quote['celular'] ?? ''), $indicativo, [$client, (string) $id, $summary, $contact],
        PublicQuoteAccess::reference($id), 'cotizaciones:' . $id . ':whatsapp', PublicQuoteAccess::pdfUrl($id), QuotePdf::filename($id));
    }

    $staff = array_values(array_unique(array_filter(array_merge([$advisor['correo']], $this->internal->emails(self::EVENT)), static fn(string $email): bool => filter_var($email, FILTER_VALIDATE_EMAIL) !== false)));
    if ($staff !== []) {
      $ticket = trim((string) ($quote['id_ticket'] ?? ''));
      $channels = array_keys(array_filter($sent));
      $this->send($staff, 'Cotización #' . $id . ' enviada' . ($ticket !== '' ? ' para la tarea #' . $ticket : ''), 'Cotización de inmuebles creada',
        '<p><strong>' . $e($advisor['nombre']) . '</strong> creó la cotización <strong>#' . $id . '</strong> para <strong>' . $e($client) . '</strong>' . ($ticket !== '' ? ' en la tarea <strong>#' . $e($ticket) . '</strong>' : '') . ', con ' . $e($summary) . ':</p>' . $list
        . '<p>' . ($channels === [] ? 'No se le pudo enviar al cliente: revisa su correo y su celular.' : 'Se le envió al cliente por ' . $e(implode(' y ', array_map(static fn(string $channel): string => $channel === 'correo' ? 'correo' : 'WhatsApp', $channels))) . '.') . '</p>',
        $this->buttons($url, 'Ver cotización'), 'cotizaciones:' . $id . ':equipo', '', $attachments);
    }
    return $sent;
  }

  /** "3 inmuebles en arriendo": así va en la plantilla y en los correos. */
  public static function summary(int $count, string $type): string
  {
    $type = mb_strtolower(trim($type), 'UTF-8');
    return $count . ' ' . ($count === 1 ? 'inmueble' : 'inmuebles') . ($type !== '' ? ' en ' . $type : '');
  }

  /** @return list<array{url:string,label:string}> */
  private function buttons(string $url, string $label): array
  {
    return [
      ['url' => $url, 'label' => $label],
      ['url' => self::BROCHURE, 'label' => 'Ver brochure'],
      ['url' => function_exists('system_image') ? system_image('link_revista', 'https://sucasainmobiliaria.com.co/revista/') : 'https://sucasainmobiliaria.com.co/revista/', 'label' => 'Ver revista'],
    ];
  }

  /** @param list<mixed> $values Variables del cuerpo: Meta no acepta vacíos, saltos de línea ni textos muy largos. */
  private function whatsappTo(string $rawPhone, string $country, array $values, string $buttonValue, string $dedupe, string $document, string $filename): bool
  {
    $phone = CommercialCallsNotifier::phone($rawPhone, $country !== '' ? $country : '57');
    if ($phone === '') {
      return false;
    }
    $parameters = array_map(static function ($value): array {
      $text = trim((string) preg_replace('/\s+/u', ' ', (string) $value));
      return ['type' => 'text', 'text' => $text !== '' ? mb_substr($text, 0, 180) : '-'];
    }, $values);
    // Meta descarga el PDF desde cotizacion.php (enlace firmado): si no estaba guardado, se genera en ese momento.
    $components = [
      ['type' => 'header', 'parameters' => [['type' => 'document', 'document' => ['link' => $document, 'filename' => $filename]]]],
      ['type' => 'body', 'parameters' => $parameters],
      ['type' => 'button', 'sub_type' => 'url', 'index' => '0', 'parameters' => [['type' => 'text', 'text' => $buttonValue]]],
    ];
    try {
      ($this->whatsapp)($phone, self::TEMPLATE, $components, ['source_module' => 'cotizaciones-whatsapp', 'dedupe_key' => $dedupe, 'priority' => 100, 'template_language' => CommercialCallsNotifier::TEMPLATE_LANGUAGE]);
      return true;
    } catch (\Throwable $exception) {
      error_log('[cotizaciones:whatsapp] ' . $exception->getMessage());
      return false;
    }
  }

  /**
   * @param string|list<string> $to
   * @param list<array{url:string,label:string}> $buttons
   * @param list<array{path:string,name:string}> $attachments
   */
  private function send($to, string $subject, string $title, string $body, array $buttons, string $dedupe, string $replyTo = '', array $attachments = []): bool
  {
    if ($to === '' || $to === []) {
      return false;
    }
    $html = str_replace('Su Casa Inmobiliaria', 'SKC SuCasa Inmobiliaria', EmailTemplate::render($title, $body . '<div style="margin-top:20px;text-align:center;">' . EmailTemplate::buttons($buttons) . '</div>'));
    // shared-notifications toma Reply-To y adjuntos del payload (EmailSmtpProvider); el adjunto se lee del disco al enviar.
    $payload = array_filter(['reply_to' => $replyTo !== '' && filter_var($replyTo, FILTER_VALIDATE_EMAIL) ? $replyTo : '', 'attachments' => $attachments]);
    try {
      ($this->enqueue)($to, $subject, $html, ['source_module' => 'cotizaciones', 'provider' => 'email_smtp', 'dedupe_key' => $dedupe, 'payload' => $payload]);
      return true;
    } catch (\Throwable $exception) {
      error_log('[cotizaciones:correo] ' . $exception->getMessage());
      return false;
    }
  }
}
