<?php

declare(strict_types=1);

namespace SCM\Precaptacion;

use SCM\Core\Auth;
use SCM\Http\Response\JsonResponse;
use SCM\Support\EmailQueue;

const ARRAY_A = 'ARRAY_A';

function is_user_logged_in(): bool { return Auth::isLoggedIn(); }
function get_current_user_id(): int { return (int) Auth::employeeId(); }
function current_user_can(string $capability): bool { return Module::policy()->canManage(); }
function disabled($value, $current = true, bool $echo = true): string { $attribute = $value == $current ? ' disabled="disabled"' : ''; if ($echo) echo $attribute; return $attribute; }
function shortcode_atts(array $defaults, array $attributes, string $name = ''): array { return array_merge($defaults, array_intersect_key($attributes, $defaults)); }
function absint($value): int { return abs((int) $value); }
function wp_unslash($value) { return $value; }
function wp_strip_all_tags(string $value): string { return strip_tags($value); }
function sanitize_text_field($value): string { return trim(preg_replace('/\s+/u', ' ', strip_tags(is_scalar($value) ? (string) $value : ''))); }
function sanitize_textarea_field($value): string { return trim(strip_tags(is_scalar($value) ? (string) $value : '')); }
function sanitize_email($value): string { return filter_var((string) $value, FILTER_VALIDATE_EMAIL) ? (string) $value : ''; }
function is_email($value): bool { return filter_var((string) $value, FILTER_VALIDATE_EMAIL) !== false; }
function wp_json_encode($value): string { return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); }
function maybe_serialize($value): string { return is_array($value) || is_object($value) ? serialize($value) : (string) $value; }
function maybe_unserialize($value) { if (!is_string($value)) return $value; $result = @unserialize($value, ['allowed_classes' => false]); return $result !== false || $value === 'b:0;' ? $result : $value; }
function is_serialized($value): bool { return is_string($value) && (maybe_unserialize($value) !== $value || $value === 'b:0;'); }
function remove_accents(string $value): string { return strtr($value, ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n','Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','Ü'=>'U','Ñ'=>'N']); }
function number_format_i18n($value): string { return number_format((float) $value); }
function current_time(string $type) { return $type === 'timestamp' ? time() : (new \DateTimeImmutable('now', new \DateTimeZone('America/Bogota')))->format('Y-m-d H:i:s'); }
function get_bloginfo(string $key): string { return $key === 'charset' ? 'UTF-8' : 'SuCasa Inmobiliaria'; }
function home_url(string $path = ''): string { return 'https://sucasainmobiliaria.com.co/' . ltrim($path, '/'); }
function admin_url(string $path = ''): string { return rtrim(SCM_BASE_URL, '/') . '/precaptacion-api.php'; }
function add_query_arg(array $args, string $url): string { return $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($args); }
function apply_filters(string $key, $value) { return $value; }
function wp_create_nonce(string $action): string { return Module::nonce(); }
function check_ajax_referer(string $action, string $field): void { Module::verify((string) ($_POST[$field] ?? '')); }
function wp_get_attachment_image_url(int $id, string $size = ''): string
{
  return (string) Module::db()->getVar('SELECT guid FROM `' . Module::db()->table('posts') . '` WHERE ID = ? AND post_type = ?', [$id, 'attachment']);
}
function get_userdata($id)
{
  $row = Module::employee((string) $id);
  return $row === [] ? false : (object) ['ID' => $id, 'display_name' => $row['nombre'] ?? '', 'user_email' => $row['correo'] ?? '', 'user_login' => $row['user_others_apss'] ?? ''];
}
function get_user_meta($id, string $key, bool $single = false)
{
  $row = Module::employee((string) $id);
  $field = ['sucursal-user'=>'sucursal','pais-user'=>'pais','ciudad-user'=>'ciudad','celular-del-empleado'=>'celular'][$key] ?? '';
  if (isset($row[$field])) return $row[$field];
  $repository = new Repository(Module::db());
  if ($repository->columns('usermeta') === []) return '';
  return Module::db()->getVar('SELECT meta_value FROM `' . Module::db()->table('usermeta') . '` WHERE user_id = ? AND meta_key = ? LIMIT 1', [$id, $key]) ?? '';
}
function wp_mail($to, string $subject, string $html, $headers = []): bool
{
  $recipients = is_array($to) ? $to : preg_split('/[,;]/', (string) $to);
  Module::deferEmail($recipients, $subject, $html, is_array($headers) ? $headers : [$headers]);
  return true;
}
function wp_send_json_success(array $data = [], int $status = 200): never
{
  Module::commit();
  JsonResponse::success($data, $status);
}
function wp_send_json_error(array $data = [], int $status = 400): never
{
  Module::rollback();
  JsonResponse::error((string) ($data['message'] ?? 'No se pudo completar la operación.'), $status);
}
