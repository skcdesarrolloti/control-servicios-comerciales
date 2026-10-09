<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SCM\Commercial\CommercialActorEditor;
use SCM\Commercial\CommercialAccessPolicy;
use SCM\Core\Settings;
use Tests\Fixtures\CommercialNotificationsFixture;

final class CommercialActorEditorTest extends TestCase
{
  private array $previousSession;

  protected function setUp(): void
  {
    $this->previousSession = $_SESSION ?? [];
    require_once dirname(__DIR__, 2) . '/src/Core/Helpers.php';
    require_once dirname(__DIR__) . '/Fixtures/CommercialNotificationsFixture.php';
  }

  protected function tearDown(): void { $_SESSION = $this->previousSession; }

  private function preview(CommercialActorEditor $editor, array $extra = []): array
  {
    $detail = $editor->detail('propietarios_activos', 10);
    return $editor->preview('propietarios_activos', 10, array_replace($detail['values'], ['nombre' => 'Nombre corregido', 'version' => $detail['version'], 'groups' => ['inmuebles', 'mandatos', 'arrendamientos', 'cierres']], $extra));
  }

  public function testPreviewIsReadOnlyAndOnlyIncludesMatchingOwnerSlot(): void
  {
    [$db, , $editor] = CommercialNotificationsFixture::makeForActorEditing();
    $preview = $this->preview($editor, ['documento' => '123456-7', 'correo' => 'corregido@example.test', 'celular' => '3001112233', 'indicativo' => '+57']);
    self::assertSame('Propietario 10', $db->getVar('SELECT nombre FROM wp_jet_cct_propietarios WHERE _ID = 10'));
    self::assertCount(6, $preview['items']);
    $mandate = array_values(array_filter($preview['items'], static fn(array $item): bool => $item['group'] === 'mandatos'))[0];
    self::assertContains('nombre_1', array_column($mandate['changes'], 'field'));
    self::assertNotContains('nombre_2', array_column($mandate['changes'], 'field'));
    self::assertNotContains('id_propietario', array_column($mandate['changes'], 'field'));
    self::assertSame('Cerrado', array_values(array_filter($preview['items'], static fn(array $item): bool => $item['group'] === 'cierres'))[0]['context']);
  }

  public function testDetailListsConcreteRelationsBeforeEditingEvenWhenValuesAlreadyMatch(): void
  {
    [$db, , $editor] = CommercialNotificationsFixture::makeForActorEditing();
    $db->update('wp_jet_cct_inmuebles', ['propietario' => 'Propietario 10'], ['_ID' => 2]);
    $detail = $editor->detail('propietarios_activos', 10);
    self::assertCount(5, $detail['related']);
    self::assertSame('', $detail['related_error']);
    self::assertSame(['inmuebles:2', 'mandatos:1', 'arrendamientos:2', 'arrendamientos:7', 'cierres:1'], array_column($detail['related'], 'key'));
    self::assertStringContainsString('SIMI-100', $detail['related'][0]['context']);
    self::assertSame('Propietario 10', $detail['related'][0]['values'][0]['value']);
    self::assertStringContainsString('C-2', $detail['related'][2]['context']);
    self::assertSame('Cerrado', $detail['related'][4]['context']);
    self::assertNotContains('Nombre · titular 2', array_column($detail['related'][1]['values'], 'label'));
    self::assertArrayNotHasKey('table', $detail['related'][0]);
    self::assertSame([], $editor->detail('copropiedades', 33)['related']);
  }

  public function testInitialRecordSelectionControlsPreviewAndSaving(): void
  {
    [$db, , $editor] = CommercialNotificationsFixture::makeForActorEditing();
    $preview = $this->preview($editor, ['select_records' => '1', 'records' => ['inmuebles:2', 'arrendamientos:7', 'inmuebles:999']]);
    self::assertSame(['actor:10', 'inmuebles:2', 'arrendamientos:7'], array_column($preview['items'], 'key'));
    self::assertSame(3, $editor->save($preview['token'], array_column($preview['items'], 'key'))['updated']);
    self::assertSame('Propietario original', $db->getVar('SELECT propietario FROM wp_jet_cct_contratos_arrendamiento WHERE _ID = 2'));
    self::assertCount(1, $this->preview($editor, ['nombre' => 'Solo principal', 'select_records' => '1', 'records' => []])['items']);
  }

