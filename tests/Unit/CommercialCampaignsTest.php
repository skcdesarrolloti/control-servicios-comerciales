<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SCM\Commercial\Campaigns\CampaignEngine;
use SCM\Commercial\Campaigns\CampaignMessage;
use SCM\Commercial\Campaigns\CampaignRecipients;
use SCM\Commercial\Campaigns\CampaignTemplates;
use SCM\Commercial\Campaigns\EmailOpenTracker;
use SCM\Commercial\CommercialAccessPolicy;
use SCM\Core\Database;
use SCM\Http\Api\CommercialActionRouter;
use SCM\Views\Campaigns\CampaignsView;
use Tests\Fixtures\CampaignFixture;

require_once dirname(__DIR__) . '/Fixtures/CampaignFixture.php';

/** Notificaciones › Campañas y Plantillas de correo, traídas de dashboard-marketing. */
final class CommercialCampaignsTest extends TestCase
{
  private array $previousSession;

  protected function setUp(): void
  {
    $this->previousSession = $_SESSION ?? [];
    require_once dirname(__DIR__, 2) . '/src/Core/Helpers.php';
  }

  protected function tearDown(): void
  {
    $_SESSION = $this->previousSession;
  }

  /** @param array<string,mixed> $overrides @return array<string,mixed> */
  private static function input(array $overrides = []): array
  {
    return $overrides + [
      'name' => 'Revista noviembre', 'channel' => 'email', 'category' => 'info', 'subject' => 'Hola {{nombre}}', 'message' => "Te contamos las novedades de {{ciudad}}.\nGracias.",
      'segments' => json_encode([['type' => 'clientes', 'q' => ''], ['type' => 'suscriptores', 'q' => '']]), 'batch_size' => '2', 'batch_interval_minutes' => '15', 'max_per_day' => '4',
    ];
  }

  /** @return list<array<string,mixed>> */
  private static function queue(Database $db, string $tag): array
  {
    return $db->getResults('SELECT * FROM skc_notification_queue WHERE gda_campaign_tag = ? ORDER BY id', [$tag]);
  }

  public function testNotificationsBecomeAGroupWithCampaignsAndTemplatesBehindOptInActions(): void
  {
    self::assertSame(['enviar_notificaciones' => 'Enviar notificaciones', 'campanas' => 'Envíos masivos', 'plantillas_correo' => 'Plantillas de correo'], CommercialAccessPolicy::SUBVIEWS['notificaciones']);
    self::assertSame('notif_sub', CommercialAccessPolicy::TAB_GROUPS['notificaciones']);
    self::assertSame(['tab' => 'notificaciones', 'notif_sub' => 'campanas'], CommercialAccessPolicy::normalizeTabGroup(['tab' => 'campanas']));
    foreach (['campanas_enviar', 'campanas_eliminar', 'plantillas_editar'] as $action) {
      self::assertContains($action, CommercialAccessPolicy::OPT_IN_ACTIONS);
    }
    $groups = (new \ReflectionClassConstant(\SCM\Views\CommercialDashboardView::class, 'PERMISSION_ACTION_GROUPS'))->getValue();
    self::assertSame(['enviar_notificacion', 'eliminar_notificacion', 'campanas_enviar', 'campanas_eliminar', 'plantillas_editar'], $groups['Notificaciones']);
    foreach (['commercial_campaign_preview', 'commercial_campaign_send', 'commercial_campaign_test', 'commercial_campaign_detail', 'commercial_campaign_control', 'commercial_template_get', 'commercial_template_save'] as $action) {
      self::assertArrayHasKey($action, CommercialActionRouter::CAMPAIGN_ACTIONS);
    }

    [, $policy] = CampaignFixture::make(false);
    // Sin configurar: entra a las tres subpestañas (la primera es Enviar), pero no envía campañas ni edita plantillas.
    self::assertSame('enviar_notificaciones', $policy->resolveNavigation('notificaciones', [])['notif_sub']);
    self::assertTrue($policy->canSubview('notificaciones', 'campanas'));
    self::assertFalse($policy->canAct('campanas_enviar'));
    self::assertFalse($policy->canAct('plantillas_editar'));
    [, $admin] = CampaignFixture::make(true);
    self::assertTrue($admin->canAct('campanas_enviar'));
  }

