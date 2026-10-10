<?php

declare(strict_types=1);

namespace SCM\Precaptacion;

use SCM\Commercial\CommercialAccessPolicy;
use SCM\Core\Auth;
use SCM\Core\Csrf;
use SCM\Core\Database;
use SCM\Core\Settings;
use SCM\Http\Response\JsonResponse;
use SCM\Support\EmailQueue;
use SCM\Support\StoredFileService;

final class Module
{
  private static Database $database;
  private static CommercialAccessPolicy $access;
  private static Csrf $csrf;
  private static array $emails = [];
  private static ManagementHistory $history;
  private static Workflow $workflow;

  public static function init(Database $db, Settings $settings, Csrf $csrf, array $config): void
  {
    self::$database = $db;
    self::$csrf = $csrf;
    self::$access = new CommercialAccessPolicy($settings, $db, $config['dashboard_admin_cargos'] ?? ['11','12','13','14']);
    require_once __DIR__ . '/Compatibility.php';
    require_once __DIR__ . '/LegacyPanel.php';
    $GLOBALS['wpdb'] = new DatabaseAdapter($db);
    self::$history = new ManagementHistory($db);
    self::$workflow = new Workflow($db, self::$history);
  }

  public static function db(): Database { return self::$database; }
  public static function history(): ManagementHistory { return self::$history; }
  public static function workflow(): Workflow { return self::$workflow; }
  public static function policy(): CommercialAccessPolicy { return self::$access; }
  public static function nonce(): string { return $_SESSION['scm_csrf']['precaptacion_nonce'] ?? self::$csrf->token('precaptacion_nonce'); }
  public static function verify(string $nonce): void
  {
    if (!self::$csrf->verify('precaptacion_nonce', $nonce, false)) JsonResponse::error('La sesión del formulario venció. Recarga la página.', 403);
  }
  public static function employee(string $id): array
  {
    return self::db()->getRow('SELECT * FROM `' . self::db()->table('jet_cct_funcionarios') . '` WHERE id_empleado = ? LIMIT 1', [$id]) ?? [];
  }

  public static function deferEmail(array $recipients, string $subject, string $html, array $headers = []): void
  {
    self::$emails[] = [$recipients, $subject, $html, $headers];
  }

  public static function commit(): void
  {
    if (($GLOBALS['wpdb']->last_error ?? '') !== '') wp_send_json_error(['message'=>'No se pudo guardar el cambio.'], 500);
    if (self::db()->pdo()->inTransaction()) self::db()->pdo()->commit();
    foreach (self::$emails as [$recipients, $subject, $html, $headers]) {
      try {
        (new EmailQueue(self::db()))->enqueue($recipients, $subject, $html, [
          'source_module'=>'precaptacion','provider'=>'email_smtp',
          'dedupe_key'=>'precaptacion:' . hash('sha256', $subject . $html),
          'meta'=>['employee_id'=>Auth::employeeId(),'headers'=>$headers],
        ]);
      } catch (\Throwable $exception) {
        error_log('[precaptacion:notification] ' . $exception->getMessage());
      }
    }
    self::$emails = [];
  }

  public static function rollback(): void
  {
    if (self::db()->pdo()->inTransaction()) self::db()->pdo()->rollBack();
    self::$emails = [];
  }

  public static function authorizeRecord(array $row): void
  {
    if ($row === []) throw new \InvalidArgumentException('Precaptación no encontrada.');
    if (!self::policy()->canManage() && (Auth::employeeId() === '' || self::workflow()->responsible($row) !== Auth::employeeId())) {
      throw new \RuntimeException('No tienes permiso para modificar esta precaptación.');
    }
  }

