<?php

declare(strict_types=1);

namespace SCM\Commercial\Mandates;

/**
 * Configuración › Actividades comerciales › Contratos de mandato: el texto de las tres plantillas de eSignatures
 * (MandateSignature::TEMPLATES) se edita desde el panel.
 *
 * - Se lee la plantilla vigente de eSignatures (no la copia de una hora) y se guarda reemplazando todo su texto.
 * - Antes de guardar: la plantilla no debe haber cambiado en eSignatures desde que se abrió (huella), los {{campos}}
 *   nuevos deben existir (los que llena el panel) y quitar un campo exige confirmarlo.
 * - Cada cambio guarda la versión anterior en storage/data/mandatos-plantillas/historial/ (quién y cuándo) y renueva
 *   la copia que usa la página del mandato (MandateContractText).
 * Solo lo usan los administradores (CommercialSettingsService::authorize).
 */
final class MandateTemplateEditor
{
  public const MAX_BYTES = 300000;
  private const HISTORY_LIMIT = 30;

  private ?MandateSignature $signature;
  private string $directory;

  public function __construct(?MandateSignature $signature, ?string $directory = null)
  {
    $this->signature = $signature;
    $this->directory = rtrim($directory ?? dirname(__DIR__, 3) . '/storage/data/mandatos-plantillas', '/\\');
  }

  /** @return array{ready:bool,message:string,templates:list<array{tipo:string,markdown:string,huella:string,campos:list<string>}>,known:list<string>,history:list<array<string,string>>} */
  public function load(): array
  {
    $data = ['ready' => false, 'message' => '', 'templates' => [], 'known' => self::knownFields(), 'history' => array_slice($this->history(), 0, 15)];
    if ($this->signature === null) {
      $data['message'] = 'La firma digital no está configurada (ESIGNATURES_TOKEN en el .env).';
      return $data;
    }
    try {
      foreach (array_keys(MandateSignature::TEMPLATES) as $type) {
        $markdown = $this->signature->templateMarkdown($type);
        $data['templates'][] = ['tipo' => $type, 'markdown' => $markdown, 'huella' => self::fingerprint($markdown), 'campos' => self::fields($markdown)];
      }
    } catch (\Throwable $error) {
      $data['templates'] = [];
      $data['message'] = 'No se pudieron leer las plantillas de eSignatures: ' . $error->getMessage();
      return $data;
    }
    $data['ready'] = true;
    return $data;
  }

  /**
   * Guarda el texto de una plantilla en eSignatures.
   * @param array<string,mixed> $data tipo, markdown, huella (la de lo que se abrió), confirmar_campos, confirmo
   */
  public function save(array $data, string $employeeId, string $employeeName): string
  {
    $type = trim((string) ($data['tipo'] ?? ''));
    $markdown = str_replace(["\r\n", "\r"], "\n", (string) ($data['markdown'] ?? ''));
    if (!isset(MandateSignature::TEMPLATES[$type])) {
      throw new \InvalidArgumentException('Elige la plantilla que vas a cambiar.');
    }
    if ($this->signature === null) {
      throw new \InvalidArgumentException('La firma digital no está configurada (ESIGNATURES_TOKEN en el .env).');
    }
    if (trim($markdown) === '') {
      throw new \InvalidArgumentException('El texto de la plantilla no puede quedar vacío.');
    }
    if (strlen($markdown) > self::MAX_BYTES) {
      throw new \InvalidArgumentException('El texto es demasiado largo.');
    }
    if ((string) ($data['confirmo'] ?? '') !== '1') {
      throw new \InvalidArgumentException('Confirma que el cambio aplica a todos los contratos nuevos de «' . $type . '».');
    }
    try {
      $current = $this->signature->templateMarkdown($type);
    } catch (\Throwable $error) {
      throw new \InvalidArgumentException('No se pudo leer la plantilla de eSignatures: ' . $error->getMessage());
    }
    if (!hash_equals(self::fingerprint($current), (string) ($data['huella'] ?? ''))) {
      throw new \InvalidArgumentException('La plantilla «' . $type . '» cambió en eSignatures después de que la abriste. Cierra y vuelve a abrir esta sección para editar la versión actual.');
    }
    if (self::fingerprint($current) === self::fingerprint($markdown)) {
      throw new \InvalidArgumentException('No hay cambios en «' . $type . '».');
    }
    $unknown = array_values(array_diff(self::fields($markdown), self::knownFields()));
    if ($unknown !== []) {
      throw new \InvalidArgumentException('Estos campos no los llena el panel y saldrían vacíos: {{' . implode('}}, {{', $unknown) . '}}. Revisa cómo están escritos.');
    }
    $removed = array_values(array_diff(self::fields($current), self::fields($markdown)));
    if ($removed !== [] && (string) ($data['confirmar_campos'] ?? '') !== '1') {
      throw new \InvalidArgumentException('Quitaste campos que llenan los datos del contrato: {{' . implode('}}, {{', $removed) . '}}. Confirma que quieres quitarlos.');
    }
    $this->remember($type, $current, $employeeId, $employeeName, $removed);
    try {
      $saved = $this->signature->replaceTemplateMarkdown($type, $markdown);
    } catch (\Throwable $error) {
      throw new \InvalidArgumentException('eSignatures no guardó la plantilla: ' . $error->getMessage());
    }
    // La página del mandato usa la copia: se renueva ya para que el cambio se vea de una vez.
    $this->write($this->directory . '/' . MandateContractText::FILES[$type], $saved !== '' ? $saved : $markdown);
    return 'Plantilla «' . $type . '» guardada en eSignatures. Aplica a los contratos que se envíen a firma desde ahora.';
  }

