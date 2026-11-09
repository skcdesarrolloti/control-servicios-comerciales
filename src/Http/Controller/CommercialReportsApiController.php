<?php

declare(strict_types=1);

namespace SCM\Http\Controller;

use SCM\Commercial\CommercialAccessPolicy;
use SCM\Commercial\Reports\CommercialBillingDocument;
use SCM\Commercial\Reports\CommercialReportsService;
use SCM\Core\Auth;
use SCM\Core\Csrf;
use SCM\Core\Database;
use SCM\Core\Settings;
use SCM\Http\Response\JsonResponse;

/** Pestaña Reportes comerciales: cada endpoint valida el nonce, su subpestaña y, si aplica, la acción sensible. */
final class CommercialReportsApiController
{
  private const VIEW = 'reportes_comerciales';

  private Database $db;
  private CommercialAccessPolicy $policy;
  private CommercialReportsService $service;
  private Csrf $csrf;
  private bool $billingEnabled;

  /** @param array<string,mixed> $config */
  public function __construct(Database $db, Settings $settings, Csrf $csrf, array $config)
  {
    $this->db = $db;
    $this->csrf = $csrf;
    $adminCargos = is_array($config['dashboard_admin_cargos'] ?? null) ? $config['dashboard_admin_cargos'] : ['11', '12', '13', '14'];
    $this->policy = new CommercialAccessPolicy($settings, $db, $adminCargos);
    $reports = CommercialReportsService::settings($settings);
    $this->service = new CommercialReportsService($db, $reports['deadline_weekday']);
    $this->billingEnabled = $reports['billing_enabled'];
  }

  private function require(array $input, string $subview, string $action = ''): void
  {
    if (!$this->csrf->verify('commercial_nonce', (string) ($input['nonce'] ?? ''), false)) {
      JsonResponse::error('Verificación de seguridad fallida.', 403);
    }
    if (!$this->policy->canSubview(self::VIEW, $subview) || ($action !== '' && !$this->policy->canAct($action))) {
      JsonResponse::error('No tienes permiso para esta acción de reportes comerciales.', 403);
    }
  }

  /** El `id_empleado` real de la sesión; vacío si no se sabe quién es. */
  private function me(): string
  {
    $id = trim(Auth::employeeId());
    if ($id !== '' || Auth::userId() <= 0) {
      return $id;
    }
    return trim((string) $this->db->getVar('SELECT `id_empleado` FROM `' . $this->db->table('jet_cct_funcionarios') . '` WHERE `_ID` = ? LIMIT 1', [Auth::userId()]));
  }

  /** @param array<string,mixed> $input @param array<int,string> $keys @return array<string,string> */
  private static function filters(array $input, array $keys): array
  {
    $out = [];
    foreach ($keys as $key) {
      $out[$key] = is_scalar($input[$key] ?? null) ? trim((string) $input[$key]) : '';
    }
    return $out;
  }

  /** @param array<string,mixed> $input */
  public function own(array $input): never
  {
    $this->require($input, 'mis_reportes');
    $me = $this->me();
    $data = $this->service->ownReports($me, (string) ($input['state'] ?? ''), self::filters($input, ['consecutivo', 'tipo', 'ticket', 'inmueble', 'desde', 'hasta']));
    JsonResponse::success($data + [
      'accounts' => $me !== '' ? $this->service->accounts([], $me)['items'] : [],
      'billing_enabled' => $this->billingEnabled,
    ]);
  }

  /** @param array<string,mixed> $input */
  public function accountCreate(array $input): never
  {
    $this->require($input, 'mis_reportes');
    if (!$this->billingEnabled) {
      JsonResponse::error('Las cuentas de cobro comerciales aún no están habilitadas.', 409);
    }
    $this->guard(function () use ($input): void {
      $result = $this->service->createAccount($this->me(), CommercialReportsService::parseIds($input['ids'] ?? ''));
      JsonResponse::success($result + ['message' => 'Cuenta de cobro ' . $result['numero'] . ' creada.' . ($result['fuera_plazo'] ? ' Quedó marcada fuera de plazo.' : '')]);
    });
  }

  /** @param array<string,mixed> $input */
  public function consolidated(array $input): never
  {
    $this->require($input, 'reportes_consolidado');
    $data = $this->service->consolidated((string) ($input['state'] ?? ''), self::filters($input, ['consecutivo', 'tipo', 'ticket', 'inmueble', 'funcionario', 'desde', 'hasta']), (int) ($input['page'] ?? 1));
    JsonResponse::success($data + ['can_pay' => $this->policy->canAct('reportes_pagar'), 'can_edit' => $this->policy->canAct('reportes_editar_valor')]);
  }

