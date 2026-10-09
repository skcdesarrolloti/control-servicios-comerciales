<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SCM\Commercial\CommercialNotificationsService;
use Tests\Fixtures\CommercialNotificationsFixture;

final class CommercialNotificationsAuditTest extends TestCase
{
  private array $previousSession;

  protected function setUp(): void
  {
    $this->previousSession = $_SESSION ?? [];
    require_once dirname(__DIR__, 2) . '/src/Core/Helpers.php';
    require_once dirname(__DIR__) . '/Fixtures/CommercialNotificationsFixture.php';
  }

  protected function tearDown(): void
  {
    $_SESSION = $this->previousSession;
  }

  private function fixture(): array
  {
    [$db, $policy, $service] = CommercialNotificationsFixture::make();
    $service->prepareDelivery(md5('audit'), []);
    $result = $service->enqueue('propietarios_activos', [10], ['email', 'sms', 'whatsapp'], 'Asunto guardado', 'Mensaje <con datos>', 'scm_marketing_generica_texto_v1');
    self::assertSame(3, $result['queued']);
    return [$db, $policy, $service];
  }

  private function asAdmin($db, $policy): CommercialNotificationsService
  {
    $_SESSION['scm_user_cargo'] = '11';
    $_SESSION['scm_user_id'] = 3;
    $_SESSION['scm_employee_id'] = '999';
    return new CommercialNotificationsService($db, $policy);
  }

  public function testStoredCreatorAndPreviewUseOriginalMessage(): void
  {
    [$db, , $service] = $this->fixture();
    foreach ($service->notificationQueue()['rows'] as $row) {
      self::assertSame('Ana Pérez', $row['creator_name']);
      self::assertSame('900', $row['employee_id']);
      self::assertSame('1', (string) $row['creator_user_id']);
      $detail = $service->notificationDetail((int) $row['id']);
      self::assertSame($db->getVar('SELECT message_text FROM skc_notification_queue WHERE id = ?', [$row['id']]), $detail['message_text']);
      self::assertArrayNotHasKey('meta_json', $detail);
      if ($row['channel'] === 'email') {
        self::assertSame('Asunto guardado', $detail['subject']);
        self::assertSame($db->getVar('SELECT message_html FROM skc_notification_queue WHERE id = ?', [$row['id']]), $detail['message_html']);
        self::assertStringContainsString('&lt;con datos&gt;', $detail['message_html']);
      }
    }
  }

  public function testNonAdminCannotAcquireDeletePermissionEvenByConfiguration(): void
  {
    [$db, $policy, $service] = $this->fixture();
    self::assertFalse($policy->canAct('eliminar_notificacion'));
    $db->insert('wp_jet_cct_confi_sistema', ['funcion' => 'control_servicios_comerciales_config', 'valor' => '{"commercial_permissions":{"9":{"views":["notificaciones"],"actions":["eliminar_notificacion"]}}}']);
    self::assertFalse($policy->canAct('eliminar_notificacion'));
    $this->expectException(\RuntimeException::class);
    $service->deleteNotification(1);
  }

  public function testDeletionCancelsPendingAndRetainsAuthorContentAndDedupe(): void
  {
    [$db, $policy] = $this->fixture();
    $original = $db->getRow('SELECT * FROM skc_notification_queue WHERE id = 1');
    $service = $this->asAdmin($db, $policy);
    self::assertTrue($policy->canAct('eliminar_notificacion'));
    $service->deleteNotification(1);
    $deleted = $db->getRow('SELECT * FROM skc_notification_queue WHERE id = 1');
    self::assertSame('cancelled', $deleted['status']);
    self::assertSame($original['message_html'], $deleted['message_html']);
    self::assertSame($original['dedupe_key'], $deleted['dedupe_key']);
    $audit = json_decode($deleted['meta_json'], true)['commercial_notifications'];
    self::assertSame('900', $audit['employee_id']);
    self::assertSame('Ana Pérez', $audit['nombre_funcionario']);
    self::assertSame('3', (string) $audit['deleted_by_user_id']);
    self::assertSame('999', $audit['deleted_by_employee_id']);
    self::assertSame('Administrador', $audit['deleted_by_name']);
    self::assertSame('pending', $audit['status_before_deletion']);
    self::assertNotEmpty($audit['deleted_at']);
    self::assertSame(2, $service->notificationQueue()['total']);
    self::assertNull($service->notificationDetail(1));
    $report = $service->notificationReport();
    self::assertSame(3, $report['totals']['total']);
    self::assertSame(1, $report['totals']['deleted']);
    self::assertSame(1, $report['totals']['cancelled']);
    $this->expectException(\RuntimeException::class);
    $service->deleteNotification(1);
  }

