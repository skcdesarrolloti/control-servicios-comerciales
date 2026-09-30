<?php

declare(strict_types=1);

namespace SCM\Commercial;

use SCM\Core\Auth;
use SCM\Core\Database;

final class CommercialPropertiesRepository
{
  public const STATUSES_PUBLIC = [
    'Publico',
    'Publicado',
  ];

  public const STATUSES_PENDING = [
    'En borrador',
    'Borrador',
    'Por publicar',
    'Pendiente por publicar',
    'Pendiente',
  ];

  public const STATUSES_NON_PUBLIC = [
    'Arrendado',
    'Vendido',
    'Desistido',
    'Ocupado',
    'En cierre',
    'Vetado',
    'No publicar',
    'En proceso de cierre',
  ];

  private Database $db;

  public function __construct(Database $db)
  {
    $this->db = $db;
  }

  /**
   * Search properties with pagination, filtering, subtab scoping, and photo batch-resolving.
   *
   * @param array<string,mixed> $filters
   * @return array{
   *   rows: array<int,array<string,mixed>>,
   *   total: int,
   *   pagination: array{page:int,per_page:int,total_pages:int,total_items:int,from:int,to:int}
   * }
   */
  public function search(array $filters, bool $canSeeAll = true, int $page = 1, int $perPage = 24): array
  {
    $table = $this->db->table('jet_cct_inmuebles');
    $funcionarios = $this->db->table('jet_cct_funcionarios');

    $page = max(1, $page);
    $perPage = max(1, min(100, $perPage));
    $offset = ($page - 1) * $perPage;

    [$where, $args] = $this->buildWhereClauses($filters, $canSeeAll);

    $whereSql = $where !== [] ? ('WHERE ' . implode(' AND ', $where)) : '';

    // Total count
    $totalSql = "SELECT COUNT(*) FROM `{$table}` i {$whereSql}";
    $total = (int) $this->db->getVar($totalSql, $args);

    if ($total === 0) {
      return [
        'rows' => [],
        'total' => 0,
        'pagination' => [
          'page' => $page,
          'per_page' => $perPage,
          'total_pages' => 0,
          'total_items' => 0,
          'from' => 0,
          'to' => 0,
        ],
      ];
    }

    $totalPages = (int) ceil($total / $perPage);
    if ($page > $totalPages) {
      $page = $totalPages;
      $offset = ($page - 1) * $perPage;
    }

    $sql = "SELECT i.*,
                   COALESCE(NULLIF(TRIM(f.`nombre`), ''), i.`funcionario`) AS funcionario_nombre,
                   f.`celular` AS funcionario_celular,
                   f.`correo` AS funcionario_correo
              FROM `{$table}` i
         LEFT JOIN `{$funcionarios}` f 
                ON (TRIM(COALESCE(i.`id_funcionario`, '')) <> '' AND TRIM(f.`id_empleado`) = TRIM(i.`id_funcionario`))
             {$whereSql}
          ORDER BY i.`_ID` DESC
             LIMIT {$perPage} OFFSET {$offset}";

    $rows = $this->db->getResults($sql, $args);

    // Batch resolve cover photos
    $rows = $this->attachPhotoUrls($rows);

    $from = $offset + 1;
    $to = min($total, $offset + count($rows));

    return [
      'rows' => $rows,
      'total' => $total,
      'pagination' => [
        'page' => $page,
        'per_page' => $perPage,
        'total_pages' => $totalPages,
        'total_items' => $total,
        'from' => $from,
        'to' => $to,
      ],
    ];
  }

