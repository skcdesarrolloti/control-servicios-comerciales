<?php

declare(strict_types=1);

namespace SCM\Commercial\Promotion\Pieces;

use SCM\Core\Database;

/**
 * Promoción › Banco de piezas sobre las tablas marketing_brand_assets y marketing_brand_asset_posts. Copia de
 * dashboard-marketing app/BrandAssetService.php, con su cálculo de impacto y sus validaciones. Los errores que debe ver el
 * usuario son InvalidArgumentException. created_by y updated_by guardan el id_empleado del funcionario.
 */
class BrandAssetService
{
  public const CATEGORIES = [
    'social' => 'Redes sociales', 'branding' => 'Branding', 'commercial' => 'Comercial', 'institutional' => 'Institucional',
    'web' => 'Web y digital', 'physical' => 'Pieza física', 'campaign' => 'Campaña',
  ];
  public const STATUSES = [
    'designed' => 'Diseñada', 'in_review' => 'En revisión', 'approved' => 'Aprobada', 'published' => 'Publicada',
    'implemented' => 'Implementada', 'adjustment' => 'Por ajustar',
  ];
  public const CHANNELS = [
    'instagram' => 'Instagram', 'facebook' => 'Facebook', 'whatsapp' => 'WhatsApp', 'web' => 'Sitio web', 'email' => 'Correo',
    'print' => 'Impreso', 'internal' => 'Comunicación interna',
  ];
  public const ASSET_LIMIT = 250;
  public const POST_LIMIT = 1000;
  private const IMPACT = '(views_count + reach_count + (clicks_count * 5) + (leads_count * 50) + (referrals_count * 75) + (acquisitions_count * 100))';

  private Database $db;
  private BrandAssetFiles $files;
  private string $table;
  private string $links;
  private string $posts;

  public function __construct(Database $db, BrandAssetFiles $files)
  {
    $this->db = $db;
    $this->files = $files;
    $this->table = $db->table('marketing_brand_assets');
    $this->links = $db->table('marketing_brand_asset_posts');
    $this->posts = $db->table('marketing_social_posts');
  }

  /** Las tablas las creó dashboard-marketing; este panel no las crea. */
  public function available(): bool
  {
    try {
      $this->db->getVar("SELECT 1 FROM `{$this->table}` LIMIT 1");
      $this->db->getVar("SELECT 1 FROM `{$this->links}` LIMIT 1");
      return true;
    } catch (\Throwable) {
      return false;
    }
  }

