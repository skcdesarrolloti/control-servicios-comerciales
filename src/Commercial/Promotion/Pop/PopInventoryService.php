<?php

declare(strict_types=1);

namespace SCM\Commercial\Promotion\Pop;

use SCM\Core\Database;
use SCM\Core\Settings;

/**
 * Promoción › Material POP: existencias, entregas y movimientos del inventario de souvenirs y material promocional, con
 * la sincronización del Club PPH (jet_cct_inventario_pph y jet_cct_recompensas_pph).
 *
 * Copia/adaptación de dashboard-marketing app/InventoryService.php (mantener ambas iguales mientras convivan): usa las
 * mismas tablas marketing_inventory_* y las mismas reglas (cada cambio de existencias deja un movimiento con saldo
 * antes/después; una entrega descuenta todos sus productos o ninguno; anular repone y deja el motivo; las entregas
 * importadas de PPH no se anulan). Cambios frente al original:
 * - La sincronización con PPH primero compara sin bloquear y solo abre transacción si hay algo que cambiar (el original
 *   bloqueaba los productos en cada carga de la pantalla).
 * - Sin el permiso de costos no se lee ni se cambia el precio.
 * - Archivar pide motivo y deja un movimiento (también al reactivar).
 * - Las salidas que cruzan el stock mínimo encolan la alerta de PopStockAlert.
 * - created_by y cct_author_id guardan el id_empleado real; el origen de lo hecho aquí es 'comercial'.
 */
final class PopInventoryService
{
  public const SOURCE = 'comercial';
  public const HISTORY_LIMIT = 150;
  public const MOVEMENT_LIMIT = 200;

  public const MOVEMENT_LABELS = [
    'opening' => 'Saldo inicial',
    'entry' => 'Entrada',
    'adjustment_out' => 'Salida por ajuste',
    'delivery' => 'Entrega',
    'reversal' => 'Anulación de entrega',
    'delivery_cancelled' => 'Anulación de entrega',
    'pph_reconciliation' => 'Conciliación PPH',
    'archive' => 'Archivado',
    'restore' => 'Reactivado',
  ];

  public const RECIPIENT_TYPES = ['Funcionario', 'Cliente', 'Propietario', 'Arrendatario', 'Proveedor', 'Aliado', 'Club PPH', 'Otro'];

  private Database $db;
  private Settings $settings;
  private PopFiles $files;
  private PopStockAlert $alert;
  private string $products;
  private string $deliveries;
  private string $items;
  private string $movements;
  private string $evidence;
  private string $pphProducts;
  private string $pphRewards;
  private bool $mysql;
  /** @var array<string,bool> */
  private array $tables = [];

  public function __construct(Database $db, Settings $settings, ?PopFiles $files = null, ?PopStockAlert $alert = null)
  {
    $this->db = $db;
    $this->settings = $settings;
    $this->files = $files ?? new PopFiles();
    $this->alert = $alert ?? new PopStockAlert($db, $settings);
    $this->products = $db->table('marketing_inventory_products');
    $this->deliveries = $db->table('marketing_inventory_deliveries');
    $this->items = $db->table('marketing_inventory_delivery_items');
    $this->movements = $db->table('marketing_inventory_movements');
    $this->evidence = $db->table('marketing_inventory_evidence');
    $this->pphProducts = $db->table('jet_cct_inventario_pph');
    $this->pphRewards = $db->table('jet_cct_recompensas_pph');
    $this->mysql = $db->pdo()->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql';
  }

  /** Las tablas las crea dashboard-marketing (InventorySchema); el panel no corre DDL en una petición web. */
  public function available(): bool
  {
    foreach ([$this->products, $this->deliveries, $this->items, $this->movements] as $table) {
      if (!$this->tableExists($table)) {
        return false;
      }
    }
    return true;
  }

  // ── Sincronización con PPH ──────────────────────────────────────────────

  /** @return array{products_created:int,products_updated:int,deliveries_imported:int} */
  public function syncFromPph(?string $employee = null): array
  {
    $summary = ['products_created' => 0, 'products_updated' => 0, 'deliveries_imported' => 0];
    if (!$this->tableExists($this->pphProducts)) {
      return $summary;
    }
    $hasRewards = $this->tableExists($this->pphRewards);
    if (!$this->pphPending($hasRewards)) {
      return $summary;
    }

    $pdo = $this->db->pdo();
    $pdo->beginTransaction();
    try {
      $sources = $this->db->getResults("SELECT _ID, cct_status, cct_modified, cantidad, precio, valor_punto, titulo, img FROM `{$this->pphProducts}` ORDER BY _ID");
      $byPph = [];
      foreach ($this->db->getResults("SELECT * FROM `{$this->products}` WHERE pph_product_id IS NOT NULL" . $this->forUpdate()) as $row) {
        $byPph[(int) $row['pph_product_id']] = $row;
      }
      foreach ($sources as $source) {
        $pphId = (int) ($source['_ID'] ?? 0);
        if ($pphId <= 0) {
          continue;
        }
        $quantity = max(0, (int) ($source['cantidad'] ?? 0));
        $hash = self::pphHash($source);
        $existing = $byPph[$pphId] ?? null;
        $now = $this->now();
        if ($existing === null) {
          $productId = $this->insert($this->products, [
            'name' => trim((string) ($source['titulo'] ?? '')) ?: 'Producto PPH ' . $pphId,
            'type' => 'PPH', 'sku' => null, 'description' => null, 'image_url' => trim((string) ($source['img'] ?? '')),
            'quantity' => $quantity, 'unit' => 'unidad', 'minimum_stock' => 0,
            'price' => self::decimal($source['precio'] ?? null), 'points' => self::integer($source['valor_punto'] ?? null),
            'active' => self::pphActive($source) ? 1 : 0, 'sync_pph' => 1, 'pph_product_id' => $pphId, 'pph_quantity' => $quantity,
            'pph_hash' => $hash, 'pph_modified_at' => self::date($source['cct_modified'] ?? null),
            'created_by' => null, 'updated_by' => null, 'created_at' => $now, 'updated_at' => $now,
          ]);
          $this->movement($productId, null, 'opening', $quantity, 0, $quantity, 'Saldo inicial importado desde PPH.', 'pph', null);
          $byPph[$pphId] = ['id' => $productId, 'pph_product_id' => $pphId, 'sync_pph' => 1, 'pph_hash' => $hash];
          $summary['products_created']++;
          continue;
        }
        if (!(int) ($existing['sync_pph'] ?? 0) || (string) ($existing['pph_hash'] ?? '') === $hash) {
          continue;
        }
        $before = (int) ($existing['quantity'] ?? 0);
        $this->db->update($this->products, [
          'name' => trim((string) ($source['titulo'] ?? '')) ?: (string) $existing['name'],
          'image_url' => trim((string) ($source['img'] ?? '')), 'quantity' => $quantity,
          'price' => self::decimal($source['precio'] ?? null), 'points' => self::integer($source['valor_punto'] ?? null),
          'active' => self::pphActive($source) ? 1 : 0, 'pph_quantity' => $quantity, 'pph_hash' => $hash,
          'pph_modified_at' => self::date($source['cct_modified'] ?? null), 'updated_at' => $now,
        ], ['id' => (int) $existing['id']]);
        if ($quantity !== $before) {
          $this->movement((int) $existing['id'], null, 'pph_reconciliation', $quantity - $before, $before, $quantity, 'Conciliación automática por cambio de existencias en PPH.', 'pph', $employee);
        }
        $summary['products_updated']++;
      }

      if ($hasRewards) {
        $imported = array_flip(array_map('intval', $this->db->getCol("SELECT pph_reward_id FROM `{$this->deliveries}` WHERE pph_reward_id IS NOT NULL")));
        $rewards = $this->db->getResults("SELECT _ID, cct_author_id, cct_created, fecha, cantidad, id_pph, nombre, tarjeta_bienvenida, id_producto, producto, puntos FROM `{$this->pphRewards}` ORDER BY _ID");
        foreach ($rewards as $reward) {
          $rewardId = (int) ($reward['_ID'] ?? 0);
          if ($rewardId > 0 && !isset($imported[$rewardId])) {
            $this->importReward($reward);
            $imported[$rewardId] = true;
            $summary['deliveries_imported']++;
          }
        }
      }
      $pdo->commit();
    } catch (\Throwable $exception) {
      if ($pdo->inTransaction()) {
        $pdo->rollBack();
      }
      throw $exception;
    }
    return $summary;
  }