  public function testRecipientsIncludeTheNewListsAndPreferencesFollowTheLegacyRule(): void
  {
    [$db, $policy] = CampaignFixture::make();
    $recipients = new CampaignRecipients($db, $policy);
    self::assertSame(['Clientes', 'Suscriptores', 'Codeudores', 'Contactos'], array_values(array_intersect_key($recipients->typeLabels(), array_flip(['clientes', 'suscriptores', 'codeudores', 'contactos']))));
    self::assertSame([105, 104, 103, 102, 101, 100], $recipients->segmentIds('clientes'), 'Sin la papelera.');
    self::assertSame([202, 200], $recipients->segmentIds('suscriptores'), 'Sin quien se dio de baja.');
    self::assertSame([104, 100], $recipients->segmentIds('clientes', 'laura'));
    // La lista completa no depende de los contratos ni del funcionario (Notificaciones solo muestra sus contactos).
    self::assertSame([15, 14, 13, 12, 11, 10], $recipients->segmentIds('propietarios_todos'));
    self::assertSame('Propietarios (todos)', $recipients->typeLabels()['propietarios_todos']);

    self::assertTrue(CampaignRecipients::allows(['tipo_actor' => 'funcionarios', 'bloqueo_email' => 1], 'promo', 'email'));
    self::assertFalse(CampaignRecipients::allows(['bloqueo_email' => 1, 'permite_info_email' => 1], 'info', 'email'));
    self::assertFalse(CampaignRecipients::allows(['permite_info_email' => 0], 'info', 'email'));
    self::assertTrue(CampaignRecipients::allows(['permite_info_email' => 1], 'info', 'email'));
    self::assertTrue(CampaignRecipients::allows([], 'info', 'email'), 'Sin columnas, la información sí.');
    self::assertFalse(CampaignRecipients::allows([], 'promo', 'email'), 'Sin columnas, lo promocional no.');
  }

  public function testPreviewCountsWhoReceivesAndWhyTheRestIsSkipped(): void
  {
    [, , $engine] = CampaignFixture::make();
    $preview = $engine->preview($engine->spec(self::input()));
    self::assertSame(['total' => 8, 'send' => 4, 'already' => 0, 'blocked' => 2, 'no_destination' => 1, 'excluded' => 0, 'invalid' => 0, 'repeated' => 1], $preview['totals']);
    self::assertSame(['clientes', 'suscriptores'], array_column($preview['segments'], 'type'));
    self::assertSame('Laura Gómez', $preview['sample']['nombre']);
    self::assertSame('Hola Laura Gómez', $preview['sample']['subject']);
    self::assertStringContainsString('las novedades de Medellín.<br />', $preview['sample']['html']);
    self::assertSame([], $preview['unknown_variables']);

    // Promocional: los suscriptores no tienen la columna permite_promo_email, así que se saltan.
    $promo = $engine->preview($engine->spec(self::input(['category' => 'promo', 'message' => 'Hola {{apodo}}'])));
    self::assertSame(3, $promo['totals']['send']);
    self::assertSame(['apodo'], $promo['unknown_variables']);

    foreach ([['name' => ''], ['channel' => 'fax'], ['segments' => '[]'], ['segments' => json_encode([['type' => 'otra']])], ['batch_size' => '0'], ['subject' => '']] as $bad) {
      try {
        $engine->spec(self::input($bad));
        self::fail('Debe rechazar ' . json_encode($bad));
      } catch (\InvalidArgumentException) {
      }
    }
    $this->expectExceptionMessage('supera 160');
    $engine->spec(self::input(['channel' => 'sms', 'message' => str_repeat('a', 140)]));
  }