  /**
   * @param array<string,string> $filters q, category, status, channel, from, to, active ('1' por defecto, '0' o 'all').
   * @return array{assets:list<array<string,mixed>>,summary:array<string,int>,channel_counts:array<string,int>,category_counts:array<string,int>,top:list<array<string,mixed>>,missing_files:int}
   */
  public function dashboard(array $filters): array
  {
    [$where, $params] = $this->where($filters);
    $assets = $this->db->getResults('SELECT *, ' . self::IMPACT . " AS impact_score FROM `{$this->table}` {$where} ORDER BY active DESC, creation_date DESC, id DESC LIMIT " . self::ASSET_LIMIT, $params);
    foreach ($assets as &$asset) {
      $asset['channels'] = self::decodeChannels((string) ($asset['channels_json'] ?? ''));
      $asset['file_ready'] = $this->files->path((string) ($asset['storage_path'] ?? '')) !== null;
    }
    unset($asset);
    $assets = $this->attachPosts($assets);

    $summary = $this->db->getRow("SELECT COUNT(*) AS total, SUM(status = 'approved') AS approved, SUM(status = 'implemented') AS implemented,
        SUM(status IN ('designed', 'in_review', 'adjustment')) AS pending, ROUND(AVG(NULLIF(brand_compliance, 0)), 0) AS consistency
      FROM `{$this->table}` WHERE active = 1") ?? [];
    $total = (int) ($summary['total'] ?? 0);
    $implemented = (int) ($summary['implemented'] ?? 0);

    $channels = array_fill_keys(array_keys(self::CHANNELS), 0);
    foreach ($this->db->getResults("SELECT channels_json FROM `{$this->table}` WHERE active = 1") as $row) {
      foreach (self::decodeChannels((string) ($row['channels_json'] ?? '')) as $channel) {
        $channels[$channel]++;
      }
    }
    arsort($channels);
    $categories = [];
    foreach ($this->db->getResults("SELECT category, COUNT(*) AS total FROM `{$this->table}` WHERE active = 1 GROUP BY category ORDER BY total DESC") as $row) {
      $categories[(string) $row['category']] = (int) $row['total'];
    }
    return [
      'assets' => $assets,
      'summary' => [
        'total' => $total, 'approved' => (int) ($summary['approved'] ?? 0), 'implemented' => $implemented, 'pending' => (int) ($summary['pending'] ?? 0),
        'consistency' => (int) ($summary['consistency'] ?? 0), 'progress' => $total > 0 ? (int) round($implemented * 100 / $total) : 0,
      ],
      'channel_counts' => $channels,
      'category_counts' => $categories,
      'top' => $this->db->getResults('SELECT id, title, category, ' . self::IMPACT . " AS impact_score FROM `{$this->table}` WHERE active = 1 ORDER BY impact_score DESC, implementation_date DESC, id DESC LIMIT 5"),
      'missing_files' => count(array_filter($assets, static fn(array $asset): bool => !$asset['file_ready'])),
    ];
  }

  /** Publicaciones reales de Meta para vincular. @return list<array<string,mixed>> */
  public function socialPosts(): array
  {
    $rows = $this->db->getResults("SELECT p._ID, p.post_date, p.platform, p.content_type, p.title, p.permalink, p.thumbnail_url, p.reach, p.impressions, p.likes,
        p.comments, p.shares, p.saves, p.metrics_json, l.asset_id AS linked_asset_id, a.title AS linked_asset_title
      FROM `{$this->posts}` p LEFT JOIN `{$this->links}` l ON l.social_post_id = p._ID LEFT JOIN `{$this->table}` a ON a.id = l.asset_id
      WHERE p.source <> 'demo' AND p.external_id <> '' ORDER BY p.post_date DESC, p.post_time DESC, p._ID DESC LIMIT " . self::POST_LIMIT);
    return array_map(fn(array $row): array => $this->decoratePost($row), $rows);
  }

  /** @param list<mixed> $postIds Reemplaza todos los vínculos de la pieza. @return int Vínculos guardados. */
  public function replacePostLinks(int $assetId, array $postIds, int $userId): int
  {
    if ($assetId <= 0 || $this->find($assetId) === []) {
      throw new \InvalidArgumentException('La pieza seleccionada no existe.');
    }
    $ids = array_values(array_unique(array_filter(array_map(static fn($id): int => is_scalar($id) ? (int) $id : 0, $postIds), static fn(int $id): bool => $id > 0)));
    if (count($ids) > self::POST_LIMIT) {
      throw new \InvalidArgumentException('Selecciona máximo 1.000 publicaciones por pieza.');
    }
    if ($ids !== []) {
      $marks = implode(',', array_fill(0, count($ids), '?'));
      $valid = array_map('intval', $this->db->getCol("SELECT _ID FROM `{$this->posts}` WHERE _ID IN ({$marks}) AND source <> 'demo' AND external_id <> ''", $ids));
      if (count($valid) !== count($ids)) {
        throw new \InvalidArgumentException('Una de las publicaciones seleccionadas ya no está disponible.');
      }
      $conflict = $this->db->getRow("SELECT a.title FROM `{$this->links}` l JOIN `{$this->table}` a ON a.id = l.asset_id WHERE l.social_post_id IN ({$marks}) AND l.asset_id <> ? LIMIT 1", [...$ids, $assetId]);
      if ($conflict !== null) {
        throw new \InvalidArgumentException('Una publicación seleccionada ya está vinculada a “' . (string) $conflict['title'] . '”.');
      }
    }
    $pdo = $this->db->pdo();
    $pdo->beginTransaction();
    try {
      $this->db->delete($this->links, ['asset_id' => $assetId]);
      $now = date('Y-m-d H:i:s');
      foreach ($ids as $postId) {
        $this->db->insert($this->links, ['asset_id' => $assetId, 'social_post_id' => $postId, 'created_by' => $userId ?: null, 'created_at' => $now]);
      }
      $pdo->commit();
    } catch (\Throwable $exception) {
      $pdo->rollBack();
      throw $exception;
    }
    return count($ids);
  }

  /** @return array<string,mixed> */
  public function find(int $id): array
  {
    if ($id <= 0) {
      return [];
    }
    $asset = $this->db->getRow("SELECT * FROM `{$this->table}` WHERE id = ? LIMIT 1", [$id]) ?? [];
    if ($asset !== []) {
      $asset['channels'] = self::decodeChannels((string) ($asset['channels_json'] ?? ''));
    }
    return $asset;
  }

  /**
   * @param array<string,mixed> $input
   * @param array<string,mixed> $file Entrada de $_FILES (asset_file); obligatoria al crear.
   */
  public function save(array $input, array $file, int $userId): int
  {
    $id = max(0, (int) ($input['id'] ?? 0));
    $existing = $id > 0 ? $this->find($id) : [];
    if ($id > 0 && $existing === []) {
      throw new \InvalidArgumentException('La pieza que intentas editar ya no existe.');
    }
    $title = self::text($input, 'title', 255);
    if ($title === null) {
      throw new \InvalidArgumentException('Escribe el nombre de la pieza.');
    }
    $category = (string) ($input['category'] ?? '');
    if (!isset(self::CATEGORIES[$category])) {
      throw new \InvalidArgumentException('Selecciona una categoría válida.');
    }
    $status = (string) ($input['status'] ?? '');
    if (!isset(self::STATUSES[$status])) {
      throw new \InvalidArgumentException('Selecciona un estado válido.');
    }
    $sent = is_array($input['channels'] ?? null) ? array_map(static fn($value): string => is_scalar($value) ? (string) $value : '', $input['channels']) : [];
    $channels = array_values(array_intersect(array_keys(self::CHANNELS), $sent));
    if ($channels === []) {
      throw new \InvalidArgumentException('Selecciona al menos un canal de uso.');
    }
    $creation = (string) ($input['creation_date'] ?? '');
    if (!self::validDate($creation)) {
      throw new \InvalidArgumentException('La fecha de creación no es válida.');
    }
    $code = self::text($input, 'asset_code', 80);
    if ($code !== null && (int) $this->db->getVar("SELECT COUNT(*) FROM `{$this->table}` WHERE asset_code = ? AND id <> ?", [$code, $id]) > 0) {
      throw new \InvalidArgumentException('Ya existe otra pieza con ese código.');
    }
    $now = date('Y-m-d H:i:s');
    $data = [
      'title' => $title, 'asset_code' => $code, 'description' => self::text($input, 'description', 3000), 'category' => $category, 'status' => $status,
      'channels_json' => json_encode($channels, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
      'campaign_name' => self::text($input, 'campaign_name', 191), 'designer_name' => self::text($input, 'designer_name', 191),
      'approver_name' => self::text($input, 'approver_name', 191), 'implementer_name' => self::text($input, 'implementer_name', 191),
      'creation_date' => $creation,
      'approval_date' => self::optionalDate($input, 'approval_date', 'La fecha de aprobación no es válida.'),
      'publication_date' => self::optionalDate($input, 'publication_date', 'La fecha de publicación no es válida.'),
      'implementation_date' => self::optionalDate($input, 'implementation_date', 'La fecha de implementación no es válida.'),
      'brand_compliance' => self::number($input, 'brand_compliance', 100),
      'views_count' => self::number($input, 'views_count'), 'reach_count' => self::number($input, 'reach_count'), 'clicks_count' => self::number($input, 'clicks_count'),
      'leads_count' => self::number($input, 'leads_count'), 'referrals_count' => self::number($input, 'referrals_count'), 'acquisitions_count' => self::number($input, 'acquisitions_count'),
      'observations' => self::text($input, 'observations', 5000), 'updated_by' => $userId ?: null, 'updated_at' => $now,
    ];
    $upload = $this->files->store($file);
    if ($id === 0 && $upload === null) {
      throw new \InvalidArgumentException('Adjunta el arte final de la pieza.');
    }
    try {
      if ($id === 0) {
        $this->db->insert($this->table, $data + $upload + ['active' => 1, 'created_by' => $userId ?: null, 'created_at' => $now]);
        return (int) $this->db->lastInsertId();
      }
      $this->db->update($this->table, $data + ($upload ?? []), ['id' => $id]);
    } catch (\Throwable $exception) {
      if ($upload !== null) {
        $this->files->remove($upload['storage_path']);
      }
      throw $exception;
    }
    if ($upload !== null) {
      $this->files->remove((string) ($existing['storage_path'] ?? ''));
    }
    return $id;
  }

  public function setActive(int $id, bool $active, int $userId): void
  {
    if ($this->find($id) === []) {
      throw new \InvalidArgumentException('La pieza seleccionada no existe.');
    }
    $this->db->update($this->table, ['active' => $active ? 1 : 0, 'updated_by' => $userId ?: null, 'updated_at' => date('Y-m-d H:i:s')], ['id' => $id]);
  }

  /** @param list<array<string,mixed>> $assets @return list<array<string,mixed>> */
  private function attachPosts(array $assets): array
  {
    if ($assets === []) {
      return [];
    }
    $positions = [];
    foreach ($assets as $index => &$asset) {
      $positions[(int) $asset['id']] = $index;
      $asset['linked_posts'] = [];
      $asset['social_kpis'] = ['reach' => 0, 'views' => 0, 'interactions' => 0, 'clicks' => 0];
    }
    unset($asset);
    $ids = array_keys($positions);
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $rows = $this->db->getResults("SELECT l.asset_id, p._ID, p.post_date, p.platform, p.content_type, p.title, p.permalink, p.thumbnail_url, p.reach, p.impressions, p.likes, p.comments, p.shares, p.saves, p.metrics_json
      FROM `{$this->links}` l JOIN `{$this->posts}` p ON p._ID = l.social_post_id WHERE l.asset_id IN ({$marks}) ORDER BY p.post_date DESC, p.post_time DESC, p._ID DESC", $ids);
    foreach ($rows as $row) {
      $index = $positions[(int) $row['asset_id']] ?? null;
      if ($index === null) {
        continue;
      }
      $post = $this->decoratePost($row);
      $assets[$index]['linked_posts'][] = $post;
      foreach (['reach', 'views', 'interactions', 'clicks'] as $metric) {
        $assets[$index]['social_kpis'][$metric] += (int) ($post[$metric] ?? 0);
      }
    }
    return $assets;
  }

  /** @param array<string,mixed> $row @return array<string,mixed> */
  private function decoratePost(array $row): array
  {
    $metrics = json_decode((string) ($row['metrics_json'] ?? ''), true);
    $metrics = is_array($metrics) ? $metrics : [];
    $base = (int) ($row['likes'] ?? 0) + (int) ($row['comments'] ?? 0) + (int) ($row['shares'] ?? 0) + (int) ($row['saves'] ?? 0);
    $row['views'] = max((int) ($row['impressions'] ?? 0), self::metric($metrics, ['views', 'post_media_view', 'post_video_views']));
    $row['interactions'] = max($base, self::metric($metrics, ['total_interactions']));
    $row['clicks'] = self::metric($metrics, ['post_clicks', 'clicks', 'website_clicks']);
    $row['permalink'] = self::safeUrl($row['permalink'] ?? '');
    $row['thumbnail_url'] = self::safeUrl($row['thumbnail_url'] ?? '');
    unset($row['metrics_json']);
    return $row;
  }

  /** @param array<string,mixed> $metrics @param list<string> $keys */
  private static function metric(array $metrics, array $keys): int
  {
    foreach ($keys as $key) {
      if (!array_key_exists($key, $metrics)) {
        continue;
      }
      $value = $metrics[$key];
      if (is_array($value)) {
        $value = $value['value'] ?? $value['total_value']['value'] ?? 0;
      }
      return max(0, is_scalar($value) ? (int) $value : 0);
    }
    return 0;
  }

  private static function safeUrl(mixed $value): string
  {
    $url = trim(is_scalar($value) ? (string) $value : '');
    return filter_var($url, FILTER_VALIDATE_URL) !== false && preg_match('#^https?://#i', $url) === 1 ? $url : '';
  }

  /** @param array<string,string> $filters @return array{0:string,1:list<mixed>} */
  private function where(array $filters): array
  {
    $clauses = [];
    $params = [];
    $active = (string) ($filters['active'] ?? '1');
    if ($active !== 'all') {
      $clauses[] = 'active = ?';
      $params[] = $active === '0' ? 0 : 1;
    }
    $q = trim((string) ($filters['q'] ?? ''));
    if ($q !== '') {
      $clauses[] = '(title LIKE ? OR asset_code LIKE ? OR campaign_name LIKE ? OR designer_name LIKE ?)';
      $like = '%' . $q . '%';
      array_push($params, $like, $like, $like, $like);
    }
    foreach (['category' => self::CATEGORIES, 'status' => self::STATUSES] as $key => $allowed) {
      $value = trim((string) ($filters[$key] ?? ''));
      if (isset($allowed[$value])) {
        $clauses[] = "{$key} = ?";
        $params[] = $value;
      }
    }
    $channel = trim((string) ($filters['channel'] ?? ''));
    if (isset(self::CHANNELS[$channel])) {
      $clauses[] = 'channels_json LIKE ?';
      $params[] = '%"' . $channel . '"%';
    }
    foreach (['from' => 'creation_date >= ?', 'to' => 'creation_date <= ?'] as $key => $expression) {
      $value = trim((string) ($filters[$key] ?? ''));
      if (self::validDate($value)) {
        $clauses[] = $expression;
        $params[] = $value;
      }
    }
    return [$clauses !== [] ? 'WHERE ' . implode(' AND ', $clauses) : '', $params];
  }

  /** @return list<string> */
  public static function decodeChannels(string $json): array
  {
    $decoded = json_decode($json, true);
    return is_array($decoded) ? array_values(array_intersect(array_keys(self::CHANNELS), array_map(static fn($value): string => is_scalar($value) ? (string) $value : '', $decoded))) : [];
  }

  /** @param array<string,mixed> $input */
  private static function text(array $input, string $key, int $max): ?string
  {
    $value = trim(is_scalar($input[$key] ?? null) ? (string) $input[$key] : '');
    return $value === '' ? null : mb_substr($value, 0, $max, 'UTF-8');
  }

  /** @param array<string,mixed> $input */
  private static function optionalDate(array $input, string $key, string $message): ?string
  {
    $value = trim(is_scalar($input[$key] ?? null) ? (string) $input[$key] : '');
    if ($value === '') {
      return null;
    }
    if (!self::validDate($value)) {
      throw new \InvalidArgumentException($message);
    }
    return $value;
  }

  private static function validDate(string $value): bool
  {
    $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value;
  }

  /** @param array<string,mixed> $input */
  private static function number(array $input, string $key, int $max = PHP_INT_MAX): int
  {
    $raw = trim(is_scalar($input[$key] ?? null) ? (string) $input[$key] : '0');
    if ($raw === '') {
      return 0;
    }
    if (preg_match('/^\d+$/', $raw) !== 1) {
      throw new \InvalidArgumentException('Los indicadores deben ser números enteros positivos.');
    }
    return min($max, (int) $raw);
  }
}