  public function testUneditedActorCanPreviewEverySelectedRecordWithoutCreatingSaveToken(): void
  {
    [$db, , $editor] = CommercialNotificationsFixture::makeForActorEditing();
    $detail = $editor->detail('propietarios_activos', 10);
    $preview = $editor->preview('propietarios_activos', 10, $detail['values'] + ['version' => $detail['version'], 'groups' => array_keys($detail['groups'])]);
    self::assertCount(6, $preview['items']);
    self::assertSame(0, $preview['total']);
    self::assertSame(6, $preview['records_total']);
    self::assertSame('', $preview['token']);
    foreach ($preview['items'] as $item) {
      self::assertFalse($item['has_changes']);
      self::assertNotEmpty($item['changes']);
      foreach ($item['changes'] as $field) {
        self::assertSame($field['before'], $field['after']);
        self::assertFalse($field['changed']);
      }
    }
    self::assertSame('Propietario original', $db->getVar('SELECT nombre_1 FROM wp_jet_cct_contrato_mandato WHERE _ID = 1'));
    self::assertEmpty($_SESSION['scm_actor_previews'] ?? []);
    $this->expectException(\RuntimeException::class);
    $editor->save($preview['token'], array_column($preview['items'], 'key'));
  }

  public function testComparisonIncludesUnchangedFieldsAndOnlyWritesChangedRecords(): void
  {
    [$db, , $editor] = CommercialNotificationsFixture::makeForActorEditing();
    $db->update('wp_jet_cct_inmuebles', ['propietario' => 'Nombre corregido'], ['_ID' => 2]);
    $preview = $this->preview($editor);
    self::assertCount(6, $preview['items']);
    self::assertSame(5, $preview['total']);
    self::assertFalse($preview['items'][1]['has_changes']);
    $mandateFields = array_column($preview['items'][2]['changes'], null, 'field');
    self::assertSame('Nombre corregido', $mandateFields['nombre_1']['after']);
    self::assertTrue($mandateFields['nombre_1']['changed']);
    self::assertSame('123456', $mandateFields['documento_1']['after']);
    self::assertFalse($mandateFields['documento_1']['changed']);
    self::assertArrayNotHasKey('nombre_2', $mandateFields);
    self::assertSame(5, $editor->save($preview['token'], array_column($preview['items'], 'key'))['updated']);
    $impact = json_decode($db->getVar('SELECT impact_json FROM wp_scm_commercial_actor_changes'), true);
    self::assertNotContains('inmuebles:2', array_column($impact, 'key'));
  }

  public function testSaveUpdatesSelectedRelationsAndRecordsWhoChangedWhat(): void
  {
    [$db, , $editor] = CommercialNotificationsFixture::makeForActorEditing();
    $preview = $this->preview($editor, ['documento' => '123456-7', 'correo' => 'corregido@example.test']);
    $result = $editor->save($preview['token'], array_column($preview['items'], 'key'));
    self::assertSame(6, $result['updated']);
    self::assertSame('Nombre corregido', $db->getVar('SELECT nombre FROM wp_jet_cct_propietarios WHERE _ID = 10'));
    self::assertSame('Nombre corregido', $db->getVar('SELECT propietario FROM wp_jet_cct_inmuebles WHERE _ID = 2'));
    self::assertSame('Nombre corregido', $db->getVar('SELECT nombre_1 FROM wp_jet_cct_contrato_mandato WHERE _ID = 1'));
    self::assertSame('Otro titular', $db->getVar('SELECT nombre_2 FROM wp_jet_cct_contrato_mandato WHERE _ID = 1'));
    self::assertSame('2010', $db->getVar('SELECT id_propietario FROM wp_jet_cct_contratos_arrendamiento WHERE _ID = 2'));
    self::assertSame('123456-7', $db->getVar('SELECT documento_propietario FROM wp_jet_cct_cierres WHERE _ID = 1'));
    $audit = $db->getRow('SELECT * FROM wp_scm_commercial_actor_changes');
    self::assertSame(3, $audit['changed_by']);
    self::assertSame('999', $audit['employee_id']);
    self::assertSame('Administrador', $audit['changed_by_name']);
    $impact = json_decode($audit['impact_json'], true);
    self::assertCount(6, $impact);
    self::assertArrayHasKey('before', $impact[0]['changes'][0]);
    self::assertArrayHasKey('after', $impact[0]['changes'][0]);
    $this->expectException(\RuntimeException::class);
    $editor->save($preview['token'], []);
  }

  public function testIndividualRecordsCanBeExcluded(): void
  {
    [$db, , $editor] = CommercialNotificationsFixture::makeForActorEditing();
    $preview = $this->preview($editor);
    self::assertSame(2, $editor->save($preview['token'], ['inmuebles:2'])['updated']);
    self::assertSame('Propietario original', $db->getVar('SELECT propietario FROM wp_jet_cct_cierres WHERE _ID = 1'));
    self::assertSame('Propietario original', $db->getVar('SELECT nombre_1 FROM wp_jet_cct_contrato_mandato WHERE _ID = 1'));
    self::assertSame('Nombre corregido', $db->getVar('SELECT propietario FROM wp_jet_cct_inmuebles WHERE _ID = 2'));
  }