  /** Texto de una versión anterior del historial (para cargarla en el editor). */
  public function version(string $id): string
  {
    foreach ($this->history() as $entry) {
      if ($entry['id'] === $id && preg_match('/^[\w.-]+$/', $entry['archivo']) === 1 && is_file($this->directory . '/historial/' . $entry['archivo'])) {
        return (string) file_get_contents($this->directory . '/historial/' . $entry['archivo']);
      }
    }
    throw new \InvalidArgumentException('Esa versión ya no está en el historial.');
  }

  /** HTML de la plantilla con datos de ejemplo vacíos (lo que verá el propietario, sin los datos de un mandato). */
  public static function preview(string $type, string $markdown): string
  {
    $post = ['tipo_contrato' => $type, 'cantidad_propietarios' => '1'];
    return MandateContractText::render(str_replace(["\r\n", "\r"], "\n", $markdown), MandateSignature::values($post, null), [
      'tabla_propietarios' => MandateSignature::ownersTable($post),
      'tabla_acuerdos' => MandateSignature::agreementsTable($post),
    ]);
  }

  /** @return list<string> {{campos}} del texto, sin repetir y en orden. */
  public static function fields(string $markdown): array
  {
    preg_match_all('/\{\{\s*([\w-]+)\s*\}\}/u', $markdown, $matches);
    return array_values(array_unique($matches[1]));
  }

  /** @return list<string> Campos que llena el panel en cualquiera de los tres tipos (MandateSignature::values y las tablas). */
  public static function knownFields(): array
  {
    $known = ['tabla_propietarios', 'tabla_acuerdos'];
    foreach (array_keys(MandateSignature::TEMPLATES) as $type) {
      $known = array_merge($known, array_keys(MandateSignature::values(['tipo_contrato' => $type, 'cantidad_propietarios' => '1'], null)));
    }
    $known = array_values(array_unique($known));
    sort($known);
    return $known;
  }

  public static function fingerprint(string $markdown): string
  {
    return hash('sha256', trim(str_replace(["\r\n", "\r"], "\n", $markdown)));
  }

  /** @return list<array<string,string>> Cambios guardados, el más reciente primero. */
  public function history(): array
  {
    $file = $this->directory . '/historial/historial.json';
    $rows = is_file($file) ? json_decode((string) file_get_contents($file), true) : [];
    return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
  }

  /** @param list<string> $removed */
  private function remember(string $type, string $previous, string $employeeId, string $employeeName, array $removed): void
  {
    $id = date('YmdHis') . '-' . bin2hex(random_bytes(3));
    $name = pathinfo(MandateContractText::FILES[$type], PATHINFO_FILENAME) . '-' . $id . '.md';
    $this->write($this->directory . '/historial/' . $name, $previous);
    $history = $this->history();
    array_unshift($history, ['id' => $id, 'tipo' => $type, 'fecha' => date('Y-m-d H:i'), 'id_empleado' => $employeeId, 'nombre' => $employeeName, 'archivo' => $name, 'campos_quitados' => implode(', ', $removed)]);
    // Se guardan las últimas versiones; las más viejas se borran.
    foreach (array_slice($history, self::HISTORY_LIMIT) as $old) {
      if (preg_match('/^[\w.-]+$/', (string) ($old['archivo'] ?? '')) === 1) {
        @unlink($this->directory . '/historial/' . $old['archivo']);
      }
    }
    $this->write($this->directory . '/historial/historial.json', (string) json_encode(array_slice($history, 0, self::HISTORY_LIMIT), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
  }

  private function write(string $path, string $content): void
  {
    if (!is_dir(dirname($path)) && !@mkdir(dirname($path), 0775, true) && !is_dir(dirname($path))) {
      throw new \InvalidArgumentException('No se pudo crear la carpeta ' . dirname($path) . ' (permisos de storage/data).');
    }
    if (@file_put_contents($path, $content) === false) {
      throw new \InvalidArgumentException('No se pudo guardar ' . basename($path) . ' (permisos de storage/data).');
    }
  }
}