  /**
   * KPI Counts for the subtabs.
   *
   * @param array<string,mixed> $filters
   * @return array{publicos:int,pendientes:int,no_publicos:int,mis_inmuebles:int,total:int}
   */
  public function summaryCounts(array $filters = [], bool $canSeeAll = true): array
  {
    $table = $this->db->table('jet_cct_inmuebles');

    // Base filters ignoring subtab and specific status
    $baseFilters = $filters;
    unset($baseFilters['property_subtab'], $baseFilters['estado']);

    [$where, $args] = $this->buildWhereClauses($baseFilters, $canSeeAll, true);
    $whereSql = $where !== [] ? (' AND ' . implode(' AND ', $where)) : '';

    $publicIn = implode(',', array_fill(0, count(self::STATUSES_PUBLIC), '?'));
    $pendingIn = implode(',', array_fill(0, count(self::STATUSES_PENDING), '?'));
    $nonPublicIn = implode(',', array_fill(0, count(self::STATUSES_NON_PUBLIC), '?'));

    $allArgs = array_merge(self::STATUSES_PUBLIC, self::STATUSES_PENDING, self::STATUSES_NON_PUBLIC, $args);

    $sql = "SELECT 
              SUM(CASE WHEN TRIM(i.`estado`) IN ({$publicIn}) THEN 1 ELSE 0 END) AS publicos,
              SUM(CASE WHEN TRIM(i.`estado`) IN ({$pendingIn}) THEN 1 ELSE 0 END) AS pendientes,
              SUM(CASE WHEN TRIM(i.`estado`) IN ({$nonPublicIn}) THEN 1 ELSE 0 END) AS no_publicos,
              COUNT(*) AS total
            FROM `{$table}` i
            WHERE 1=1 {$whereSql}";

    $row = $this->db->getRow($sql, $allArgs);

    // Calculate mis_inmuebles count
    $myCount = $this->countMyProperties();

    return [
      'publicos' => (int) ($row['publicos'] ?? 0),
      'pendientes' => (int) ($row['pendientes'] ?? 0),
      'no_publicos' => (int) ($row['no_publicos'] ?? 0),
      'mis_inmuebles' => $myCount,
      'total' => (int) ($row['total'] ?? 0),
    ];
  }

  /**
   * Count properties assigned to the current authenticated user.
   */
  public function countMyProperties(): int
  {
    $table = $this->db->table('jet_cct_inmuebles');
    [$myWhere, $myArgs] = $this->buildMyScopeClauses();
    if ($myWhere === '') {
      return 0;
    }

    $sql = "SELECT COUNT(*) FROM `{$table}` i WHERE {$myWhere}";
    return (int) $this->db->getVar($sql, $myArgs);
  }

  /**
   * Retrieve distinct filter dropdown options.
   *
   * @return array{
   *   tipos_inmueble: array<int,string>,
   *   tipos_negocio: array<int,string>,
   *   ciudades: array<int,string>,
   *   barrios: array<int,string>,
   *   estados_no_publicos: array<int,string>,
   *   funcionarios: array<int,array{id:string,name:string}>
   * }
   */
  public function filterOptions(): array
  {
    $table = $this->db->table('jet_cct_inmuebles');
    $funcionarios = $this->db->table('jet_cct_funcionarios');

    $tiposInmueble = $this->db->getCol("SELECT DISTINCT TRIM(tipo_inmueble) FROM `{$table}` WHERE tipo_inmueble IS NOT NULL AND TRIM(tipo_inmueble) <> '' ORDER BY tipo_inmueble ASC");
    $tiposNegocio = $this->db->getCol("SELECT DISTINCT TRIM(tipo_negocio) FROM `{$table}` WHERE tipo_negocio IS NOT NULL AND TRIM(tipo_negocio) <> '' ORDER BY tipo_negocio ASC");
    $ciudades = $this->db->getCol("SELECT DISTINCT TRIM(ciudad) FROM `{$table}` WHERE ciudad IS NOT NULL AND TRIM(ciudad) <> '' ORDER BY ciudad ASC");
    $barrios = $this->db->getCol("SELECT DISTINCT TRIM(barrio) FROM `{$table}` WHERE barrio IS NOT NULL AND TRIM(barrio) <> '' ORDER BY barrio ASC");

    // Officials with assigned properties
    $funcsSql = "SELECT DISTINCT TRIM(i.`id_funcionario`) AS id, 
                        COALESCE(NULLIF(TRIM(f.`nombre`), ''), NULLIF(TRIM(i.`funcionario`), ''), CONCAT('Funcionario #', i.`id_funcionario`)) AS name
                   FROM `{$table}` i
              LEFT JOIN `{$funcionarios}` f 
                     ON (TRIM(COALESCE(i.`id_funcionario`, '')) <> '' AND TRIM(f.`id_empleado`) = TRIM(i.`id_funcionario`))
                  WHERE i.`id_funcionario` IS NOT NULL AND TRIM(i.`id_funcionario`) <> ''
               ORDER BY name ASC";
    $funcsRows = $this->db->getResults($funcsSql);
    $funcs = [];
    $seen = [];
    foreach ($funcsRows as $fr) {
      $id = trim((string) ($fr['id'] ?? ''));
      $name = trim((string) ($fr['name'] ?? ''));
      if ($id !== '' && !isset($seen[$id])) {
        $seen[$id] = true;
        $funcs[] = ['id' => $id, 'name' => $name];
      }
    }

    return [
      'tipos_inmueble' => array_values(array_filter(array_map('strval', $tiposInmueble))),
      'tipos_negocio' => array_values(array_filter(array_map('strval', $tiposNegocio))),
      'ciudades' => array_values(array_filter(array_map('strval', $ciudades))),
      'barrios' => array_values(array_filter(array_map('strval', $barrios))),
      'estados_no_publicos' => self::STATUSES_NON_PUBLIC,
      'funcionarios' => $funcs,
    ];
  }

