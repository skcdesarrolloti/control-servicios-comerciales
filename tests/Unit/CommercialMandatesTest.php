<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SCM\Commercial\CommercialAccessPolicy;
use SCM\Commercial\CommercialInternalNotifications;
use SCM\Commercial\Mandates\CommercialMandatesNotifier;
use SCM\Commercial\Mandates\CommercialMandatesService;
use SCM\Commercial\Mandates\MandateDocument;
use SCM\Core\Csrf;
use SCM\Core\Database;
use SCM\Core\Settings;
use SCM\Http\Api\CommercialActionRouter;
use SCM\Http\Controller\PublicMandatePageController;
use SCM\Support\FileRateLimiter;
use SCM\Support\PublicMandateAccess;
use SCM\Views\CommercialDashboardView;
use SCM\Views\CommercialMandatesView;
use Tests\Fixtures\CommercialNotificationsFixture;

/** Contratos de mandato: el contrato que lee el propietario, su revisión antes de la firma, permisos y vistas. */
final class CommercialMandatesTest extends TestCase
{
  private const COLUMNS = ['estado', 'propietarios', 'id_inmueble', 'id_propietario', 'id_copropiedad', 'id_empleado', 'creador', 'email_creador', 'celular_creador', 'tipo_contrato', 'cct_created',
    'direccion', 'direccion_fisica', 'barrio', 'estrato', 'area_construida', 'escritura', 'fecha_inmueble', 'circulo_notarial', 'matricula_inmobiliaria', 'referencia_catastral', 'propiedad_horizontal',
    'tiene_parqueadero', 'tipo_parqueadero', 'parqueadero', 'numero_parqueadero', 'tiene_deposito', 'depositos', 'tiene_vigilancia', 'vigilancia', 'equipos_entregados', 'nota_equipos', 'observaciones', 'existe_simi', 'codigo_simi',
    'copropiedad', 'nit_co', 'administrador_co', 'direccion_co', 'contacto_co', 'correo_co', 'tipo_cuenta_co', 'numero_cuenta_co', 'banco_cuenta_co',
    'servicios_publicos', 'energia', 'agua', 'gas', 'tv', 'telefono', 'internet', 'otros',
    'precio', 'incluye_iva', 'iva_total_precio', 'iva_precio', 'administracion', 'cargo', 'tasa_comision', 'comision', 'iva_comision', 'total_comision',
    'incluye_garantia', 'aseguradora', 'tasa_garantia', 'precio_mes_garantia', 'meses_cobertura_garantia', 'incluye_garantia_servicios', 'precio_garantia_sevicios', 'cobertura_servicios', 'pago_servicios', 'precio_mes_servicios', 'precio_anual_servicios',
    'incluye_clausula', 'tasa_clausula', 'precio_clausula', 'tasa_berkley', 'prima_canon_berkley', 'incluye_servicios_berkley', 'valor_asegurado_berkley', 'prima_servicios_berkley', 'gastos_expedicion_berkley', 'pago_berkley', 'precio_mes_berkley', 'precio_anual_berkley',
    'tasa_seguros_mundial', 'prima_canon_seguros_mundial', 'incluye_servicios_seguros_mundial', 'valor_asegurado_seguros_mundial', 'expedicion_seguros_mundial', 'forma_pago_seguros_mundial', 'prima_mes_seguros_mundial', 'total_prima_seguros_mundial',
    'duracion', 'incremento_pactado', 'dias_gracia', 'rete_fuente', 'rete_ica', 'rete_iva', 'adulto_mayor', 'pro_universitario', 'recreacion_deporte', 'acuerdo', 'clasificacion', 'adendas', 'pdf_firmado'];

  private Database $db;
  private CommercialAccessPolicy $policy;
  private array $previousSession;
  /** @var list<array{0:mixed,1:string,2:string}> */
  private array $emails = [];
  /** @var list<array{0:string,1:string,2:array<int,mixed>}> */
  private array $whatsapps = [];