  public static function dispatch(string $action, array $input): never
  {
    if (!self::policy()->canView('precaptacion')) JsonResponse::error('No tienes permiso para acceder a Precaptación.', 403);
    self::verify((string) ($input['nonce'] ?? ''));
    if ($action === 'precaptaciones_marcar_antiguas_no_contactadas') {
      JsonResponse::error('Los registros antiguos deben verificarse individualmente. No se pueden confirmar llamadas ni mensajes de forma automática.', 409);
    }
    if ($action === 'precaptaciones_exportar') LegacyPanel::export_csv($input);
    $repository = new Repository(self::db());
    if (in_array($action, ['precaptaciones_reabrir','precaptaciones_asignar','precaptaciones_cerrar'], true)) {
      if (!self::policy()->canManage()) JsonResponse::error('Esta acción requiere acceso administrativo.', 403);
      self::db()->pdo()->beginTransaction();
      $lock = self::db()->pdo()->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
      $row = self::db()->getRow('SELECT * FROM `' . self::db()->table('jet_cct_precaptaciones') . '` WHERE _ID = ?' . $lock, [(int) ($input['id_precaptacion'] ?? 0)]) ?? [];
      self::authorizeRecord($row);
      if (LegacyPanel::row_has_ticket(self::db()->table('jet_cct_precaptaciones'), $row)) throw new \InvalidArgumentException('Gestiona el registro desde su tarea vinculada.');
      $responsible = (string) ($input['responsable'] ?? '');
      if ($action === 'precaptaciones_asignar' && (self::employee($responsible)['activo'] ?? '') !== 'Si') throw new \InvalidArgumentException('Selecciona un funcionario activo.');
      $cargo = (string) (self::employee($responsible)['id_cargo'] ?? '');
      $permissions = self::policy()->permissions();
      if ($action === 'precaptaciones_asignar' && isset($permissions[$cargo]) && !in_array($cargo, self::policy()->adminCargoIds(), true) && (!in_array('precaptacion', $permissions[$cargo]['views'], true) || !in_array('precaptacion_editar', $permissions[$cargo]['actions'], true))) throw new \InvalidArgumentException('Ese cargo no tiene permisos para gestionar precaptaciones.');
      self::workflow()->administrative($row, substr($action, strlen('precaptaciones_')), (string) ($input['motivo'] ?? ''), $responsible);
      if ($action === 'precaptaciones_reabrir') self::db()->update(self::db()->table('jet_cct_precaptaciones'), ['merece_ticket'=>'','contactado'=>'No'], ['_ID'=>$row['_ID']]);
      self::commit(); JsonResponse::success(['message'=>'Proceso actualizado correctamente.']);
    }
    if ($action === 'precaptacion_catalog_create') {
      if (!self::policy()->canAct('precaptacion_catalogos')) JsonResponse::error('No tienes permiso para crear barrios o inmobiliarias.', 403);
      $result = $repository->createCatalog((string) ($input['kind'] ?? ''), $input);
      JsonResponse::success($result + ['message'=>$result['existing'] ? 'Ya existe. Se seleccionó el registro existente.' : 'Registro creado y seleccionado.']);
    }
    if ($action === 'precaptacion_catalog_lookup') {
      if (!self::policy()->canAct('precaptacion_catalogos')) JsonResponse::error('No tienes permiso para consultar barrios o inmobiliarias.', 403);
      JsonResponse::success($repository->catalogLookup((string) ($input['kind'] ?? ''), $input));
    }
    if ($action === 'precaptacion_create') {
      if (!self::policy()->canAct('precaptacion_crear')) JsonResponse::error('No tienes permiso para registrar precaptaciones.', 403);
      $data = $repository->validate($input);
      if ($repository->duplicate($data) > 0) throw new \InvalidArgumentException('Ya existe una precaptación con ese teléfono y dirección. Gestiona el registro existente o solicita su reapertura.');
      $sizes = (array) ($_FILES['fotos']['size'] ?? []);
      if (count($sizes) > 2 || array_filter($sizes, static fn($size): bool => (int) $size > 10485760)) {
        throw new \InvalidArgumentException('Adjunta una o dos fotografías de hasta 10 MB cada una.');
      }
      $photos = StoredFileService::fromRuntime()->storeImages('fotos', 2);
      if (count($photos) !== count($sizes)) throw new \InvalidArgumentException('No se pudieron recibir todas las fotografías. Verifica su formato y tamaño.');
      self::db()->pdo()->beginTransaction();
      $id = $repository->create($data, $photos);
      self::workflow()->ensure(self::db()->getRow('SELECT * FROM `' . self::db()->table('jet_cct_precaptaciones') . '` WHERE _ID = ?', [$id]) ?? []);
      self::notifyCreated($id, $data);
      self::commit();
      JsonResponse::success(['id'=>$id,'message'=>'Precaptación registrada correctamente.']);
    }
    $method = [
      'precaptaciones_filtrar'=>'ajax_filtrar_precaptaciones',
      'precaptaciones_actualizar'=>'ajax_actualizar_precaptacion',
      'precaptaciones_crear_ticket'=>'ajax_crear_ticket',
      'precaptaciones_marcar_duplicada'=>'ajax_marcar_duplicada',
      'precaptaciones_marcar_sin_informacion'=>'ajax_marcar_sin_informacion',
      'precaptaciones_normalizar_promocionado_por'=>'ajax_normalizar_promocionado_por',
      'precaptaciones_normalizar_competencia'=>'ajax_normalizar_competencia',
      'precaptaciones_normalizar_razon_creado'=>'ajax_normalizar_razon_creado',
    ][$action] ?? null;
    if ($method === null) JsonResponse::error('Acción desconocida.', 400);
    $management = [];
    if ($action !== 'precaptaciones_filtrar') {
      $permission = $action === 'precaptaciones_crear_ticket' ? 'precaptacion_ticket' : 'precaptacion_editar';
      if (!self::policy()->canAct($permission)) JsonResponse::error('No tienes permiso para realizar esta acción.', 403);
      if ($action === 'precaptaciones_actualizar') {
        if (!in_array($input['merece_ticket'] ?? '', ['Si','No','Seguir llamando'], true)) throw new \InvalidArgumentException('Indica si merece tarea o si debes seguir llamando.');
        if (in_array(Repository::normalizeName((string) ($input['razones'] ?? '')), ['ticket creado','tarea creada'], true)) throw new \InvalidArgumentException('La razón Tarea creada se asigna al crear la tarea.');
        if (($input['tipo_gestion'] ?? '') !== 'administrativa' && $input['merece_ticket'] === 'Si' && !self::policy()->canAct('precaptacion_ticket')) JsonResponse::error('No tienes permiso para crear tareas desde precaptación.', 403);
        $input['fecha'] = time();
      }
      if (str_contains($action, 'normalizar') || str_contains($action, 'marcar_')) {
        if (!self::policy()->canManage()) JsonResponse::error('Esta acción requiere acceso administrativo.', 403);
      }
      self::db()->pdo()->beginTransaction();
      if (isset($input['id_precaptacion'])) {
        $lock = self::db()->pdo()->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        $row = self::db()->getRow('SELECT * FROM `' . self::db()->table('jet_cct_precaptaciones') . '` WHERE _ID = ?' . $lock, [(int) $input['id_precaptacion']]) ?? [];
        try { self::authorizeRecord($row); }
        catch (\RuntimeException $exception) { wp_send_json_error(['message'=>$exception->getMessage()], 403); }
        $ticket = '';
        foreach (['id_ticket_asignado','ticket_asignado','id_ticket','ticket','numero_de_ticket'] as $field) {
          $candidate = trim((string) ($row[$field] ?? ''));
          if ($candidate !== '' && $candidate !== '0') { $ticket = $candidate; break; }
        }
        if (LegacyPanel::row_has_ticket(self::db()->table('jet_cct_precaptaciones'), $row)) {
          if ($action === 'precaptaciones_crear_ticket' && $ticket !== '') wp_send_json_success(['ticket_id'=>$ticket,'ticket_url'=>home_url('/ticket/?id_ticket=' . rawurlencode($ticket)),'message'=>'La precaptación ya tiene una tarea.']);
          wp_send_json_error(['message'=>'Esta precaptación ya tiene una tarea y no se puede editar.'], 409);
        }
        // Identity and PPH reward state always come from the saved record.
        if ($action === 'precaptaciones_actualizar') {
          if (isset($input['gestion_version']) && (int) $input['gestion_version'] !== (int) (self::history()->latest((int) $input['id_precaptacion'])['id'] ?? 0)) {
            wp_send_json_error(['message'=>'Otra gestión se guardó mientras editabas. Recarga el listado antes de continuar.'], 409);
          }
          // Preserve omitted contact fields, rather than erasing them on partial requests.
          foreach (['contacto','correo','celular','razones','resultado'] as $field) $input[$field] = $input[$field] ?? $row[$field] ?? '';
          $management = self::workflow()->apply($row, $input);
          $input['contactado'] = $management['outcome'] === 'contactado' ? 'Si' : 'No';
          $input['merece_ticket'] = $management['merit'];
        }
        foreach (['origen','id_pph','bandera'] as $field) $input[$field] = $row[$field] ?? '';
        if ($action === 'precaptaciones_actualizar' && !self::policy()->canManage()) $input['id_empleado'] = Auth::employeeId();
        if ($action === 'precaptaciones_crear_ticket') {
          if (($row['merece_ticket'] ?? '') !== 'Si') wp_send_json_error(['message'=>'Guarda primero un resultado que merezca tarea.'], 409);
          $latest = self::history()->latest((int) $row['_ID']);
          if (!$latest || $latest['outcome'] !== 'contactado' || (self::workflow()->get((int) $row['_ID'])['status'] ?? '') !== 'pendiente_tarea') throw new \InvalidArgumentException('Registra un contacto confirmado y aprueba la creación de tarea primero.');
          $assigned = self::employee((string) ($input['asignado'] ?? $input['id_empleado'] ?? Auth::employeeId()));
          if (($assigned['activo'] ?? '') !== 'Si') wp_send_json_error(['message'=>'Selecciona un funcionario activo.'], 422);
          // Preserve the existing medium/origin while deriving identity from the saved record.
          foreach (['solicitante'=>'contacto','correo_solicitante'=>'correo','celular_solicitante'=>'celular','medio'=>'origen','tipo_inmueble'=>'tipo_inmueble','destinacion'=>'categoria'] as $target => $source) $input[$target] = $row[$source] ?? '';
          $input['tema_ayuda'] = 'Captacion';
          if (trim((string) $input['solicitante']) === '' || trim((string) $input['celular_solicitante']) === '' || trim((string) ($input['asunto'] ?? '')) === '' || trim((string) ($input['descripcion'] ?? '')) === '') throw new \InvalidArgumentException('Completa contacto, celular, asunto y descripción de la tarea.');
        }
        if (in_array($action, ['precaptaciones_marcar_duplicada','precaptaciones_marcar_sin_informacion'], true)) self::workflow()->administrative($row, 'cerrar', $action === 'precaptaciones_marcar_duplicada' ? 'Duplicada' : 'Sin información');
        if (in_array($action, ['precaptaciones_actualizar','precaptaciones_marcar_duplicada','precaptaciones_marcar_sin_informacion'], true)) {
          if (array_key_exists('cct_modified', $row)) {
            self::db()->update(self::db()->table('jet_cct_precaptaciones'), ['cct_modified'=>current_time('mysql')], ['_ID'=>(int) $input['id_precaptacion']]);
          }
        }
      } elseif (in_array($action, ['precaptaciones_actualizar','precaptaciones_crear_ticket','precaptaciones_marcar_duplicada','precaptaciones_marcar_sin_informacion'], true)) {
        wp_send_json_error(['message'=>'Selecciona una precaptación válida.'], 422);
      }
    }
    $_POST = $input;
    LegacyPanel::{$method}();
    JsonResponse::error('No se pudo completar la operación.', 500);
  }

  private static function notifyCreated(int $id, array $data): void
  {
    $config = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/resources/precaptacion-form.json'), true)['notification'];
    $context = $data + ['creador'=>Auth::user(),'inserted_cct_precaptaciones'=>(string) $id,'banner'=>system_image('banner', system_image('portal_logo_url', SCM_DEFAULT_PORTAL_LOGO_URL))];
    $replace = static function (string $text) use ($context): string {
      return preg_replace_callback('/%([\w]+)%/', static fn(array $match): string => esc_html((string) ($context[$match[1]] ?? '')), $text);
    };
    $html = $replace((string) $config['content']);
    $old = home_url('/mi-cuenta/precaptacion/?id_precaptacion=' . $id);
    $html = str_replace($old, esc_url(rtrim(SCM_BASE_URL, '/') . '/?tab=precaptacion'), $html);
    $recipients = preg_split('/[,;]/', (string) $config['custom_email']);
    if (!empty($config['cc_email'])) $recipients = array_merge($recipients, preg_split('/[,;]/', (string) $config['cc_email']));
    self::deferEmail($recipients, $replace((string) $config['subject']), $html);
  }
}