  /**
   * Get single property details with resolved gallery image URLs.
   *
   * @param int|string $identifier Can be _ID or codigo
   * @return array<string,mixed>|null
   */
  public function propertyDetail($identifier): ?array
  {
    $table = $this->db->table('jet_cct_inmuebles');
    $funcionarios = $this->db->table('jet_cct_funcionarios');
    $propietarios = $this->db->table('jet_cct_propietarios');

    $isNumeric = is_numeric($identifier);
    $where = $isNumeric ? '(i.`_ID` = ? OR i.`codigo` = ?)' : 'i.`codigo` = ?';
    $args = $isNumeric ? [(int) $identifier, (string) $identifier] : [(string) $identifier];

    $sql = "SELECT i.*,
                   COALESCE(NULLIF(TRIM(f.`nombre`), ''), i.`funcionario`) AS funcionario_nombre,
                   f.`celular` AS funcionario_celular,
                   f.`correo` AS funcionario_correo,
                   f.`rol` AS funcionario_rol,
                   p.`celular` AS propietario_celular,
                   p.`correo` AS propietario_correo,
                   p.`documento` AS propietario_documento
              FROM `{$table}` i
         LEFT JOIN `{$funcionarios}` f 
                ON (TRIM(COALESCE(i.`id_funcionario`, '')) <> '' AND TRIM(f.`id_empleado`) = TRIM(i.`id_funcionario`))
         LEFT JOIN `{$propietarios}` p
                ON (TRIM(COALESCE(i.`id_propietario`, '')) <> '' AND CAST(p.`_ID` AS CHAR) = TRIM(i.`id_propietario`))
             WHERE {$where}
             LIMIT 1";

    $property = $this->db->getRow($sql, $args);
    if (!is_array($property) || $property === []) {
      return null;
    }

    // Resolve gallery and cover photo URLs
    $mediaIds = [];
    $coverId = trim((string) ($property['foto_portada'] ?? ''));
    if ($coverId !== '' && ctype_digit($coverId)) {
      $mediaIds[] = (int) $coverId;
    }

    $galleryRaw = trim((string) ($property['galeria'] ?? ''));
    $galleryIds = [];
    if ($galleryRaw !== '') {
      $rawParts = explode(',', $galleryRaw);
      foreach ($rawParts as $part) {
        $cleanId = trim($part);
        if ($cleanId !== '' && ctype_digit($cleanId)) {
          $galleryIds[] = (int) $cleanId;
          $mediaIds[] = (int) $cleanId;
        }
      }
    }

    $urlMap = [];
    if ($mediaIds !== []) {
      $postsTable = $this->db->table('posts');
      $inClause = implode(',', array_fill(0, count($mediaIds), '?'));
      $postRows = $this->db->getResults("SELECT ID, guid FROM `{$postsTable}` WHERE ID IN ({$inClause})", $mediaIds);
      foreach ($postRows as $pr) {
        $urlMap[(int) $pr['ID']] = (string) $pr['guid'];
      }
    }

    $coverUrl = '';
    if ($coverId !== '' && isset($urlMap[(int) $coverId])) {
      $coverUrl = $urlMap[(int) $coverId];
    } elseif (filter_var($coverId, FILTER_VALIDATE_URL)) {
      $coverUrl = $coverId;
    }

    $galleryUrls = [];
    foreach ($galleryIds as $gid) {
      if (isset($urlMap[$gid])) {
        $galleryUrls[] = $urlMap[$gid];
      }
    }

    if ($coverUrl === '' && !empty($galleryUrls)) {
      $coverUrl = $galleryUrls[0];
    }

    $property['foto_portada_url'] = $coverUrl;
    $property['galeria_urls'] = $galleryUrls;

    return $property;
  }