  protected function setUp(): void
  {
    $this->previousSession = $_SESSION ?? [];
    require_once dirname(__DIR__, 2) . '/src/Core/Helpers.php';
    require_once dirname(__DIR__) . '/Fixtures/CommercialNotificationsFixture.php';
    if (!defined('SCM_APP_SECRET')) define('SCM_APP_SECRET', 'secreto-de-prueba-de-al-menos-32-caracteres');
    if (!defined('SCM_BASE_URL')) define('SCM_BASE_URL', 'https://panel.test/public');
    if (!defined('SCM_PUBLIC_PATH')) define('SCM_PUBLIC_PATH', dirname(__DIR__, 2) . '/public');
    $this->boot(true);
  }

  protected function tearDown(): void
  {
    $_SESSION = $this->previousSession;
  }

  /** Base con un mandato de Ana Pérez (900) con dos propietarios y otro del 901. */
  private function boot(bool $admin): void
  {
    [$this->db, $this->policy] = CommercialNotificationsFixture::make($admin);
    $columns = implode(', ', array_map(static fn(string $column): string => $column . ' TEXT', self::COLUMNS));
    $this->db->pdo()->exec("CREATE TABLE wp_jet_cct_contrato_mandato (_ID INTEGER PRIMARY KEY, {$columns})");
    for ($i = 1; $i <= 6; $i++) {
      foreach (['calidad', 'tipo', 'nombre', 'tipo_documento', 'documento', 'lugar', 'empresa', 'nit', 'direccion', 'correo', 'celular', 'indicativo', 'fallecimiento', 'porcentaje', 'tipo_giro', 'nombre_giro', 'titular_cuenta', 'documento_cuenta', 'tipo_cuenta', 'numero_cuenta', 'banco_cuenta', 'correo_cuenta', 'id_propietario_nuevo', 'ano_inicial', 'ano_final', 'ipc', 'mes_inicial', 'mes_final', 'valor'] as $field) {
        $this->db->pdo()->exec("ALTER TABLE wp_jet_cct_contrato_mandato ADD COLUMN {$field}_{$i} TEXT");
      }
    }
    $this->db->insert('wp_jet_cct_contrato_mandato', $this->mandate());
    $this->db->insert('wp_jet_cct_contrato_mandato', ['_ID' => 51, 'estado' => 'Firmado', 'propietarios' => '1', 'nombre_1' => 'Otro Propietario', 'id_empleado' => '901', 'creador' => 'Otro usuario', 'tipo_contrato' => 'Por defecto', 'pdf_firmado' => '/formularios/firmados/mandato-51.pdf']);
    $this->emails = [];
    $this->whatsapps = [];
  }

