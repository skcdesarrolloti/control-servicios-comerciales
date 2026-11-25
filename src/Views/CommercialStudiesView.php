<?php

declare(strict_types=1);

namespace SCM\Views;

use SCM\Commercial\CommercialAccessPolicy;
use SCM\Commercial\Studies\CommercialStudiesService;
use SCM\Support\PublicStudyAccess;

/**
 * Estudios de aseguradora en el panel: la ventana del popup de la tarea (Gestión operativa › Estudio de aseguradora:
 * crear, responder, editar y desistir), el visor, el formulario de desistir y la pestaña Estudios / Mis estudios.
 * Usa las clases de las cotizaciones (cqt-*) y las propias en commercial-studies.css (cst-*).
 */
final class CommercialStudiesView
{
  /** Color de cada estado (clase cst-state--*). */
  private const STATE_TONES = [
    CommercialStudiesService::WAITING => 'wait',
    CommercialStudiesService::POSTPONED => 'warn',
    CommercialStudiesService::APPROVED => 'ok',
    'Entregado' => 'done',
    'Negocio desistido' => 'off',
    CommercialStudiesService::REJECTED => 'bad',
    CommercialStudiesService::DESISTED => 'off',
  ];

  private static function e($value): string
  {
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  }

  private static function icon(string $name): string
  {
    return '<span class="material-symbols-outlined" aria-hidden="true">' . $name . '</span>';
  }

  public static function state(string $state): string
  {
    return '<span class="cst-state cst-state--' . (self::STATE_TONES[$state] ?? 'wait') . '">' . self::e($state) . '</span>';
  }

  /** @param array<int|string,string> $values valor => texto */
  private static function select(string $name, string $label, array $values, string $current, string $empty, bool $required = false, string $extra = ''): string
  {
    $html = '<label class="cqt-field"><span>' . $label . ($required ? ' <em aria-hidden="true">*</em>' : '') . '</span><select name="' . $name . '"' . ($required ? ' required' : '') . $extra . '><option value="">' . $empty . '</option>';
    foreach ($values as $value => $text) {
      $html .= '<option value="' . self::e($value) . '"' . ((string) $value === $current ? ' selected' : '') . '>' . self::e($text) . '</option>';
    }
    return $html . '</select></label>';
  }

  /** @param list<string> $values @return array<string,string> */
  private static function pairs(array $values): array
  {
    return $values === [] ? [] : array_combine($values, $values);
  }

  private static function input(string $name, string $label, string $value = '', bool $required = false, string $type = 'text', string $extra = ''): string
  {
    return '<label class="cqt-field"><span>' . $label . ($required ? ' <em aria-hidden="true">*</em>' : '') . '</span><input type="' . $type . '" name="' . $name . '" value="' . self::e($value) . '"' . ($required ? ' required' : '') . $extra . ' autocomplete="off"></label>';
  }

  // ── Popup de la tarea ───────────────────────────────

