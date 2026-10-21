<?php
declare(strict_types=1);
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SCM\Commercial\CommercialPropertyVideoService;
use SCM\Commercial\CommercialSignAccessDenied;

final class CommercialPropertyVideoTest extends TestCase
{
  private array $config = ['property_video_url'=>'http://127.0.0.1:8090','property_video_token'=>'test-only-token-at-least-32-characters'];

  public function testRequiresConfiguredServiceAndValidOptions(): void
  {
    self::assertFalse((new CommercialPropertyVideoService([]))->available());
    self::assertTrue((new CommercialPropertyVideoService($this->config))->available());
    foreach ([['title'=>'Casa','captions'=>['a','b'],'quality'=>'4k'],['title'=>'','captions'=>['a','b']],['title'=>'Casa','captions'=>['a']]] as $input) {
      try { CommercialPropertyVideoService::options($input,2); self::fail('Se aceptaron opciones inválidas.'); } catch (\InvalidArgumentException $exception) {}
    }
    self::assertSame(4,CommercialPropertyVideoService::options(['title'=>'Casa','captions'=>['Sala','Cocina']],2)['seconds']);
  }

  public function testCreationSignsTaskScopedReceiptWithoutExposingServiceToken(): void
  {
    $file = $this->photo();
    try {
      $service = new CommercialPropertyVideoService($this->config,static function ($method,$path,$fields): array {
        self::assertSame('POST',$method); self::assertSame('/jobs',$path);
        self::assertSame('Casa',json_decode($fields['manifest'],true)['title']);
        self::assertInstanceOf(\CURLFile::class,$fields['images[0]']);
        return ['job_id'=>str_repeat('a',32)];
      });
      $result = $service->create(100,['title'=>'Casa','captions'=>['Sala','Cocina']],[$file,$file]);
      self::assertSame(str_repeat('a',32),$service->verify(100,$result));
      self::assertStringNotContainsString($this->config['property_video_token'],json_encode($result));
      try { $service->verify(200,$result); self::fail('Se aceptó otra tarea.'); } catch (CommercialSignAccessDenied $exception) {}
      $result['job_id'] = str_repeat('b',32);
      $this->expectException(CommercialSignAccessDenied::class);
      $service->verify(100,$result);
    } finally { @unlink($file); }
  }

  public function testRejectsFakeImagesBeforeContactingRenderer(): void
  {
    $file = tempnam(sys_get_temp_dir(),'scm-video-'); file_put_contents($file,'<?php echo "fake";');
    try {
      $service = new CommercialPropertyVideoService($this->config,static function (): void { self::fail('No debe contactar al generador.'); });
      $this->expectException(\InvalidArgumentException::class);
      $service->create(100,['title'=>'Casa','captions'=>['a','b']],[$file,$file]);
    } finally { @unlink($file); }
  }

  public function testStatusFiltersProviderOutputAndDownloadRejectsNonMp4(): void
  {
    $file = $this->photo();
    try {
      $service = new CommercialPropertyVideoService($this->config,static function ($method,$path,$fields,$output): array {
        if ($path === '/jobs') return ['job_id'=>str_repeat('c',32)];
        if ($output) { file_put_contents($output,'invalid video'); return []; }
        return ['status'=>'rendering','progress'=>999,'secret'=>'private value'];
      });
      $job = $service->create(100,['title'=>'Casa','captions'=>['a','b']],[$file,$file]);
      self::assertSame(['status'=>'rendering','progress'=>100,'message'=>''],$service->status(100,$job));
      $this->expectException(\RuntimeException::class); $service->download(100,$job);
    } finally { @unlink($file); }
  }

  private function photo(): string
  {
    $chunk = static fn(string $type,string $data): string => pack('N',strlen($data)) . $type . $data . pack('N',crc32($type . $data));
    $png = "\x89PNG\r\n\x1a\n" . $chunk('IHDR',pack('NNCCCCC',600,600,8,2,0,0,0)) . $chunk('IDAT',gzcompress(str_repeat("\0" . str_repeat("\xff",1800),600))) . $chunk('IEND','');
    $file = tempnam(sys_get_temp_dir(),'scm-video-'); file_put_contents($file,$png); return $file;
  }
}
