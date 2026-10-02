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

  public const PORTALS = [
    'mercado_libre_destacados' => [
      'label' => 'Mercado Libre',
      'property_column' => 'mercado_libre_destacado',
      'history_column' => 'mercado_libre_destacados',
    ],
    'proppit_promocionados' => [
      'label' => 'Proppit',
      'property_column' => 'proppit_promocionado',
      'history_column' => 'proppit_promocionados',
    ],
    'ciencuadras_ascendidos' => [
      'label' => 'Ciencuadras Ascendido',
      'property_column' => 'ciencuadras_ascendido',
      'history_column' => 'ciencuadras_ascendidos',
    ],
    'ciencuadras_destacados' => [
      'label' => 'Ciencuadras Destacado',
      'property_column' => 'ciencuadras_destacado',
      'history_column' => 'ciencuadras_destacados',
    ],
    'finca_raiz_silver' => [
      'label' => 'Finca Raiz Silver',
      'property_column' => 'finca_raiz_silver',
      'history_column' => 'finca_raiz_silver',
    ],
    'finca_raiz_gold' => [
      'label' => 'Finca Raiz Gold',
      'property_column' => 'finca_raiz_gold',
      'history_column' => 'finca_raiz_gold',
    ],
    'finca_raiz_black' => [
      'label' => 'Finca Raiz Black',
      'property_column' => 'finca_raiz_black',
      'history_column' => 'finca_raiz_black',
    ],
  ];

  private Database $db;
  /** @var array<string,string>|null */
  private ?array $amenitiesMapCache = null;

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
          ORDER BY (CASE WHEN (i.`destacado` = 'Si' OR i.`marcado_destacado` = 'Si' OR i.`promocion_premium` = 'Si') THEN 1 ELSE 0 END) DESC,
                   COALESCE(i.`fecha_destacado`, 0) DESC,
                   i.`_ID` DESC
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
   * @return array{publicos:int,pendientes:int,no_publicos:int,destacados:int,solicitudes:int,mis_inmuebles:int,total:int}
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
              SUM(CASE WHEN (i.`destacado` = 'Si' OR i.`promocion_premium` = 'Si' OR i.`mercado_libre_destacado` = 'Si' OR i.`ciencuadras_destacado` = 'Si' OR i.`ciencuadras_ascendido` = 'Si' OR i.`finca_raiz_silver` = 'Si' OR i.`finca_raiz_gold` = 'Si' OR i.`finca_raiz_black` = 'Si' OR i.`proppit_promocionado` = 'Si') THEN 1 ELSE 0 END) AS destacados,
              COUNT(*) AS total
            FROM `{$table}` i
            WHERE 1=1 {$whereSql}";

    $row = $this->db->getRow($sql, $allArgs);

    // Calculate mis_inmuebles count & user pending requests count
    $myCount = $this->countMyProperties();
    $currentEmployeeId = trim(Auth::employeeId());
    $myRequestsCount = $this->countUserPendingRequests($currentEmployeeId);

    $destacadosCount = (int) ($row['destacados'] ?? 0);
    if (!$canSeeAll) {
      [$myWhere, $myArgs] = $this->buildMyScopeClauses();
      if ($myWhere !== '') {
        $destacadosCount = (int) $this->db->getVar("SELECT COUNT(*) FROM `{$table}` i WHERE (i.`destacado` = 'Si' OR i.`promocion_premium` = 'Si' OR i.`mercado_libre_destacado` = 'Si' OR i.`ciencuadras_destacado` = 'Si' OR i.`ciencuadras_ascendido` = 'Si' OR i.`finca_raiz_silver` = 'Si' OR i.`finca_raiz_gold` = 'Si' OR i.`finca_raiz_black` = 'Si' OR i.`proppit_promocionado` = 'Si') AND ({$myWhere})", $myArgs);
      } else {
        $destacadosCount = 0;
      }
    }

    return [
      'publicos' => (int) ($row['publicos'] ?? 0),
      'pendientes' => (int) ($row['pendientes'] ?? 0),
      'no_publicos' => (int) ($row['no_publicos'] ?? 0),
      'destacados' => $destacadosCount,
      'mis_solicitudes' => $myRequestsCount,
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
   *   destinaciones: array<int,string>,
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
    $destinaciones = $this->db->getCol("SELECT DISTINCT TRIM(destinacion) FROM `{$table}` WHERE destinacion IS NOT NULL AND TRIM(destinacion) <> '' ORDER BY destinacion ASC");

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
      'destinaciones' => array_values(array_filter(array_map('strval', $destinaciones))),
      'estados_no_publicos' => self::STATUSES_NON_PUBLIC,
      'funcionarios' => $funcs,
      'user_quotas' => $this->getUserQuotas(trim(Auth::employeeId())),
      'user_requests' => $this->getUserRequests(trim(Auth::employeeId()), 150),
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

    $propertyCode = (string) ($property['codigo'] ?: $property['_ID']);
    $property['foto_portada_url'] = $coverUrl;
    $property['galeria_urls'] = $galleryUrls;
    $property['amenities_map'] = $this->amenitiesIconMap();
    $property['active_portals'] = $this->getActivePortalKeys($property);
    $property['highlight_history'] = $this->getPropertyHighlightHistory($propertyCode);
    $employeeId = trim(Auth::employeeId());
    if ($employeeId === '' && Auth::userId() > 0) {
      $funcionarios = $this->db->table('jet_cct_funcionarios');
      $emp = $this->db->getVar("SELECT TRIM(COALESCE(`id_empleado`, '')) FROM `{$funcionarios}` WHERE `_ID` = ? LIMIT 1", [Auth::userId()]);
      $employeeId = trim((string) $emp);
    }
    $property['user_quotas'] = $this->getUserQuotas($employeeId);

    return $property;
  }

  /**
   * Load map of all property amenities/features to their SVG icons from the 4 CCT tables:
   * wp_jet_cct_caract_internas, wp_jet_cct_caract_externas, wp_jet_cct_zonas_sociales, wp_jet_cct_alrededores.
   *
   * @return array<string,string>
   */
  public function amenitiesIconMap(): array
  {
    if ($this->amenitiesMapCache !== null) {
      return $this->amenitiesMapCache;
    }

    $map = [];
    $tables = [
      $this->db->table('jet_cct_caract_internas'),
      $this->db->table('jet_cct_caract_externas'),
      $this->db->table('jet_cct_zonas_sociales'),
      $this->db->table('jet_cct_alrededores'),
    ];

    foreach ($tables as $table) {
      $rows = $this->db->getResults("SELECT `valor`, `etiqueta` FROM `{$table}` WHERE `valor` IS NOT NULL AND TRIM(`valor`) <> ''");
      foreach ($rows as $row) {
        $valor = trim((string) ($row['valor'] ?? ''));
        $etiqueta = stripslashes((string) ($row['etiqueta'] ?? ''));
        if ($valor === '') {
          continue;
        }
        if (preg_match('/<svg[\s\S]*?<\/svg>/i', $etiqueta, $m)) {
          $svg = stripslashes($m[0]);
          // Strip out fixed dimensions and legacy classes so our UI classes apply cleanly
          $svg = preg_replace('/\s*(width|height|class)="[^"]*"/i', '', $svg) ?? $svg;
          $svg = preg_replace('/<svg\b/i', '<svg class="w-4 h-4 shrink-0 inline-block text-primary"', $svg, 1) ?? $svg;
          $map[$valor] = $svg;
          $map[mb_strtolower($valor)] = $svg;
        }
      }
    }

    $this->amenitiesMapCache = $map;
    return $map;
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
      } elseif ($subtab === 'destacados') {
        $where[] = "(i.`destacado` = 'Si' OR i.`promocion_premium` = 'Si' OR i.`mercado_libre_destacado` = 'Si' OR i.`ciencuadras_destacado` = 'Si' OR i.`ciencuadras_ascendido` = 'Si' OR i.`finca_raiz_silver` = 'Si' OR i.`finca_raiz_gold` = 'Si' OR i.`finca_raiz_black` = 'Si' OR i.`proppit_promocionado` = 'Si')";
        if (!$canSeeAll) {
          [$myWhere, $myArgs] = $this->buildMyScopeClauses();
          if ($myWhere !== '') {
            $where[] = "({$myWhere})";
            $args = array_merge($args, $myArgs);
          } else {
            $where[] = "1 = 0";
          }
        }
      }
      // 'mis_inmuebles', 'solicitudes', 'cupos' handled separately or without estado restrictions
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

    $destinacion = trim((string) ($filters['destinacion'] ?? ''));
    if ($destinacion !== '') {
      $where[] = 'TRIM(i.`destinacion`) = ?';
      $args[] = $destinacion;
    }

    $destacado = trim((string) ($filters['destacado'] ?? ''));
    if ($destacado !== '') {
      if ($destacado === 'destacados' || $destacado === 'Si') {
        $where[] = "(i.`destacado` = 'Si' OR i.`promocion_premium` = 'Si' OR i.`mercado_libre_destacado` = 'Si' OR i.`ciencuadras_destacado` = 'Si' OR i.`ciencuadras_ascendido` = 'Si' OR i.`finca_raiz_silver` = 'Si' OR i.`finca_raiz_gold` = 'Si' OR i.`finca_raiz_black` = 'Si' OR i.`proppit_promocionado` = 'Si')";
      } elseif ($destacado === 'no_destacados' || $destacado === 'No') {
        $where[] = "(COALESCE(i.`destacado`, 'No') <> 'Si' AND COALESCE(i.`promocion_premium`, 'No') <> 'Si' AND COALESCE(i.`mercado_libre_destacado`, 'No') <> 'Si' AND COALESCE(i.`ciencuadras_destacado`, 'No') <> 'Si' AND COALESCE(i.`ciencuadras_ascendido`, 'No') <> 'Si' AND COALESCE(i.`finca_raiz_silver`, 'No') <> 'Si' AND COALESCE(i.`finca_raiz_gold`, 'No') <> 'Si' AND COALESCE(i.`finca_raiz_black`, 'No') <> 'Si' AND COALESCE(i.`proppit_promocionado`, 'No') <> 'Si')";
      } elseif ($destacado === 'premium') {
        $where[] = "i.`promocion_premium` = 'Si'";
      } elseif ($destacado === 'portal_mercado_libre') {
        $where[] = "i.`mercado_libre_destacado` = 'Si'";
      } elseif ($destacado === 'portal_ciencuadras') {
        $where[] = "(i.`ciencuadras_destacado` = 'Si' OR i.`ciencuadras_ascendido` = 'Si')";
      } elseif ($destacado === 'portal_finca_raiz') {
        $where[] = "(i.`finca_raiz_silver` = 'Si' OR i.`finca_raiz_gold` = 'Si' OR i.`finca_raiz_black` = 'Si')";
      } elseif ($destacado === 'portal_proppit') {
        $where[] = "i.`proppit_promocionado` = 'Si'";
      }
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

  /**
   * Helper to check if a DB value is truthy / affirmative.
   */
  public static function isAffirmative(mixed $value): bool
  {
    $normalized = mb_strtolower(trim((string) $value));
    return in_array($normalized, ['1', 'si', 'sí', 'yes', 'true', 'activo', 'activa', 'destacado', 'promocionado'], true);
  }

  /**
   * Return list of active portal keys for a property.
   *
   * @param array<string,mixed> $property
   * @return array<int,array{key:string,label:string}>
   */
  public function getActivePortalKeys(array $property): array
  {
    $active = [];
    foreach (self::PORTALS as $portalKey => $pInfo) {
      if (self::isAffirmative($property[$pInfo['property_column']] ?? null)) {
        $active[] = ['key' => $portalKey, 'label' => $pInfo['label']];
      }
    }
    return $active;
  }

  /**
   * Count pending highlight requests.
   */
  public function countPendingRequests(): int
  {
    $table = $this->db->table('skc_destacado_solicitudes');
    try {
      return (int) $this->db->getVar("SELECT COUNT(*) FROM `{$table}` WHERE estado = 'pendiente'");
    } catch (\Throwable $e) {
      return 0;
    }
  }

  /**
   * Pending highlight requests count for a specific employee.
   */
  public function countUserPendingRequests(string $employeeId): int
  {
    if ($employeeId === '') {
      return 0;
    }
    $table = $this->db->table('skc_destacado_solicitudes');
    try {
      return (int) $this->db->getVar("SELECT COUNT(*) FROM `{$table}` WHERE solicitado_por_id = ? AND estado = 'pendiente'", [$employeeId]);
    } catch (\Throwable) {
      return 0;
    }
  }

  /**
   * Pending highlight requests joined with property info.
   *
   * @return array<int,array<string,mixed>>
   */
  public function getPendingRequests(int $limit = 100): array
  {
    $requestsTable = $this->db->table('skc_destacado_solicitudes');
    $inmueblesTable = $this->db->table('jet_cct_inmuebles');
    $sql = "SELECT s.*, i.`tipo_inmueble`, i.`tipo_negocio`, i.`ciudad`, i.`barrio`, i.`direccion`, i.`estado` AS inmueble_estado, i.`foto_portada`
              FROM `{$requestsTable}` s
         LEFT JOIN `{$inmueblesTable}` i ON i.`codigo` = s.`codigo_inmueble`
             WHERE s.`estado` = 'pendiente'
          ORDER BY s.`requested_at` ASC, s.`id` ASC
             LIMIT {$limit}";
    return $this->db->getResults($sql);
  }

  /**
   * Requests made by a specific employee, joined with property data and resolved photos.
   *
   * @return array<int,array<string,mixed>>
   */
  public function getUserRequests(string $employeeId, int $limit = 150): array
  {
    if ($employeeId === '') {
      return [];
    }
    $requestsTable = $this->db->table('skc_destacado_solicitudes');
    $inmueblesTable = $this->db->table('jet_cct_inmuebles');
    $sql = "SELECT s.*, 
                   i.`_ID` AS inmueble_id, 
                   i.`tipo_inmueble`, 
                   i.`tipo_negocio`, 
                   i.`ciudad`, 
                   i.`barrio`, 
                   i.`direccion`, 
                   i.`estado` AS inmueble_estado,
                   i.`precio_arriendo`,
                   i.`precio_venta`,
                   i.`foto_portada`,
                   i.`galeria`
              FROM `{$requestsTable}` s
         LEFT JOIN `{$inmueblesTable}` i ON (i.`codigo` = s.`codigo_inmueble` OR CAST(i.`_ID` AS CHAR) = s.`codigo_inmueble`)
             WHERE s.`solicitado_por_id` = ?
          ORDER BY s.`requested_at` DESC
             LIMIT {$limit}";
    $rows = $this->db->getResults($sql, [$employeeId]);
    return $this->attachPhotoUrls($rows);
  }

  /**
   * Cancel/delete a pending highlight request.
   *
   * @return array{ok:bool,message:string}
   */
  public function cancelHighlightRequest(int $requestId, string $employeeId): array
  {
    if ($requestId <= 0) {
      return ['ok' => false, 'message' => 'ID de solicitud no válido.'];
    }
    $table = $this->db->table('skc_destacado_solicitudes');
    $req = $this->db->getRow("SELECT * FROM `{$table}` WHERE id = ? LIMIT 1", [$requestId]);
    if (!$req) {
      return ['ok' => false, 'message' => 'Solicitud no encontrada.'];
    }
    if ($req['estado'] !== 'pendiente') {
      return ['ok' => false, 'message' => 'Solo se pueden cancelar solicitudes pendientes.'];
    }
    if ($employeeId !== '' && trim((string) ($req['solicitado_por_id'] ?? '')) !== $employeeId) {
      return ['ok' => false, 'message' => 'No tienes permiso para cancelar esta solicitud.'];
    }
    $this->db->query("DELETE FROM `{$table}` WHERE id = ?", [$requestId]);
    return ['ok' => true, 'message' => 'Solicitud cancelada correctamente.'];
  }

  /**
   * Quotas allocated vs used vs pending for a specific employee.
   *
   * @return array<string,array{label:string,assigned:int,used:int,pending:int,available:int}>
   */
  public function getUserQuotas(string $employeeId): array
  {
    $empty = [];
    foreach (self::PORTALS as $k => $info) {
      $empty[$k] = ['label' => $info['label'], 'assigned' => 0, 'used' => 0, 'pending' => 0, 'available' => 0];
    }
    if ($employeeId === '') {
      return $empty;
    }

    $funcTable = $this->db->table('jet_cct_funcionarios');
    $inmTable = $this->db->table('jet_cct_inmuebles');
    $reqTable = $this->db->table('skc_destacado_solicitudes');
    $cols = implode(', ', array_keys(self::PORTALS));
    $row = $this->db->getRow("SELECT {$cols} FROM `{$funcTable}` WHERE id_empleado = ? LIMIT 1", [$employeeId]);
    if (!$row) {
      return $empty;
    }

    $res = [];
    foreach (self::PORTALS as $portalKey => $pInfo) {
      $assigned = max(0, (int) ($row[$portalKey] ?? 0));
      $pCol = $pInfo['property_column'];
      $used = (int) $this->db->getVar("SELECT COUNT(*) FROM `{$inmTable}` WHERE TRIM(id_funcionario) = ? AND `{$pCol}` = 'Si' AND estado = 'Publico'", [$employeeId]);
      $pending = (int) $this->db->getVar("SELECT COUNT(*) FROM `{$reqTable}` WHERE solicitado_por_id = ? AND portal = ? AND estado = 'pendiente'", [$employeeId, $portalKey]);
      $available = max(0, $assigned - $used - $pending);
      $res[$portalKey] = [
        'label' => $pInfo['label'],
        'assigned' => $assigned,
        'used' => $used,
        'pending' => $pending,
        'available' => $available,
      ];
    }
    return $res;
  }

  /**
   * All active officials and their quota columns, plus totals and configured limits.
   *
   * @return array<string,mixed>
   */
  public function getAllQuotasSummary(): array
  {
    $funcTable = $this->db->table('jet_cct_funcionarios');
    $cols = implode(', ', array_keys(self::PORTALS));
    $sql = "SELECT _ID, id_empleado, nombre, rol, gestion, activo, {$cols}
              FROM `{$funcTable}`
             WHERE activo = 'Si' AND id_empleado IS NOT NULL AND TRIM(id_empleado) <> ''
          ORDER BY nombre ASC";
    $quotas = $this->db->getResults($sql);

    $totals = array_fill_keys(array_keys(self::PORTALS), 0);
    foreach ($quotas as $emp) {
      foreach (array_keys(self::PORTALS) as $k) {
        $totals[$k] += (int) ($emp[$k] ?? 0);
      }
    }

    $limits = array_fill_keys(array_keys(self::PORTALS), 0);
    try {
      $confTable = $this->db->table('jet_cct_confi_sistema');
      $keys = array_keys(self::PORTALS);
      $placeholders = implode(',', array_fill(0, count($keys), '?'));
      $rows = $this->db->getResults("SELECT funcion, valor FROM `{$confTable}` WHERE funcion IN ({$placeholders})", $keys);
      foreach ($rows as $r) {
        $k = (string) ($r['funcion'] ?? '');
        if (array_key_exists($k, $limits)) {
          $limits[$k] = max(0, (int) ($r['valor'] ?? 0));
        }
      }
    } catch (\Throwable) {
      // Fallback
    }

    return [
      'quotas' => $quotas,
      'totals' => $totals,
      'limits' => $limits,
      'grand_total' => array_sum($totals),
      'grand_limit' => array_sum($limits),
    ];
  }

  /**
   * Active pending requests for a specific property.
   *
   * @return array<int,array<string,mixed>>
   */
  public function getPropertyPendingRequests(string $code): array
  {
    if ($code === '') {
      return [];
    }
    $reqTable = $this->db->table('skc_destacado_solicitudes');
    return $this->db->getResults("SELECT * FROM `{$reqTable}` WHERE codigo_inmueble = ? AND estado = 'pendiente' ORDER BY requested_at DESC", [$code]);
  }

  /**
   * Property highlight history from wp_jet_cct_inmuebles_destacados.
   *
   * @return array<int,array<string,mixed>>
   */
  public function getPropertyHighlightHistory(string $code): array
  {
    if ($code === '') {
      return [];
    }
    $histTable = $this->db->table('jet_cct_inmuebles_destacados');
    return $this->db->getResults("SELECT * FROM `{$histTable}` WHERE id_inmueble = ? ORDER BY _ID DESC LIMIT 20", [$code]);
  }

  /**
   * Request property highlight in a portal.
   *
   * @return array{ok:bool,message:string}
   */
  public function requestHighlight(string $code, string $portal, string $reason, string $oportunidad, string $negociable, string $employeeId, string $employeeName): array
  {
    if (!isset(self::PORTALS[$portal])) {
      return ['ok' => false, 'message' => 'Portal no válido.'];
    }
    if ($code === '' || $reason === '') {
      return ['ok' => false, 'message' => 'Complete el inmueble, portal y motivo.'];
    }

    $inmTable = $this->db->table('jet_cct_inmuebles');
    $reqTable = $this->db->table('skc_destacado_solicitudes');

    $pCol = self::PORTALS[$portal]['property_column'];
    $prop = $this->db->getRow("SELECT _ID, codigo, estado, `{$pCol}` FROM `{$inmTable}` WHERE codigo = ? OR _ID = ? LIMIT 1", [$code, is_numeric($code) ? (int) $code : 0]);
    if (!$prop) {
      return ['ok' => false, 'message' => 'Inmueble no encontrado.'];
    }
    if (trim((string) ($prop[$pCol] ?? '')) === 'Si') {
      return ['ok' => false, 'message' => 'El inmueble ya se encuentra destacado en ' . self::PORTALS[$portal]['label'] . '.'];
    }

    $active = $this->db->getVar("SELECT COUNT(*) FROM `{$reqTable}` WHERE codigo_inmueble = ? AND portal = ? AND estado = 'pendiente'", [$code, $portal]);
    if ((int) $active > 0) {
      return ['ok' => false, 'message' => 'Ya existe una solicitud pendiente para este portal.'];
    }

    if ($employeeId === '') {
      $employeeId = trim(Auth::employeeId());
      if ($employeeId === '' && Auth::userId() > 0) {
        $funcionarios = $this->db->table('jet_cct_funcionarios');
        $emp = $this->db->getVar("SELECT TRIM(COALESCE(`id_empleado`, '')) FROM `{$funcionarios}` WHERE `_ID` = ? LIMIT 1", [Auth::userId()]);
        $employeeId = trim((string) $emp);
      }
    }

    $quotas = $this->getUserQuotas($employeeId);
    $avail = (int) ($quotas[$portal]['available'] ?? 0);
    if ($avail <= 0) {
      return ['ok' => false, 'message' => 'No cuentas con cupos disponibles en ' . self::PORTALS[$portal]['label'] . '.'];
    }

    $now = date('Y-m-d H:i:s');
    $this->db->query("INSERT INTO `{$reqTable}` (codigo_inmueble, inmueble_id, portal, razon, oportunidad, negociable, estado, solicitado_por_id, solicitado_por_nombre, quota_consumida, requested_at)
      VALUES (?, ?, ?, ?, ?, ?, 'pendiente', ?, ?, 'No', ?)", [
      (string) ($prop['codigo'] ?: $prop['_ID']),
      (int) $prop['_ID'],
      $portal,
      $reason,
      $oportunidad === 'Si' ? 'Si' : 'No',
      $negociable === 'Si' ? 'Si' : 'No',
      $employeeId,
      $employeeName,
      $now,
    ]);

    $this->db->query("UPDATE `{$inmTable}` SET marcado_destacado = 'Si', fecha_destacado = ? WHERE _ID = ?", [time(), $prop['_ID']]);

    return ['ok' => true, 'message' => 'Solicitud de destacado para ' . self::PORTALS[$portal]['label'] . ' enviada con éxito.'];
  }

  /**
   * Complete/approve a highlight request.
   *
   * @return array{ok:bool,message:string}
   */
  public function completeHighlightRequest(int $requestId, string $employeeId, string $employeeName): array
  {
    $reqTable = $this->db->table('skc_destacado_solicitudes');
    $inmTable = $this->db->table('jet_cct_inmuebles');
    $histTable = $this->db->table('jet_cct_inmuebles_destacados');
    $opsHistTable = $this->db->table('jet_cct_historial_del_inmueble');

    $req = $this->db->getRow("SELECT * FROM `{$reqTable}` WHERE id = ? AND estado = 'pendiente' LIMIT 1", [$requestId]);
    if (!$req) {
      return ['ok' => false, 'message' => 'La solicitud no existe o ya no está pendiente.'];
    }

    $portal = (string) ($req['portal'] ?? '');
    if (!isset(self::PORTALS[$portal])) {
      return ['ok' => false, 'message' => 'Portal inválido.'];
    }
    $portalInfo = self::PORTALS[$portal];
    $code = (string) $req['codigo_inmueble'];

    $prop = $this->db->getRow("SELECT _ID, codigo FROM `{$inmTable}` WHERE codigo = ? OR _ID = ? LIMIT 1", [$code, is_numeric($code) ? (int) $code : 0]);
    if (!$prop) {
      return ['ok' => false, 'message' => 'Inmueble asociado no encontrado.'];
    }

    $now = time();
    $propId = (int) $prop['_ID'];
    $propCode = (string) ($prop['codigo'] ?: $prop['_ID']);

    // Update request
    $this->db->query("UPDATE `{$reqTable}` SET estado = 'destacado', completado_por_id = ?, completado_por_nombre = ?, completed_at = NOW() WHERE id = ?", [
      $employeeId,
      $employeeName,
      $requestId,
    ]);

    // Update property
    $this->db->query("UPDATE `{$inmTable}` SET fecha_destacado = ?, oportunidad = ?, negociable = ?, destacado = 'Si', `{$portalInfo['property_column']}` = 'Si' WHERE _ID = ?", [
      $now,
      (string) ($req['oportunidad'] ?? 'No'),
      (string) ($req['negociable'] ?? 'No'),
      $propId,
    ]);

    // Check if remaining pending requests
    $remPending = (int) $this->db->getVar("SELECT COUNT(*) FROM `{$reqTable}` WHERE codigo_inmueble = ? AND estado = 'pendiente'", [$propCode]);
    if ($remPending === 0) {
      $this->db->query("UPDATE `{$inmTable}` SET marcado_destacado = 'No' WHERE _ID = ?", [$propId]);
    }

    // Insert history in wp_jet_cct_inmuebles_destacados
    $veces = (int) $this->db->getVar("SELECT MAX(CAST(veces_destacado AS UNSIGNED)) FROM `{$histTable}` WHERE id_inmueble = ?", [$propCode]) + 1;
    $this->db->query("INSERT INTO `{$histTable}` (fecha, id_inmueble, id_empleado, empleado, cct_author_id, cct_created, observacion_destacado, veces_destacado, oportunidad, negociable, `{$portalInfo['history_column']}`)
      VALUES (?, ?, ?, ?, ?, NOW(), ?, ?, ?, ?, 'Si')", [
      $now,
      $propCode,
      (string) ($req['solicitado_por_id'] ?? $employeeId),
      (string) ($req['solicitado_por_nombre'] ?? $employeeName),
      (int) ($employeeId ?: 1),
      (string) ($req['razon'] ?? 'Destacado'),
      $veces,
      (string) ($req['oportunidad'] ?? 'No'),
      (string) ($req['negociable'] ?? 'No'),
    ]);

    // Insert into operational history
    $this->db->query("INSERT INTO `{$opsHistTable}` (fecha, id_inmueble, id_empleado, funcionario, tipo_reporte, observacion, cct_author_id, cct_created, cct_modified)
      VALUES (?, ?, ?, ?, 'Destacado', ?, ?, NOW(), NOW())", [
      $now,
      $propCode,
      $employeeId,
      $employeeName,
      'Se activó el destacado en ' . $portalInfo['label'] . '. Razón: ' . ($req['razon'] ?? 'Aprobado'),
      (int) ($employeeId ?: 1),
    ]);

    return ['ok' => true, 'message' => 'Solicitud completada y destacado activado en ' . $portalInfo['label'] . '.'];
  }

  /**
   * Release highlight for a property and portal.
   *
   * @return array{ok:bool,message:string}
   */
  public function releaseHighlight(string $code, string $portal, string $employeeId, string $employeeName): array
  {
    $inmTable = $this->db->table('jet_cct_inmuebles');
    $opsHistTable = $this->db->table('jet_cct_historial_del_inmueble');

    $prop = $this->db->getRow("SELECT * FROM `{$inmTable}` WHERE codigo = ? OR _ID = ? LIMIT 1", [$code, is_numeric($code) ? (int) $code : 0]);
    if (!$prop) {
      return ['ok' => false, 'message' => 'Inmueble no encontrado.'];
    }

    $activePortals = $this->getActivePortalKeys($prop);
    if ($portal === '' && count($activePortals) === 1) {
      $portal = (string) $activePortals[0]['key'];
    }

    if ($portal === 'all' || $portal === 'todos') {
      $propId = (int) $prop['_ID'];
      $propCode = (string) ($prop['codigo'] ?: $prop['_ID']);
      $updates = [];
      foreach (self::PORTALS as $pInfo) {
        $updates[] = "`{$pInfo['property_column']}` = 'No'";
      }
      $updatesSql = implode(', ', $updates);
      $this->db->query("UPDATE `{$inmTable}` SET {$updatesSql}, destacado = 'No', marcado_destacado = 'No', fecha_destacado = NULL WHERE _ID = ?", [$propId]);

      $now = time();
      $this->db->query("INSERT INTO `{$opsHistTable}` (fecha, id_inmueble, id_empleado, funcionario, tipo_reporte, observacion, cct_author_id, cct_created, cct_modified)
        VALUES (?, ?, ?, ?, 'Destacado', ?, ?, NOW(), NOW())", [
        $now,
        $propCode,
        $employeeId,
        $employeeName,
        'Se liberaron todos los cupos de destacados del inmueble.',
        (int) ($employeeId ?: 1),
      ]);
      return ['ok' => true, 'message' => 'Se han liberado todos los cupos de destacados del inmueble.'];
    }

    if (!isset(self::PORTALS[$portal])) {
      return ['ok' => false, 'message' => 'Portal no válido.'];
    }
    $portalInfo = self::PORTALS[$portal];
    $propId = (int) $prop['_ID'];
    $propCode = (string) ($prop['codigo'] ?: $prop['_ID']);

    // Check if other portals remain active
    $remaining = false;
    foreach (self::PORTALS as $k => $info) {
      if ($k !== $portal && self::isAffirmative($prop[$info['property_column']] ?? '')) {
        $remaining = true;
        break;
      }
    }

    $reqTable = $this->db->table('skc_destacado_solicitudes');
    $hasPending = (int) $this->db->getVar("SELECT COUNT(*) FROM `{$reqTable}` WHERE codigo_inmueble = ? AND estado = 'pendiente'", [$propCode]) > 0;

    $now = time();
    $this->db->query("UPDATE `{$inmTable}` SET `{$portalInfo['property_column']}` = 'No', destacado = ?, marcado_destacado = ?, fecha_destacado = ? WHERE _ID = ?", [
      $remaining ? 'Si' : 'No',
      ($remaining || $hasPending) ? 'Si' : 'No',
      ($remaining || $hasPending) ? ($prop['fecha_destacado'] ?? $now) : null,
      $propId,
    ]);

    // Insert into operational history
    $this->db->query("INSERT INTO `{$opsHistTable}` (fecha, id_inmueble, id_empleado, funcionario, tipo_reporte, observacion, cct_author_id, cct_created, cct_modified)
      VALUES (?, ?, ?, ?, 'Destacado', ?, ?, NOW(), NOW())", [
      $now,
      $propCode,
      $employeeId,
      $employeeName,
      'Se liberó el cupo de destacado de ' . $portalInfo['label'] . ($remaining ? '. Los demás portales permanecen activos.' : '.'),
      (int) ($employeeId ?: 1),
    ]);

    return ['ok' => true, 'message' => 'Cupo de ' . $portalInfo['label'] . ' liberado correctamente.'];
  }

  /**
   * Toggle Promoción Premium for property and sync with WordPress post meta.
   *
   * @return array{ok:bool,message:string}
   */
  public function togglePremium(string $code, string $value, string $employeeId, string $employeeName): array
  {
    $value = $value === 'Si' ? 'Si' : 'No';
    $inmTable = $this->db->table('jet_cct_inmuebles');
    $postmetaTable = $this->db->table('postmeta');
    $opsHistTable = $this->db->table('jet_cct_historial_del_inmueble');

    $prop = $this->db->getRow("SELECT _ID, codigo, estado, estrato, promocion_premium FROM `{$inmTable}` WHERE codigo = ? OR _ID = ? LIMIT 1", [$code, is_numeric($code) ? (int) $code : 0]);
    if (!$prop) {
      return ['ok' => false, 'message' => 'Inmueble no encontrado.'];
    }

    $propId = (int) $prop['_ID'];
    $propCode = (string) ($prop['codigo'] ?: $prop['_ID']);

    $this->db->query("UPDATE `{$inmTable}` SET promocion_premium = ? WHERE _ID = ?", [$value, $propId]);

    // Sync with WordPress postmeta
    if (is_numeric($propCode)) {
      $postId = (int) $propCode;
      $metaId = (int) $this->db->getVar("SELECT meta_id FROM `{$postmetaTable}` WHERE post_id = ? AND meta_key = 'inmueble-premium' LIMIT 1", [$postId]);
      if ($metaId > 0) {
        $this->db->query("UPDATE `{$postmetaTable}` SET meta_value = ? WHERE meta_id = ?", [$value, $metaId]);
      } else {
        $this->db->query("INSERT INTO `{$postmetaTable}` (post_id, meta_key, meta_value) VALUES (?, 'inmueble-premium', ?)", [$postId, $value]);
      }
    }

    // Insert into operational history
    $obs = $value === 'Si' ? 'El inmueble fue marcado para Promoción Premium.' : 'El inmueble fue retirado de Promoción Premium.';
    $this->db->query("INSERT INTO `{$opsHistTable}` (fecha, id_inmueble, id_empleado, funcionario, tipo_reporte, observacion, cct_author_id, cct_created, cct_modified)
      VALUES (?, ?, ?, ?, 'Promoción premium', ?, ?, NOW(), NOW())", [
      time(),
      $propCode,
      $employeeId,
      $employeeName,
      $obs,
      (int) ($employeeId ?: 1),
    ]);

    return [
      'ok' => true,
      'is_premium' => $value === 'Si',
      'message' => $value === 'Si' ? 'Inmueble marcado como Promoción Premium.' : 'Inmueble retirado de Promoción Premium.',
    ];
  }
}
