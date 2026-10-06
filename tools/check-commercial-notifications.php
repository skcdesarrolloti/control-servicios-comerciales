<?php

declare(strict_types=1);

require dirname(__DIR__) . '/tests/bootstrap.php';
require dirname(__DIR__) . '/src/Core/Helpers.php';
require dirname(__DIR__) . '/tests/Fixtures/CommercialNotificationsFixture.php';

use Tests\Fixtures\CommercialNotificationsFixture;

$check = static function (bool $ok, string $message): void {
  if (!$ok) { throw new RuntimeException($message); }
};
[$db, $policy, $service] = CommercialNotificationsFixture::make();
foreach (['propietarios_activos' => [15,14,10], 'propietarios_no_activos' => [12,11], 'arrendatarios_activos' => [22,20], 'arrendatarios_no_activos' => [21], 'copropiedades' => [32,31,30], 'club_pph' => [41,40]] as $type => $expected) {
  $check($service->idsForFilter($type, '') === $expected, 'Falló el alcance: ' . $type);
  $check($service->search($type, '')['total'] === count($expected), 'Búsqueda sin alcance: ' . $type);
  $check($service->stats()[$type]['total'] === count($expected), 'Estadística sin alcance: ' . $type);
}
$check(!isset($service->types()['proveedores']), 'No debe incluir proveedores');
$templates = $service->whatsappTemplates();
$check(count($templates) === 4, 'Debe haber cuatro plantillas');
$profile = $service->senderProfile();
$check($profile['name'] === 'Ana Pérez' && $profile['cargo'] === 'Consultora de Arriendo' && $profile['phone'] === '3001234567', 'Firma incorrecta');
$service->prepareDelivery(str_repeat('a', 32), []);
try {
  $service->enqueue('propietarios_activos', [10,13], ['whatsapp'], '', 'Mensaje', array_key_first($templates));
  throw new RuntimeException('Se permitió enviar a un contacto ajeno');
} catch (RuntimeException $error) {
  $check(str_contains($error->getMessage(), 'no están disponibles'), $error->getMessage());
}
$check((int) $db->getVar('SELECT COUNT(*) FROM skc_notification_queue') === 0, 'El envío no autorizado tuvo efectos parciales');
try {
  $service->enqueue('propietarios_activos', [10,11], ['whatsapp'], '', 'Mensaje', array_key_first($templates));
  throw new RuntimeException('Se permitió enviar a un no activo desde activos');
} catch (RuntimeException $error) {
  $check(str_contains($error->getMessage(), 'no están disponibles'), $error->getMessage());
}
$check((int) $db->getVar('SELECT COUNT(*) FROM skc_notification_queue') === 0, 'La categoría inválida tuvo efectos parciales');
$check($service->search('propietarios_activos', '', 1, 20, 'no_activos')['total'] === 3, 'Se puede sobreescribir la categoría activa');
$check($service->search('arrendatarios_no_activos', '', 1, 20, 'activos')['total'] === 1, 'Se puede sobreescribir la categoría no activa');
$result = $service->enqueue('propietarios_activos', [10,14,15], ['whatsapp'], '', 'Información del inmueble', array_key_first($templates));
$check($result['queued'] === 1 && $result['filtered'] === 2, 'No se respetaron las preferencias');
$row = $db->getRow('SELECT * FROM skc_notification_queue');
$payload = json_decode($row['payload_json'], true);
$check($payload['components'][0]['parameters'][0]['text'] === 'Propietario 10', 'Saludo incorrecto');
$check(str_contains($payload['components'][0]['parameters'][2]['text'], '3001234567'), 'Firma sin celular');
$check($row['project_code'] === 'control-servicios-comerciales' && $row['status'] === 'pending', 'Cola incorrecta');
$service->enqueue('propietarios_activos', [10], ['whatsapp'], '', 'Información del inmueble', array_key_first($templates));
$check((int) $db->getVar('SELECT COUNT(*) FROM skc_notification_queue') === 1, 'El reintento duplicó el envío');
foreach (['image' => 'imagen', 'document' => 'documento', 'video' => 'video'] as $header => $kind) {
  $service->prepareDelivery(md5($kind), ['type' => $header, 'url' => 'https://example.test/file.php?n=example&s=signature', 'name' => 'Archivo de prueba']);
  $service->enqueue('propietarios_activos', [10], ['whatsapp'], '', 'Mensaje con archivo', 'scm_comercial_generica_' . $kind . '_v1');
  $payload = json_decode($db->getVar('SELECT payload_json FROM skc_notification_queue ORDER BY id DESC LIMIT 1'), true);
  $check($payload['components'][0]['type'] === 'header' && $payload['components'][0]['parameters'][0]['type'] === $header, 'Encabezado incorrecto: ' . $kind);
  $check(count($payload['components'][1]['parameters']) === 3, 'Cuerpo incorrecto con archivo');
}
$service->prepareDelivery(md5('missing'), []);
try {
  $service->enqueue('propietarios_activos', [10], ['whatsapp'], '', 'Mensaje', 'scm_comercial_generica_imagen_v1');
  throw new RuntimeException('Se permitió un encabezado sin archivo');
} catch (InvalidArgumentException $error) { $check(str_contains($error->getMessage(), 'Adjunta'), 'Error inesperado'); }
$db->insert('skc_notification_queue', ['project_code' => 'control-servicios-comerciales', 'source_module' => 'commercial_notifications', 'status' => 'pending', 'meta_json' => '{"commercial_notifications":{"employee_id":"901"}}']);
$db->insert('skc_notification_queue', ['project_code' => 'control-servicios-inmobiliarios', 'source_module' => 'admin_notifications', 'status' => 'pending', 'meta_json' => '{}']);
$check($service->notificationQueue()['total'] === 4, 'Historial expone envíos ajenos');
$db->insert('wp_jet_cct_confi_sistema', ['funcion' => 'control_servicios_comerciales_config', 'valor' => '{"commercial_permissions":{"9":{"views":["inicio"],"actions":[]}}}']);
$restricted = new SCM\Commercial\CommercialAccessPolicy(new SCM\Core\Settings($db, true, 'control_servicios_comerciales_config'), $db, ['11']);
$check(!$restricted->canView('notificaciones') && !$restricted->canAct('enviar_notificacion'), 'No se respetaron permisos por cargo');
[$adminDb, $adminPolicy, $adminService] = CommercialNotificationsFixture::make(true);
$check($adminPolicy->canView('notificaciones') && $adminPolicy->canAct('enviar_notificacion'), 'Administrador sin acceso');
$check(count($adminService->idsForFilter('propietarios_activos', '')) === 4 && count($adminService->idsForFilter('propietarios_no_activos', '')) === 2, 'Administrador no puede ver todos');
[$emailDb, $emailPolicy, $emailService] = CommercialNotificationsFixture::make();
$emailDb->pdo()->exec("UPDATE wp_jet_cct_propietarios SET nombre = '<img src=x onerror=alert(1)>' WHERE _ID = 10");
$emailService->prepareDelivery(md5('email'), []);
$emailResult = $emailService->enqueue('propietarios_activos', [10], ['email'], 'Información comercial', 'Mensaje <de prueba>');
$emailHtml = (string) $emailDb->getVar('SELECT message_html FROM skc_notification_queue');
$check($emailResult['queued'] === 1 && str_contains($emailHtml, '&lt;img') && !str_contains($emailHtml, '<img'), 'Email permite HTML del contacto');
$check(str_contains($emailHtml, '&lt;de prueba&gt;') && str_contains($emailHtml, 'Ana Pérez'), 'Email perdió mensaje o firma');
[$inactiveDb, $inactivePolicy, $inactiveService] = CommercialNotificationsFixture::make();
$inactiveService->prepareDelivery(md5('inactive'), []);
$inactiveResult = $inactiveService->enqueue('propietarios_no_activos', [11,12], ['whatsapp'], '', 'Información comercial', array_key_first($templates));
$check($inactiveResult['queued'] === 2, 'No se puede enviar a propietarios no activos vinculados');
echo "Notificaciones comerciales: 6 categorías por contratos, alcance, permisos, firma, preferencias, deduplicación, 3 encabezados multimedia e historial: OK\n";