  public function testProcessingMessageCannotBeDeleted(): void
  {
    [$db, $policy] = $this->fixture();
    $db->update('skc_notification_queue', ['status' => 'processing'], ['id' => 1]);
    $service = $this->asAdmin($db, $policy);
    try {
      $service->deleteNotification(1);
      self::fail('Se eliminó un mensaje que está procesándose.');
    } catch (\RuntimeException $exception) {
      self::assertStringContainsString('procesándose', $exception->getMessage());
    }
    self::assertSame('processing', $db->getVar('SELECT status FROM skc_notification_queue WHERE id = 1'));
    self::assertSame(3, $service->notificationQueue()['total']);
  }

  public function testSentMessagesKeepDeliveryStateAfterDeletion(): void
  {
    [$db, $policy] = $this->fixture();
    $db->update('skc_notification_queue', ['status' => 'sent', 'sent_at' => '2026-10-08 17:30:00'], ['id' => 1]);
    $service = $this->asAdmin($db, $policy);
    $service->deleteNotification(1);
    self::assertSame('sent', $db->getVar('SELECT status FROM skc_notification_queue WHERE id = 1'));
    self::assertSame(1, $service->notificationReport()['totals']['sent']);
  }

  public function testHistoryPreviewAndReportRespectScopeAndProject(): void
  {
    [$db, $policy, $service] = $this->fixture();
    $db->update('skc_notification_queue', ['meta_json' => '{"commercial_notifications":{"employee_id":"901","nombre_funcionario":"Otro usuario"}}'], ['id' => 1]);
    $db->update('skc_notification_queue', ['source_module' => 'otro_modulo'], ['id' => 2]);
    $db->update('skc_notification_queue', ['project_code' => 'otro_proyecto'], ['id' => 3]);
    self::assertSame(0, $service->notificationQueue()['total']);
    self::assertSame(0, $service->notificationReport()['totals']['total']);
    foreach ([1, 2, 3, 999] as $id) { self::assertNull($service->notificationDetail($id)); }
    self::assertSame(0, $service->notificationReport(['employee_id' => '901'])['totals']['total']);
    $admin = $this->asAdmin($db, $policy);
    self::assertSame(1, $admin->notificationReport()['totals']['total']);
    foreach ([2, 3] as $id) {
      try { $admin->deleteNotification($id); self::fail('Se eliminó un mensaje ajeno al módulo.'); }
      catch (\RuntimeException $exception) { self::assertStringContainsString('no está disponible', $exception->getMessage()); }
    }
  }

