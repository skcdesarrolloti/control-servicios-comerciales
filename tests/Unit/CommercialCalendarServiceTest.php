<?php

declare(strict_types=1);

namespace Tests\Unit;

use PDO;
use PHPUnit\Framework\TestCase;
use SCM\Commercial\CommercialAccessPolicy;
use SCM\Commercial\CommercialCalendarService;
use SCM\Core\Database;
use SCM\Core\Settings;
use SCM\Support\ColombiaBusinessCalendar;

final class CommercialCalendarServiceTest extends TestCase
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

  /** @param array<string,mixed> $permissions */
  private function service(string $cargo, array $permissions = []): CommercialCalendarService
  {
    $_SESSION = ['scm_user_cargo' => $cargo, 'scm_user_id' => 7];
    $pdo = new PDO('sqlite::memory:');
    $pdo->exec('CREATE TABLE wp_jet_cct_confi_sistema (_ID INTEGER PRIMARY KEY AUTOINCREMENT, cct_status TEXT, cct_author_id INTEGER, cct_created TEXT, cct_modified TEXT, funcion TEXT, valor TEXT, departamento TEXT)');
    $statement = $pdo->prepare('INSERT INTO wp_jet_cct_confi_sistema (funcion, valor) VALUES (?, ?)');
    $statement->execute([Settings::FUNCTION_KEY, json_encode([
      'commercial_permissions' => $permissions,
      'commercial_admin_cargos' => ['13'],
    ], JSON_THROW_ON_ERROR)]);
    $db = new Database($pdo);
    $settings = new Settings($db);
    return new CommercialCalendarService($db, $settings, new CommercialAccessPolicy($settings, $db, ['13']), []);
  }

  public function testBusinessDaysSkipSundaysAndColombianHolidays(): void
  {
    // Viernes + 1 día útil = sábado (lunes a sábado son hábiles).
    self::assertSame('2026-10-31', ColombiaBusinessCalendar::addBusinessDays('2026-10-30 10:00:00', 1)?->format('Y-m-d'));
    // Sábado + 1: salta el domingo y el festivo de Todos los Santos (lunes 2 de noviembre de 2026).
    self::assertSame('2026-11-03', ColombiaBusinessCalendar::addBusinessDays('2026-10-31 10:00:00', 1)?->format('Y-m-d'));
    self::assertNull(ColombiaBusinessCalendar::addBusinessDays('no-es-fecha', 3));
  }

  public function testAdminSavesCommercialCategoriesAndAllMeansEmpty(): void
  {
    $service = $this->service('13');
    self::assertSame([], $service->categoryIds());
    self::assertSame(['3', '26'], $service->saveCategoryIds(['3', ' 26 ', 'x'], ['3', '26', '40']));
    self::assertSame(['3', '26'], $service->categoryIds());
    self::assertSame([], $service->saveCategoryIds(['3', '26', '40'], ['3', '26', '40']));
    self::assertSame([], $service->categoryIds());
  }

  public function testNonAdminCannotChangeCategoriesAndEmptySelectionIsRejected(): void
  {
    try {
      $this->service('9')->saveCategoryIds(['3']);
      self::fail('Un cargo no administrador no debe configurar categorías.');
    } catch (\RuntimeException $exception) {
      self::assertStringContainsString('permiso', $exception->getMessage());
    }
    $this->expectException(\InvalidArgumentException::class);
    $this->service('13')->saveCategoryIds([]);
  }

  public function testDueTypesFollowViewPermissions(): void
  {
    $types = $this->service('9', ['9' => ['views' => ['calendario', 'abiertos'], 'actions' => []]])->visibleDueTypes();
    self::assertArrayHasKey('tarea_atrasada', $types);
    self::assertArrayHasKey('tarea_seguimiento', $types);
    self::assertArrayNotHasKey('aviso_retoque', $types);
    self::assertArrayNotHasKey('actualizacion_inmueble', $types);
    self::assertArrayNotHasKey('precaptacion_llamada', $types);
    self::assertCount(count(CommercialCalendarService::DUE_TYPES), $this->service('13')->visibleDueTypes());
  }

  public function testOverdueItemsAccumulateOnTodayAndFutureItemsStayOnTheirDate(): void
  {
    $service = $this->service('13');
    $method = new \ReflectionMethod($service, 'dueItem');
    $zone = new \DateTimeZone(ColombiaBusinessCalendar::TIMEZONE);
    $today = new \DateTimeImmutable('today', $zone);
    $from = $today->modify('first day of this month')->getTimestamp();
    $to = $today->modify('last day of this month')->setTime(23, 59, 59)->getTimestamp();

    $overdue = $method->invoke($service, [
      'id' => 'sla-1', 'type' => 'tarea_atrasada', 'title' => 'Tarea #1',
      'due_ts' => $today->modify('-40 days')->setTime(9, 0)->getTimestamp(),
    ], $from, $to);
    self::assertIsArray($overdue);
    self::assertSame('Vencido', $overdue['estado']);
    self::assertSame($today->format('Y-m-d'), $overdue['fecha_calendario']);
    self::assertSame(1, $overdue['acumulado_vencido']);
    self::assertSame(40, $overdue['dias_vencido']);

    $nextMonth = $today->modify('first day of next month');
    $call = $method->invoke($service, [
      'id' => 'precaptacion-5', 'type' => 'precaptacion_llamada', 'title' => 'Llamar',
      'due_ts' => $nextMonth->setTime(15, 30)->getTimestamp(), 'keep_time' => true,
    ], $nextMonth->getTimestamp(), $nextMonth->modify('last day of this month')->setTime(23, 59, 59)->getTimestamp());
    self::assertIsArray($call);
    self::assertSame('Pendiente', $call['estado']);
    self::assertSame($nextMonth->format('Y-m-d') . ' 15:30:00', $call['fecha_inicio']);
    self::assertSame('Precaptación', $call['grupo']);

    $outside = $method->invoke($service, [
      'id' => 'x', 'type' => 'tarea_por_vencer', 'title' => 'Fuera',
      'due_ts' => $today->modify('+3 months')->getTimestamp(),
    ], $from, $to);
    self::assertNull($outside);
  }
}