  public function testSendQueuesByBatchesInWorkingHoursWithTrackingAndNeverRepeats(): void
  {
    [$db, , $engine] = CampaignFixture::make();
    $result = $engine->send($engine->spec(self::input(['utm_campaign' => 'revista-nov'])), '999', 'Administrador');
    self::assertSame(4, $result['queued']);
    $rows = self::queue($db, 'Revista noviembre');
    self::assertCount(4, $rows);
    self::assertSame(['clientes', 'clientes', 'suscriptores', 'suscriptores'], array_column($rows, 'gda_tipo_actor'));
    self::assertSame([100, 105, 200, 202], array_map('intval', array_column($rows, 'gda_id_actor')));
    self::assertSame(['pending'], array_values(array_unique(array_column($rows, 'status'))));
    self::assertSame(CampaignEngine::PROJECT_CODE, $rows[0]['project_code']);
    self::assertSame(999, (int) $rows[0]['gda_id_funcionario']);
    // Sábado 18:40 (Bogotá): lote 1 a las 18:40 y lote 2 a las 18:55; después del máximo diario (4) seguiría el lunes.
    self::assertSame(['2026-11-07 23:40:00', '2026-11-07 23:40:00', '2026-11-07 23:55:00', '2026-11-07 23:55:00'], array_column($rows, 'scheduled_at'));
    self::assertSame('2026-11-09 12:00:00', $engine->scheduledAt(['batch_size' => 2, 'batch_interval_minutes' => 15, 'max_per_day' => 4], 4), 'El quinto pasa al lunes a las 7:00.');
    self::assertSame('2026-11-09 12:00:00', CampaignFixture::engine($db, self::policy($db), '2026-11-07 19:30:00')->scheduledAt(CampaignEngine::PACE_DEFAULTS, 0), 'Después de las 19:00 del sábado, el lunes.');

    $tracking = $db->getResults('SELECT * FROM wp_jet_cct_email_tracking ORDER BY _ID');
    self::assertCount(4, $tracking);
    self::assertStringContainsString('https://panel.test/public/track-email.php?t=' . $tracking[0]['tracking_token'], $rows[0]['message_html']);
    self::assertSame((int) $rows[0]['id'], (int) $tracking[0]['queue_id']);
    self::assertSame('1', (string) $db->getVar('SELECT COUNT(*) FROM wp_jet_cct_campaign_config WHERE campaign_tag = ?', ['Revista noviembre']));
    self::assertSame(2, (int) $db->getVar('SELECT COUNT(*) FROM wp_jet_cct_campaign_batches WHERE campaign_tag = ?', ['Revista noviembre']));

    // Enviarla otra vez no repite a nadie.
    $again = $engine->send($engine->spec(self::input()), '999', 'Administrador');
    self::assertSame(0, $again['queued']);
    self::assertSame(5, $again['totals']['already'], 'También Laura repetida: su correo ya la recibió.');

    // Quien la recibió desde el panel anterior (tipo "clientes", proyecto gestor-actores) tampoco la vuelve a recibir.
    $db->insert('skc_notification_queue', ['project_code' => CampaignEngine::LEGACY_PROJECT, 'channel' => 'email', 'status' => 'sent', 'meta_json' => json_encode(['gda' => ['campaign_tag' => 'Edición vieja', 'tipo_actor' => 'clientes', 'id_actor' => 100]]), 'created_at' => '2026-07-01 10:00:00']);
    $legacy = $engine->preview($engine->spec(self::input(['name' => 'Edición vieja'])));
    self::assertSame(1, $legacy['totals']['already']);
    // El panel anterior guardaba "propietarios" (todos): cuenta para la lista completa y para activos o no activos.
    $db->insert('skc_notification_queue', ['project_code' => CampaignEngine::LEGACY_PROJECT, 'channel' => 'email', 'status' => 'sent', 'destination' => 'otro@example.test', 'meta_json' => json_encode(['gda' => ['campaign_tag' => 'Edición vieja', 'tipo_actor' => 'propietarios', 'id_actor' => 10]]), 'created_at' => '2026-07-01 10:00:00']);
    $owners = $engine->preview($engine->spec(self::input(['name' => 'Edición vieja', 'segments' => json_encode([['type' => 'propietarios_todos']])])));
    self::assertSame([6, 1], [$owners['totals']['total'], $owners['totals']['already']]);
  }

  private static function policy(Database $db): CommercialAccessPolicy
  {
    return new CommercialAccessPolicy(new \SCM\Core\Settings($db, true, 'control_servicios_comerciales_config'), $db, ['11']);
  }

