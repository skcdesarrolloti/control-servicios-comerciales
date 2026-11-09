<?php

declare(strict_types=1);

$env = static function (string $key, ?string $default = null): ?string {
  $value = getenv($key);
  return $value === false ? $default : $value;
};

$envBool = static function (string $key, bool $default = false) use ($env): bool {
  $value = $env($key);
  if ($value === null) {
    return $default;
  }
  return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $default;
};

return [
  'environment' => $env('APP_ENV', 'production'),
  'debug' => $envBool('APP_DEBUG', false),
  'secure_cookies' => $envBool('APP_SECURE_COOKIES', true),
  'timezone' => $env('APP_TIMEZONE', 'America/Bogota'),
  'base_url' => $env('APP_URL', ''),
  'app_secret' => $env('APP_SECRET', ''),

  'db_host' => $env('DB_HOST', ''),
  'db_port' => (int) $env('DB_PORT', '3306'),
  'db_name' => $env('DB_DATABASE', ''),
  'db_user' => $env('DB_USERNAME', ''),
  'db_pass' => $env('DB_PASSWORD', ''),
  'db_prefix' => $env('DB_PREFIX', 'wp_'),
  'db_charset' => $env('DB_CHARSET', 'utf8mb4'),

  'sla_risk_days' => (int) $env('SLA_RISK_DAYS', '8'),
  'sla_overdue_days' => (int) $env('SLA_OVERDUE_DAYS', '15'),
  'default_per_page' => (int) $env('DEFAULT_PER_PAGE', '10'),

  'ticket_url' => $env('TICKET_URL', ''),
  'preventiva_url' => $env('PREVENTIVA_URL', ''),
  'correctiva_url' => $env('CORRECTIVA_URL', ''),
  'cotizacion_url' => $env('COTIZACION_URL', ''),
  'group_comercial' => $env('GROUP_COMERCIAL', ''),
  'shared_notifications_path' => $env('SHARED_NOTIFICATIONS_PATH', ''),
  'allow_legacy_passwords' => $envBool('AUTH_ALLOW_LEGACY_PASSWORDS', false),
  'settings_function_key' => $env('SETTINGS_FUNCTION_KEY', 'control_servicios_comerciales_config'),
  'dashboard_admin_cargos' => array_values(array_filter(array_map('trim', explode(',', (string) $env('DASHBOARD_ADMIN_CARGOS', '11,12,13,14'))))),
  'commercial_employee_cargos' => array_values(array_filter(array_map('trim', explode(',', (string) $env('COMMERCIAL_EMPLOYEE_CARGOS', '1,6,9,10,11,12,13,14,17'))))),
  'calendar_allowed_cargos' => array_values(array_filter(array_map('trim', explode(',', (string) $env('CALENDAR_ALLOWED_CARGOS', '9,10,17'))))),
  // Vacío = la API del propio panel (public/calendar-api.php).
  'calendar_api_url' => $env('CALENDAR_API_URL', ''),
  'google_client_id' => $env('GOOGLE_CLIENT_ID', ''),
  'google_client_secret' => $env('GOOGLE_CLIENT_SECRET', ''),
  'google_redirect_uri' => $env('CALENDARIO_GOOGLE_REDIRECT_URI', ''),
  'google_token_key' => $env('CALENDARIO_GOOGLE_TOKEN_KEY', ''),
  'google_sync_enabled' => $envBool('CALENDARIO_GOOGLE_SYNC_ENABLED', false),
  'google_calendar_id' => $env('CALENDARIO_GOOGLE_CALENDAR_ID', 'primary'),
  'reminder_template' => $env('CALENDARIO_WHATSAPP_REMINDER_TEMPLATE', 'recordatorio_calendario'),
  'reminder_template_lang' => $env('CALENDARIO_WHATSAPP_REMINDER_TEMPLATE_LANG', 'es_CO'),
  'minimax_api_key' => $env('MINIMAX_API_KEY', ''),
  'minimax_base_url' => rtrim((string) $env('MINIMAX_BASE_URL', 'https://api.minimax.io/v1'), '/'),
  'minimax_model' => $env('MINIMAX_MODEL', 'MiniMax-M3'),
  'minimax_timeout' => max(10, (int) $env('MINIMAX_TIMEOUT', '45')),
  'clipdrop_api_key' => $env('CLIPDROP_API_KEY', ''),
  'property_video_url' => $env('PROPERTY_VIDEO_URL', ''),
  'property_video_token' => $env('PROPERTY_VIDEO_TOKEN', ''),
  'auto_login_enabled' => $envBool('AUTO_LOGIN_ENABLED', false),
  'auto_login_secret' => $env('AUTO_LOGIN_SECRET', ''),
  'auto_login_ttl' => max(60, (int) $env('AUTO_LOGIN_TTL', '300')),

  // Redes sociales: las mismas variables de dashboard-marketing. Las direcciones de retorno siguen siendo las registradas
  // en Google y TikTok; legacy/dashboard-marketing las reenvía a public/social-callback.php.
  'meta' => [
    'graph_version' => $env('META_GRAPH_VERSION', 'v25.0'),
    'access_token' => $env('META_ACCESS_TOKEN', ''),
    'instagram_user_id' => $env('META_IG_USER_ID', ''),
    'facebook_page_id' => $env('META_FB_PAGE_ID', ''),
    'facebook_page_access_token' => $env('META_FB_PAGE_ACCESS_TOKEN', ''),
  ],
  'youtube' => [
    'client_id' => $env('GOOGLE_YOUTUBE_CLIENT_ID', ''),
    'client_secret' => $env('GOOGLE_YOUTUBE_CLIENT_SECRET', ''),
    'redirect_uri' => $env('GOOGLE_YOUTUBE_REDIRECT_URI', 'https://sucasainmobiliaria.com.co/dashboard-marketing/youtube/callback'),
    'channel_id' => $env('GOOGLE_YOUTUBE_CHANNEL_ID', ''),
    'channel_name' => $env('GOOGLE_YOUTUBE_CHANNEL_NAME', ''),
    'token_key' => $env('GOOGLE_YOUTUBE_TOKEN_KEY', ''),
  ],
  'tiktok' => [
    'client_key' => $env('TIKTOK_CLIENT_KEY', ''),
    'client_secret' => $env('TIKTOK_CLIENT_SECRET', ''),
    'redirect_uri' => $env('TIKTOK_REDIRECT_URI', 'https://sucasainmobiliaria.com.co/dashboard-marketing/tiktok/callback'),
    'scopes' => $env('TIKTOK_SCOPES', 'user.info.basic,video.list'),
    'token_key' => $env('TIKTOK_TOKEN_KEY', ''),
  ],

  'upload_max_bytes' => (int) $env('UPLOAD_MAX_BYTES', '10485760'),
  'wordpress_upload_path' => $env('WORDPRESS_UPLOAD_PATH', ''),
  'wordpress_upload_url' => $env('WORDPRESS_UPLOAD_URL', ''),
  'upload_max_files' => (int) $env('UPLOAD_MAX_FILES', '10'),
  'sign_notification_emails' => $env('SIGN_NOTIFICATION_EMAILS', 'gcorrearivera@gmail.com,sc.dircomercial@gmail.com,sucasacomercial@gmail.com,sucasacorreos@gmail.com,nabuita1@gmail.com'),
  'property_entry_notification_emails' => $env('PROPERTY_ENTRY_NOTIFICATION_EMAILS', 'sucasacomercial@gmail.com,gcorrearivera@gmail.com,sc.dircomercial@gmail.com'),

  'cliente_notifications_override' => [
    'enabled' => $envBool('CLIENTE_NOTIFICATION_OVERRIDE_ENABLED', false),
    'email' => $env('CLIENTE_NOTIFICATION_OVERRIDE_EMAIL', ''),
    'whatsapp' => $env('CLIENTE_NOTIFICATION_OVERRIDE_WHATSAPP', ''),
    'name' => $env('CLIENTE_NOTIFICATION_OVERRIDE_NAME', ''),
  ],
];
