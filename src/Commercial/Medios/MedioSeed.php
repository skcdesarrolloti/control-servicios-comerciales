<?php

declare(strict_types=1);

namespace SCM\Commercial\Medios;

/**
 * Lista inicial de Medios (cómo llegó el cliente o la visita), de un solo nivel.
 *
 * - Los 32 valores del glosario de JetEngine "Medio de llamada" (wp_jet_post_types id 195), en su orden.
 * - Valores que ya están en los registros y que escribe este panel o el administrativo (portales de autoservicio,
 *   WhatsApp del portal, Panel administrativo, Retencion de contrato, Ruta, WhatsApp Guardian, Otro).
 * - Canales de las notificaciones y de la analítica web: SMS, WhatsApp y los asistentes de IA (chatgpt.com, perplexity).
 *
 * Los alias son las otras formas en que llega el mismo medio (se comparan sin mayúsculas, tildes ni signos). Mientras
 * la tabla scm_medios no exista (antes de php bin/medios-normalize.php --apply) el panel resuelve con esta lista.
 */
final class MedioSeed
{
  /** Nombre => alias. */
  public const ROWS = [
    'Aviso' => [],
    'Aviso señuelo' => [],
    'Chat' => [],
    'Cien Cuadras' => [],
    'Correo' => ['Correo Electrónico', 'Email', 'E-mail'],
    'Club PPH' => [],
    'Facebook' => ['fb', 'facebook.com', 'm.facebook.com', 'l.facebook.com'],
    'Finca Raíz' => [],
    'ICasas' => [],
    'Instagram' => ['ig', 'instagram.com', 'l.instagram.com'],
    'Metro Cuadrado' => [],
    'Mitula' => [],
    'Propietario' => [],
    'Por actualización' => [],
    'Por ruta' => [],
    'Prensa' => [],
    'Presencial' => [],
    'Properati' => [],
    'Referido' => [],
    'Revista' => [],
    'Semillero Propietario' => [],
    'Sitio Web' => [],
    'Tiktok' => [],
    'Precaptacion' => [],
    'Proppit' => [],
    'Mercado libre' => [],
    'Cesion' => [],
    'Retencion' => [],
    'Publicidad' => ['Publicidad Pagada (Ads)'],
    'Historia instagram' => [],
    'Estado whatsapp' => [],
    'Google ads' => [],
    // Escritos por los portales de autoservicio y el panel administrativo.
    'Portal arrendatario' => [],
    'Portal propietario' => [],
    'Portal copropiedad' => [],
    'Panel administrativo' => [],
    'Retencion de contrato' => [],
    'Ruta' => [],
    'WhatsApp cliente' => [],
    'WhatsApp Guardian' => [],
    'Otro' => [],
    // Canales de las notificaciones del panel ("Base de Datos ..." por SMS, correo o WhatsApp) y de la analítica web.
    'SMS' => ['Mensaje de texto'],
    'WhatsApp' => ['wa'],
    'ChatGPT / IA' => ['chatgpt.com', 'chatgpt', 'perplexity', 'perplexity.ai', 'gemini.google.com', 'copilot.microsoft.com'],
  ];

  /** Cuántos medios vienen del glosario original (los primeros de ROWS). */
  public const GLOSSARY_COUNT = 32;

  /**
   * Filas con el mismo formato que MedioCatalog::all() (id 0: todavía no están en la base).
   * @return list<array{id:int,nombre:string,slug:string,aliases:list<string>,activo:bool,orden:int}>
   */
  public static function rows(): array
  {
    $out = [];
    $orden = 0;
    foreach (self::ROWS as $name => $aliases) {
      $out[] = ['id' => 0, 'nombre' => $name, 'slug' => MedioResolver::slug($name), 'aliases' => $aliases, 'activo' => true, 'orden' => ++$orden];
    }
    return $out;
  }
}