  public function testContentForEachChannelDesignedTemplatesAndUtmLinks(): void
  {
    [$db, , $engine] = CampaignFixture::make();
    $templates = new CampaignTemplates($db);
    $spec = $engine->spec(self::input(['template_id' => '1', 'subject' => '', 'message' => 'Feria del {{ciudad}}', 'utm_campaign' => 'revista-nov']), $templates);
    self::assertSame('Nueva edición para {{nombre}}', $spec['subject']);
    $engine->send($spec, '999', 'Administrador');
    $html = (string) $db->getVar("SELECT message_html FROM skc_notification_queue WHERE gda_id_actor = 105 AND gda_tipo_actor = 'clientes'");
    self::assertStringContainsString('<h1>Hola Pedro &lt;b&gt;Ruiz&lt;/b&gt;</h1>', $html, 'Los valores se escapan en el correo.');
    self::assertStringContainsString('<p>Feria del Medellín</p>', $html);
    self::assertStringNotContainsString('<script', $html);
    self::assertStringContainsString('href="https://sucasainmobiliaria.com.co/revista?x=1&amp;utm_source=correo&amp;utm_medium=correo&amp;utm_campaign=revista-nov#inicio"', $html);
    self::assertStringContainsString('href="https://otro.test/"', $html, 'Los enlaces a otros sitios no cambian.');
    self::assertSame('Nueva edición para Pedro Ruiz', $db->getVar("SELECT subject FROM skc_notification_queue WHERE gda_id_actor = 105 AND gda_tipo_actor = 'clientes'"));

    $sms = CampaignMessage::build(['channel' => 'sms', 'message' => 'Hola {{nombre}}, visítanos'], ['nombre' => 'Ana']);
    self::assertSame('SKC SuCasa Inmobiliaria Hola Ana, visítanos', $sms['text']);
    self::assertNull(CampaignMessage::build(['channel' => 'sms', 'message' => 'Hola {{nombre}} ' . str_repeat('x', 120)], ['nombre' => str_repeat('N', 30)]), 'Si el nombre lo pasa de 160, se salta.');
    $whatsapp = CampaignMessage::build(['channel' => 'whatsapp', 'message' => 'Nueva revista'], ['nombre' => 'Ana']);
    self::assertSame(CampaignMessage::WHATSAPP_TEMPLATE, $whatsapp['payload']['template_name']);
    self::assertSame(['Ana', 'Nueva revista', 'SKC SuCasa Inmobiliaria'], array_column($whatsapp['payload']['components'][0]['parameters'], 'text'));
    self::assertSame('marketing', $engine->spec(self::input(['channel' => 'whatsapp', 'category' => 'info']))['category'], 'WhatsApp usa la preferencia de marketing.');
  }

  public function testControlsReportAndEmailOpens(): void
  {
    [$db, , $engine] = CampaignFixture::make();
    $engine->send($engine->spec(self::input()), '999', 'Administrador');
    $tag = 'Revista noviembre';
    self::assertSame(4, $engine->setPaused($tag, true));
    self::assertSame(['paused'], array_values(array_unique(array_column(self::queue($db, $tag), 'status'))));
    self::assertSame('paused', $db->getVar('SELECT status FROM wp_jet_cct_campaign_config WHERE campaign_tag = ?', [$tag]));
    self::assertSame(4, $engine->setPaused($tag, false));

    $rows = self::queue($db, $tag);
    $db->update('skc_notification_queue', ['status' => 'sent', 'sent_at' => '2026-11-07 23:41:00'], ['id' => (int) $rows[0]['id']]);
    $db->update('skc_notification_queue', ['status' => 'failed', 'attempts' => 3, 'last_error' => 'Buzón lleno'], ['id' => (int) $rows[1]['id']]);
    self::assertSame(1, $engine->retryFailed($tag));
    self::assertSame(['pending', 0], [$db->getVar('SELECT status FROM skc_notification_queue WHERE id = ?', [(int) $rows[1]['id']]), (int) $db->getVar('SELECT attempts FROM skc_notification_queue WHERE id = ?', [(int) $rows[1]['id']])]);

    $engine->exclude($tag, 'suscriptores', 202, 'Pidió no recibir la revista', '999');
    self::assertSame('cancelled', $db->getVar("SELECT status FROM skc_notification_queue WHERE gda_campaign_tag = ? AND gda_id_actor = 202", [$tag]));
    $totals = $engine->preview($engine->spec(self::input()))['totals'];
    self::assertSame([4, 1], [$totals['already'], $totals['excluded']], 'El excluido no vuelve a entrar al reenviar.');

    $token = (string) $db->getVar('SELECT tracking_token FROM wp_jet_cct_email_tracking WHERE queue_id = ?', [(int) $rows[0]['id']]);
    $tracker = new EmailOpenTracker($db);
    self::assertTrue($tracker->record($token, '181.50.1.77', 'Mozilla/5.0', '2026-11-08 12:00:00'));
    self::assertTrue($tracker->record($token, '181.50.1.77', 'Mozilla/5.0', '2026-11-08 13:00:00'));
    self::assertFalse($tracker->record('noesuntoken', '1.1.1.1', 'x'));
    $open = $db->getRow('SELECT * FROM wp_jet_cct_email_tracking WHERE tracking_token = ?', [$token]);
    self::assertSame([2, '2026-11-08 12:00:00', '2026-11-08 13:00:00', '181.50.1.0'], [(int) $open['open_count'], $open['opened_at'], $open['last_opened_at'], $open['last_ip']]);

    $detail = $engine->detail($tag);
    self::assertSame([4, 1, 0, 2, 1, 1, 4], [$detail['total'], $detail['sent'], $detail['failed'], $detail['pending'], $detail['cancelled'], $detail['opened'], $detail['tracked']]);
    self::assertSame(['email'], $detail['channels']);
    self::assertFalse($detail['legacy']);
    self::assertSame(1, $detail['exclusions']);
    self::assertSame('2026-11-07 23:40:00', $detail['next_at']);

    self::assertSame(2, $engine->cancel($tag));
    self::assertSame(['sent', 'cancelled'], array_values(array_unique(array_column(self::queue($db, $tag), 'status'))));
    $list = $engine->list();
    self::assertSame('cancelled', $list[0]['status']);
    $this->expectExceptionMessage('La campaña no existe.');
    $engine->detail('No existe');
  }

