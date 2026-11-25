<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SCM\Commercial\CommercialAccessPolicy;
use SCM\Commercial\Mandates\MandateClosingSheet;
use SCM\Views\CommercialMandatesView;

/** Hoja de cierre del mandato: se abre en las páginas del panel inmobiliario (crear o ver), dentro del panel comercial. */
final class MandateClosingSheetTest extends TestCase
{
  private const SERVICES = 'https://sucasainmobiliaria.com.co/control-servicios-inmobiliarios/public/';

  public function testWithoutASheetItCreatesOneFromTheMandate(): void
  {
    $sheet = new MandateClosingSheet(self::SERVICES);
    self::assertSame(
      'https://sucasainmobiliaria.com.co/control-servicios-inmobiliarios/public/hoja-cierre.php?id_contrato=640&embed=1',
      $sheet->url(['_ID' => '640'], ['id' => 0, 'estado' => ''])
    );
    self::assertSame('Crear hoja de cierre', MandateClosingSheet::label(['id' => 0, 'estado' => '']));
  }

  public function testWithASheetItOpensItAndADesistedOneStartsANewSheet(): void
  {
    $sheet = new MandateClosingSheet(self::SERVICES);
    self::assertSame(
      'https://sucasainmobiliaria.com.co/control-servicios-inmobiliarios/public/hoja-cierre-ver.php?numero=95&embed=1',
      $sheet->url(['_ID' => '640'], ['id' => 95, 'estado' => 'Esperando respuesta'])
    );
    self::assertSame('Hoja de cierre #95', MandateClosingSheet::label(['id' => 95, 'estado' => 'Aprobada']));
    self::assertStringContainsString('hoja-cierre.php?id_contrato=640', $sheet->url(['_ID' => '640'], ['id' => 95, 'estado' => 'Desistida']));
    self::assertSame('Crear hoja de cierre', MandateClosingSheet::label(['id' => 95, 'estado' => 'Desistida']));
  }

  public function testItNeedsTheAddressOfTheServicesPanel(): void
  {
    self::assertFalse((new MandateClosingSheet(''))->configured());
    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('INMOBILIARIO_URL');
    (new MandateClosingSheet(''))->url(['_ID' => '640'], ['id' => 0, 'estado' => '']);
  }

  public function testTheButtonAndItsPermission(): void
  {
    self::assertArrayHasKey(MandateClosingSheet::ACTION, CommercialAccessPolicy::ACTIONS);
    self::assertNotContains(MandateClosingSheet::ACTION, CommercialAccessPolicy::OPT_IN_ACTIONS, 'Lo tienen los cargos que ven Mandatos hasta que se guarden los permisos.');
    $button = CommercialMandatesView::closingButton(640, ['id' => 95, 'estado' => 'Esperando respuesta'], 12);
    self::assertStringContainsString('data-cma-closing="640"', $button);
    self::assertStringContainsString('data-ticket-pk="12"', $button);
    self::assertStringContainsString('Hoja de cierre #95', $button);
    self::assertStringContainsString('cqt-primary', $button, 'Pendiente de aprobar: resalta.');
    self::assertStringContainsString('Crear hoja de cierre', CommercialMandatesView::closingButton(640, ['id' => 0, 'estado' => '']));
  }

  public function testTheTaskShowsTheButtonOnlyWithAMandate(): void
  {
    $view = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Views/CommercialTicketModalView.php');
    self::assertStringContainsString("is_array(\$closingMandate)", $view);
    $page = (string) file_get_contents(dirname(__DIR__, 2) . '/public/hoja-cierre.php');
    self::assertStringContainsString('canOpen($mandate)', $page, 'Solo los mandatos que la persona puede abrir.');
    self::assertStringContainsString('MandateClosingSheet::ACTION', $page);
  }
}
