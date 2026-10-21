<?php
declare(strict_types=1);
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SCM\Commercial\CommercialImageCleanupService;

final class CommercialImageCleanupTest extends TestCase
{
  private array $files = [];
  protected function tearDown(): void { foreach ($this->files as $file) @unlink($file); }

  private function png(int $width = 500, int $height = 500): string
  {
    $chunk = static fn(string $type, string $data): string => pack('N',strlen($data)) . $type . $data . pack('N',crc32($type . $data));
    return "\x89PNG\r\n\x1a\n" . $chunk('IHDR',pack('NNCCCCC',$width,$height,8,2,0,0,0)) . $chunk('IDAT',gzcompress(str_repeat("\0" . str_repeat("\xff",$width * 3),$height))) . $chunk('IEND','');
  }
  private function file(string $data): string { $path = tempnam(sys_get_temp_dir(),'scm-cleanup-'); $this->files[] = $path; file_put_contents($path,$data); return $path; }

  public function testDisabledProviderNeverCallsTransport(): void
  {
    $service = new CommercialImageCleanupService([],function () { self::fail('Se llamó al proveedor sin configuración.'); });
    self::assertFalse($service->available());
    $this->expectException(\RuntimeException::class); $service->cleanup('not-uploaded','not-uploaded');
  }
  public function testReturnsValidatedImageWithoutExposingCredentials(): void
  {
    $bytes = $this->png(); $image = $this->file($bytes); $mask = $this->file($bytes);
    $service = new CommercialImageCleanupService(['clipdrop_api_key'=>'test-secret'],function ($sentImage,$sentMask) use ($image,$mask,$bytes) { self::assertSame($image,$sentImage); self::assertSame($mask,$sentMask); return ['status'=>200,'body'=>$bytes]; });
    $result = $service->cleanup($image,$mask);
    self::assertSame($bytes,base64_decode($result['image_base64'],true)); self::assertSame(['image_base64'],array_keys($result));
  }
  public function testRejectsMismatchedMaskBeforeContactingProvider(): void
  {
    $service = new CommercialImageCleanupService(['clipdrop_api_key'=>'test-secret'],function () { self::fail('Se envió una máscara inválida.'); });
    $this->expectException(\InvalidArgumentException::class); $service->cleanup($this->file($this->png()),$this->file($this->png(600,500)));
  }
  public function testRejectsDisguisedNonImageUpload(): void
  {
    $service = new CommercialImageCleanupService(['clipdrop_api_key'=>'test-secret'],function () { self::fail('Se envió un archivo inválido.'); });
    $this->expectException(\InvalidArgumentException::class); $service->cleanup($this->file('<?php echo 1;'),$this->file($this->png()));
  }
  public function testRejectsInvalidImageFromProvider(): void
  {
    $service = new CommercialImageCleanupService(['clipdrop_api_key'=>'test-secret'],static fn()=>['status'=>200,'body'=>'<script>test</script>']);
    $this->expectException(\RuntimeException::class); $service->cleanup($this->file($this->png()),$this->file($this->png()));
  }
  public function testProviderErrorDoesNotLeakItsResponse(): void
  {
    $service = new CommercialImageCleanupService(['clipdrop_api_key'=>'test-secret'],static fn()=>['status'=>401,'body'=>'test-secret with sensitive provider details']);
    try { $service->cleanup($this->file($this->png()),$this->file($this->png())); self::fail('No se detectó el error del proveedor.'); }
    catch (\RuntimeException $error) { self::assertStringContainsString('configuración',$error->getMessage()); self::assertStringNotContainsString('test-secret',$error->getMessage()); }
  }
}
