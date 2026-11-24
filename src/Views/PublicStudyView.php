<?php

declare(strict_types=1);

namespace SCM\Views;

use SCM\Commercial\Calls\CommercialCallsNotifier;
use SCM\Commercial\Studies\CommercialStudiesService;

/**
 * Estudio de aseguradora (public/estudio.php), en lugar de /estudio-aseguradora/ de WordPress. El cliente lo abre desde
 * el correo o el WhatsApp: estado con su explicación, el avance (enviado → respuesta → entrega), los datos del estudio y
 * el contacto de su consultor/a. Con la sesión del panel también muestra los datos internos (llaves, caso de entrega,
 * contrato, Drive). Sin JavaScript.
 */
final class PublicStudyView
{
  private static function e($value): string
  {
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  }

  /** @param array<string,mixed>|null $study CommercialStudiesService::viewData() */
  public static function render(?array $study, string $css, string $logo, string $error = ''): string
  {
    $title = $study !== null ? 'Estudio de aseguradora #' . (int) $study['id'] : 'Estudio no disponible';
    $html = '<!doctype html><html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow">'
      . '<title>' . self::e($title) . ' · SKC SuCasa Inmobiliaria</title><link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>'
      . '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&amp;display=swap"><link rel="stylesheet" href="' . self::e($css) . '"></head><body>'
      . '<header class="ps-top"><div class="ps-wrap ps-top-in">' . ($logo !== '' ? '<img src="' . self::e(self::https($logo)) . '" alt="" class="ps-logo">' : '') . '<span class="ps-brand">SKC SuCasa Inmobiliaria</span></div></header><main class="ps-wrap ps-main">';
    if ($study === null) {
      return $html . '<section class="ps-card ps-empty"><h1>' . self::e($title) . '</h1><p role="alert">' . self::e($error) . '</p></section></main></body></html>';
    }
    $state = (string) $study['estado'];
    $tone = match ($state) {
      CommercialStudiesService::APPROVED, 'Entregado' => 'ok',
      CommercialStudiesService::POSTPONED => 'warn',
      CommercialStudiesService::REJECTED => 'bad',
      CommercialStudiesService::DESISTED, 'Negocio desistido' => 'off',
      default => 'wait',
    };
    $html .= '<section class="ps-card ps-hero ps-hero--' . $tone . '"><p class="ps-eyebrow">Estudio de aseguradora #' . (int) $study['id'] . ($study['fecha'] !== '' ? ' · ' . self::e($study['fecha']) : '') . '</p>'
      . '<h1>Hola, ' . self::e($study['cliente'] !== '' ? $study['cliente'] : 'apreciado cliente') . '</h1>'
      . '<p class="ps-state"><span class="ps-badge ps-badge--' . $tone . '">' . self::e($study['estado_label']) . '</span></p>'
      . '<p>' . self::e($study['estado_texto']) . '</p>';
    if ($study['faltante'] !== '') {
      $html .= '<div class="ps-callout"><h2>Lo que necesitamos</h2><p class="ps-pre">' . self::e($study['faltante']) . '</p><p class="ps-hint">Envíalo a tu consultor/a para continuar con el estudio.</p></div>';
    }
    if ($study['razon'] !== '') {
      $html .= '<div class="ps-callout ps-callout--off"><h2>Motivo</h2><p class="ps-pre">' . self::e($study['razon']) . '</p></div>';
    }
    $html .= '</section>' . self::steps($study);

    $facts = array_filter([
      'Aseguradora' => $study['aseguradora'],
      'Número de solicitud' => $study['num_solicitud'],
      'Valor del canon' => $study['valor'],
      'Fecha del estudio' => $study['fecha'],
      'Fecha de respuesta' => $study['fecha_respuesta'],
      'Fecha posible de entrega' => $study['fecha_entrega'],
    ], static fn($value): bool => (string) $value !== '');
    $html .= '<div class="ps-grid"><section class="ps-card"><h2>Datos del estudio</h2>' . self::facts($facts);
    if ($study['inmueble'] !== null) {
      $property = $study['inmueble'];
      $html .= '<h3>Inmueble</h3><p>' . self::e(trim($property['tipo'] . ' en ' . implode(', ', array_filter([$property['barrio'], $property['ciudad']])), ' en')) . ' · Código ' . self::e($property['codigo'])
        . ' <a href="' . self::e($property['url']) . '" target="_blank" rel="noopener noreferrer">Ver ficha</a></p>';
    }
    $html .= '</section>' . self::advisor($study['consultor']) . '</div>';

    if (is_array($study['interno'])) {
      $internal = $study['interno'];
      $rows = array_filter([
        'Tarea comercial' => $internal['tarea'] > 0 ? '#' . $internal['tarea'] : '',
        'Registrado por' => $internal['creador'] . ($internal['hora_estudio'] !== '' ? ' · ' . $internal['hora_estudio'] : ''),
        'Correo del cliente' => $internal['correo_cliente'],
        'Celular del cliente' => $internal['celular_cliente'],
        'Medio' => $internal['medio'],
        'Llaves' => $internal['llaves'],
        'Propietario' => $internal['propietario'],
        'Caso de entrega' => $internal['caso_entrega'] > 0 ? '#' . $internal['caso_entrega'] . ($internal['responsable_entrega'] !== '' ? ' · ' . $internal['responsable_entrega'] : '') . ($internal['estado_entrega'] !== '' ? ' · ' . $internal['estado_entrega'] : '') : '',
        'Contrato de arrendamiento' => $internal['contrato'] > 0 ? '#' . $internal['contrato'] . ($internal['estado_contrato'] !== '' ? ' · ' . $internal['estado_contrato'] : '') : '',
        'Contrato de mandato' => $internal['mandato'] > 0 ? '#' . $internal['mandato'] : '',
      ], static fn($value): bool => trim((string) $value) !== '');
      $html .= '<section class="ps-card ps-internal"><h2>Datos internos <small>solo los ve el equipo</small></h2>' . self::facts($rows)
        . ($internal['anexos'] !== '' && preg_match('#^https?://#i', $internal['anexos']) === 1 ? '<p><a class="ps-button ps-button--light" href="' . self::e($internal['anexos']) . '" target="_blank" rel="noopener noreferrer">Abrir anexos de la entrega (Drive)</a></p>' : '')
        . '<p class="ps-hint">Enlace para el cliente: <span class="ps-pre">' . self::e($internal['enlace_cliente']) . '</span></p></section>';
    }
    return $html . '</main><footer class="ps-foot"><div class="ps-wrap"><p class="ps-motto">Construimos confianza, protegemos su patrimonio.</p><p>SKC SuCasa Inmobiliaria</p></div></footer></body></html>';
  }