  /**
   * @param array<string,mixed> $data CommercialStudiesService::formData()
   * @param string $flash Mensaje de la última acción (se anuncia arriba).
   */
  public static function taskPanel(array $data, string $flash = ''): string
  {
    $pk = (int) $data['ticket_pk'];
    $can = $data['can'];
    $studies = $data['studies'];
    $html = '<section class="cqt cqt-task cst" data-cst-task data-ticket-pk="' . $pk . '">'
      . '<header class="cqt-task-head"><button type="button" class="cqt-back" data-commercial-return-case="' . $pk . '">' . self::icon('arrow_back') . 'Volver a la tarea</button>'
      . '<div><p class="cqt-eyebrow">Tarea #' . $pk . ($data['asunto'] !== '' ? ' · ' . self::e($data['asunto']) : '') . '</p><h1 tabindex="-1">Estudios de aseguradora</h1>'
      . '<p>Registra el estudio, su respuesta y, si se cae el negocio, desístelo. El cliente recibe cada novedad por correo y WhatsApp.</p></div>'
      . '<button type="button" class="cqt-icon" data-commercial-close-case aria-label="Cerrar">' . self::icon('close') . '</button></header>'
      . '<div class="cqt-task-body"><div class="cqt-main">'
      . ($flash !== '' ? '<p class="cst-flash" role="status">' . self::icon('check_circle') . '<span>' . self::e($flash) . '</span></p>' : '');
    if ($studies === []) {
      $html .= '<p class="cqt-empty">Esta tarea todavía no tiene estudios de aseguradora.' . ($can['crear'] ? ' Regístralo con el formulario <strong>Nuevo estudio</strong>.' : '') . '</p>';
    }
    foreach ($studies as $study) {
      $html .= self::card($study, $data, $pk);
    }
    $html .= '</div><aside class="cqt-tray cst-new" aria-labelledby="cst-new-title-' . $pk . '"><div class="cqt-tray-in">';
    if ($can['crear']) {
      $html .= '<h2 id="cst-new-title-' . $pk . '">Nuevo estudio</h2>'
        . '<form class="cst-form" data-cst-form="create" novalidate><input type="hidden" name="ticket_pk" value="' . $pk . '">'
        . self::baseFields($data, [])
        . '<p class="cqt-error" data-cst-error role="alert" hidden></p>'
        . '<button type="submit" class="cqt-primary cqt-send">' . self::icon('send') . 'Registrar estudio</button>'
        . '<p class="cqt-hint">La tarea pasa a "En estudio" y el cliente recibe el aviso con el enlace para seguir su estudio.</p></form>';
    } else {
      $html .= '<h2 id="cst-new-title-' . $pk . '">Nuevo estudio</h2><p class="cqt-hint">Tu cargo no tiene permiso para crear estudios.</p>';
    }
    return $html . '</div></aside></div></section>';
  }

  /**
   * Aseguradora, solicitud, valor y datos del cliente (crear y editar).
   * @param array<string,mixed> $data
   * @param array<string,mixed> $study Fila del estudio al editar; vacío al crear.
   */
  private static function baseFields(array $data, array $study): string
  {
    $edit = $study !== [];
    $insurers = self::pairs($data['aseguradoras']);
    $current = $edit ? (string) $study['aseguradora'] : '';
    if ($current !== '' && !isset($insurers[$current])) {
      $insurers[$current] = $current;
    }
    return '<div class="cst-grid">'
      . self::select('aseguradora', 'Aseguradora', $insurers, $current, 'Elige una aseguradora', true)
      . self::input('num_solicitud', 'Número de solicitud', $edit ? (string) $study['num_solicitud'] : '', true, 'text', ' maxlength="60"')
      . self::input('valor', 'Valor del canon', $edit && $study['valor_raw'] !== '' ? '$ ' . number_format((int) $study['valor_raw'], 0, ',', '.') : '', true, 'text', ' inputmode="numeric" data-cst-money placeholder="$"')
      . self::input('cliente', 'Cliente', $edit ? (string) $study['cliente'] : (string) $data['cliente'], true, 'text', ' maxlength="120"')
      . self::input('email', 'Correo del cliente', $edit ? (string) $study['correo'] : (string) $data['email'], false, 'email', ' maxlength="160"')
      . self::input('celular', 'Celular (WhatsApp)', $edit ? (string) $study['celular'] : (string) $data['celular'], false, 'tel', ' maxlength="30"')
      . '</div>';
  }