  public function testReportDatesUseFullColombianDaysAndGroupByChannel(): void
  {
    [$db, , $service] = $this->fixture();
    $db->update('skc_notification_queue', ['created_at' => '2026-10-08 04:59:59'], ['id' => 1]);
    $db->update('skc_notification_queue', ['created_at' => '2026-10-08 05:00:00', 'status' => 'sent'], ['id' => 2]);
    $db->update('skc_notification_queue', ['created_at' => '2026-10-09 04:59:59', 'status' => 'failed'], ['id' => 3]);
    $report = $service->notificationReport(['date_from' => '2026-10-08', 'date_to' => '2026-10-08']);
    self::assertSame(2, $report['totals']['total']);
    self::assertSame(1, $report['totals']['sent']);
    self::assertSame(1, $report['totals']['failed']);
    self::assertCount(1, $report['rows']);
    self::assertSame(1, $report['rows'][0]['sms']);
    self::assertSame(1, $report['rows'][0]['whatsapp']);
    self::assertSame(0, $report['rows'][0]['email']);
    $db->update('skc_notification_queue', ['created_at' => '2026-10-09 05:00:00'], ['id' => 3]);
    self::assertSame(1, $service->notificationReport(['date_from' => '2026-10-08', 'date_to' => '2026-10-08'])['totals']['total']);
    self::assertSame(0, $service->notificationReport(['employee_id' => '901'])['totals']['total']);
  }

  public function testInvalidDatesAreRejected(): void
  {
    [, , $service] = $this->fixture();
    foreach ([['date_from' => '2026-02-30'], ['date_to' => 'incorrecta'], ['date_from' => '2026-10-09', 'date_to' => '2026-10-08']] as $filters) {
      try { $service->notificationReport($filters); self::fail('Se aceptaron fechas inválidas.'); }
      catch (\InvalidArgumentException $exception) { self::assertNotEmpty($exception->getMessage()); }
    }
  }

  public function testReportKeepsOneEmployeeRowAcrossChannelsAndHistoricalNameChanges(): void
  {
    [$db, , $service] = $this->fixture();
    $row = $db->getRow('SELECT * FROM skc_notification_queue WHERE id = 1');
    unset($row['id']);
    $row['meta_json'] = '{"commercial_notifications":{"employee_id":"900","nombre_funcionario":"Nombre anterior","cargo":"Cargo anterior"}}';
    $row['created_at'] = '2020-01-01 00:00:00';
    $row['dedupe_key'] = 'historic-name';
    $db->insert('skc_notification_queue', $row);
    $report = $service->notificationReport();
    self::assertCount(1, $report['rows']);
    self::assertSame('Ana Pérez', $report['rows'][0]['creator_name']);
    self::assertSame(4, $report['rows'][0]['total']);
    self::assertSame(2, $report['rows'][0]['email']);
    self::assertSame(1, $report['rows'][0]['sms']);
    self::assertSame(1, $report['rows'][0]['whatsapp']);
  }

  public function testHistoricalMediaAndMissingAuthorAreHandled(): void
  {
    [$db, $policy, $service] = $this->fixture();
    $service->prepareDelivery(md5('media-audit'), ['type' => 'image', 'url' => 'https://example.test/photo.jpg', 'name' => 'Foto']);
    $service->enqueue('propietarios_activos', [10], ['whatsapp'], '', 'Foto de prueba', 'scm_marketing_generica_imagen_v1');
    $id = (int) $db->lastInsertId();
    self::assertSame('Foto', $service->notificationDetail($id)['media']['name']);
    $db->update('skc_notification_queue', ['meta_json' => '{}'], ['id' => $id]);
    $admin = $this->asAdmin($db, $policy);
    $detail = $admin->notificationDetail($id);
    self::assertSame('Sin autor registrado', $detail['creator_name']);
    self::assertSame('https://example.test/photo.jpg', $detail['media']['url']);
    $db->update('skc_notification_queue', ['payload_json' => '{"components":[{"type":"header","parameters":[{"type":"image","image":{"link":"javascript:alert(1)"}}]}]}'], ['id' => $id]);
    self::assertSame([], $admin->notificationDetail($id)['media']);
    $db->update('skc_notification_queue', ['meta_json' => 'invalid legacy json'], ['id' => $id]);
    self::assertSame(4, $admin->notificationQueue()['total']);
    self::assertSame(4, $admin->notificationReport()['totals']['total']);
    $admin->deleteNotification($id);
    self::assertSame(3, $admin->notificationQueue()['total']);
    self::assertSame(1, $admin->notificationReport()['totals']['deleted']);
  }
}