  /** Comparación sin bloqueos: productos de PPH nuevos o con cambios y recompensas sin importar. */
  private function pphPending(bool $hasRewards): bool
  {
    $known = [];
    foreach ($this->db->getResults("SELECT pph_product_id, sync_pph, pph_hash FROM `{$this->products}` WHERE pph_product_id IS NOT NULL") as $row) {
      $known[(int) $row['pph_product_id']] = $row;
    }
    foreach ($this->db->getResults("SELECT _ID, cct_status, cantidad, precio, valor_punto, titulo, img FROM `{$this->pphProducts}`") as $source) {
      $row = $known[(int) ($source['_ID'] ?? 0)] ?? null;
      if ($row === null || ((int) $row['sync_pph'] === 1 && (string) $row['pph_hash'] !== self::pphHash($source))) {
        return true;
      }
    }
    if (!$hasRewards) {
      return false;
    }
    return (int) $this->db->getVar("SELECT COUNT(*) FROM `{$this->pphRewards}` r WHERE NOT EXISTS (SELECT 1 FROM `{$this->deliveries}` d WHERE d.pph_reward_id = r._ID)") > 0;
  }

  /** @param array<string,mixed> $reward */
  private function importReward(array $reward): void
  {
    $rewardId = (int) $reward['_ID'];
    $timestamp = (int) ($reward['fecha'] ?? 0);
    $created = (string) ($reward['cct_created'] ?? '') ?: $this->now();
    $deliveryId = $this->insert($this->deliveries, [
      'delivered_at' => $timestamp > 0 ? date('Y-m-d H:i:s', $timestamp) : $created,
      'recipient_name' => trim((string) ($reward['nombre'] ?? '')) ?: 'Destinatario PPH',
      'recipient_type' => 'Club PPH', 'recipient_id' => self::text($reward['id_pph'] ?? null), 'recipient_source' => 'pph',
      'recipient_document' => self::text($reward['tarjeta_bienvenida'] ?? null), 'recipient_metadata' => null,
      'notes' => 'Entrega importada desde recompensas PPH.', 'status' => 'delivered', 'source' => 'pph',
      'external_id' => 'pph-reward-' . $rewardId, 'pph_reward_id' => $rewardId, 'created_by' => self::integer($reward['cct_author_id'] ?? null),
      'cancelled_by' => null, 'cancelled_at' => null, 'cancellation_reason' => null, 'created_at' => $created, 'updated_at' => $created,
    ]);
    $pphProductId = (int) ($reward['id_producto'] ?? 0);
    $product = $pphProductId > 0 ? $this->db->getRow("SELECT id, name, type, price, points FROM `{$this->products}` WHERE pph_product_id = ? LIMIT 1", [$pphProductId]) : null;
    $this->insert($this->items, [
      'delivery_id' => $deliveryId, 'product_id' => $product['id'] ?? null,
      'product_name' => trim((string) ($reward['producto'] ?? '')) ?: (string) ($product['name'] ?? 'Producto PPH'),
      'product_type' => $product['type'] ?? 'PPH', 'quantity' => max(1, (int) ($reward['cantidad'] ?? 1)),
      'unit_price' => $product['price'] ?? null, 'points' => self::integer($reward['puntos'] ?? ($product['points'] ?? null)),
      'pph_product_id' => $pphProductId > 0 ? $pphProductId : null, 'created_at' => $created,
    ]);
  }

  // ── Consultas ───────────────────────────────────────────────────────────