  /** @param array<string,mixed> $input */
  public function payReports(array $input): never
  {
    $this->require($input, 'reportes_consolidado', 'reportes_pagar');
    $updated = $this->service->markReportsPaid(CommercialReportsService::parseIds($input['ids'] ?? ''));
    JsonResponse::success(['updated' => $updated, 'message' => $updated === 1 ? 'Reporte marcado como pagado.' : $updated . ' reportes marcados como pagados.']);
  }

  /** @param array<string,mixed> $input */
  public function value(array $input): never
  {
    $this->require($input, 'reportes_consolidado', 'reportes_editar_valor');
    $this->guard(function () use ($input): void {
      $value = CommercialReportsService::money($input['valor'] ?? '');
      $this->service->updateValue((int) ($input['id'] ?? 0), $value);
      JsonResponse::success(['valor' => $value, 'message' => 'Valor actualizado.']);
    });
  }

  /** @param array<string,mixed> $input */
  public function accounts(array $input): never
  {
    $this->require($input, 'cuentas_cobro');
    JsonResponse::success($this->service->accounts(self::filters($input, ['numero', 'funcionario', 'desde', 'hasta', 'estado'])) + ['can_pay' => $this->policy->canAct('reportes_pagar')]);
  }

  /** @param array<string,mixed> $input */
  public function payAccounts(array $input): never
  {
    $this->require($input, 'cuentas_cobro', 'reportes_pagar');
    $result = $this->service->markAccountsPaid(CommercialReportsService::parseIds($input['ids'] ?? ''));
    JsonResponse::success($result + ['message' => 'Cuentas pagadas: ' . $result['accounts'] . '. Reportes actualizados: ' . $result['reports'] . '.']);
  }

  /** @param array<string,mixed> $input */
  public function account(array $input): never
  {
    JsonResponse::success(['account' => $this->visibleAccount($input)]);
  }

  /** @param array<string,mixed> $input */
  public function accountPdf(array $input): never
  {
    $account = $this->visibleAccount($input);
    try {
      $bytes = CommercialBillingDocument::pdf($account, $this->service->employee((string) $account['id_empleado']));
    } catch (\Throwable $exception) {
      error_log('[commercial-reports:pdf] ' . $exception->getMessage());
      JsonResponse::error('No se pudo generar el PDF de la cuenta de cobro.', 500);
    }
    $this->download($bytes, 'application/pdf', 'cuenta_cobro_' . $account['numero'] . '.pdf');
  }

  /** @param array<string,mixed> $input */
  public function accountExcel(array $input): never
  {
    $account = $this->visibleAccount($input);
    $this->download(CommercialBillingDocument::excel($account, $this->service->employee((string) $account['id_empleado'])), 'application/vnd.ms-excel; charset=UTF-8', 'cuenta_cobro_' . $account['numero'] . '.xls');
  }

  /** La cuenta la ve su dueño desde Mis reportes o quien tiene la subpestaña Cuentas de cobro. @param array<string,mixed> $input @return array<string,mixed> */
  private function visibleAccount(array $input): array
  {
    if (!$this->csrf->verify('commercial_nonce', (string) ($input['nonce'] ?? ''), false)) {
      JsonResponse::error('Verificación de seguridad fallida.', 403);
    }
    $account = $this->service->account((int) ($input['id'] ?? 0));
    if ($account === null) {
      JsonResponse::error('Cuenta de cobro no encontrada.', 404);
    }
    $own = $this->me() !== '' && $account['id_empleado'] === $this->me() && $this->policy->canSubview(self::VIEW, 'mis_reportes');
    if (!$own && !$this->policy->canSubview(self::VIEW, 'cuentas_cobro')) {
      JsonResponse::error('No tienes permiso para ver esta cuenta de cobro.', 403);
    }
    return $account;
  }

  private function download(string $bytes, string $type, string $name): never
  {
    while (ob_get_level() > 0) {
      ob_end_clean();
    }
    header('Content-Type: ' . $type);
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9_.-]/', '', $name) . '"');
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    header('Content-Length: ' . strlen($bytes));
    echo $bytes;
    exit;
  }

  private function guard(callable $work): never
  {
    try {
      $work();
    } catch (\DomainException $exception) {
      JsonResponse::error($exception->getMessage(), 422);
    } catch (\Throwable $exception) {
      error_log('[commercial-reports] ' . $exception->getMessage());
      JsonResponse::error('No se pudo completar la operación.', 500);
    }
    exit;
  }
}