  /**
   * Un estudio de la tarea con sus acciones y sus formularios (ocultos hasta pedirlos).
   * @param array<string,mixed> $study CommercialStudiesService listRow
   * @param array<string,mixed> $data
   */
  private static function card(array $study, array $data, int $pk): string
  {
    $id = (int) $study['id'];
    $can = $data['can'];
    $facts = array_filter([
      'Solicitud' => $study['num_solicitud'],
      'Valor' => $study['valor'],
      'Registrado' => $study['fecha'] . ($study['asesor'] !== '' ? ' por ' . $study['asesor'] : ''),
      'Cliente' => $study['cliente'],
      'Caso de entrega' => $study['caso_entrega'] > 0 ? '#' . $study['caso_entrega'] : '',
    ], static fn($value): bool => (string) $value !== '');
    $html = '<article class="cqt-panel cst-card" data-cst-card="' . $id . '">'
      . '<header class="cst-card-head"><div><h2>Estudio #' . $id . ' · ' . self::e($study['aseguradora'] ?: 'Sin aseguradora') . '</h2>' . self::state($study['estado']) . '</div></header><dl class="cst-facts">';
    foreach ($facts as $label => $value) {
      $html .= '<div><dt>' . $label . '</dt><dd>' . self::e($value) . '</dd></div>';
    }
    if ($study['inmueble'] !== '') {
      $html .= '<div><dt>Inmueble</dt><dd>' . self::propertyButton($study, $pk) . '</dd></div>';
    }
    $html .= '</dl>';
    if ($study['documentos'] !== []) {
      $html .= self::documentList($study['documentos']);
    }
    if ($study['estado'] === CommercialStudiesService::POSTPONED && $study['faltante'] !== '') {
      $html .= '<p class="cst-note"><strong>Falta:</strong> ' . nl2br(self::e($study['faltante'])) . '</p>';
    }
    $html .= '<div class="cst-actions"><button type="button" class="cqt-secondary cqt-small" data-cst-view="' . $id . '" data-ticket-pk="' . $pk . '">' . self::icon('visibility') . 'Ver estudio</button>'
      . self::copyButton($study['enlace'], 'Copiar enlace del cliente');
    if ($can['responder'] && $study['respondible']) {
      $html .= '<button type="button" class="cqt-primary cqt-small" data-cst-toggle="respond-' . $id . '" aria-expanded="false">' . self::icon('fact_check') . 'Registrar respuesta</button>';
    }
    if ($can['crear'] && $study['editable']) {
      $html .= '<button type="button" class="cqt-secondary cqt-small" data-cst-toggle="edit-' . $id . '" aria-expanded="false">' . self::icon('edit') . 'Editar</button>';
    }
    if ($can['desistir'] && $study['desistible']) {
      $html .= '<button type="button" class="cqt-secondary cqt-small cst-danger" data-cst-toggle="desist-' . $id . '" aria-expanded="false">' . self::icon('block') . 'Desistir negocio</button>';
    }
    $html .= '</div>';
    if ($can['responder'] && $study['respondible']) {
      $html .= self::respondForm($study, $data, $pk);
    }
    if ($can['crear'] && $study['editable']) {
      $html .= '<form class="cst-form cst-inline" data-cst-form="edit" data-cst-panel="edit-' . $id . '" hidden novalidate><h3>Editar estudio #' . $id . '</h3>'
        . '<input type="hidden" name="ticket_pk" value="' . $pk . '"><input type="hidden" name="id" value="' . $id . '">'
        . self::baseFields($data, $study)
        . ($study['estado'] === CommercialStudiesService::POSTPONED ? '<label class="cqt-field"><span>Información o documentación faltante <em aria-hidden="true">*</em></span><textarea name="faltante" rows="3" maxlength="2000" required>' . self::e($study['faltante']) . '</textarea></label>' : '')
        . ($study['estado'] === CommercialStudiesService::APPROVED ? self::documentEditor($study['documentos']) : '')
        . '<p class="cqt-hint">Si el estudio ya tiene caso de entrega y contrato, también se les corrige la aseguradora y la solicitud.</p>'
        . '<p class="cqt-error" data-cst-error role="alert" hidden></p>'
        . '<div class="cst-form-actions"><button type="submit" class="cqt-primary cqt-small">' . self::icon('save') . 'Guardar cambios</button><button type="button" class="cqt-secondary cqt-small" data-cst-toggle="edit-' . $id . '">Cancelar</button></div></form>';
    }
    if ($can['desistir'] && $study['desistible']) {
      $html .= self::desistForm($id, $data['motivos'], $pk, 'desist-' . $id);
    }
    return $html . '</article>';
  }

