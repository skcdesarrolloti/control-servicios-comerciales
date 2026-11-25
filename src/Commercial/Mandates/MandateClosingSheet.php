<?php

declare(strict_types=1);

namespace SCM\Commercial\Mandates;

/**
 * Hoja de cierre del mandato desde el panel comercial (Mandatos, Mis mandatos y el popup de la tarea).
 *
 * No es una copia: abre las páginas del panel inmobiliario (control-servicios-inmobiliarios), que siguen siendo las únicas
 * que crean, revisan, aprueban y desisten hojas de cierre. Así sus permisos por cargo, las políticas de la lista de
 * chequeo, los archivos obligatorios, Notificaciones internas y las plantillas de WhatsApp son los de allá, y lo que
 * cambie allá se ve aquí sin tocar este panel:
 *   - sin hoja (o desistida): hoja-cierre.php?id_contrato=<mandato> (lo demás lo toma del mandato);
 *   - con hoja: hoja-cierre-ver.php?numero=<hoja> (ver, revisar servicios, responder, editar, desistir e imprimir).
 * Siempre con embed=1 (sin la barra superior de allá) dentro de la ventana del panel comercial. Los dos paneles están en
 * el mismo dominio y usan la misma sesión (cookie scm_sess, mismas claves), así que no hace falta otro inicio de sesión.
 */
final class MandateClosingSheet
{
  public const ACTION = 'mandato_hoja_cierre';
  public const DESISTED = 'Desistida';

  private string $servicesUrl;

  public function __construct(string $servicesUrl)
  {
    $this->servicesUrl = rtrim(trim($servicesUrl), '/');
  }

  public function configured(): bool
  {
    return preg_match('#^https?://#i', $this->servicesUrl) === 1;
  }

  /** La hoja actual del mandato, o ninguna si no tiene o la última se desistió (entonces se crea otra). @param array{id:int,estado:string} $closing */
  public static function current(array $closing): int
  {
    return $closing['id'] > 0 && $closing['estado'] !== self::DESISTED ? $closing['id'] : 0;
  }

  /** Texto del botón. @param array{id:int,estado:string} $closing */
  public static function label(array $closing): string
  {
    $id = self::current($closing);
    return $id > 0 ? 'Hoja de cierre #' . $id : 'Crear hoja de cierre';
  }

  /**
   * Página del panel inmobiliario que corresponde.
   * @param array<string,mixed> $mandate
   * @param array{id:int,estado:string} $closing
   */
  public function url(array $mandate, array $closing): string
  {
    if (!$this->configured()) {
      throw new \RuntimeException('Falta INMOBILIARIO_URL en el .env del panel: la dirección del panel inmobiliario.');
    }
    $id = self::current($closing);
    if ($id > 0) {
      return $this->sheetUrl($id);
    }
    return $this->servicesUrl . '/hoja-cierre.php?' . http_build_query(['id_contrato' => (int) ($mandate['_ID'] ?? 0), 'embed' => '1'], '', '&', PHP_QUERY_RFC3986);
  }

  /** Una hoja por su número (Hojas de cierre y Mis cierres): hoja-cierre-ver.php, aunque esté desistida. */
  public function sheetUrl(int $id): string
  {
    if (!$this->configured()) {
      throw new \RuntimeException('Falta INMOBILIARIO_URL en el .env del panel: la dirección del panel inmobiliario.');
    }
    return $this->servicesUrl . '/hoja-cierre-ver.php?' . http_build_query(['numero' => $id, 'embed' => '1'], '', '&', PHP_QUERY_RFC3986);
  }
}