  /**
   * Batch resolve photo URLs for an array of property rows.
   *
   * @param array<int,array<string,mixed>> $rows
   * @return array<int,array<string,mixed>>
   */
  private function attachPhotoUrls(array $rows): array
  {
    if ($rows === []) {
      return [];
    }

    $attachmentIds = [];
    foreach ($rows as $row) {
      $cover = trim((string) ($row['foto_portada'] ?? ''));
      if ($cover !== '' && ctype_digit($cover)) {
        $attachmentIds[(int) $cover] = true;
      }
      $gallery = trim((string) ($row['galeria'] ?? ''));
      if ($gallery !== '') {
        $firstId = trim(explode(',', $gallery)[0] ?? '');
        if ($firstId !== '' && ctype_digit($firstId)) {
          $attachmentIds[(int) $firstId] = true;
        }
      }
    }

    $urlMap = [];
    if (!empty($attachmentIds)) {
      $postsTable = $this->db->table('posts');
      $ids = array_keys($attachmentIds);
      $inClause = implode(',', array_fill(0, count($ids), '?'));
      $posts = $this->db->getResults("SELECT ID, guid FROM `{$postsTable}` WHERE ID IN ({$inClause})", $ids);
      foreach ($posts as $p) {
        $urlMap[(int) $p['ID']] = (string) $p['guid'];
      }
    }

    foreach ($rows as &$row) {
      $cover = trim((string) ($row['foto_portada'] ?? ''));
      $url = '';
      if ($cover !== '' && isset($urlMap[(int) $cover])) {
        $url = $urlMap[(int) $cover];
      } elseif (filter_var($cover, FILTER_VALIDATE_URL)) {
        $url = $cover;
      }

      if ($url === '') {
        $gallery = trim((string) ($row['galeria'] ?? ''));
        if ($gallery !== '') {
          $firstId = trim(explode(',', $gallery)[0] ?? '');
          if ($firstId !== '' && isset($urlMap[(int) $firstId])) {
            $url = $urlMap[(int) $firstId];
          }
        }
      }

      $row['foto_portada_url'] = $url;
    }
    unset($row);

    return $rows;
  }