  /**
   * Popup de Responder del listado: el resumen del estudio y su formulario de respuesta, sin abrir la tarea.
   * El contenedor lleva data-cst-task para que la búsqueda de inmuebles use la tarea del estudio.
   * @param array<string,mixed> $study @param array<string,mixed> $data CommercialStudiesService::formData()
   */
  public static function respondDialog(array $study, array $data, int $pk): string
  {
    $summary = implode(' · ', array_filter([(string) $study['cliente'], trim((string) $study['aseguradora'] . ($study['num_solicitud'] !== '' ? ' · solicitud ' . $study['num_solicitud'] : '')), 'Tarea #' . $pk]));
    return '<div data-cst-task data-ticket-pk="' . $pk . '"><p class="cqt-eyebrow">' . self::e($summary) . '</p>'
      . ($study['estado'] === CommercialStudiesService::POSTPONED && $study['faltante'] !== '' ? '<p class="cst-note"><strong>Falta:</strong> ' . nl2br(self::e($study['faltante'])) . '</p>' : '')
      . self::respondForm($study, $data, $pk, true) . '</div>';
  }

  /** @param array<string,mixed> $study @param array<string,mixed> $data */
  private static function respondForm(array $study, array $data, int $pk, bool $dialog = false): string
  {
    $id = (int) $study['id'];
    $options = self::pairs(CommercialStudiesService::RESPONSES);
    if ($study['estado'] === CommercialStudiesService::POSTPONED) {
      $options = [CommercialStudiesService::WAITING => 'Esperando respuesta (se completaron los documentos)'] + $options;
    }
    $services = '';
    foreach ($data['servicios'] as $service) {
      $services .= '<label class="cqt-check"><input type="checkbox" name="servicios_publicos[]" value="' . self::e($service) . '" data-cst-service> ' . self::e($service) . '</label>';
    }
    return '<form class="cst-form cst-inline" data-cst-form="respond"' . ($dialog ? '' : ' data-cst-panel="respond-' . $id . '" hidden') . ' novalidate><h3>Respuesta del estudio #' . $id . '</h3>'
      . '<input type="hidden" name="ticket_pk" value="' . $pk . '"><input type="hidden" name="id" value="' . $id . '">'
      . '<div class="cst-grid">' . self::select('estado', 'Respuesta de la aseguradora', $options, '', 'Elige una opción', true, ' data-cst-response') . '</div>'
      . '<div data-cst-when="' . CommercialStudiesService::POSTPONED . '" hidden><label class="cqt-field"><span>Información o documentación faltante <em aria-hidden="true">*</em></span><textarea name="faltante" rows="3" maxlength="2000" placeholder="Todo lo que falta para obtener la respuesta del estudio">' . self::e($study['faltante']) . '</textarea></label></div>'
      . '<div data-cst-when="' . CommercialStudiesService::DESISTED . '" hidden><div class="cst-grid">' . self::select('motivo', 'Razón de desistir', self::pairs($data['motivos']), '', 'Elige una razón', false) . '</div></div>'
      . '<div data-cst-when="' . CommercialStudiesService::DESISTED . ' ' . CommercialStudiesService::REJECTED . '" hidden><label class="cqt-field"><span>Observación para el cliente</span><textarea name="observacion" rows="2" maxlength="2000" placeholder="Opcional"></textarea></label></div>'
      . '<div data-cst-when="' . CommercialStudiesService::APPROVED . '" hidden class="cst-approve">'
      . '<p class="cqt-hint">Al aprobar se crea el caso de entrega "Revisión antes de la ocupación" para <strong>' . self::e($data['responsable_entrega'] !== '' ? $data['responsable_entrega'] : 'el responsable de las entregas (elígelo en Configuración)') . '</strong>, el contrato "No asignado" por entregar y el inmueble queda "En proceso de cierre".</p>'
      . '<div class="cst-property" data-cst-property><label class="cqt-field"><span>Inmueble que se entrega <em aria-hidden="true">*</em></span><input type="search" data-cst-property-search placeholder="Código, dirección, barrio, conjunto o propietario" autocomplete="off"></label>'
      . '<input type="hidden" name="id_inmueble" data-cst-property-code><p class="cst-picked" data-cst-property-picked hidden></p><ul class="cst-results" data-cst-property-results aria-live="polite"></ul>'
      . '<p class="cqt-hint">Solo salen inmuebles públicos o en borrador.</p></div>'
      . '<h4>Ubicación de las llaves</h4><div class="cst-grid">'
      . self::input('ubicacion_llaves', 'Ubicación', '', false, 'text', ' maxlength="160"')
      . self::input('persona_llaves', 'Quién las tiene', '', false, 'text', ' maxlength="160"')
      . self::input('contacto_llaves', 'Teléfono', '', false, 'tel', ' maxlength="30"')
      . self::input('fecha_posible_entrega', 'Fecha posible de entrega', '', false, 'date')
      . '</div><fieldset class="cst-services"><legend>Servicios públicos del inmueble</legend>' . $services . '</fieldset>'
      . self::documentInputs(true)
      . '</div>'
      . '<p class="cqt-error" data-cst-error role="alert" hidden></p>'
      . '<div class="cst-form-actions"><button type="submit" class="cqt-primary cqt-small">' . self::icon('send') . 'Guardar respuesta y avisar al cliente</button><button type="button" class="cqt-secondary cqt-small" ' . ($dialog ? 'data-cst-dialog-close' : 'data-cst-toggle="respond-' . $id . '"') . '>Cancelar</button></div></form>';
  }