  /** @return array<string,mixed> */
  private function mandate(array $changes = []): array
  {
    return $changes + [
      '_ID' => 50, 'estado' => 'No enviado', 'propietarios' => '2', 'id_inmueble' => '28355', 'id_propietario' => '10', 'id_empleado' => '900', 'creador' => 'Ana Pérez', 'email_creador' => 'ana@example.test', 'celular_creador' => '3001234567',
      'tipo_contrato' => 'Por defecto', 'cct_created' => '2026-11-20 09:00:00',
      'calidad_1' => 'Propietario', 'tipo_1' => 'Natural', 'nombre_1' => 'Lilia Granados', 'tipo_documento_1' => 'Cédula de Ciudadania', 'documento_1' => '21235713', 'lugar_1' => 'Villavicencio',
      'correo_1' => 'lilia@example.test', 'celular_1' => '3006681522', 'indicativo_1' => '+57', 'porcentaje_1' => '50', 'tipo_cuenta_1' => 'Ahorros', 'numero_cuenta_1' => '058500053242', 'banco_cuenta_1' => 'Banco Davivienda', 'id_propietario_nuevo_1' => '10',
      'calidad_2' => 'Propietario', 'tipo_2' => 'Juridica', 'nombre_2' => 'Inversiones SAS', 'tipo_documento_2' => 'NIT', 'documento_2' => '900123456-7', 'empresa_2' => 'Pedro Ruiz', 'nit_2' => '7788', 'correo_2' => 'inv@example.test', 'celular_2' => '3009998888', 'porcentaje_2' => '50', 'id_propietario_nuevo_2' => '14',
      'direccion' => 'Cll 30 No 19-30', 'barrio' => 'Pie de La Popa', 'estrato' => 'Comercial', 'area_construida' => '127', 'fecha_inmueble' => '1998-03-20', 'propiedad_horizontal' => 'No', 'copropiedad' => 'No debe salir',
      'tiene_parqueadero' => 'Si', 'tipo_parqueadero' => 'Compartido', 'parqueadero' => '3',
      'servicios_publicos' => 'a:2:{i:0;s:7:"Energia";i:1;s:4:"Agua";}', 'energia' => '7682378',
      'precio' => '1.650.000', 'incluye_iva' => 'No', 'tasa_comision' => '10', 'comision' => '165.000', 'iva_comision' => '31.350', 'total_comision' => '196.350',
      'incluye_garantia' => 'Si', 'aseguradora' => 'El libertador', 'tasa_garantia' => '2.45', 'precio_mes_garantia' => '40.425',
      'incluye_garantia_servicios' => 'Si', 'precio_garantia_sevicios' => '2.000.000', 'pago_servicios' => '6', 'precio_mes_servicios' => '52.661,669',
      'incluye_clausula' => 'No', 'tasa_clausula' => '15', 'precio_clausula' => '999.999',
      'duracion' => '12 Meses', 'incremento_pactado' => 'IPC', 'acuerdo' => 'Anual', 'ano_inicial_1' => '2026', 'ano_final_1' => '2027', 'adendas' => "Primera línea\nSegunda línea",
    ];
  }

  private function service(?CommercialAccessPolicy $policy = null): CommercialMandatesService
  {
    return new CommercialMandatesService($this->db, $policy ?? $this->policy, $this->notifier());
  }

  private function notifier(): CommercialMandatesNotifier
  {
    return new CommercialMandatesNotifier($this->db, new Settings($this->db, true, 'control_servicios_comerciales_config'),
      function ($to, string $subject, string $html): void { $this->emails[] = [$to, $subject, $html]; },
      function (string $phone, string $template, array $components): void { $this->whatsapps[] = [$phone, $template, $components]; });
  }

  /** @return array<string,array{key:string,title:string,items:list<array{0:string,1:string}>}> */
  private function sections(array $row): array
  {
    return array_column(MandateDocument::sections($row), null, 'key');
  }

  /** @param list<array{0:string,1:string}> $items @return array<string,string> */
  private static function map(array $items): array
  {
    return array_column($items, 1, 0);
  }

  public function testTheDocumentReadsLikeTheSignatureTemplate(): void
  {
    $sections = $this->sections($this->mandate());
    $owners = $sections['propietarios']['owners'];
    self::assertCount(2, $owners);
    $first = self::map($owners[0]['items']);
    self::assertSame('C.C. 21235713', $first['Documento de identificación']);
    self::assertSame('50 %', $first['% de participación']);
    self::assertSame('+57 3006681522', $first['Celular']);
    self::assertArrayNotHasKey('Representante legal', $first, 'Persona natural: sin representante legal.');
    $second = self::map($owners[1]['items']);
    self::assertSame(['NIT 900123456-7', 'Pedro Ruiz'], [$second['Documento de identificación'], $second['Representante legal']]);

    self::assertArrayNotHasKey('copropiedad', $sections, 'Sin propiedad horizontal no hay copropiedad.');
    $property = self::map($sections['inmueble']['items']);
    self::assertSame(['20/03/1998', '127 m²', 'Compartido · Cantidad: 3'], [$property['Fecha de la escritura'], $property['Área construida'], $property['Parqueadero']]);
    self::assertSame('Energía, Agua', self::map($sections['servicios']['items'])['Servicios del inmueble']);

    $canon = self::map($sections['canon']['items']);
    self::assertSame(['$1.650.000', '10 %', '$196.350'], [$canon['Canon de arrendamiento'], $canon['Comisión de administración'], $canon['Total de la comisión']]);
    self::assertArrayNotHasKey('IVA del canon', $canon);

    $guarantees = self::map($sections['garantias']['items']);
    self::assertSame(['2,45 %', '$40.425', '6 cuotas', '$52.662'], [$guarantees['Tasa de la garantía'], $guarantees['Valor mensual de la garantía'], $guarantees['Forma de pago de servicios'], $guarantees['Prima mensual de servicios']]);
    self::assertSame('No', $guarantees['¿Incluye cláusula penal?']);
    self::assertArrayNotHasKey('Valor de la cláusula penal', $guarantees, 'Lo que está en "No" no muestra sus datos.');

    self::assertSame(['head' => ['Año inicial', 'Año final', 'IPC'], 'rows' => [['2026', '2027', 'No aplica']]], $sections['condiciones']['table']);
    self::assertSame([['Adendas', "Primera línea\nSegunda línea"]], $sections['condiciones']['text']);
  }