  /**
   * Productos filtrados y el resumen del inventario.
   * @param array{q?:string,estado?:string} $filters
   * @return array{products:list<array<string,mixed>>,summary:array<string,int|float>,types:list<string>}
   */
  public function stock(array $filters): array
  {
    $where = ['1=1'];
    $args = [];
    $q = trim((string) ($filters['q'] ?? ''));
    if ($q !== '') {
      $where[] = '(name LIKE ? OR sku LIKE ? OR type LIKE ?)';
      $term = '%' . $this->db->escapeLike($q) . '%';
      array_push($args, $term, $term, $term);
    }
    $where[] = match ((string) ($filters['estado'] ?? '')) {
      'archivados' => 'active = 0',
      'agotados' => 'active = 1 AND quantity = 0',
      'bajo' => 'active = 1 AND minimum_stock > 0 AND quantity <= minimum_stock',
      'todos' => '1=1',
      default => 'active = 1',
    };
    $products = $this->db->getResults("SELECT * FROM `{$this->products}` WHERE " . implode(' AND ', $where) . ' ORDER BY active DESC, CASE WHEN quantity = 0 THEN 0 ELSE 1 END, name ASC', $args);
    $row = $this->db->getRow("SELECT COUNT(*) total, SUM(CASE WHEN active = 1 THEN 1 ELSE 0 END) activos,
        COALESCE(SUM(CASE WHEN active = 1 THEN quantity ELSE 0 END), 0) unidades,
        SUM(CASE WHEN active = 1 AND quantity = 0 THEN 1 ELSE 0 END) agotados,
        SUM(CASE WHEN active = 1 AND minimum_stock > 0 AND quantity <= minimum_stock AND quantity > 0 THEN 1 ELSE 0 END) bajos,
        COALESCE(SUM(CASE WHEN active = 1 AND price IS NOT NULL THEN quantity * price ELSE 0 END), 0) valor
      FROM `{$this->products}`") ?? [];
    $summary = [
      'total' => (int) ($row['total'] ?? 0), 'activos' => (int) ($row['activos'] ?? 0), 'unidades' => (int) ($row['unidades'] ?? 0),
      'agotados' => (int) ($row['agotados'] ?? 0), 'bajos' => (int) ($row['bajos'] ?? 0), 'valor' => (float) ($row['valor'] ?? 0),
    ];
    $types = array_values(array_filter(array_map('strval', $this->db->getCol("SELECT DISTINCT type FROM `{$this->products}` WHERE type IS NOT NULL AND type <> '' ORDER BY type"))));
    return ['products' => $products, 'summary' => $summary, 'types' => $types];
  }

  /** Productos para los filtros (también archivados) y para entregar (activos con existencias). @return list<array<string,mixed>> */
  public function productOptions(): array
  {
    return $this->db->getResults("SELECT id, name, active, quantity, unit FROM `{$this->products}` ORDER BY active DESC, name");
  }

  /**
   * Entregas (las más recientes primero, hasta HISTORY_LIMIT) con sus productos y evidencias.
   * @param array<string,string> $filters q, desde, hasta, producto, origen, estado
   * @return list<array<string,mixed>>
   */
  public function deliveries(array $filters): array
  {
    $where = ['1=1'];
    $args = [];
    $q = trim((string) ($filters['q'] ?? ''));
    if ($q !== '') {
      $term = '%' . $this->db->escapeLike($q) . '%';
      $where[] = "(d.recipient_name LIKE ? OR d.recipient_id LIKE ? OR d.recipient_document LIKE ? OR d.recipient_source LIKE ? OR EXISTS (SELECT 1 FROM `{$this->items}` hi WHERE hi.delivery_id = d.id AND hi.product_name LIKE ?))";
      array_push($args, $term, $term, $term, $term, $term);
    }
    $this->dateRange($where, $args, 'd.delivered_at', $filters);
    if ((int) ($filters['producto'] ?? 0) > 0) {
      $where[] = "EXISTS (SELECT 1 FROM `{$this->items}` fi WHERE fi.delivery_id = d.id AND fi.product_id = ?)";
      $args[] = (int) $filters['producto'];
    }
    if (trim((string) ($filters['origen'] ?? '')) !== '') {
      $where[] = 'd.source = ?';
      $args[] = trim((string) $filters['origen']);
    }
    if (in_array($filters['estado'] ?? '', ['delivered', 'cancelled'], true)) {
      $where[] = 'd.status = ?';
      $args[] = $filters['estado'];
    }
    $rows = $this->db->getResults("SELECT d.* FROM `{$this->deliveries}` d WHERE " . implode(' AND ', $where) . ' ORDER BY d.delivered_at DESC, d.id DESC LIMIT ' . self::HISTORY_LIMIT, $args);
    if ($rows === []) {
      return [];
    }
    $ids = array_map(static fn(array $row): int => (int) $row['id'], $rows);
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $items = [];
    foreach ($this->db->getResults("SELECT delivery_id, product_id, product_name, quantity FROM `{$this->items}` WHERE delivery_id IN ({$marks}) ORDER BY id", $ids) as $item) {
      $items[(int) $item['delivery_id']][] = $item;
    }
    $evidence = $this->evidenceFor('delivery_id', $ids);
    foreach ($rows as &$row) {
      $row['items'] = $items[(int) $row['id']] ?? [];
      $row['evidence'] = $evidence[(int) $row['id']] ?? [];
    }
    unset($row);
    return $rows;
  }

  /**
   * Movimientos (los más recientes primero, hasta MOVEMENT_LIMIT) con producto, destinatario y evidencias.
   * @param array<string,string> $filters q, desde, hasta, producto, origen, tipo
   * @return list<array<string,mixed>>
   */
  public function movements(array $filters): array
  {
    $where = ['1=1'];
    $args = [];
    $q = trim((string) ($filters['q'] ?? ''));
    if ($q !== '') {
      $term = '%' . $this->db->escapeLike($q) . '%';
      $where[] = '(p.name LIKE ? OR m.reason LIKE ? OR d.recipient_name LIKE ?)';
      array_push($args, $term, $term, $term);
    }
    $this->dateRange($where, $args, 'm.created_at', $filters);
    foreach (['producto' => 'm.product_id', 'origen' => 'm.source', 'tipo' => 'm.movement_type'] as $key => $column) {
      $value = trim((string) ($filters[$key] ?? ''));
      if ($value !== '' && $value !== '0') {
        $where[] = $column . ' = ?';
        $args[] = $key === 'producto' ? (int) $value : $value;
      }
    }
    $rows = $this->db->getResults("SELECT m.*, p.name product_name, d.recipient_name FROM `{$this->movements}` m
        JOIN `{$this->products}` p ON p.id = m.product_id LEFT JOIN `{$this->deliveries}` d ON d.id = m.delivery_id
       WHERE " . implode(' AND ', $where) . ' ORDER BY m.created_at DESC, m.id DESC LIMIT ' . self::MOVEMENT_LIMIT, $args);
    if ($rows === []) {
      return [];
    }
    $evidence = $this->evidenceFor('movement_id', array_map(static fn(array $row): int => (int) $row['id'], $rows));
    foreach ($rows as &$row) {
      $row['evidence'] = $evidence[(int) $row['id']] ?? [];
    }
    unset($row);
    return $rows;
  }

  /** Orígenes y tipos con datos, para los filtros. @return array{delivery_sources:list<string>,movement_sources:list<string>,movement_types:list<string>} */
  public function filterOptions(): array
  {
    $col = fn(string $sql): array => array_values(array_filter(array_map('strval', $this->db->getCol($sql))));
    return [
      'delivery_sources' => $col("SELECT DISTINCT source FROM `{$this->deliveries}` WHERE source <> '' ORDER BY source"),
      'movement_sources' => $col("SELECT DISTINCT source FROM `{$this->movements}` WHERE source <> '' ORDER BY source"),
      'movement_types' => $col("SELECT DISTINCT movement_type FROM `{$this->movements}` WHERE movement_type <> '' ORDER BY movement_type"),
    ];
  }

  // ── Operaciones ─────────────────────────────────────────────────────────

  /**
   * Crea o edita un producto. Sin $withCosts el precio no se toca (ni al crear).
   * @param array<string,mixed> $input id, name, type, sku, unit, minimum_stock, price, points, description, sync_pph, initial_quantity
   */
  public function saveProduct(array $input, string $employee, bool $withCosts): int
  {
    $id = max(0, (int) ($input['id'] ?? 0));
    $name = trim((string) ($input['name'] ?? ''));
    if ($name === '' || mb_strlen($name, 'UTF-8') > 255) {
      throw new \InvalidArgumentException('Escribe el nombre del producto (máximo 255 caracteres).');
    }
    $type = PopConfig::categoryName((string) ($input['type'] ?? ''));
    $allowed = array_map(static fn(string $value): string => mb_strtolower($value, 'UTF-8'), $this->categories());
    if ($type === '' || !in_array(mb_strtolower($type, 'UTF-8'), $allowed, true)) {
      throw new \InvalidArgumentException('Elige una de las categorías de Configuración › Material POP.');
    }
    $sku = strtoupper(trim((string) ($input['sku'] ?? '')));
    $syncPph = filter_var($input['sync_pph'] ?? false, FILTER_VALIDATE_BOOLEAN);
    $initial = (int) ($input['initial_quantity'] ?? 0);
    if ($id === 0 && $initial < 0) {
      throw new \InvalidArgumentException('La cantidad inicial no puede ser negativa.');
    }
    $now = $this->now();
    $data = [
      'name' => $name, 'type' => $type, 'sku' => $sku !== '' ? $sku : null,
      'description' => trim((string) ($input['description'] ?? '')), 'unit' => trim((string) ($input['unit'] ?? '')) ?: 'unidad',
      'minimum_stock' => max(0, (int) ($input['minimum_stock'] ?? 0)), 'points' => self::integer($input['points'] ?? null),
      'sync_pph' => $syncPph ? 1 : 0, 'updated_by' => self::integer($employee), 'updated_at' => $now,
    ];
    if ($withCosts) {
      $data['price'] = self::decimal($input['price'] ?? null, true);
    }
    if ($sku !== '' && (int) $this->db->getVar("SELECT COUNT(*) FROM `{$this->products}` WHERE sku = ? AND id <> ?", [$sku, $id]) > 0) {
      throw new \InvalidArgumentException('El SKU ' . $sku . ' ya está asignado a otro producto.');
    }

    $image = $this->files->store('product_image', 1);
    $evidence = [];
    $pdo = $this->db->pdo();
    $previousImage = '';
    try {
      $evidence = $id === 0 ? $this->files->store('evidence', PopFiles::MAX_EVIDENCE) : [];
      if ($image !== []) {
        $data['image_url'] = $image[0];
      }
      $pdo->beginTransaction();
      if ($id > 0) {
        $product = $this->db->getRow("SELECT * FROM `{$this->products}` WHERE id = ?" . $this->forUpdate(), [$id]);
        if ($product === null) {
          throw new \InvalidArgumentException('El producto no existe.');
        }
        if (!(int) $product['active']) {
          throw new \InvalidArgumentException('El producto está archivado: reactívalo antes de editarlo.');
        }
        $previousImage = (string) ($product['image_url'] ?? '');
        $this->db->update($this->products, $data, ['id' => $id]);
      } else {
        $id = $this->insert($this->products, $data + [
          'quantity' => $initial, 'price' => null, 'image_url' => null, 'active' => 1, 'pph_product_id' => null, 'pph_quantity' => null,
          'pph_hash' => null, 'pph_modified_at' => null, 'created_by' => self::integer($employee), 'created_at' => $now,
        ]);
        $movementId = $this->movement($id, null, 'opening', $initial, 0, $initial, 'Saldo inicial del producto.', self::SOURCE, $employee);
        $this->attachEvidence($evidence, null, $movementId, $id);
      }
      if ($syncPph) {
        $this->pushToPph($id, $employee);
      }
      $pdo->commit();
    } catch (\Throwable $exception) {
      if ($pdo->inTransaction()) {
        $pdo->rollBack();
      }
      $this->files->remove(array_merge($image, $evidence));
      throw $exception;
    }
    if ($image !== [] && $previousImage !== '' && $previousImage !== $image[0]) {
      // Solo borra las imágenes guardadas por el panel (enlace firmado); las de PPH o del panel anterior se conservan.
      $this->files->remove([$previousImage]);
    }
    return $id;
  }

  /** Entrada (+) o salida (−) con motivo y evidencias opcionales. */
  public function adjustStock(int $productId, int $delta, string $reason, string $employee): void
  {
    $reason = trim($reason);
    if ($productId <= 0 || $delta === 0) {
      throw new \InvalidArgumentException('Indica una cantidad distinta de cero: positiva para entradas, negativa para salidas.');
    }
    if ($reason === '' || mb_strlen($reason, 'UTF-8') > 500) {
      throw new \InvalidArgumentException('Escribe el motivo del ajuste (máximo 500 caracteres).');
    }
    $evidence = $this->files->store('evidence', PopFiles::MAX_EVIDENCE);
    $pdo = $this->db->pdo();
    $alert = null;
    try {
      $pdo->beginTransaction();
      $product = $this->lockProduct($productId, $employee);
      if (!(int) $product['active']) {
        throw new \InvalidArgumentException('El producto está archivado.');
      }
      $before = (int) $product['quantity'];
      $after = $before + $delta;
      if ($after < 0) {
        throw new \InvalidArgumentException('No hay existencias suficientes: hay ' . $before . ' ' . ($product['unit'] ?: 'unidad') . '.');
      }
      $this->setQuantity($product, $after, $employee);
      $movementId = $this->movement($productId, null, $delta > 0 ? 'entry' : 'adjustment_out', $delta, $before, $after, $reason, self::SOURCE, $employee);
      $this->attachEvidence($evidence, null, $movementId, $productId);
      $pdo->commit();
      $alert = [$product, $before, $after];
    } catch (\Throwable $exception) {
      if ($pdo->inTransaction()) {
        $pdo->rollBack();
      }
      $this->files->remove($evidence);
      throw $exception;
    }
    $this->alert->notify(...$alert);
  }

  /** Archiva (o reactiva) con motivo; queda un movimiento sin cambio de existencias. */
  public function setActive(int $productId, bool $active, string $reason, string $employee): void
  {
    $reason = trim($reason);
    if ($reason === '' || mb_strlen($reason, 'UTF-8') > 500) {
      throw new \InvalidArgumentException('Escribe el motivo (máximo 500 caracteres).');
    }
    $pdo = $this->db->pdo();
    $pdo->beginTransaction();
    try {
      $product = $this->db->getRow("SELECT * FROM `{$this->products}` WHERE id = ?" . $this->forUpdate(), [$productId]);
      if ($product === null) {
        throw new \InvalidArgumentException('El producto no existe.');
      }
      if ((bool) (int) $product['active'] === $active) {
        throw new \InvalidArgumentException($active ? 'El producto ya está activo.' : 'El producto ya está archivado.');
      }
      $this->db->update($this->products, ['active' => $active ? 1 : 0, 'updated_by' => self::integer($employee), 'updated_at' => $this->now()], ['id' => $productId]);
      $quantity = (int) $product['quantity'];
      $this->movement($productId, null, $active ? 'restore' : 'archive', 0, $quantity, $quantity, $reason, self::SOURCE, $employee);
      if (!empty($product['sync_pph']) && (int) ($product['pph_product_id'] ?? 0) > 0) {
        $this->updatePph((int) $product['pph_product_id'], ['cct_status' => $active ? 'publish' : 'draft', 'cct_modified' => $this->now()]);
        $source = $this->db->getRow("SELECT * FROM `{$this->pphProducts}` WHERE _ID = ? LIMIT 1", [(int) $product['pph_product_id']]);
        $this->db->update($this->products, ['pph_hash' => $source !== null ? self::pphHash($source) : null], ['id' => $productId]);
      }
      $pdo->commit();
    } catch (\Throwable $exception) {
      if ($pdo->inTransaction()) {
        $pdo->rollBack();
      }
      throw $exception;
    }
  }

  /**
   * Entrega de uno o varios productos: descuenta todos o ninguno.
   * @param array<string,mixed> $input delivered_at, recipient_name, recipient_type, recipient_id, recipient_document,
   *   recipient_source, notes, items (lista de {product_id, quantity} o JSON).
   * @return int id de la entrega.
   */
  public function createDelivery(array $input, string $employee): int
  {
    return $this->deliver($input, $employee, self::SOURCE, null, []);
  }

  /**
   * Entrega que llega por la API (formularios y sistemas externos): origen 'api', sin funcionario. Con external_id es
   * idempotente: si ya existe una entrega de la API con ese id se devuelve esa, sin descontar otra vez.
   * @param array<string,mixed> $input Los campos de createDelivery más external_id y evidence_urls (HTTPS).
   * @return array{id:int,duplicate:bool,status:string}
   */
  public function createApiDelivery(array $input): array
  {
    $externalId = mb_substr(trim(is_scalar($input['external_id'] ?? null) ? (string) $input['external_id'] : ''), 0, 191, 'UTF-8');
    $existing = $externalId !== '' ? $this->db->getRow("SELECT id, status FROM `{$this->deliveries}` WHERE source = 'api' AND external_id = ? LIMIT 1", [$externalId]) : null;
    if ($existing !== null) {
      return ['id' => (int) $existing['id'], 'duplicate' => true, 'status' => (string) $existing['status']];
    }
    if (trim((string) ($input['delivered_at'] ?? '')) === '') {
      // Los formularios suelen no mandar la fecha: la entrega es en el momento del envío.
      $input['delivered_at'] = $this->now();
    }
    $urls = self::evidenceUrls($input['evidence_urls'] ?? ($input['evidence_url'] ?? []));
    try {
      $id = $this->deliver($input, '', 'api', $externalId !== '' ? $externalId : null, $urls);
    } catch (\PDOException $exception) {
      // Dos envíos simultáneos con el mismo external_id: la llave única deja pasar uno solo.
      $existing = $externalId !== '' ? $this->db->getRow("SELECT id, status FROM `{$this->deliveries}` WHERE source = 'api' AND external_id = ? LIMIT 1", [$externalId]) : null;
      if ($existing === null) {
        throw $exception;
      }
      return ['id' => (int) $existing['id'], 'duplicate' => true, 'status' => (string) $existing['status']];
    }
    return ['id' => $id, 'duplicate' => false, 'status' => 'delivered'];
  }

  /**
   * Productos activos para formularios y sistemas externos, sin precios.
   * @return list<array{id:int,name:string,type:string,sku:string,unit:string,quantity:int,points:int|null,image_url:string,origin:string}>
   */
  public function apiProducts(): array
  {
    $rows = $this->db->getResults("SELECT id, name, type, sku, unit, quantity, points, image_url, pph_product_id FROM `{$this->products}` WHERE active = 1 ORDER BY name");
    return array_map(static fn(array $row): array => [
      'id' => (int) $row['id'], 'name' => (string) $row['name'], 'type' => (string) $row['type'], 'sku' => (string) ($row['sku'] ?? ''),
      'unit' => (string) ($row['unit'] ?: 'unidad'), 'quantity' => (int) $row['quantity'], 'points' => self::integer($row['points'] ?? null),
      'image_url' => (string) ($row['image_url'] ?? ''), 'origin' => $row['pph_product_id'] !== null ? 'pph' : 'inventory',
    ], $rows);
  }

  /**
   * @param array<string,mixed> $input
   * @param list<string> $evidenceUrls Enlaces HTTPS de evidencia (solo la API).
   */
  private function deliver(array $input, string $employee, string $source, ?string $externalId, array $evidenceUrls): int
  {
    $recipient = trim((string) ($input['recipient_name'] ?? ''));
    if ($recipient === '' || mb_strlen($recipient, 'UTF-8') > 255) {
      throw new \InvalidArgumentException('Escribe el nombre de quien recibe.');
    }
    $items = self::items($input['items'] ?? []);
    if ($items === []) {
      throw new \InvalidArgumentException('Agrega al menos un producto con su cantidad.');
    }
    $deliveredAt = $this->deliveryDate((string) ($input['delivered_at'] ?? ''));
    $evidence = $this->files->store('evidence', PopFiles::MAX_EVIDENCE);
    if (count($evidence) + count($evidenceUrls) > PopFiles::MAX_EVIDENCE) {
      $this->files->remove($evidence);
      throw new \InvalidArgumentException('Puedes adjuntar máximo ' . PopFiles::MAX_EVIDENCE . ' evidencias.');
    }
    $pdo = $this->db->pdo();
    $alerts = [];
    try {
      $pdo->beginTransaction();
      $now = $this->now();
      $deliveryId = $this->insert($this->deliveries, [
        'delivered_at' => $deliveredAt, 'recipient_name' => $recipient,
        'recipient_type' => self::text($input['recipient_type'] ?? null), 'recipient_id' => self::text($input['recipient_id'] ?? null),
        'recipient_source' => self::text($input['recipient_source'] ?? null) ?? ($source === 'api' ? 'Integración' : 'Panel comercial'),
        'recipient_document' => self::text($input['recipient_document'] ?? null), 'recipient_metadata' => null,
        'notes' => self::text($input['notes'] ?? null), 'status' => 'delivered', 'source' => $source, 'external_id' => $externalId,
        'pph_reward_id' => null, 'created_by' => self::integer($employee), 'cancelled_by' => null, 'cancelled_at' => null,
        'cancellation_reason' => null, 'created_at' => $now, 'updated_at' => $now,
      ]);
      foreach ($items as $productId => $quantity) {
        $product = $this->lockProduct($productId, $employee);
        if (!(int) $product['active']) {
          throw new \InvalidArgumentException('El producto "' . $product['name'] . '" está archivado.');
        }
        $before = (int) $product['quantity'];
        if ($quantity > $before) {
          throw new \InvalidArgumentException('No hay existencias suficientes de "' . $product['name'] . '": hay ' . $before . '.');
        }
        $after = $before - $quantity;
        $this->setQuantity($product, $after, $employee);
        $this->insert($this->items, [
          'delivery_id' => $deliveryId, 'product_id' => $productId, 'product_name' => $product['name'], 'product_type' => $product['type'],
          'quantity' => $quantity, 'unit_price' => $product['price'], 'points' => $product['points'], 'pph_product_id' => $product['pph_product_id'],
          'created_at' => $now,
        ]);
        $this->movement($productId, $deliveryId, 'delivery', -$quantity, $before, $after, 'Entrega a ' . $recipient . '.', $source, $employee);
        $alerts[] = [$product, $before, $after];
      }
      $this->attachEvidence(array_merge($evidence, $evidenceUrls), $deliveryId, null, null);
      $pdo->commit();
    } catch (\Throwable $exception) {
      if ($pdo->inTransaction()) {
        $pdo->rollBack();
      }
      $this->files->remove($evidence);
      throw $exception;
    }
    foreach ($alerts as $alert) {
      $this->alert->notify(...$alert);
    }
    return $deliveryId;
  }

  /** Anula una entrega y repone sus productos; las importadas de PPH no se anulan aquí. */
  public function cancelDelivery(int $deliveryId, string $reason, string $employee): void
  {
    $reason = trim($reason);
    if ($reason === '' || mb_strlen($reason, 'UTF-8') > 500) {
      throw new \InvalidArgumentException('Escribe el motivo de la anulación (máximo 500 caracteres).');
    }
    $pdo = $this->db->pdo();
    $pdo->beginTransaction();
    try {
      $delivery = $this->db->getRow("SELECT * FROM `{$this->deliveries}` WHERE id = ?" . $this->forUpdate(), [$deliveryId]);
      if ($delivery === null || (string) $delivery['status'] !== 'delivered') {
        throw new \InvalidArgumentException('La entrega no existe o ya fue anulada.');
      }
      if ((string) $delivery['source'] === 'pph') {
        throw new \InvalidArgumentException('Las entregas importadas de recompensas PPH no se anulan desde aquí.');
      }
      foreach ($this->db->getResults("SELECT * FROM `{$this->items}` WHERE delivery_id = ? ORDER BY id" . $this->forUpdate(), [$deliveryId]) as $item) {
        $productId = (int) ($item['product_id'] ?? 0);
        if ($productId <= 0) {
          continue;
        }
        $product = $this->lockProduct($productId, $employee);
        $before = (int) $product['quantity'];
        $after = $before + (int) $item['quantity'];
        $this->setQuantity($product, $after, $employee);
        $this->movement($productId, $deliveryId, 'reversal', (int) $item['quantity'], $before, $after, 'Anulación de entrega: ' . $reason, self::SOURCE, $employee);
      }
      $now = $this->now();
      $this->db->update($this->deliveries, ['status' => 'cancelled', 'cancelled_by' => self::integer($employee), 'cancelled_at' => $now, 'cancellation_reason' => $reason, 'updated_at' => $now], ['id' => $deliveryId]);
      $pdo->commit();
    } catch (\Throwable $exception) {
      if ($pdo->inTransaction()) {
        $pdo->rollBack();
      }
      throw $exception;
    }
  }

  /** Categorías de Configuración más las que ya tienen productos. @return list<string> */
  public function categories(): array
  {
    $types = array_map('strval', $this->db->getCol("SELECT DISTINCT type FROM `{$this->products}` WHERE type IS NOT NULL AND type <> ''"));
    return PopConfig::categories(array_merge(PopConfig::read($this->settings)['categories'], $types));
  }

  // ── Internos ────────────────────────────────────────────────────────────

  /** @return array<string,mixed> Producto bloqueado y conciliado con PPH antes de operar. */
  private function lockProduct(int $productId, string $employee): array
  {
    $product = $this->db->getRow("SELECT * FROM `{$this->products}` WHERE id = ?" . $this->forUpdate(), [$productId]);
    if ($product === null) {
      throw new \InvalidArgumentException('Uno de los productos elegidos no existe.');
    }
    $pphId = (int) ($product['pph_product_id'] ?? 0);
    if (!empty($product['sync_pph']) && $pphId > 0 && $this->tableExists($this->pphProducts)) {
      $pph = $this->db->getRow("SELECT * FROM `{$this->pphProducts}` WHERE _ID = ?" . $this->forUpdate(), [$pphId]);
      if ($pph !== null) {
        $pphQuantity = max(0, (int) ($pph['cantidad'] ?? 0));
        $before = (int) $product['quantity'];
        if ($pphQuantity !== $before) {
          $this->db->update($this->products, ['quantity' => $pphQuantity, 'pph_quantity' => $pphQuantity, 'pph_hash' => self::pphHash($pph), 'pph_modified_at' => self::date($pph['cct_modified'] ?? null), 'updated_at' => $this->now()], ['id' => $productId]);
          $this->movement($productId, null, 'pph_reconciliation', $pphQuantity - $before, $before, $pphQuantity, 'Conciliación previa a la operación.', 'pph', $employee);
          $product['quantity'] = $pphQuantity;
        }
      }
    }
    return $product;
  }

  /** @param array<string,mixed> $product */
  private function setQuantity(array $product, int $quantity, string $employee): void
  {
    $updates = ['quantity' => $quantity, 'updated_by' => self::integer($employee), 'updated_at' => $this->now()];
    $pphId = (int) ($product['pph_product_id'] ?? 0);
    if (!empty($product['sync_pph']) && $pphId > 0) {
      $this->updatePph($pphId, ['cantidad' => (string) $quantity, 'cct_modified' => $this->now()]);
      $source = $this->db->getRow("SELECT * FROM `{$this->pphProducts}` WHERE _ID = ? LIMIT 1", [$pphId]);
      $updates += ['pph_quantity' => $quantity, 'pph_hash' => $source !== null ? self::pphHash($source) : null, 'pph_modified_at' => $this->now()];
    }
    $this->db->update($this->products, $updates, ['id' => (int) $product['id']]);
  }

  /** Publica o actualiza el producto en el inventario del Club PPH. */
  private function pushToPph(int $productId, string $employee): void
  {
    if (!$this->tableExists($this->pphProducts)) {
      throw new \RuntimeException('La tabla de productos PPH no está disponible.');
    }
    $product = $this->db->getRow("SELECT * FROM `{$this->products}` WHERE id = ?", [$productId]) ?? [];
    $pphId = (int) ($product['pph_product_id'] ?? 0);
    $data = [
      'cct_status' => (int) $product['active'] === 1 ? 'publish' : 'draft', 'cct_modified' => $this->now(),
      'cantidad' => (string) (int) $product['quantity'], 'precio' => $product['price'] === null ? null : (string) $product['price'],
      'valor_punto' => $product['points'] === null ? null : (string) $product['points'], 'titulo' => $product['name'], 'img' => $product['image_url'],
    ];
    if ($pphId > 0) {
      $this->updatePph($pphId, $data);
    } else {
      $pphId = $this->insert($this->pphProducts, $data + ['cct_author_id' => self::integer($employee) ?? 0, 'cct_created' => $this->now(), 'fecha' => time()]);
    }
    $source = $this->db->getRow("SELECT * FROM `{$this->pphProducts}` WHERE _ID = ? LIMIT 1", [$pphId]) ?? [];
    $this->db->update($this->products, ['sync_pph' => 1, 'pph_product_id' => $pphId, 'pph_quantity' => (int) $product['quantity'], 'pph_hash' => self::pphHash($source), 'pph_modified_at' => $this->now()], ['id' => $productId]);
  }

  /** @param array<string,mixed> $data */
  private function updatePph(int $pphId, array $data): void
  {
    $sets = implode(', ', array_map(static fn(string $column): string => "`{$column}` = ?", array_keys($data)));
    $statement = $this->db->pdo()->prepare("UPDATE `{$this->pphProducts}` SET {$sets} WHERE _ID = ?");
    $statement->execute([...array_values($data), $pphId]);
  }

  private function movement(int $productId, ?int $deliveryId, string $type, int $delta, int $before, int $after, string $reason, string $source, ?string $employee): int
  {
    return $this->insert($this->movements, [
      'product_id' => $productId, 'delivery_id' => $deliveryId, 'movement_type' => $type, 'quantity_delta' => $delta,
      'stock_before' => $before, 'stock_after' => $after, 'reason' => mb_substr($reason, 0, 500, 'UTF-8'), 'source' => $source,
      'created_by' => $employee === null ? null : self::integer($employee), 'created_at' => $this->now(),
    ]);
  }

  /** @param list<string> $urls */
  private function attachEvidence(array $urls, ?int $deliveryId, ?int $movementId, ?int $productId): void
  {
    foreach ($urls as $url) {
      $this->insert($this->evidence, [
        'delivery_id' => $deliveryId, 'movement_id' => $movementId, 'product_id' => $productId, 'evidence_type' => 'url',
        'storage_path' => null, 'external_url' => $url, 'original_name' => null, 'mime_type' => 'image/jpeg', 'file_size' => null,
        'created_at' => $this->now(),
      ]);
    }
  }

  /**
   * Evidencias visibles: las del panel y las URL externas. Las que el panel anterior guardó como archivo propio
   * (evidence_type 'file') solo se cuentan: viven en el servidor de dashboard-marketing.
   * @param list<int> $ids
   * @return array<int,list<array{url:string}>>
   */
  private function evidenceFor(string $column, array $ids): array
  {
    if ($ids === [] || !$this->tableExists($this->evidence)) {
      return [];
    }
    $out = [];
    $rows = $this->db->getResults("SELECT {$column} AS owner, evidence_type, external_url FROM `{$this->evidence}` WHERE {$column} IN (" . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY id', $ids);
    foreach ($rows as $row) {
      $url = (string) ($row['external_url'] ?? '');
      $out[(int) $row['owner']][] = ['url' => (string) $row['evidence_type'] === 'url' && str_starts_with(strtolower($url), 'http') ? $url : ''];
    }
    return $out;
  }

  /**
   * @param list<string> $where
   * @param list<mixed> $args
   * @param array<string,mixed> $filters
   */
  private function dateRange(array &$where, array &$args, string $column, array $filters): void
  {
    foreach (['desde' => ['>=', ' 00:00:00'], 'hasta' => ['<=', ' 23:59:59']] as $key => [$operator, $time]) {
      $value = self::filterDate((string) ($filters[$key] ?? ''));
      if ($value !== '') {
        $where[] = "{$column} {$operator} ?";
        $args[] = $value . $time;
      }
    }
  }

  /** @param array<string,mixed> $data */
  private function insert(string $table, array $data): int
  {
    $this->db->insert($table, $data);
    return (int) $this->db->lastInsertId();
  }

  private function forUpdate(): string
  {
    return $this->mysql ? ' FOR UPDATE' : '';
  }

  /** Si la tabla existe y se puede leer (sin information_schema, que no hay en las pruebas con SQLite). */
  private function tableExists(string $table): bool
  {
    if (!array_key_exists($table, $this->tables)) {
      try {
        $this->db->getVar("SELECT 1 FROM `{$table}` LIMIT 1");
        $this->tables[$table] = true;
      } catch (\Throwable) {
        $this->tables[$table] = false;
      }
    }
    return $this->tables[$table];
  }

  private function now(): string
  {
    return date('Y-m-d H:i:s');
  }

  private function deliveryDate(string $value): string
  {
    $value = trim($value);
    if ($value === '') {
      throw new \InvalidArgumentException('Indica la fecha y hora de la entrega.');
    }
    try {
      $date = new \DateTimeImmutable($value);
    } catch (\Throwable) {
      throw new \InvalidArgumentException('La fecha y hora de la entrega no son válidas.');
    }
    if ($date > new \DateTimeImmutable('+1 hour')) {
      throw new \InvalidArgumentException('La fecha de la entrega no puede ser futura.');
    }
    return $date->format('Y-m-d H:i:s');
  }

  /**
   * Productos y cantidades (suma las repetidas).
   * @param mixed $raw
   * @return array<int,int>
   */
  public static function items($raw): array
  {
    if (is_string($raw)) {
      $decoded = json_decode($raw, true);
      $raw = is_array($decoded) ? $decoded : [];
    }
    $items = [];
    foreach (is_array($raw) ? $raw : [] as $key => $row) {
      [$productId, $quantity] = is_array($row) ? [(int) ($row['product_id'] ?? $row['id'] ?? 0), (int) ($row['quantity'] ?? 0)] : [(int) $key, (int) $row];
      if ($productId > 0 && $quantity > 0) {
        $items[$productId] = ($items[$productId] ?? 0) + $quantity;
      }
    }
    return $items;
  }

  /**
   * Enlaces de evidencia de la API: solo HTTPS, sin repetidos, máximo MAX_EVIDENCE.
   * @param mixed $raw Lista, JSON o un enlace suelto.
   * @return list<string>
   */
  public static function evidenceUrls($raw): array
  {
    if (is_string($raw)) {
      $decoded = json_decode($raw, true);
      $raw = is_array($decoded) ? $decoded : (trim($raw) === '' ? [] : [$raw]);
    }
    $urls = [];
    foreach (is_array($raw) ? $raw : [] as $url) {
      $url = trim(is_scalar($url) ? (string) $url : '');
      if ($url === '') {
        continue;
      }
      if (!filter_var($url, FILTER_VALIDATE_URL) || !str_starts_with(strtolower($url), 'https://')) {
        throw new \InvalidArgumentException('Las URLs de evidencia deben usar HTTPS.');
      }
      $urls[$url] = $url;
    }
    if (count($urls) > PopFiles::MAX_EVIDENCE) {
      throw new \InvalidArgumentException('Puedes adjuntar máximo ' . PopFiles::MAX_EVIDENCE . ' evidencias.');
    }
    return array_values($urls);
  }

  /** @param array<string,mixed> $source */
  public static function pphHash(array $source): string
  {
    return hash('sha256', (string) json_encode([
      trim((string) ($source['titulo'] ?? '')), max(0, (int) ($source['cantidad'] ?? 0)), trim((string) ($source['precio'] ?? '')),
      trim((string) ($source['valor_punto'] ?? '')), trim((string) ($source['img'] ?? '')), strtolower(trim((string) ($source['cct_status'] ?? 'publish'))),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
  }

  /** @param array<string,mixed> $source */
  private static function pphActive(array $source): bool
  {
    return !in_array(strtolower(trim((string) ($source['cct_status'] ?? 'publish'))), ['draft', 'trash', 'inactive'], true);
  }

  private static function filterDate(string $value): string
  {
    $date = \DateTimeImmutable::createFromFormat('!Y-m-d', trim($value));
    return $date instanceof \DateTimeImmutable && $date->format('Y-m-d') === trim($value) ? trim($value) : '';
  }

  /** @param mixed $value */
  private static function text($value): ?string
  {
    $text = trim(is_scalar($value) ? (string) $value : '');
    return $text === '' ? null : mb_substr($text, 0, 255, 'UTF-8');
  }

  /** @param mixed $value */
  private static function integer($value): ?int
  {
    $text = trim(is_scalar($value) ? (string) $value : '');
    return $text === '' || !is_numeric($text) ? null : (int) $text;
  }

  /** @param mixed $value */
  private static function decimal($value, bool $strict = false): ?string
  {
    $text = trim(is_scalar($value) ? (string) $value : '');
    if ($text === '') {
      return null;
    }
    // Pesos colombianos: "40.000", "40000" o "40000.50".
    $text = preg_replace('/[^\d,.-]/', '', $text) ?? '';
    if (preg_match('/^\d{1,3}(\.\d{3})+$/', $text) === 1) {
      $text = str_replace('.', '', $text);
    }
    $text = str_replace(',', '.', $text);
    if (!is_numeric($text) || (float) $text < 0) {
      if ($strict) {
        throw new \InvalidArgumentException('El precio debe ser un número positivo.');
      }
      return null;
    }
    return number_format((float) $text, 2, '.', '');
  }

  /** @param mixed $value */
  private static function date($value): ?string
  {
    $text = trim(is_scalar($value) ? (string) $value : '');
    return $text === '' || $text === '0000-00-00 00:00:00' ? null : $text;
  }
}
