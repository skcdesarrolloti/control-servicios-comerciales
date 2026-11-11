<?php

declare(strict_types=1);

namespace SCM\Views\Promotion;

use SCM\Commercial\CommercialAccessPolicy;
use SCM\Commercial\Promotion\StaffCardService;
use SCM\Core\Database;

/** Promoción › Fichas de funcionarios. Subir, reemplazar y quitar piden fichas_gestionar; sin él solo se ven. */
final class StaffCardsView
{
  /** @return array{available:bool,can_manage:bool,employees:list<array{id:string,nombre:string,correo:string,ficha:string}>} */
  public static function context(CommercialAccessPolicy $policy, Database $db, ?StaffCardService $service = null): array
  {
    $service ??= new StaffCardService($db);
    $available = $service->available();
    return ['available' => $available, 'can_manage' => $available && $policy->canAct('fichas_gestionar'), 'employees' => $available ? $service->employees() : []];
  }

  /** @param array{available:bool,can_manage:bool,employees:list<array{id:string,nombre:string,correo:string,ficha:string}>} $context */
  public static function render(array $context, string $baseUrl): string
  {
    $e = [PromotionUi::class, 'e'];
    $html = PromotionUi::hero('badge', 'Fichas de funcionarios', 'La imagen de presentación de cada funcionario. Llega en el WhatsApp con el que el cliente conoce a su asesor.')
      . '<div class="cpr-live" data-cpr-live role="status" aria-live="polite"></div>';
    if (!$context['available']) {
      return $html . '<p class="cpr-alert is-error">Falta la columna <code>ficha</code> en <code>wp_jet_cct_funcionarios</code>. Créala como texto: guarda la dirección de la imagen.</p>';
    }
    $with = count(array_filter($context['employees'], static fn(array $employee): bool => $employee['ficha'] !== ''));
    $html .= '<div class="cpr-card cpr-staff-bar"><label class="cpr-field"><span>Buscar funcionario</span><input type="search" data-staff-filter placeholder="Nombre o correo"></label>'
      . '<p class="cpr-help">' . $with . ' de ' . count($context['employees']) . ' funcionarios activos tienen ficha. Usa una imagen vertical u horizontal en JPG o PNG; quien no tenga ficha envía el banner de los correos.</p></div>'
      . '<div class="cpr-staff-grid" data-staff-grid>';
    foreach ($context['employees'] as $employee) {
      $id = $e($employee['id']);
      $html .= '<article class="cpr-card cpr-staff-card" data-staff-card data-staff-search="' . $e(mb_strtolower($employee['nombre'] . ' ' . $employee['correo'])) . '">'
        . ($employee['ficha'] !== ''
          ? '<img class="cpr-staff-photo" src="' . $e($employee['ficha']) . '" alt="Ficha de ' . $e($employee['nombre']) . '" loading="lazy">'
          : '<div class="cpr-staff-photo is-empty"><span class="material-symbols-outlined" aria-hidden="true">badge</span><span>Sin ficha</span></div>')
        . '<h3>' . $e($employee['nombre']) . '</h3><p class="cpr-help">' . $e($employee['correo']) . '</p>';
      if ($context['can_manage']) {
        $html .= '<form class="cpr-staff-form" data-staff-card-form enctype="multipart/form-data"><input type="hidden" name="id_empleado" value="' . $id . '">'
          . '<label class="cpr-field"><span>' . ($employee['ficha'] !== '' ? 'Reemplazar imagen' : 'Subir imagen') . '</span><input type="file" name="ficha" accept="image/jpeg,image/png,image/webp" required></label>'
          . '<div class="cpr-staff-actions"><button type="submit" class="cpr-primary">Guardar</button>'
          . ($employee['ficha'] !== '' ? '<button type="button" class="cpr-ghost" data-staff-card-remove="' . $id . '" data-staff-name="' . $e($employee['nombre']) . '">Quitar</button>' : '')
          . '</div><p class="cpr-alert is-error" data-staff-error hidden></p></form>';
      }
      $html .= '</article>';
    }
    return $html . '</div>';
  }
}