  /**
   * Build WHERE clauses based on filters, permissions, and subtab.
   *
   * @param array<string,mixed> $filters
   * @return array{0: array<int,string>, 1: array<int,mixed>}
   */
  private function buildWhereClauses(array $filters, bool $canSeeAll, bool $skipSubtab = false): array
  {
    $where = [];
    $args = [];

    $subtab = trim((string) ($filters['property_subtab'] ?? 'publicos'));
    if ($subtab === '') {
      $subtab = 'publicos';
    }

    // Scoping for non-admins
    if (!$canSeeAll) {
      // Non-admins viewing 'mis_inmuebles' are strictly scoped to their properties
      if ($subtab === 'mis_inmuebles') {
        [$myWhere, $myArgs] = $this->buildMyScopeClauses();
        if ($myWhere !== '') {
          $where[] = "({$myWhere})";
          $args = array_merge($args, $myArgs);
        }
      }
    } else {
      // Admins viewing 'mis_inmuebles'
      if ($subtab === 'mis_inmuebles') {
        [$myWhere, $myArgs] = $this->buildMyScopeClauses();
        if ($myWhere !== '') {
          $where[] = "({$myWhere})";
          $args = array_merge($args, $myArgs);
        }
      }
    }

    // Subtab state partition
    if (!$skipSubtab) {
      if ($subtab === 'publicos') {
        $in = implode(',', array_fill(0, count(self::STATUSES_PUBLIC), '?'));
        $where[] = "TRIM(i.`estado`) IN ({$in})";
        $args = array_merge($args, self::STATUSES_PUBLIC);
      } elseif ($subtab === 'pendientes') {
        $in = implode(',', array_fill(0, count(self::STATUSES_PENDING), '?'));
        $where[] = "TRIM(i.`estado`) IN ({$in})";
        $args = array_merge($args, self::STATUSES_PENDING);
      } elseif ($subtab === 'no_publicos') {
        $in = implode(',', array_fill(0, count(self::STATUSES_NON_PUBLIC), '?'));
        $where[] = "TRIM(i.`estado`) IN ({$in})";
        $args = array_merge($args, self::STATUSES_NON_PUBLIC);
      }
      // 'mis_inmuebles' does not restrict by estado, showing all states of the user's properties
    }

    // Free text search
    $search = trim((string) ($filters['busqueda'] ?? ''));
    if ($search !== '') {
      $searchWildcard = '%' . $search . '%';
      $where[] = "(
        i.`codigo` LIKE ? OR
        i.`direccion` LIKE ? OR
        i.`barrio` LIKE ? OR
        i.`ciudad` LIKE ? OR
        i.`propietario` LIKE ? OR
        i.`arrendatario` LIKE ? OR
        i.`copropiedad` LIKE ? OR
        i.`tipo_inmueble` LIKE ?
      )";
      $args[] = $searchWildcard;
      $args[] = $searchWildcard;
      $args[] = $searchWildcard;
      $args[] = $searchWildcard;
      $args[] = $searchWildcard;
      $args[] = $searchWildcard;
      $args[] = $searchWildcard;
      $args[] = $searchWildcard;
    }

    // Exact filters
    $tipoInmueble = trim((string) ($filters['tipo_inmueble'] ?? ''));
    if ($tipoInmueble !== '') {
      $where[] = 'TRIM(i.`tipo_inmueble`) = ?';
      $args[] = $tipoInmueble;
    }

    $tipoNegocio = trim((string) ($filters['tipo_negocio'] ?? ''));
    if ($tipoNegocio !== '') {
      $where[] = 'TRIM(i.`tipo_negocio`) = ?';
      $args[] = $tipoNegocio;
    }

    $ciudad = trim((string) ($filters['ciudad'] ?? ''));
    if ($ciudad !== '') {
      $where[] = 'TRIM(i.`ciudad`) = ?';
      $args[] = $ciudad;
    }

    $barrio = trim((string) ($filters['barrio'] ?? ''));
    if ($barrio !== '') {
      $where[] = 'TRIM(i.`barrio`) = ?';
      $args[] = $barrio;
    }

    $estado = trim((string) ($filters['estado'] ?? ''));
    if ($estado !== '') {
      $where[] = 'TRIM(i.`estado`) = ?';
      $args[] = $estado;
    }

    $funcionarioId = trim((string) ($filters['id_funcionario'] ?? ''));
    if ($funcionarioId !== '') {
      $where[] = 'TRIM(i.`id_funcionario`) = ?';
      $args[] = $funcionarioId;
    }

    return [$where, $args];
  }

  /**
   * Helper to build SQL condition for current user's properties.
   *
   * @return array{0: string, 1: array<int,mixed>}
   */
  private function buildMyScopeClauses(): array
  {
    $userId = Auth::userId();
    $employeeId = trim(Auth::employeeId());

    if ($employeeId === '' && $userId > 0) {
      $funcionarios = $this->db->table('jet_cct_funcionarios');
      $emp = $this->db->getVar("SELECT TRIM(COALESCE(`id_empleado`, '')) FROM `{$funcionarios}` WHERE `_ID` = ? LIMIT 1", [$userId]);
      $employeeId = trim((string) $emp);
    }

    if ($employeeId === '') {
      return ['1 = 0', []];
    }

    return ['TRIM(i.`id_funcionario`) = ?', [$employeeId]];
  }
}