  /** Código del inmueble que abre su ficha interna (la misma de la tarea; dentro está "Ver en la web"). @param array<string,mixed> $study */
  private static function propertyButton(array $study, int $pk = 0): string
  {
    return '<button type="button" class="cqt-link cst-property-link" data-commercial-property-detail="' . self::e($study['inmueble']) . '" data-study-id="' . (int) $study['id'] . '"' . ($pk > 0 ? ' data-ticket-pk="' . $pk . '"' : '') . ' title="Ver la ficha del inmueble">'
      . self::icon('home') . '#' . self::e($study['inmueble']) . '</button>';
  }

  private static function copyButton(string $url, string $label): string
  {
    return $url === '' ? '' : '<button type="button" class="cqt-secondary cqt-small" data-cst-copy="' . self::e($url) . '" title="Enlace para que el cliente siga su estudio">' . self::icon('link') . $label . '</button>';
  }

  /** Campos de archivo de cada documento (CommercialStudiesService::DOCUMENTS). */
  private static function documentInputs(bool $required): string
  {
    $html = '<fieldset class="cst-docs"><legend>' . ($required ? 'Documentos de la aprobación' : 'Agregar documentos') . '</legend>';
    foreach (CommercialStudiesService::DOCUMENTS as $type => [$title, $formats, $max, $help]) {
      $accept = implode(',', array_merge(array_map(static fn(string $ext): string => '.' . $ext, array_values(array_unique($formats))), array_keys($formats)));
      $kinds = implode(', ', array_map('strtoupper', array_values(array_unique($formats))));
      $html .= '<label class="cqt-field cst-doc"><span>' . self::e($title) . ($required ? ' <em aria-hidden="true">*</em>' : '') . '</span>'
        . '<input type="file" name="docs_' . $type . '[]" accept="' . self::e($accept) . '"' . ($max > 1 ? ' multiple' : '') . ' data-cst-doc="' . $type . '" data-cst-doc-max="' . $max . '" data-cst-doc-title="' . self::e($title) . '">'
        . '<small class="cqt-hint">' . self::e($help) . ' ' . $kinds . ($max > 1 ? ', hasta ' . $max . ' archivos' : '') . '.</small></label>';
    }
    return $html . '<p class="cqt-hint">Quedan en el estudio y en el caso de entrega ("Ver documentos" del panel inmobiliario).</p></fieldset>';
  }

  /**
   * Documentos de un estudio aprobado en el formulario de edición: quitar los que sobran y agregar otros.
   * @param list<array{tipo:string,titulo:string,nombre:string,url:string,fecha:int,por:string}> $documents
   */
  private static function documentEditor(array $documents): string
  {
    $html = '';
    if ($documents !== []) {
      $html .= '<fieldset class="cst-docs"><legend>Documentos actuales</legend>';
      foreach ($documents as $index => $document) {
        $html .= '<label class="cqt-check"><input type="checkbox" name="quitar_documentos[]" value="' . $index . '"><span>Quitar <strong>' . self::e($document['titulo']) . '</strong>: ' . self::e($document['nombre']) . '</span></label>';
      }
      $html .= '</fieldset>';
    }
    return $html . self::documentInputs(false);
  }

