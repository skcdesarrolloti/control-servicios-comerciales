<?php

declare(strict_types=1);

namespace SCM\Commercial\Campaigns;

use SCM\Commercial\CommercialSmsMessage;
use SCM\Support\EmailTemplate;

/**
 * Contenido de una campaña para cada destinatario. Adaptación de dashboard-marketing app/CampaignEngine.php
 * (resolveVariables y el píxel de apertura):
 * - Correo con el mensaje general: banner, "Hola {{nombre}}", el mensaje y la firma de SKC SuCasa Inmobiliaria.
 * - Correo con una plantilla diseñada (jet_cct_plantillas): su HTML con las variables reemplazadas; si trae {{mensaje}},
 *   ahí va el mensaje escrito en la campaña.
 * - SMS: "SKC SuCasa Inmobiliaria " + mensaje, máximo 160 caracteres ya reemplazadas las variables.
 * - WhatsApp: la plantilla aprobada en Meta de texto genérico (nombre, mensaje y firma).
 * Variables: {{nombre}}, {{correo}}, {{celular}}, {{documento}}, {{ciudad}}, {{rol_persona}}, {{tipo_actor}} y cualquier
 * columna de la lista ({{tipo_cliente}}, {{barrio}}...). En el correo los valores se escapan.
 */
final class CampaignMessage
{
  public const SIGNATURE = 'SKC SuCasa Inmobiliaria';
  public const WHATSAPP_TEMPLATE = 'scm_marketing_generica_texto_v1';
  public const WHATSAPP_BODY = "Hola {{1}}, recibe un cordial saludo de SKC SuCasa Inmobiliaria.\n\nTe compartimos la siguiente información:\n{{2}}\n\nAtentamente,\n{{3}}\n\nGracias por confiar en SKC SuCasa Inmobiliaria.";

