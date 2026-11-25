<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SCM\Commercial\CommercialSettingsService;
use SCM\Commercial\Mandates\MandateContractText;
use SCM\Commercial\Mandates\MandateSignature;
use SCM\Commercial\Mandates\MandateTemplateEditor;

/** Configuración › Contratos de mandato: editar el texto de las plantillas de eSignatures. */
final class MandateTemplateEditorTest extends TestCase
{
  private string $directory;
  /** @var array<string,string> texto de cada plantilla en el eSignatures simulado */
  private array $remote = [];
  /** @var list<array{0:string,1:string,2:?array<string,mixed>}> */
  private array $calls = [];

  protected function setUp(): void
  {
    $this->directory = sys_get_temp_dir() . '/scm-plantillas-' . bin2hex(random_bytes(4));
    foreach (MandateSignature::TEMPLATES as $type => $id) {
      $this->remote[$id] = "**Cartagena,** {{fecha}}.\n# ANEXO 1\n{{tabla_propietarios}}\n| Canon | {{precio_canon}} |\n| --- | --- |\nTipo " . $type;
    }
  }

  protected function tearDown(): void
  {
    foreach (array_merge(glob($this->directory . '/historial/*') ?: [], glob($this->directory . '/*.*') ?: []) as $file) {
      @unlink($file);
    }
    @rmdir($this->directory . '/historial');
    @rmdir($this->directory);
  }

  private function editor(bool $withToken = true): MandateTemplateEditor
  {
    $signature = new MandateSignature('token', true, function (string $method, string $path, ?array $body): array {
      $this->calls[] = [$method, $path, $body];
      if (preg_match('#^templates/([\w-]+)/content$#', $path, $m) === 1) {
        if ($method === 'POST') {
          $this->remote[$m[1]] = (string) $body['edits'][0]['replace_with_markdown'];
          return ['status' => 'updated', 'data' => ['markdown' => $this->remote[$m[1]]]];
        }
        return ['data' => ['template_id' => $m[1], 'markdown' => $this->remote[$m[1]]]];
      }
      return ['data' => []];
    });
    return new MandateTemplateEditor($withToken ? $signature : null, $this->directory);
  }

  /** @return array<string,string> */
  private function edit(string $type, string $markdown, array $extra = []): array
  {
    $current = $this->remote[MandateSignature::TEMPLATES[$type]];
    return $extra + ['tipo' => $type, 'markdown' => $markdown, 'huella' => MandateTemplateEditor::fingerprint($current), 'confirmo' => '1'];
  }

  public function testItLoadsTheThreeTemplatesFromESignatures(): void
  {
    $data = $this->editor()->load();
    self::assertTrue($data['ready']);
    self::assertSame(['Por defecto', 'Berkley', 'Seguros mundial'], array_column($data['templates'], 'tipo'));
    self::assertSame(['fecha', 'tabla_propietarios', 'precio_canon'], $data['templates'][0]['campos']);
    self::assertContains('precio_canon', $data['known']);
    self::assertStringContainsString('ESIGNATURES_TOKEN', $this->editor(false)->load()['message']);
  }

  public function testSavingReplacesTheTemplateKeepsTheOldOneAndRenewsTheCopy(): void
  {
    $editor = $this->editor();
    $before = $this->remote[MandateSignature::TEMPLATES['Berkley']];
    $new = str_replace('Tipo Berkley', 'Cláusula nueva {{duracion}}', $before);
    $message = $editor->save($this->edit('Berkley', $new), '999', 'Administrador');
    self::assertStringContainsString('Berkley', $message);
    self::assertSame($new, $this->remote[MandateSignature::TEMPLATES['Berkley']]);
    $post = end($this->calls);
    self::assertSame(['POST', 'templates/' . MandateSignature::TEMPLATES['Berkley'] . '/content'], [$post[0], $post[1]]);
    self::assertSame(['dry_run' => 'no', 'edits' => [['find_markdown' => '', 'replace_with_markdown' => $new]]], $post[2], 'find_markdown vacío reemplaza todo el texto.');
    self::assertSame($new, file_get_contents($this->directory . '/berkley.md'), 'La copia de la página del mandato se renueva de una vez.');
    $history = $editor->history();
    self::assertSame(['Berkley', '999', 'Administrador'], [$history[0]['tipo'], $history[0]['id_empleado'], $history[0]['nombre']]);
    self::assertSame($before, $editor->version($history[0]['id']), 'La versión anterior queda para volver a ella.');
    self::assertSame($new, MandateContractText::template('Berkley', null, $this->directory));
  }

  public function testItRefusesUnsafeChanges(): void
  {
    $editor = $this->editor();
    $current = $this->remote[MandateSignature::TEMPLATES['Por defecto']];
    $cases = [
      'sin confirmar' => [$this->edit('Por defecto', $current . ' más', ['confirmo' => '']), 'Confirma'],
      'cambió en eSignatures' => [$this->edit('Por defecto', $current . ' más', ['huella' => 'otra']), 'cambió en eSignatures'],
      'sin cambios' => [$this->edit('Por defecto', $current), 'No hay cambios'],
      'campo desconocido' => [$this->edit('Por defecto', $current . ' {{precio_canonn}}'), '{{precio_canonn}}'],
      'quita un campo' => [$this->edit('Por defecto', str_replace('{{precio_canon}}', '', $current)), 'Quitaste campos'],
      'vacío' => [$this->edit('Por defecto', '  '), 'vacío'],
      'tipo' => [$this->edit('Por defecto', 'x', ['tipo' => 'Otro']), 'Elige'],
    ];
    foreach ($cases as $name => [$data, $message]) {
      try {
        $editor->save($data, '999', 'Administrador');
        self::fail('Debía rechazar: ' . $name);
      } catch (\InvalidArgumentException $error) {
        self::assertStringContainsString($message, $error->getMessage(), $name);
      }
    }
    self::assertSame($current, $this->remote[MandateSignature::TEMPLATES['Por defecto']], 'Nada se guardó en eSignatures.');
    self::assertSame([], $editor->history());
    $editor->save($this->edit('Por defecto', str_replace('{{precio_canon}}', '', $current), ['confirmar_campos' => '1']), '999', 'Administrador');
    self::assertSame('precio_canon', $editor->history()[0]['campos_quitados'], 'Confirmado, se quita y queda anotado.');
  }

  public function testTheRealTemplatesOnlyUseFieldsThePanelFills(): void
  {
    foreach (MandateContractText::FILES as $type => $file) {
      $fields = MandateTemplateEditor::fields((string) file_get_contents(dirname(__DIR__, 2) . '/resources/mandatos/' . $file));
      self::assertSame([], array_values(array_diff($fields, MandateTemplateEditor::knownFields())), $type);
    }
    $preview = MandateTemplateEditor::preview('Berkley', "Hola {{precio_canon}}\n{{tabla_propietarios}}");
    self::assertStringContainsString('No aplica', $preview);
    self::assertContains('mandatos', CommercialSettingsService::SECTIONS);
  }
}