  /** @param list<array{tipo:string,titulo:string,nombre:string,url:string,fecha:int,por:string}> $documents */
  public static function documentList(array $documents): string
  {
    $html = '<div class="cst-doclist"><h3>Documentos</h3><ul>';
    foreach ($documents as $document) {
      $pdf = preg_match('/\.pdf&/', $document['url']) === 1;
      $html .= '<li><button type="button" class="cst-docbtn" data-cst-file="' . self::e($document['url']) . '" data-cst-file-title="' . self::e($document['titulo'] . ' · ' . $document['nombre']) . '">'
        . self::icon($pdf ? 'picture_as_pdf' : 'image') . '<span><strong>' . self::e($document['titulo']) . '</strong><small>' . self::e($document['nombre']) . '</small></span></button></li>';
    }
    return $html . '</ul></div>';
  }

  /**
   * Formulario de desistir el negocio (en la tarea, en línea; en el listado, dentro de un popup).
   * @param list<string> $reasons
   */
  public static function desistForm(int $id, array $reasons, int $ticketPk, string $panel = ''): string
  {
    return '<form class="cst-form cst-inline cst-desist" data-cst-form="desist"' . ($panel !== '' ? ' data-cst-panel="' . $panel . '" hidden' : '') . ' novalidate><h3>Desistir el negocio del estudio #' . $id . '</h3>'
      . ($ticketPk > 0 ? '<input type="hidden" name="ticket_pk" value="' . $ticketPk . '">' : '') . '<input type="hidden" name="id" value="' . $id . '">'
      . '<p class="cst-warning">' . self::icon('warning') . '<span>El contrato "No asignado" queda desistido, el inmueble vuelve a <strong>En borrador</strong>, el caso de entrega se cierra como desistido y la tarea queda desistida. Con <strong>Cambio de inmueble</strong> el estudio vuelve a "Esperando respuesta" para aprobarlo con otro inmueble.</span></p>'
      . '<div class="cst-grid">' . self::select('motivo', 'Motivo', self::pairs($reasons), '', 'Elige un motivo', true) . '</div>'
      . '<label class="cqt-field"><span>Observación</span><textarea name="observacion" rows="2" maxlength="2000" placeholder="Opcional"></textarea></label>'
      . '<label class="cqt-check"><input type="checkbox" name="confirmar" value="1" required> Entiendo que esta acción no se puede deshacer desde el panel.</label>'
      . '<p class="cqt-error" data-cst-error role="alert" hidden></p>'
      . '<div class="cst-form-actions"><button type="submit" class="cqt-primary cqt-small cst-danger-solid">' . self::icon('block') . 'Desistir negocio</button>'
      . ($panel !== '' ? '<button type="button" class="cqt-secondary cqt-small" data-cst-toggle="' . $panel . '">Cancelar</button>' : '<button type="button" class="cqt-secondary cqt-small" data-cst-dialog-close>Cancelar</button>') . '</div></form>';
  }

  /** Popup con el estudio (vista interna de estudio.php con la sesión del panel). */
  public static function viewer(int $id): string
  {
    $staff = PublicStudyAccess::staffUrl($id);
    $client = PublicStudyAccess::url($id);
    return '<div class="cqt-viewer-box" role="document"><header class="cqt-viewer-head"><h2 id="cst-viewer-title">Estudio de aseguradora #' . $id . '</h2><div class="cqt-viewer-actions">'
      . '<button type="button" class="cqt-secondary" data-cqt-copy="' . self::e($client) . '">' . self::icon('content_copy') . 'Copiar enlace del cliente</button>'
      . '<a class="cqt-secondary" href="' . self::e($staff) . '" target="_blank" rel="noopener">' . self::icon('open_in_new') . 'Abrir</a>'
      . '<button type="button" class="cqt-icon" data-cst-viewer-close aria-label="Cerrar">' . self::icon('close') . '</button></div></header>'
      . '<iframe class="cqt-frame" src="' . self::e($staff . '&embed=1') . '" title="Estudio de aseguradora #' . $id . '"></iframe></div>';
  }