  public function testActorCanBeChangedWithoutPropagationAndCannotChangeItsIds(): void
  {
    [$db, , $editor] = CommercialNotificationsFixture::makeForActorEditing();
    $preview = $this->preview($editor, ['groups' => [], 'id_propietario' => '99999', 'cct_author_id' => '999', 'bloqueo_whatsapp' => '1']);
    self::assertCount(1, $preview['items']);
    $editor->save($preview['token'], []);
    $row = $db->getRow('SELECT * FROM wp_jet_cct_propietarios WHERE _ID = 10');
    self::assertSame('2010', $row['id_propietario']);
    self::assertSame('900', $row['cct_author_id']);
    self::assertSame(0, $row['bloqueo_whatsapp']);
  }

  public function testStaleRelatedRecordStopsEveryUpdate(): void
  {
    [$db, , $editor] = CommercialNotificationsFixture::makeForActorEditing();
    $preview = $this->preview($editor);
    $db->update('wp_jet_cct_cierres', ['propietario' => 'Edición concurrente'], ['_ID' => 1]);
    try { $editor->save($preview['token'], array_column($preview['items'], 'key')); self::fail('Aceptó una vista previa obsoleta.'); }
    catch (\RuntimeException $e) { self::assertStringContainsString('Cambió', $e->getMessage()); }
    self::assertSame('Propietario 10', $db->getVar('SELECT nombre FROM wp_jet_cct_propietarios WHERE _ID = 10'));
    self::assertSame('Propietario original', $db->getVar('SELECT nombre_1 FROM wp_jet_cct_contrato_mandato WHERE _ID = 1'));
    self::assertSame(0, (int) $db->getVar('SELECT COUNT(*) FROM wp_scm_commercial_actor_changes'));
  }

  public function testDatabaseFailureRollsBackAllUpdates(): void
  {
    [$db, , $editor] = CommercialNotificationsFixture::makeForActorEditing();
    $preview = $this->preview($editor);
    $db->pdo()->exec("CREATE TRIGGER fail_actor BEFORE UPDATE ON wp_jet_cct_propietarios BEGIN SELECT RAISE(ABORT, 'fixture rollback'); END");
    try { $editor->save($preview['token'], array_column($preview['items'], 'key')); self::fail('Aceptó un fallo de escritura.'); }
    catch (\PDOException $e) { self::assertStringContainsString('fixture rollback', $e->getMessage()); }
    self::assertSame('Propietario original', $db->getVar('SELECT nombre_1 FROM wp_jet_cct_contrato_mandato WHERE _ID = 1'));
    self::assertSame('Propietario original', $db->getVar('SELECT propietario FROM wp_jet_cct_cierres WHERE _ID = 1'));
    self::assertSame(0, (int) $db->getVar('SELECT COUNT(*) FROM wp_scm_commercial_actor_changes'));
  }

  public function testPermissionIsOptInAndRecipientScopeIsEnforced(): void
  {
    [$db, $policy, $editor] = CommercialNotificationsFixture::makeForActorEditing(false);
    self::assertFalse($policy->canAct('editar_actor'));
    try { $editor->detail('propietarios_activos', 10); self::fail('El cargo no tiene permiso.'); }
    catch (\RuntimeException $e) { self::assertStringContainsString('permiso', $e->getMessage()); }
    $db->insert('wp_jet_cct_confi_sistema', ['funcion' => 'control_servicios_comerciales_config', 'valor' => '{"commercial_permissions":{"9":{"views":["notificaciones"],"actions":["editar_actor"]}}}']);
    $policy = new CommercialAccessPolicy(new Settings($db, true, 'control_servicios_comerciales_config'), $db, ['11']);
    $editor = new CommercialActorEditor($db, $policy);
    self::assertTrue($policy->canAct('editar_actor'));
    self::assertSame(10, $editor->detail('propietarios_activos', 10)['id']);
    $this->expectException(\RuntimeException::class);
    $editor->detail('propietarios_activos', 13);
  }

  public function testInvalidDataDuplicateDocumentAndForgedTargetsAreRejected(): void
  {
    [, , $editor] = CommercialNotificationsFixture::makeForActorEditing();
    foreach ([['correo' => 'correo-invalido'], ['nombre' => ''], ['indicativo' => '+abc'], ['celular' => '+573001112233'], ['documento' => '654.321']] as $invalid) {
      try { $this->preview($editor, $invalid); self::fail('Aceptó datos inválidos.'); }
      catch (\InvalidArgumentException $e) { self::assertNotEmpty($e->getMessage()); }
    }
    $preview = $this->preview($editor);
    $this->expectException(\InvalidArgumentException::class);
    $editor->save($preview['token'], ['inmuebles:999']);
  }