  /**
   * @param array<string,mixed> $content channel, subject, message, template (fila de jet_cct_plantillas o null)
   * @param array<string,mixed> $recipient Fila de CampaignRecipients::rows()
   * @return array{subject:string,html:string,text:string,payload:array<string,mixed>}|null null si queda vacío o el SMS se pasa de 160.
   */
  public static function build(array $content, array $recipient, string $pixelUrl = ''): ?array
  {
    $channel = (string) $content['channel'];
    $message = trim((string) ($content['message'] ?? ''));
    if ($channel === 'sms') {
      $text = CommercialSmsMessage::PREFIX . self::plain(self::variables($message, $recipient, false));
      return mb_strlen($text, 'UTF-8') <= CommercialSmsMessage::MAX_CHARACTERS && trim($message) !== ''
        ? ['subject' => '', 'html' => '', 'text' => $text, 'payload' => []] : null;
    }
    if ($channel === 'whatsapp') {
      $name = (string) ($recipient['nombre'] ?? '');
      $body = self::plain(self::variables($message, $recipient, false));
      if ($body === '') {
        return null;
      }
      $parameters = array_map(static fn(string $value): array => ['type' => 'text', 'text' => trim(preg_replace('/\s+/u', ' ', $value) ?? $value)], [$name !== '' ? $name : 'cliente', $body, self::SIGNATURE]);
      return [
        'subject' => '', 'html' => '', 'text' => strtr(self::WHATSAPP_BODY, ['{{1}}' => $name, '{{2}}' => $body, '{{3}}' => self::SIGNATURE]),
        'payload' => ['type' => 'template', 'template_name' => self::WHATSAPP_TEMPLATE, 'template_language' => 'es_CO', 'components' => [['type' => 'body', 'parameters' => $parameters]]],
      ];
    }
    $subject = self::plain(self::variables((string) ($content['subject'] ?? ''), $recipient, false));
    $template = is_array($content['template'] ?? null) ? $content['template'] : null;
    if ($template !== null) {
      $body = (string) ($template['contenido'] ?? '');
      $messageHtml = nl2br(htmlspecialchars(self::variables($message, $recipient, false), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
      $body = str_replace(['{{mensaje}}', '{{custom_message}}'], $messageHtml, $body);
      $html = self::variables($body, $recipient, true);
      $html = self::isDocument($html) ? self::sanitize($html) : EmailTemplate::render($subject, self::sanitize($html));
    } else {
      if ($message === '') {
        return null;
      }
      $name = htmlspecialchars((string) ($recipient['nombre'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
      $html = EmailTemplate::render($subject, '<p>Hola <strong>' . $name . '</strong>, recibe un cordial saludo de SKC SuCasa Inmobiliaria.</p><div>'
        . nl2br(htmlspecialchars(self::variables($message, $recipient, false), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'))
        . '</div><p>Atentamente,<br><strong>' . self::SIGNATURE . '</strong></p>');
    }
    if ($subject === '' || trim(strip_tags($html)) === '') {
      return null;
    }
    if ($pixelUrl !== '') {
      $pixel = '<img src="' . htmlspecialchars($pixelUrl, ENT_QUOTES, 'UTF-8') . '" width="1" height="1" alt="" style="display:block;width:1px;height:1px;border:0">';
      $html = stripos($html, '</body>') !== false ? (string) preg_replace('~</body>~i', $pixel . '</body>', $html, 1) : $html . $pixel;
    }
    return ['subject' => $subject, 'html' => $html, 'text' => self::plain($html), 'payload' => []];
  }

  /** @param array<string,mixed> $recipient */
  public static function variables(string $text, array $recipient, bool $html): string
  {
    $map = [];
    foreach ($recipient as $key => $value) {
      if (is_scalar($value) || $value === null) {
        $map['{{' . $key . '}}'] = trim((string) ($value ?? ''));
      }
    }
    $map['{{tipo_actor}}'] = (string) ($recipient['tipo_label'] ?? '');
    $map['{{custom_message}}'] = $map['{{custom_message}}'] ?? '';
    foreach (['{{documento}}', '{{ciudad}}', '{{rol_persona}}'] as $key) {
      $map[$key] = $map[$key] ?? '';
    }
    if ($html) {
      $map = array_map(static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), $map);
    }
    return strtr($text, $map);
  }

  /** Variables que quedaron sin reemplazar (para avisar en la vista previa). @return list<string> */
  public static function unknownVariables(string $text, array $recipient): array
  {
    preg_match_all('/\{\{\s*([a-z0-9_]+)\s*\}\}/i', self::variables($text, $recipient, false), $matches);
    return array_values(array_unique(array_filter($matches[1], static fn(string $name): bool => !in_array($name, ['mensaje', 'custom_message'], true))));
  }

  public static function isDocument(string $html): bool
  {
    return preg_match('/<!doctype\s+html|<html[\s>]|<body[\s>]/i', $html) === 1;
  }

  /** Quita scripts, iframes, eventos on* y enlaces javascript: (igual que Notificaciones con las plantillas guardadas). */
  public static function sanitize(string $html): string
  {
    $html = preg_replace('@<(script|iframe|object|embed|form)[^>]*?>.*?</\1>@si', '', $html) ?? $html;
    $html = preg_replace('@<(script|iframe|object|embed|form|input|button)[^>]*/?>@si', '', $html) ?? $html;
    $html = preg_replace('/\son[a-z]+\s*=\s*(["\']).*?\1/si', '', $html) ?? $html;
    $html = preg_replace('/\s(href|src)\s*=\s*(["\'])\s*javascript:[\s\S]*?\2/si', '', $html) ?? $html;
    return trim($html);
  }

  public static function plain(string $value): string
  {
    $value = preg_replace('@<(style|script|head)[^>]*?>.*?</\1>@si', '', $value) ?? $value;
    $value = preg_replace('/<br\s*\/?>|<\/p>|<\/div>|<\/tr>|<\/h[1-6]>/i', "\n", $value) ?? $value;
    $text = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace("/[ \t]+/u", ' ', $text) ?? $text;
    return trim(preg_replace("/\n\s*\n+/u", "\n\n", $text) ?? $text);
  }
}