  public function testEachContractTypeShowsItsOwnGuarantees(): void
  {
    $berkley = self::map($this->sections($this->mandate(['tipo_contrato' => 'Berkley', 'tasa_berkley' => '3', 'incluye_servicios_berkley' => 'No', 'valor_asegurado_berkley' => '5.000.000', 'precio_mes_berkley' => '120.000']))['garantias']['items']);
    self::assertSame(['Berkley', '3 %', '$120.000'], [$berkley['Póliza'], $berkley['Tasa Berkley'], $berkley['Prima mensual']]);
    self::assertArrayNotHasKey('Valor asegurado de servicios', $berkley);
    self::assertArrayNotHasKey('Aseguradora', $berkley);

    $single = MandateDocument::owners($this->mandate(['propietarios' => '1', 'porcentaje_1' => '']));
    self::assertCount(1, $single);
    self::assertSame('100 %', self::map($single[1]['items'])['% de participación'], 'Un solo propietario es el 100 %.');
    $withCopropiedad = $this->sections($this->mandate(['propiedad_horizontal' => 'Si', 'copropiedad' => 'Edificio Mar', 'nit_co' => '800']));
    self::assertSame('Edificio Mar', self::map($withCopropiedad['copropiedad']['items'])['Copropiedad']);
  }

  public function testTheOwnerLinkIsSignedAndExpires(): void
  {
    $reference = PublicMandateAccess::reference(50);
    self::assertSame(50, PublicMandateAccess::verify($reference));
    self::assertSame(0, PublicMandateAccess::verify(substr($reference, 0, -1) . (str_ends_with($reference, '0') ? '1' : '0')));
    self::assertSame(0, PublicMandateAccess::verify(PublicMandateAccess::reference(50, time() - 10)));
    self::assertStringStartsWith('https://panel.test/public/mandato.php?m=50.', PublicMandateAccess::url(50));
    self::assertSame('https://panel.test/public/mandato.php?id=50', PublicMandateAccess::staffUrl(50));
  }

