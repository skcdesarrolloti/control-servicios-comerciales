<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SCM\Commercial\CommercialAccessPolicy;
use SCM\Commercial\Promotion\Social\SocialStatsRepository;
use SCM\Core\Database;
use SCM\Core\Settings;
use SCM\Views\Promotion\PromotionView;
use Tests\Fixtures\CommercialPromotionDatabase;

require_once dirname(__DIR__) . '/Fixtures/CommercialPromotionDatabase.php';

/** Promoción › Redes sociales (solo consulta), traída de dashboard-marketing. */
final class CommercialSocialTest extends TestCase
{
  private const TODAY = '2026-11-04';

  private array $previousSession;
  private Database $db;
  private Settings $settings;

  protected function setUp(): void
  {
    $this->previousSession = $_SESSION ?? [];
    require_once dirname(__DIR__, 2) . '/src/Core/Helpers.php';
    $this->db = CommercialPromotionDatabase::create();
    $this->settings = CommercialPromotionDatabase::settings($this->db);
    $this->seed();
    CommercialPromotionDatabase::login('13', '101');
  }

  protected function tearDown(): void
  {
    $_SESSION = $this->previousSession;
  }

  private function seed(): void
  {
    CommercialPromotionDatabase::social($this->db);
    $permissions = $this->settings->get('commercial_permissions', []);
    $permissions['6']['actions'][] = 'redes_ver_inversion';
    $this->settings->set('commercial_permissions', $permissions);
  }

  private function policy(): CommercialAccessPolicy
  {
    return new CommercialAccessPolicy($this->settings, $this->db, ['13']);
  }

  private function page(string $cargo, array $input): string
  {
    CommercialPromotionDatabase::login($cargo, '101');
    return PromotionView::page(['promocion_sub' => 'redes_sociales'] + $input, $this->policy(), $this->db, $this->settings, '/public', false, self::TODAY);
  }

  public function testSocialIsAPromotionSubtabWithAnOptInSpendPermission(): void
  {
    self::assertSame('Redes sociales', CommercialAccessPolicy::SUBVIEWS['promocion']['redes_sociales']);
    self::assertContains('redes_ver_inversion', CommercialAccessPolicy::OPT_IN_ACTIONS);
    self::assertSame(['tab' => 'promocion', 'promocion_sub' => 'redes_sociales'], CommercialAccessPolicy::normalizeTabGroup(['tab' => 'redes_sociales']));
    $groups = (new \ReflectionClassConstant(\SCM\Views\CommercialDashboardView::class, 'PERMISSION_ACTION_GROUPS'))->getValue();
    self::assertContains('redes_ver_inversion', $groups['Promoción']);
    CommercialPromotionDatabase::login('7', '303');
    self::assertTrue($this->policy()->canSubview('promocion', 'redes_sociales'));
    self::assertFalse($this->policy()->canAct('redes_ver_inversion'));
  }

  public function testDefaultRangeEndsAtTheLatestDataAndKpisAreSummed(): void
  {
    $repository = new SocialStatsRepository($this->db);
    $filters = $repository->filters([], self::TODAY);
    self::assertSame(['instagram', '2026-07-01', '2026-09-28', '2026-09-28'], [$filters['network'], $filters['from'], $filters['to'], $filters['latest']]);
    $data = $repository->summary($filters, false);
    self::assertSame(['followers' => 1751, 'followers_change' => 51, 'reach' => 600, 'impressions' => 30, 'profile_views' => 15, 'website_clicks' => 6, 'interactions' => 15, 'posts' => 2], $data['kpis']);
    self::assertCount(90, $data['daily']);
    self::assertSame(['day' => '2026-09-28', 'value' => 300], end($data['daily']));
    self::assertSame('Apartamento <b>en</b> Laureles', $data['posts'][0]['title']);
    self::assertSame(26, $data['posts'][0]['interactions']);
    self::assertSame('2026-09-28', $data['audience']['snapshot']);
    self::assertSame(['25-34', '35-44'], array_column($data['audience']['groups']['age'], 'label'), 'Solo la última foto.');

    $ads = $repository->summary($repository->filters(['pr_red' => 'publicidad'], self::TODAY), true);
    self::assertSame(['impressions' => 3744, 'reach' => 3544, 'clicks' => 56, 'link_clicks' => 56, 'spend' => 15389.0], array_diff_key($ads['kpis'], ['cpc' => true]));
    self::assertCount(1, $ads['campaigns'], 'Una fila por campaña.');
    self::assertSame([9527, '2025-01-01', '2026-08-06'], [$ads['campaigns'][0]['impressions'], $ads['campaigns'][0]['since'], $ads['campaigns'][0]['date']]);
    $plain = $repository->summary($repository->filters(['pr_red' => 'publicidad'], self::TODAY), false);
    self::assertArrayNotHasKey('spend', $plain['kpis']);
    self::assertArrayNotHasKey('spend', $plain['campaigns'][0]);

    $youtube = $repository->summary($repository->filters(['pr_red' => 'youtube'], self::TODAY), false);
    self::assertSame(['views' => 250, 'watch_minutes' => 241, 'subscribers_net' => 4, 'interactions' => 12], $youtube['kpis']);
    self::assertSame(320, $youtube['account']['subscribers']);
    $tiktok = $repository->summary($repository->filters(['pr_red' => 'tiktok', 'pr_desde' => '2026-09-01', 'pr_hasta' => '2026-09-30'], self::TODAY), false);
    self::assertSame(['videos' => 1, 'views' => 700, 'interactions' => 36], $tiktok['kpis']);
    $long = $repository->filters(['pr_desde' => '2020-01-01', 'pr_hasta' => '2026-11-03'], self::TODAY);
    self::assertSame(['2024-11-03', true], [$long['from'], $long['clamped']], 'Máximo 2 años.');
  }

  public function testViewHidesSpendWithoutPermissionAndNeverPrintsTokens(): void
  {
    $instagram = $this->page('13', []);
    self::assertStringContainsString('data-commercial-promotion="redes_sociales"', $instagram);
    self::assertStringContainsString('Apartamento &lt;b&gt;en&lt;/b&gt; Laureles', $instagram);
    self::assertStringNotContainsString('javascript:alert', $instagram);
    self::assertStringContainsString('Último dato de Instagram: 28/09/2026', $instagram);

    $withSpend = $this->page('6', ['pr_red' => 'publicidad']);
    self::assertStringContainsString('Inversión', $withSpend);
    self::assertStringContainsString('$15.389', $withSpend);
    $without = $this->page('7', ['pr_red' => 'publicidad']);
    self::assertStringNotContainsString('$15.389', $without);
    self::assertStringNotContainsString('44.867', $without);
    self::assertStringContainsString('solo los ve quien tiene el permiso', $without);

    $tiktok = $this->page('7', ['pr_red' => 'tiktok', 'pr_desde' => '2026-09-01', 'pr_hasta' => '2026-09-30']);
    self::assertStringContainsString('La última sincronización falló: Token vencido', $tiktok);
    foreach ([$this->page('7', ['pr_red' => 'youtube']), $tiktok] as $html) {
      self::assertStringNotContainsString('SECRETO', $html);
    }
    CommercialPromotionDatabase::login('8', '202');
    self::assertStringContainsString('no está disponible', PromotionView::page(['promocion_sub' => 'redes_sociales'], $this->policy(), $this->db, $this->settings, '/public', false, self::TODAY));
  }
}