  public function testTemplatesAreSanitizedDuplicatedAndArchivedNotDeleted(): void
  {
    [$db, , $engine] = CampaignFixture::make();
    $templates = new CampaignTemplates($db);
    self::assertSame(['Revista Esencia'], array_column($templates->list(), 'nombre'), 'Solo las de correo.');
    $id = $templates->save(['nombre' => 'Boletín', 'asunto' => 'Novedades', 'contenido' => '<p onclick="x()">Hola {{nombre}}</p><script>robar()</script><a href="javascript:alert(1)">x</a>'], '999');
    $saved = $templates->find($id);
    self::assertSame('<p>Hola {{nombre}}</p><a>x</a>', $saved['contenido']);
    self::assertSame(999, (int) $db->getVar('SELECT cct_author_id FROM wp_jet_cct_plantillas WHERE _ID = ?', [$id]));
    $copy = $templates->duplicate($id, '999');
    self::assertSame('Boletín (copia)', $templates->find($copy)['nombre']);
    self::assertSame('Boletín (copia) 2', $templates->find($templates->duplicate($id, '999'))['nombre']);
    try {
      $templates->save(['nombre' => 'boletín', 'asunto' => 'x', 'contenido' => '<p>x</p>'], '999');
      self::fail('El nombre no se repite.');
    } catch (\InvalidArgumentException) {
    }
    $templates->setArchived($id, true, '999');
    self::assertNotContains('Boletín', array_column($templates->list(), 'nombre'));
    self::assertContains('Boletín', array_column($templates->list(true), 'nombre'));
    self::assertSame('trash', $db->getVar('SELECT cct_status FROM wp_jet_cct_plantillas WHERE _ID = ?', [$id]));
    try {
      $engine->spec(self::input(['template_id' => (string) $id]), $templates);
      self::fail('Una plantilla archivada no se usa.');
    } catch (\InvalidArgumentException $exception) {
      self::assertStringContainsString('archivada', $exception->getMessage());
    }
    $templates->setArchived($id, false, '999');
    self::assertSame('activa', $templates->find($id)['estado']);
  }