  public function testTheReviewFlowFromSendingToApproval(): void
  {
    $service = $this->service();
    self::assertSame('sin_enviar', $service->viewData(50)['revision']);

    $result = $service->sendToOwners(50);
    self::assertCount(2, $result['resultados']);
    self::assertTrue($result['resultados'][0]['correo'] && $result['resultados'][0]['whatsapp']);
    self::assertSame(['lilia@example.test', 'inv@example.test'], array_values(array_filter(array_map(static fn(array $mail) => is_string($mail[0]) ? $mail[0] : null, $this->emails))));
    self::assertSame(CommercialMandatesNotifier::TEMPLATE_REVIEW, $this->whatsapps[0][1]);
    self::assertSame('+573006681522', $this->whatsapps[0][0]);
    $body = array_column($this->whatsapps[0][2][0]['parameters'], 'text');
    self::assertSame(['Lilia Granados', '50', 'Cll 30 No 19-30 (código 28355)'], array_slice($body, 0, 3));
    self::assertStringContainsString('Ana Pérez · Correo: ana@example.test', $body[3]);
    self::assertSame(50, PublicMandateAccess::verify($this->whatsapps[0][2][1]['parameters'][0]['text']), 'El botón lleva el enlace firmado.');
    self::assertStringContainsString('Su consultor/a asignado es', $this->emails[0][2]);
    self::assertSame('enviado', $service->viewData(50)['revision']);
    $service->ownerSeen(50);
    self::assertSame('visto', $service->viewData(50)['revision']);

    $this->emails = [];
    $service->ownerNote(50, 'canon', 'Inversiones SAS', 'El canon acordado es 1.700.000');
    self::assertSame('observaciones', $service->viewData(50)['revision']);
    self::assertSame(['ana@example.test'], $this->emails[0][0], 'La observación le llega a quien creó el mandato.');
    $staff = array_values(array_filter($this->whatsapps, static fn(array $row): bool => $row[1] === CommercialMandatesNotifier::TEMPLATE_STAFF));
    self::assertCount(1, $staff, 'Y por WhatsApp al consultor/a.');
    $body = array_column($staff[0][2][0]['parameters'], 'text');
    self::assertSame(['Inversiones SAS', '50'], [$body[1], $body[2]]);
    self::assertStringContainsString('Canon y comisión: «El canon acordado es 1.700.000»', $body[4]);
    $link = PublicMandateAccess::verifyStaff($staff[0][2][1]['parameters'][0]['text']);
    self::assertSame(50, $link['id'] ?? 0, 'El botón lleva su enlace individual firmado al mandato.');
    self::assertNull(PublicMandateAccess::verifyStaff('50.' . (time() + 99) . '.900.' . str_repeat('a', 64)), 'Una firma falsa no abre nada.');
    try {
      $service->ownerApprove(50, 'Lilia Granados', true);
      self::fail('No se aprueba con observaciones pendientes.');
    } catch (\InvalidArgumentException $exception) {
      self::assertStringContainsString('observaciones pendientes', $exception->getMessage());
    }

    $note = $service->viewData(50)['observaciones'][0];
    self::assertSame(['canon', 'Inversiones SAS', 'pendiente'], [$note['seccion'], $note['nombre'], $note['estado']]);
    $this->db->update('wp_jet_cct_contrato_mandato', ['precio' => '1.700.000'], ['_ID' => 50]);
    $service->answerNote($note['id'], 'Corregido a $1.700.000', true);
    $after = $service->viewData(50)['observaciones'][0];
    self::assertSame(['resuelta', 'Corregido a $1.700.000', true], [$after['estado'], $after['respuesta'], $after['anterior']]);

    $service->ownerApprove(50, 'Lilia Granados', true);
    $view = $service->viewData(50);
    self::assertSame(['aprobado', 'Lilia Granados'], [$view['revision'], $view['aprobado_por']]);
    $this->db->update('wp_jet_cct_contrato_mandato', ['duracion' => '24 Meses'], ['_ID' => 50]);
    self::assertSame('cambio', $service->viewData(50)['revision'], 'Si el mandato cambia después de aprobarlo, se pide revisar de nuevo.');
  }

  public function testOwnerInputIsValidated(): void
  {
    $service = $this->service();
    foreach ([['canon', 'Inventado', 'Algo'], ['canon', 'Lilia Granados', '   '], ['canon', 'Lilia Granados', str_repeat('x', CommercialMandatesService::MAX_NOTE + 1)]] as [$section, $name, $message]) {
      try {
        $service->ownerNote(50, $section, $name, $message);
        self::fail('Debía rechazar: ' . $name);
      } catch (\InvalidArgumentException) {
      }
    }
    $service->ownerNote(50, 'no-existe', 'Lilia Granados', 'Pregunta');
    self::assertSame('general', $service->viewData(50)['observaciones'][0]['seccion']);
    $this->expectException(\InvalidArgumentException::class);
    $service->ownerNote(51, 'canon', 'Otro Propietario', 'Ya firmado');
  }

