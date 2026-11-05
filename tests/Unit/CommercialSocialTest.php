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

  public function testDefaultRangeEndsAtTheLatestDataAndInstagramIsMeasuredFromItsPosts(): void
  {
    $repository = new SocialStatsRepository($this->db);
    $filters = $repository->filters([], self::TODAY);
    self::assertSame(['instagram', '2026-07-01', '2026-09-28', '2026-09-28'], [$filters['network'], $filters['from'], $filters['to'], $filters['latest']]);
    $data = $repository->summary($filters, false);
    self::assertSame(['followers' => 1751, 'exposure' => 550, 'interactions' => 27, 'rate' => 4.91, 'profile_views' => 15, 'website_clicks' => 6, 'posts' => 2], $data['kpis']);
    self::assertSame('reach', $data['exposure']);
    self::assertSame('Apartamento <b>en</b> Laureles', $data['top'][0]['title']);
    self::assertSame(26, $data['top'][0]['interactions']);
    self::assertCount(2, $data['posts']);
    self::assertSame('Septiembre 2026', SocialStatsRepository::months($data['posts'], 'date')[0]['label']);
    self::assertSame('2026-09-28', $data['audience']['snapshot']);
    self::assertSame(['25-34', '35-44'], array_column($data['audience']['groups']['age'], 'label'), 'Solo la última foto.');
    $long = $repository->filters(['pr_desde' => '2020-01-01', 'pr_hasta' => '2026-11-03'], self::TODAY);
    self::assertSame(['2024-11-03', true], [$long['from'], $long['clamped']], 'Máximo 2 años.');
  }

  public function testPeriodsAreGroupedAndComparedLikeTheOriginalPanel(): void
  {
    self::assertSame('day', SocialStatsRepository::granularity('2026-09-01', '2026-10-15'));
    self::assertSame('week', SocialStatsRepository::granularity('2026-01-01', '2026-11-01'));
    self::assertSame('month', SocialStatsRepository::granularity('2025-01-01', '2026-11-01'));
    $rows = [['day' => '2026-09-07', 'views' => 10, 'followers' => 5], ['day' => '2026-09-13', 'views' => 20, 'followers' => 6], ['day' => '2026-09-14', 'views' => 1, 'followers' => 7]];
    $weeks = SocialStatsRepository::group($rows, 'day', ['views'], ['followers'], 'week');
    self::assertSame([[30.0, 6, '07 sep'], [1.0, 7, '14 sep']], array_map(static fn(array $row): array => [$row['views'], $row['followers'], $row['short']], $weeks));
    self::assertSame('Septiembre 2026', SocialStatsRepository::group($rows, 'day', ['views'], [], 'month')[0]['label']);

    $repository = new SocialStatsRepository($this->db);
    $day = fn(string $date, int $followers, int $impressions) => $this->db->insert('wp_marketing_social_daily_stats', ['metric_date' => $date, 'platform' => 'facebook', 'followers' => $followers, 'reach' => 0, 'impressions' => $impressions, 'profile_views' => 1, 'website_clicks' => 0, 'likes' => 5, 'comments' => 0, 'shares' => 0, 'saves' => 0, 'content_count' => 1]);
    $day('2026-09-01', 100, 50);
    $day('2026-09-02', 110, 150);
    $partial = $repository->summary(['network' => 'facebook', 'from' => '2026-09-02', 'to' => '2026-09-03'], false);
    self::assertFalse($partial['comparison']['ready'], 'Hace falta todo el periodo anterior.');
    $data = $repository->summary(['network' => 'facebook', 'from' => '2026-09-02', 'to' => '2026-09-02'], false);
    self::assertSame('impressions', $data['exposure'], 'Facebook mide visualizaciones.');
    self::assertSame([150, 110, 3.33], [$data['kpis']['exposure'], $data['kpis']['followers'], $data['kpis']['rate']]);
    self::assertSame([true, '2026-09-01', '2026-09-01', 1, 1], [$data['comparison']['ready'], $data['comparison']['previous_from'], $data['comparison']['previous_to'], $data['comparison']['requested'], $data['comparison']['covered']]);
    self::assertSame(50, $data['previous']['exposure']);
  }

  public function testAdsShowTheLastPeriodWithActivityAndHideSpendWithoutPermission(): void
  {
    $repository = new SocialStatsRepository($this->db);
    $filters = $repository->filters(['pr_red' => 'publicidad'], self::TODAY);
    $ads = $repository->summary($filters, true);
    self::assertSame(['from' => '2025-01-01', 'to' => '2026-08-06', 'exact' => false], $ads['period'], 'Una foto en cero no borra lo acumulado.');
    self::assertSame([9527, 7000, 202, 150, 44867.0], [$ads['kpis']['impressions'], $ads['kpis']['reach'], $ads['kpis']['clicks'], $ads['kpis']['link_clicks'], $ads['kpis']['spend']]);
    self::assertSame(44867 / 202, $ads['kpis']['cpc']);
    self::assertSame(['20 años construyendo'], array_column($ads['campaigns'], 'name'));
    self::assertSame(['Anuncio feria'], array_column($ads['ads'], 'name'));
    self::assertSame([['type' => 'link_click', 'value' => 90], ['type' => 'lead', 'value' => 3]], $ads['campaigns'][0]['actions']);
    $exact = $repository->summary(['network' => 'publicidad', 'from' => '2026-07-11', 'to' => '2026-07-11'], true);
    self::assertSame(['from' => '2026-07-11', 'to' => '2026-07-11', 'exact' => true], $exact['period']);

    $plain = $repository->summary($filters, false);
    self::assertArrayNotHasKey('spend', $plain['kpis']);
    self::assertArrayNotHasKey('cpc', $plain['kpis']);
    foreach (['campaigns', 'ads'] as $list) {
      self::assertArrayNotHasKey('spend', $plain[$list][0]);
      self::assertArrayNotHasKey('cpm', $plain[$list][0]);
    }
    self::assertArrayNotHasKey('spend', $plain['series'][0]);
  }

  public function testYoutubeAndTiktokOnlyReadTheConnectedAccount(): void
  {
    $repository = new SocialStatsRepository($this->db);
    $youtube = $repository->summary($repository->filters(['pr_red' => 'youtube'], self::TODAY), false);
    self::assertSame(250, $youtube['kpis']['views'], 'Sin el canal viejo.');
    self::assertSame(['Recorrido casa'], array_column($youtube['videos'], 'title'));
    self::assertSame([40 * 2, 241, 75.0, 50.0, 4, 12, 2], [$youtube['kpis']['engaged_views'], $youtube['kpis']['watch_minutes'], $youtube['kpis']['average_view_duration'], $youtube['kpis']['average_view_percentage'], $youtube['kpis']['subscribers_net'], $youtube['kpis']['interactions'], $youtube['kpis']['videos_published']]);
    self::assertSame(320, $youtube['account']['subscribers']);
    self::assertSame(['from' => '2026-07-01', 'to' => '2026-08-05', 'exact' => false], $youtube['breakdowns_period'], 'Sin el periodo exacto, el último guardado.');
    self::assertSame('SHORTS', $youtube['breakdowns']['creator_content_type'][0]['label']);
    $exact = $repository->summary(['network' => 'youtube', 'from' => '2026-07-01', 'to' => '2026-08-05'], false);
    self::assertTrue($exact['breakdowns_period']['exact']);

    $tiktok = $repository->summary($repository->filters(['pr_red' => 'tiktok', 'pr_desde' => '2026-09-01', 'pr_hasta' => '2026-09-30'], self::TODAY), false);
    self::assertSame(['videos' => 1, 'views' => 700, 'likes' => 30, 'comments' => 4, 'shares' => 2, 'interactions' => 36], $tiktok['kpis']);
    self::assertSame([700, 36], [(int) $tiktok['series'][0]['views'], (int) $tiktok['series'][0]['interactions']]);
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
    self::assertStringContainsString('$44.867', $withSpend);
    self::assertStringContainsString('Inversión: $15.389', $withSpend);
    self::assertStringContainsString('data-social-ads', $withSpend);
    $without = $this->page('7', ['pr_red' => 'publicidad']);
    self::assertStringNotContainsString('44.867', $without);
    self::assertStringNotContainsString('15.389', $without);
    self::assertStringNotContainsString('CPM', str_replace('el CPM solo los ve', '', $without));
    self::assertStringContainsString('Leads', $without, 'El detalle conserva las acciones.');
    self::assertStringContainsString('solo los ve quien tiene el permiso', $without);

    $tiktok = $this->page('7', ['pr_red' => 'tiktok', 'pr_desde' => '2026-09-01', 'pr_hasta' => '2026-09-30']);
    self::assertStringContainsString('La última sincronización falló: Token vencido', $tiktok);
    self::assertStringContainsString('Vistas por día', $tiktok);
    $youtube = $this->page('7', ['pr_red' => 'youtube']);
    foreach (['Rendimiento por formato', 'Shorts', 'Descubrimiento y audiencia', 'Búsqueda de YouTube', 'Videos publicados', 'Videos destacados', 'Vistas con interés', 'Último periodo guardado: 01/07/2026 a 05/08/2026'] as $text) {
      self::assertStringContainsString($text, $youtube);
    }
    self::assertStringNotContainsString('Canal viejo', $youtube);
    self::assertStringContainsString('+50,0% vs anterior', $this->page('7', ['pr_red' => 'youtube', 'pr_desde' => '2026-08-05', 'pr_hasta' => '2026-08-05']));
    foreach (['Publicaciones destacadas', 'Publicaciones realizadas', 'Tasa de interacción', 'Sin base anterior', 'data-social-detail='] as $text) {
      self::assertStringContainsString($text, $this->page('13', []));
    }
    foreach ([$this->page('7', ['pr_red' => 'youtube']), $tiktok] as $html) {
      self::assertStringNotContainsString('SECRETO', $html);
    }
    CommercialPromotionDatabase::login('8', '202');
    self::assertStringContainsString('no está disponible', PromotionView::page(['promocion_sub' => 'redes_sociales'], $this->policy(), $this->db, $this->settings, '/public', false, self::TODAY));
  }
}
