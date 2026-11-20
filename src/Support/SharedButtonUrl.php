<?php

declare(strict_types=1);

namespace SCM\Support;

/**
 * Botón de las plantillas de WhatsApp que comparte este panel con control-servicios-inmobiliarios
 * (pqr_trasladada_funcionario_v1, scm_cita_funcionario_v1, scm_cita_solicitante_v1).
 * Copia en control-servicios-inmobiliarios/src/Support/SharedButtonUrl.php: mantener ambas iguales.
 *
 * En Meta su URL fija es solo el dominio, `https://sucasainmobiliaria.com.co/{{1}}`, y cada panel completa el resto con su
 * propia dirección: aquí `dashboard-comercial/public/...` (tarea del panel o vista pública) y en el inmobiliario
 * `control-servicios-inmobiliarios/public/?scm_case=...`. Así el mismo botón sirve en las dos aplicaciones.
 */
final class SharedButtonUrl
{
  public const ROOT = 'https://sucasainmobiliaria.com.co/';

  /** Lo que va en {{1}}: la dirección completa sin el dominio fijo de la plantilla. */
  public static function suffix(string $url): string
  {
    if (str_starts_with($url, self::ROOT)) {
      return substr($url, strlen(self::ROOT));
    }
    // Fuera del dominio de producción (pruebas, local): ruta y consulta, sin la barra inicial.
    $parts = parse_url($url);
    $path = ltrim((string) ($parts['path'] ?? ''), '/');
    return $path . (isset($parts['query']) ? '?' . $parts['query'] : '');
  }
}