  public function testOnlyWhoCanOpenTheMandateSeesItAndActsOnIt(): void
  {
    $admin = $this->service();
    self::assertSame([51, 50], array_column($admin->listing([])['rows'], 'id'), 'Del más reciente al más antiguo.');
    self::assertSame([], array_column($admin->listing(['portafolio' => '1'])['rows'], 'id'), 'Mis mandatos: solo los de la persona, también para quien ve todo el equipo.');

    $this->boot(false);
    $consultant = $this->service();
    self::assertSame([50], array_column($consultant->listing(['ma_empleado' => '901'])['rows'], 'id'), 'Sin ver todo el equipo: solo los suyos, aunque pida otro.');
    self::assertTrue($consultant->canOpen($this->db->getRow('SELECT * FROM wp_jet_cct_contrato_mandato WHERE _ID = 50')));
    self::assertFalse($consultant->canOpen($this->db->getRow('SELECT * FROM wp_jet_cct_contrato_mandato WHERE _ID = 51')));
    $this->expectException(\RuntimeException::class);
    $consultant->sendToOwners(51);
  }

  public function testPermissionsMenuAndEndpointsAreRegistered(): void
  {
    self::assertArrayHasKey('mandatos', CommercialAccessPolicy::VIEWS);
    self::assertContains('mandatos', CommercialAccessPolicy::VIEW_GROUPS['Actividades comerciales']);
    self::assertArrayHasKey('mandatos', CommercialAccessPolicy::SCOPED_VIEWS);
    $groups = (new \ReflectionClassConstant(CommercialDashboardView::class, 'PERMISSION_ACTION_GROUPS'))->getValue();
    self::assertSame(['mandato_crear', CommercialMandatesService::ACTION_SEND, CommercialMandatesService::ACTION_ANSWER, 'mandato_enviar_firma', \SCM\Commercial\Mandates\MandateClosingSheet::ACTION], $groups['Contratos de mandato']);
    foreach (['mandato_crear', CommercialMandatesService::ACTION_SEND, CommercialMandatesService::ACTION_ANSWER, 'mandato_enviar_firma'] as $action) {
      self::assertArrayHasKey($action, CommercialAccessPolicy::ACTIONS);
    }
    self::assertSame(['commercial_mandates_list' => 'list', 'commercial_mandate_view' => 'view', 'commercial_mandate_task' => 'task', 'commercial_mandate_form' => 'form', 'commercial_mandate_save' => 'save', 'commercial_mandate_sign' => 'sign', 'commercial_mandate_search' => 'search', 'commercial_mandate_property' => 'property'], CommercialActionRouter::MANDATE_ACTIONS);
    $events = array_merge(...array_values(array_map(static fn(array $group): array => array_keys($group['items']), CommercialInternalNotifications::CATALOG)));
    foreach ([CommercialMandatesNotifier::EVENT_SENT, CommercialMandatesNotifier::EVENT_NOTE, CommercialMandatesNotifier::EVENT_APPROVED] as $event) {
      self::assertContains($event, $events);
    }
  }

  public function testTheListShowsTheReviewAndOpensTheMandate(): void
  {
    $this->service()->ownerNote(50, 'canon', 'Lilia Granados', 'Revisar el canon');
    $html = CommercialMandatesView::page([], $this->service());
    self::assertStringContainsString('Contratos de mandato', $html);
    self::assertStringContainsString('data-cma-view="50"', $html);
    self::assertStringContainsString('Con observaciones', $html);
    self::assertStringContainsString('1 observación(es) pendiente(s)', $html);
    self::assertStringContainsString('href="/formularios/firmados/mandato-51.pdf"', $html);
    self::assertStringContainsString('src="https://panel.test/public/mandato.php?id=50&amp;embed=1"', CommercialMandatesView::viewer(50));
    self::assertStringContainsString('data-cma-form data-id="50"', $html, 'Editar desde la pestaña.');
    self::assertStringContainsString('data-cma-sign="50"', $html, 'Enviar a firma desde la pestaña.');
    self::assertStringNotContainsString('data-cma-sign="51"', $html, 'Lo firmado no se reenvía.');
  }