  // ── Pestaña Estudios ────────────────────────────────

  /** @param array<string,mixed> $input */
  public static function page(array $input, CommercialStudiesService $service, CommercialAccessPolicy $policy): string
  {
    $listing = $service->listing($input);
    $f = $listing['filters'];
    $mine = $listing['mine'] || !$listing['team'];
    return '<section class="cqt cqt-page cst" data-cst-page>'
      . '<header class="cqt-head"><span class="scm-head-icon" aria-hidden="true"><span class="material-symbols-outlined">verified_user</span></span><div><p class="cqt-eyebrow">Actividades comerciales · Registros</p><h1>' . ($listing['mine'] ? 'Mis estudios' : 'Estudios de aseguradora') . '</h1><p>'
      . ($mine ? 'Los estudios de aseguradora que has registrado.' : 'Los estudios de aseguradora de todo el equipo.')
      . ' Se crean y se editan desde el popup de cada tarea con el botón Estudio de aseguradora; los que esperan respuesta se responden también desde aquí con Responder.</p></div></header>'
      . '<form class="cqt-filters cqt-list-filters" data-cst-list-filters aria-label="Filtros de estudios">'
      . ($listing['mine'] ? '<input type="hidden" name="portafolio" value="1">' : '')
      . '<input type="hidden" name="es_estado" value="' . self::e($f['estado']) . '">'
      . '<label class="cqt-field cqt-wide"><span>Buscar</span><input type="search" name="es_q" value="' . self::e($f['q']) . '" placeholder="Cliente, celular, correo, solicitud, estudio, tarea o inmueble"></label>'
      . self::select('es_aseguradora', 'Aseguradora', self::pairs($service->insurers()), $f['aseguradora'], 'Todas')
      . ($listing['team'] && !$listing['mine'] ? self::select('es_empleado', 'Consultor/a', $service->creators(), $f['empleado'], 'Todo el equipo') : '')
      . '<label class="cqt-field"><span>Desde</span><input type="date" name="es_desde" value="' . self::e($f['desde']) . '"></label>'
      . '<label class="cqt-field"><span>Hasta</span><input type="date" name="es_hasta" value="' . self::e($f['hasta']) . '"></label>'
      . '<div class="cqt-filter-actions"><button type="submit" class="cqt-primary">Filtrar</button><button type="button" class="cqt-secondary" data-cst-list-clear>Limpiar</button></div>'
      . '</form><div data-cst-list aria-live="polite">' . self::results($listing, $policy, $service->canDesist()) . '</div></section>';
  }

