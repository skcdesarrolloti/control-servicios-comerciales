<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SCM\Commercial\CommercialAccessPolicy;
use SCM\Commercial\Mandates\ClosingSheetsListing;
use SCM\Core\Database;
use SCM\Http\Api\CommercialActionRouter;
use SCM\Views\CommercialClosingSheetsView;
use SCM\Views\CommercialDashboardView;
use Tests\Fixtures\CommercialNotificationsFixture;

/** Registros › Hojas de cierre y Mi portafolio › Mis cierres. */
final class ClosingSheetsListingTest extends TestCase
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

  /** @return array{0:Database,1:CommercialAccessPolicy} */
  private function boot(bool $admin): array
  {
    [$db, $policy] = CommercialNotificationsFixture::make($admin);
    $db->pdo()->exec('CREATE TABLE wp_jet_cct_cierres (_ID INTEGER PRIMARY KEY, fecha TEXT, estado TEXT, id_inmueble TEXT, direccion TEXT, propietario TEXT, arrendatario TEXT, creador TEXT, id_empleado TEXT, verificador TEXT, aprobador TEXT, id_contrato TEXT, id_ticket TEXT)');
    $rows = [
      [440, 'Aprobada', '900', 'Ana Pérez', '648', 'ANA LEYDA BLANCO'],
      [441, 'Esperando respuesta', '900', 'Ana Pérez', '649', 'Pedro Gómez'],
      [442, 'Realizada', '901', 'Otro consultor', '650', 'Lucía Díaz'],
      [443, 'Desistida', '', '', '651', 'Sin dueño'],
    ];
    foreach ($rows as [$id, $state, $employee, $name, $mandate, $owner]) {
      $db->insert('wp_jet_cct_cierres', ['_ID' => $id, 'fecha' => '1795467953', 'estado' => $state, 'id_inmueble' => '152289', 'direccion' => 'Cra 80', 'propietario' => $owner, 'arrendatario' => 'Arrendatario', 'creador' => $name, 'id_empleado' => $employee, 'verificador' => '', 'aprobador' => '', 'id_contrato' => $mandate, 'id_ticket' => $id === 441 ? '700' : '']);
    }
    return [$db, $policy];
  }

  public function testMyClosingSheetsAreOnlyTheOnesIMade(): void
  {
    [$db, $policy] = $this->boot(false);
    $listing = (new ClosingSheetsListing($db, $policy))->listing(['portafolio' => '1']);
    self::assertTrue($listing['mine']);
    self::assertSame([441, 440], array_column($listing['rows'], 'id'), 'Por id_empleado exacto, la más reciente primero.');
    self::assertSame(['Aprobada' => 1, 'Esperando respuesta' => 1], $listing['estados']);
    self::assertSame([440], array_column((new ClosingSheetsListing($db, $policy))->listing(['portafolio' => '1', 'hc_estado' => 'Aprobada'])['rows'], 'id'));
    self::assertSame([441], array_column((new ClosingSheetsListing($db, $policy))->listing(['portafolio' => '1', 'hc_q' => '649'])['rows'], 'id'), 'Busca por mandato.');
    $service = new ClosingSheetsListing($db, $policy);
    self::assertTrue($service->canOpen($service->find(440) ?? []));
    self::assertFalse($service->canOpen($service->find(442) ?? []), 'La hoja de otro consultor no se abre.');
    self::assertFalse($service->canOpen($service->find(443) ?? []), 'Sin dueño tampoco.');
  }

  public function testTheTeamSeesEveryoneAndFiltersByConsultant(): void
  {
    [$db, $policy] = $this->boot(true);
    $service = new ClosingSheetsListing($db, $policy);
    $team = $service->listing([]);
    self::assertFalse($team['mine']);
    self::assertSame([443, 442, 441, 440], array_column($team['rows'], 'id'));
    self::assertSame([442], array_column($service->listing(['hc_empleado' => '901'])['rows'], 'id'));
    self::assertSame([], $service->listing(['portafolio' => '1'])['rows'], 'Mis cierres del administrador: solo los suyos, aunque vea todo el equipo.');
    self::assertSame(['900' => 'Ana Pérez', '901' => 'Otro consultor'], $service->creators());
    $html = CommercialClosingSheetsView::page([], $service);
    self::assertStringContainsString('<h1>Hojas de cierre</h1>', $html);
    self::assertStringContainsString('data-cma-closing-sheet="441"', $html);
    self::assertStringContainsString('Mandato #649', $html);
    self::assertStringContainsString('En verificación de servicios', $html);
    self::assertStringContainsString('data-commercial-open-case="700"', $html, 'La tarea relacionada abre su ventana.');
    self::assertStringContainsString('Sin tarea', $html);
    self::assertStringContainsString('data-cma-closing-sheet="441" data-cma-closing-action="editar"', $html);
    self::assertStringContainsString('data-cma-closing-sheet="441" data-cma-closing-action="desistir"', $html);
    self::assertStringContainsString('data-cma-closing-sheet="442" data-cma-closing-action="imprimir"', $html);
    self::assertStringNotContainsString('data-cma-closing-sheet="442" data-cma-closing-action="editar"', $html, 'Realizada: no se edita ni se desiste.');
    self::assertStringNotContainsString('data-cma-closing-sheet="443" data-cma-closing-action="desistir"', $html);
    self::assertStringContainsString('<h1>Mis cierres</h1>', CommercialClosingSheetsView::page(['portafolio' => '1'], $service));
  }

  public function testViewPermissionMenuAndEndpoint(): void
  {
    self::assertArrayHasKey('cierres', CommercialAccessPolicy::VIEWS);
    self::assertContains('cierres', CommercialAccessPolicy::VIEW_GROUPS['Actividades comerciales']);
    self::assertArrayHasKey('cierres', CommercialAccessPolicy::SCOPED_VIEWS);
    self::assertSame('closingList', CommercialActionRouter::MANDATE_ACTIONS['commercial_closing_sheets_list']);
    $links = (new \ReflectionMethod(CommercialDashboardView::class, 'portfolioLinks'))->invoke(null, ['mandatos', 'cierres'], null);
    self::assertContains('Mis cierres', array_column($links, 'label'));
    $page = (string) file_get_contents(dirname(__DIR__, 2) . '/public/hoja-cierre.php');
    self::assertStringContainsString('$listing->canOpen($sheet)', $page, '?numero= revisa que la hoja sea de la persona.');
  }
}