  /** @param array<string,mixed> $query @param array<string,mixed> $post */
  private function page(array $query, array $post = [], string $method = 'GET'): string
  {
    $dir = sys_get_temp_dir() . '/scm-mandate-limits-' . bin2hex(random_bytes(4));
    $controller = new PublicMandatePageController($this->db, new Settings($this->db, true, 'control_servicios_comerciales_config'), new FileRateLimiter($dir), new Csrf(SCM_APP_SECRET), [], $this->notifier(), 'https://panel.test/public/mandato.php');
    ob_start();
    $controller->handle($query, $post, $method, '127.0.0.1');
    return (string) ob_get_clean();
  }

  public function testTheOwnerPageShowsTheContractAndTakesNotes(): void
  {
    $reference = PublicMandateAccess::reference(50);
    $html = $this->page(['m' => $reference]);
    self::assertStringContainsString('Hola, Lilia Granados y Inversiones SAS', $html);
    self::assertStringContainsString('C.C. 21235713', $html);
    self::assertStringContainsString('CLAUSULA PRIMERA', $html, 'La página es el contrato completo.');
    self::assertStringNotContainsString('id="seccion-propietarios"', $html, 'Los datos están en el Anexo 1: no se repiten en tarjetas.');
    self::assertStringContainsString('<option value="canon">', $html, 'La observación se deja eligiendo la sección.');
    self::assertStringContainsString('name="nombre" required', $html, 'Con dos propietarios se elige quién escribe.');
    self::assertStringContainsString('Aprobar el contrato', $html);
    self::assertStringContainsString('Su consultor/a asignado es', $html);
    self::assertStringContainsString('Construimos confianza, protegemos su patrimonio.', $html);
    self::assertStringNotContainsString('Datos internos', $html);
    self::assertStringNotContainsString('No debe salir', $html);

    $this->page(['m' => $reference], ['accion' => 'observacion', 'seccion' => 'inmueble', 'nombre' => 'Lilia Granados', 'mensaje' => 'Falta el depósito'], 'POST');
    $html = $this->page(['m' => $reference, 'ok' => 'observacion']);
    self::assertStringContainsString('Recibimos su observación', $html);
    self::assertStringContainsString('Falta el depósito', $html);
    self::assertStringContainsString('id="seccion-inmueble"', $html, 'Cada observación sale bajo su sección.');
    self::assertStringContainsString('Tiene <strong>1</strong> observación(es) pendiente(s)', $html);

    self::assertStringContainsString('no es válido o venció', $this->page(['m' => '50.123.abc']));
    self::assertStringContainsString('ya fue firmado', $this->page(['m' => PublicMandateAccess::reference(51)]));
  }

  public function testTheStaffPageNeedsTheSessionAndShowsTheTeamTools(): void
  {
    $html = $this->page(['id' => '50']);
    self::assertStringContainsString('Datos internos', $html);
    self::assertStringContainsString('mandato.php?m=50.', $html);
    self::assertStringContainsString('Enviar al propietario por correo y WhatsApp', $html);
    self::assertStringContainsString('index.php?tab=mandatos&amp;abrir_mandato=50', $html, 'Editar y firmar se hacen en el panel.');
    self::assertStringNotContainsString('formularios/', $html);
    self::assertStringContainsString('name="_csrf_token"', $html);

    $token = (string) $_SESSION['scm_csrf']['mandate_staff'];
    $this->page(['id' => '50'], ['accion' => 'enviar', '_csrf_token' => $token], 'POST');
    self::assertSame('enviado', $this->service()->viewData(50)['revision']);
    self::assertStringContainsString('venció', $this->page(['id' => '50'], ['accion' => 'enviar', '_csrf_token' => 'falso'], 'POST'));

    unset($_SESSION['scm_logged_in']);
    $this->page(['id' => '50']);
    self::assertFalse(\SCM\Core\Auth::isLoggedIn());
  }
}