  /** Avance: enviado a la aseguradora → respuesta → entrega del inmueble. @param array<string,mixed> $study */
  private static function steps(array $study): string
  {
    $state = (string) $study['estado'];
    $answered = $state !== CommercialStudiesService::WAITING;
    $approved = in_array($state, [CommercialStudiesService::APPROVED, 'Entregado', 'Negocio desistido'], true);
    $steps = [
      ['Enviado a la aseguradora', $study['fecha'], 'done'],
      [$answered ? 'Respuesta: ' . $study['estado_label'] : 'Esperando respuesta', $study['fecha_respuesta'], $answered ? ($approved ? 'done' : ($state === CommercialStudiesService::POSTPONED ? 'current' : 'stop')) : 'current'],
    ];
    if ($approved || $state === CommercialStudiesService::WAITING || $state === CommercialStudiesService::POSTPONED) {
      $steps[] = ['Entrega del inmueble', $state === 'Entregado' ? 'Entregado' : ($study['fecha_entrega'] !== '' ? 'Fecha posible: ' . $study['fecha_entrega'] : ''), $state === 'Entregado' ? 'done' : ($state === 'Negocio desistido' ? 'stop' : ($approved ? 'current' : 'todo'))];
    }
    $html = '<ol class="ps-steps" aria-label="Avance del estudio">';
    foreach ($steps as [$label, $detail, $status]) {
      $html .= '<li class="ps-step ps-step--' . $status . '"' . ($status === 'current' ? ' aria-current="step"' : '') . '><span class="ps-dot" aria-hidden="true"></span><span><strong>' . self::e($label) . '</strong>' . ($detail !== '' ? '<small>' . self::e($detail) . '</small>' : '') . '</span></li>';
    }
    return $html . '</ol>';
  }

  /** @param array<string,string> $facts */
  private static function facts(array $facts): string
  {
    $html = '<dl class="ps-facts">';
    foreach ($facts as $label => $value) {
      $html .= '<div><dt>' . self::e($label) . '</dt><dd>' . self::e($value) . '</dd></div>';
    }
    return $html . '</dl>';
  }

  /** @param array{nombre:string,correo:string,celular:string} $advisor */
  private static function advisor(array $advisor): string
  {
    $phone = CommercialCallsNotifier::phone($advisor['celular']);
    return '<aside class="ps-card ps-advisor"><p class="ps-eyebrow">Su consultor/a asignado es</p><h2>' . self::e($advisor['nombre']) . '</h2>'
      . ($advisor['celular'] !== '' ? '<p>Celular: ' . self::e($advisor['celular']) . '</p>' : '') . ($advisor['correo'] !== '' ? '<p>Correo: <a href="mailto:' . self::e($advisor['correo']) . '">' . self::e($advisor['correo']) . '</a></p>' : '')
      . ($phone !== '' ? '<p><a class="ps-button" href="https://wa.me/' . self::e(ltrim($phone, '+')) . '" target="_blank" rel="noopener noreferrer">Escribir por WhatsApp</a></p>' : '') . '</aside>';
  }

  private static function https(string $url): string
  {
    return (string) preg_replace('#^http://#i', 'https://', trim($url));
  }
}