  /** @param array<string,mixed> $listing */
  public static function results(array $listing, CommercialAccessPolicy $policy, bool $canDesist): string
  {
    $current = $listing['filters']['estado'];
    $all = array_sum($listing['estados']);
    $html = '<div class="cst-pills" role="group" aria-label="Estado del estudio"><button type="button" class="cst-pill" data-cst-state="" aria-pressed="' . ($current === '' ? 'true' : 'false') . '">Todos <strong>' . number_format($all, 0, ',', '.') . '</strong></button>';
    foreach (CommercialStudiesService::LIST_STATES as $state) {
      $count = (int) ($listing['estados'][$state] ?? 0);
      $html .= '<button type="button" class="cst-pill cst-pill--' . self::STATE_TONES[$state] . '" data-cst-state="' . self::e($state) . '" aria-pressed="' . ($current === $state ? 'true' : 'false') . '">' . self::e($state) . ' <strong>' . number_format($count, 0, ',', '.') . '</strong></button>';
    }
    $html .= '</div>';
    if ($listing['rows'] === []) {
      return $html . '<p class="cqt-empty">No hay estudios con estos filtros.</p>';
    }
    $canOpenTask = $policy->canAct('ver_ticket');
    $html .= '<div class="cqt-table-wrap"><table class="cqt-table"><thead><tr><th>Estudio</th><th>Cliente</th><th>Aseguradora</th><th>Inmueble</th><th>Tarea</th><th>Consultor/a</th><th><span class="sr-only">Acciones</span></th></tr></thead><tbody>';
    foreach ($listing['rows'] as $row) {
      $id = (int) $row['id'];
      $task = $row['tarea'] > 0 ? ($canOpenTask ? '<button type="button" class="cqt-link" data-commercial-open-case="' . $row['tarea'] . '">#' . $row['tarea'] . '</button>' : '#' . $row['tarea']) : '<span class="cqt-muted">Sin tarea</span>';
      $html .= '<tr>'
        . '<td data-label="Estudio"><strong>#' . $id . '</strong><small>' . self::e($row['fecha']) . '</small>' . self::state($row['estado']) . '</td>'
        . '<td data-label="Cliente"><strong>' . self::e($row['cliente'] ?: 'Sin nombre') . '</strong><small>' . self::e($row['celular']) . '</small><small>' . self::e($row['correo']) . '</small></td>'
        . '<td data-label="Aseguradora"><strong>' . self::e($row['aseguradora']) . '</strong><small>Solicitud ' . self::e($row['num_solicitud']) . '</small><small>' . self::e($row['valor']) . '</small></td>'
        . '<td data-label="Inmueble">' . ($row['inmueble'] !== ''
          ? self::propertyButton($row) . ($row['inmueble_titulo'] !== '' ? '<small>' . self::e($row['inmueble_titulo']) . '</small>' : '') . ($row['inmueble_detalle'] !== '' ? '<small>' . self::e($row['inmueble_detalle']) . '</small>' : '')
          : '<span class="cqt-muted">Sin inmueble</span>') . '</td>'
        . '<td data-label="Tarea">' . $task . '</td>'
        . '<td data-label="Consultor/a">' . self::e($row['asesor'] ?: 'Sin consultor/a') . '</td>'
        . '<td data-label="Acciones"><div class="cqt-row-actions cst-row-actions">'
        // Responder abre un popup con el mismo formulario de respuesta de la tarea, sin salir del listado.
        . ($canOpenTask && $row['tarea'] > 0 && $row['respondible'] ? '<button type="button" class="cqt-primary cqt-small" data-cst-respond="' . $id . '">' . self::icon('fact_check') . 'Responder</button>' : '')
        . '<button type="button" class="cqt-secondary cqt-small" data-cst-view="' . $id . '">' . self::icon('visibility') . 'Ver</button>'
        . self::copyButton($row['enlace'], 'Enlace')
        . ($canDesist && $row['desistible'] ? '<button type="button" class="cqt-secondary cqt-small cst-danger" data-cst-desist="' . $id . '">' . self::icon('block') . 'Desistir negocio</button>' : '')
        . '</div></td></tr>';
    }
    $html .= '</tbody></table></div>';
    $pages = (int) ceil($listing['total'] / $listing['per_page']);
    if ($pages > 1) {
      $page = $listing['page'];
      $html .= '<nav class="cqt-pager" aria-label="Páginas de estudios"><button type="button" class="cqt-secondary" data-cst-list-page="' . ($page - 1) . '"' . ($page <= 1 ? ' disabled' : '') . '>Anterior</button><span>Página ' . $page . ' de ' . $pages . '</span><button type="button" class="cqt-secondary" data-cst-list-page="' . ($page + 1) . '"' . ($page >= $pages ? ' disabled' : '') . '>Siguiente</button></nav>';
    }
    return $html;
  }

  /** @param list<array{codigo:string,titulo:string,detalle:string,servicios:list<string>}> $items */
  public static function propertyResults(array $items): string
  {
    if ($items === []) {
      return '<li class="cqt-muted">No hay inmuebles públicos o en borrador con esa búsqueda.</li>';
    }
    $html = '';
    foreach ($items as $item) {
      $html .= '<li><button type="button" class="cst-result" data-cst-pick="' . self::e($item['codigo']) . '" data-label="' . self::e('#' . $item['codigo'] . ' · ' . $item['titulo']) . '" data-services="' . self::e(implode('|', $item['servicios'])) . '">'
        . '<strong>#' . self::e($item['codigo']) . ' · ' . self::e($item['titulo']) . '</strong><small>' . self::e($item['detalle']) . '</small></button></li>';
    }
    return $html;
  }
}