  public function testTokensAreBoundToUserAndExpire(): void
  {
    [, , $editor] = CommercialNotificationsFixture::makeForActorEditing();
    $preview = $this->preview($editor);
    $_SESSION['scm_user_id'] = 1;
    try { $editor->save($preview['token'], []); self::fail('Aceptó la revisión de otro usuario.'); }
    catch (\RuntimeException $e) { self::assertStringContainsString('sesión', $e->getMessage()); }
    $_SESSION['scm_user_id'] = 3;
    $_SESSION['scm_actor_previews'][$preview['token']]['expires'] = time() - 1;
    $this->expectException(\RuntimeException::class);
    $editor->save($preview['token'], []);
  }

  public function testAmbiguousIdsPreventPropagationToAnotherActor(): void
  {
    [$db, , $editor] = CommercialNotificationsFixture::makeForActorEditing();
    $db->update('wp_jet_cct_propietarios', ['id_propietario' => '10'], ['_ID' => 13]);
    $detail = $editor->detail('propietarios_activos', 10);
    self::assertSame([], $detail['related']);
    self::assertStringContainsString('referencia', $detail['related_error']);
    try { $this->preview($editor); self::fail('Se propagaron referencias ambiguas.'); }
    catch (\RuntimeException $exception) { self::assertStringContainsString('referencia', $exception->getMessage()); }
    self::assertCount(1, $this->preview($editor, ['groups' => []])['items']);
  }

  public function testTenantCopropiedadAndClubUseTheirOwnFieldsAndRelations(): void
  {
    [$db, , $editor] = CommercialNotificationsFixture::makeForActorEditing();
    foreach (['arrendatarios_activos' => 22, 'copropiedades' => 32, 'club_pph' => 40] as $type => $id) {
      $detail = $editor->detail($type, $id);
      $preview = $editor->preview($type, $id, array_replace($detail['values'], ['nombre' => 'Nuevo ' . $type, 'correo' => 'nuevo@example.test', 'celular' => '3001112233', 'version' => $detail['version'], 'groups' => ['inmuebles', 'mandatos', 'arrendamientos', 'cierres']]));
      $editor->save($preview['token'], array_column($preview['items'], 'key'));
      $column = $type === 'arrendatarios_activos' ? 'arrendatario' : ($type === 'copropiedades' ? 'copropiedad' : 'nombre_pph');
      self::assertSame('Nuevo ' . $type, $db->getVar("SELECT `{$column}` FROM wp_jet_cct_cierres WHERE _ID = 1"));
    }
    self::assertSame('3001112233', $db->getVar('SELECT telefono FROM wp_jet_cct_club_pph WHERE _ID = 40'));
    self::assertSame('3001112233', $db->getVar('SELECT contacto FROM wp_jet_cct_copropiedades WHERE _ID = 32'));
    self::assertSame('nuevo@example.test', $db->getVar('SELECT correo_co FROM wp_jet_cct_contrato_mandato WHERE _ID = 1'));
  }

  public function testJuridicalActorAndMandateEditCompanyRatherThanRepresentative(): void
  {
    [$db, , $editor] = CommercialNotificationsFixture::makeForActorEditing();
    $db->update('wp_jet_cct_propietarios', ['tipo_persona' => 'Jurídica', 'nombre_juridico' => 'Empresa original', 'documento_juridico' => '900123456'], ['_ID' => 10]);
    $db->update('wp_jet_cct_contrato_mandato', ['tipo_1' => 'Jurídica', 'empresa_1' => 'Empresa original', 'nit_1' => '900123456'], ['_ID' => 1]);
    $preview = $this->preview($editor, ['nombre' => 'Empresa nueva', 'documento' => '900123456-1']);
    $editor->save($preview['token'], array_column($preview['items'], 'key'));
    self::assertSame('Propietario 10', $db->getVar('SELECT nombre FROM wp_jet_cct_propietarios WHERE _ID = 10'));
    self::assertSame('Empresa nueva', $db->getVar('SELECT nombre_juridico FROM wp_jet_cct_propietarios WHERE _ID = 10'));
    self::assertSame('Empresa nueva', $db->getVar('SELECT empresa_1 FROM wp_jet_cct_contrato_mandato WHERE _ID = 1'));
    self::assertSame('Propietario original', $db->getVar('SELECT nombre_1 FROM wp_jet_cct_contrato_mandato WHERE _ID = 1'));
  }
}