  public function testViewsShowActionsOnlyWithTheirPermissions(): void
  {
    [$db, $admin, $engine] = CampaignFixture::make(true);
    $engine->send($engine->spec(self::input()), '999', 'Administrador');
    $settings = new \SCM\Core\Settings($db, true, 'control_servicios_comerciales_config');
    $html = CampaignsView::page('campanas', [], $admin, $db, $settings, '/public');
    self::assertStringContainsString('data-commercial-campaigns="campanas"', $html);
    self::assertStringContainsString('commercial-campaigns.js', $html);
    self::assertStringContainsString('data-camp-row="Revista noviembre"', $html);
    self::assertStringContainsString('data-camp-open="new"', $html);
    self::assertStringContainsString('<option value="1">Revista Esencia</option>', $html);
    self::assertStringContainsString('data-camp-segment="clientes"', $html);
    self::assertSame('', CampaignsView::page('enviar_notificaciones', [], $admin, $db, $settings, '/public'));
    $templates = CampaignsView::page('plantillas_correo', [], $admin, $db, $settings, '/public');
    self::assertStringContainsString('data-tpl-open="edit"', $templates);

    [$db, $viewer, $engine] = CampaignFixture::make(false);
    $engine->send($engine->spec(self::input()), '999', 'Administrador');
    $html = CampaignsView::page('campanas', [], $viewer, $db, $settings, '/public');
    self::assertStringContainsString('data-camp-row="Revista noviembre"', $html);
    self::assertStringNotContainsString('data-camp-open="new"', $html);
    self::assertStringNotContainsString('data-camp-dialog="new"', $html);
    self::assertStringContainsString('data-can-send="0"', $html);
    $templates = CampaignsView::page('plantillas_correo', [], $viewer, $db, $settings, '/public');
    self::assertStringContainsString('data-tpl-open="view"', $templates);
    self::assertStringNotContainsString('data-tpl-open="edit"', $templates);
  }

  /** @return array{status:int,json:array<string,mixed>} */
  private static function endpoint(bool $admin, string $action, array $input = []): array
  {
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/Fixtures/campaign-endpoint.php') . ' '
      . implode(' ', array_map('escapeshellarg', [$admin ? '1' : '0', $action, base64_encode(json_encode($input, JSON_THROW_ON_ERROR))]));
    $lines = preg_split('/\R/', trim((string) shell_exec($command))) ?: [];
    $status = (int) array_pop($lines);
    return ['status' => $status, 'json' => json_decode(implode("\n", $lines), true, 512, JSON_THROW_ON_ERROR)];
  }

  public function testEndpointsCheckSubtabActionAndConfirmation(): void
  {
    $denied = self::endpoint(false, 'commercial_campaign_send', self::input(['confirm' => '1']));
    self::assertSame([403, 'No tienes permiso para esta acción.'], [$denied['status'], $denied['json']['data']['message']]);
    self::assertSame(403, self::endpoint(false, 'commercial_template_save', ['nombre' => 'X', 'asunto' => 'X', 'contenido' => '<p>x</p>'])['status']);
    self::assertSame(403, self::endpoint(true, 'commercial_campaign_preview', self::input(['nonce' => 'otro']))['status']);

    $preview = self::endpoint(true, 'commercial_campaign_preview', self::input());
    self::assertSame([200, 4], [$preview['status'], $preview['json']['data']['totals']['send']]);
    $unconfirmed = self::endpoint(true, 'commercial_campaign_send', self::input());
    self::assertSame([422, 'Confirma la acción marcando la casilla.'], [$unconfirmed['status'], $unconfirmed['json']['data']['message']]);
    $sent = self::endpoint(true, 'commercial_campaign_send', self::input(['confirm' => '1']));
    self::assertSame(200, $sent['status']);
    self::assertSame(4, $sent['json']['data']['queued']);
    self::assertStringContainsString('Envío programado: 4 mensajes', $sent['json']['data']['message']);
    $invalid = self::endpoint(true, 'commercial_campaign_preview', self::input(['segments' => '[]']));
    self::assertSame(422, $invalid['status']);
    $test = self::endpoint(true, 'commercial_campaign_test', self::input());
    self::assertSame([200, 'Prueba enviada a admin@example.test. Llega en unos minutos.'], [$test['status'], $test['json']['data']['message']]);
    self::assertSame(200, self::endpoint(true, 'commercial_template_save', ['nombre' => 'Nueva', 'asunto' => 'Asunto', 'contenido' => '<p>Hola</p>'])['status']);
    self::assertSame(422, self::endpoint(true, 'commercial_template_save', ['operation' => 'archive', 'id' => '1'])['status']);
  }
}
