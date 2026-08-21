<?php

declare(strict_types=1);

namespace SCM\Commercial;

final class CommercialStatusCatalog
{
  public const OPEN = [
    'Nuevo',
    'Contactado',
    'Prospectado',
    'En búsqueda',
    'Mostrando',
    'En estudio',
    'En cierre',
    'Aprobado',
    'Por publicar',
    'Pendiente colocar aviso',
    'En actividad comercial',
    'Captado',
    'Recaptado',
    'En ruta',
    'Retocando',
  ];

  public const POSTPONED = [
    'Aplazado',
    'Postergado',
  ];

  public const CLOSED = [
    'Desistido',
    'Rechazado',
    'Trasladado',
    'Finalizado',
    'Duplicado',
    'Entregado',
  ];

  /** @return array<string,array{label:string,description:string,statuses:array<int,string>}> */
  public static function buckets(): array
  {
    return [
      'abiertos' => [
        'label' => 'Abiertos',
        'description' => 'Gestiones comerciales activas que requieren seguimiento.',
        'statuses' => self::OPEN,
      ],
      'postergados' => [
        'label' => 'Postergados',
        'description' => 'Gestiones pausadas que deben retomarse posteriormente.',
        'statuses' => self::POSTPONED,
      ],
      'cerrados' => [
        'label' => 'Cerrados',
        'description' => 'Gestiones que ya terminaron o fueron trasladadas.',
        'statuses' => self::CLOSED,
      ],
    ];
  }

  /** @return array<int,string> */
  public static function all(): array
  {
    return array_values(array_unique(array_merge(self::OPEN, self::POSTPONED, self::CLOSED)));
  }

  /** @return array<int,string> */
  public static function statusesForBucket(string $bucket): array
  {
    return self::buckets()[$bucket]['statuses'] ?? self::OPEN;
  }

  public static function isValid(string $status): bool
  {
    return in_array(trim($status), self::all(), true);
  }

  public static function bucketForStatus(string $status): string
  {
    $status = trim($status);
    foreach (self::buckets() as $key => $bucket) {
      if (in_array($status, $bucket['statuses'], true)) {
        return $key;
      }
    }
    return 'abiertos';
  }

  /** @return array<string,string> */
  public static function descriptions(): array
  {
    return [
      'Nuevo' => 'Solicitud comercial recién creada y pendiente de primera gestión.',
      'Contactado' => 'Ya se estableció el primer contacto con el cliente o propietario.',
      'Prospectado' => 'La oportunidad fue validada como prospecto comercial.',
      'En búsqueda' => 'Se están buscando inmuebles u opciones que cumplan la necesidad.',
      'Mostrando' => 'La oportunidad está en etapa de visitas o demostraciones.',
      'En estudio' => 'La documentación o viabilidad del negocio está siendo evaluada.',
      'En cierre' => 'La negociación está en su fase final.',
      'Aprobado' => 'La operación o documentación recibió aprobación.',
      'Por publicar' => 'El inmueble está listo y pendiente de publicación.',
      'Pendiente colocar aviso' => 'Está pendiente instalar el aviso físico del inmueble.',
      'En actividad comercial' => 'La gestión se encuentra activa dentro del proceso comercial.',
      'Captado' => 'El inmueble u oportunidad quedó formalmente captado.',
      'Recaptado' => 'Se reactivó la captación de un inmueble u oportunidad anterior.',
      'En ruta' => 'La visita o gestión de campo está en desplazamiento.',
      'Retocando' => 'El material del inmueble está en edición o ajuste antes de publicar.',
      'Aplazado' => 'La gestión se movió temporalmente a una fecha posterior.',
      'Postergado' => 'La oportunidad permanece pausada hasta una nueva gestión.',
      'Desistido' => 'El interesado o propietario decidió no continuar.',
      'Rechazado' => 'La oportunidad no fue aceptada después de su evaluación.',
      'Trasladado' => 'La responsabilidad fue transferida a otro proceso o responsable.',
      'Finalizado' => 'La gestión concluyó y no requiere más acciones.',
      'Duplicado' => 'El registro corresponde a una oportunidad ya existente.',
      'Entregado' => 'El inmueble o resultado comprometido fue entregado.',
    ];
  }
}
