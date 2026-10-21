<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SCM\Commercial\CommercialAccessPolicy;
use SCM\Commercial\CommercialPropertyMediaService;
use SCM\Commercial\CommercialSignAccessDenied;
use SCM\Core\Database;
use SCM\Core\Settings;
use Tests\Fixtures\CommercialPropertyEntryDatabase;

require_once dirname(__DIR__) . '/Fixtures/CommercialPropertyEntryDatabase.php';

final class CommercialPropertyMediaTest extends TestCase
{
  private Database $db;
  private CommercialPropertyMediaService $service;
  private array $previous;

  protected function setUp(): void
  {
    $this->previous = $_SESSION ?? [];
    $_SESSION = ['scm_user_id'=>1,'scm_employee_id'=>'101','scm_user_cargo'=>'9'];
    $this->db = CommercialPropertyEntryDatabase::create();
    $policy = new CommercialAccessPolicy(new Settings($this->db, false, 'control_servicios_comerciales_config'), $this->db, ['11']);
    $this->service = new CommercialPropertyMediaService($this->db, $policy, ['wordpress_upload_path'=>sys_get_temp_dir(),'wordpress_upload_url'=>'https://example.test/wp-content/uploads']);
    $this->db->insert('wp_posts', ['ID'=>500,'post_type'=>'inmuebles','post_status'=>'publish']);
    foreach ([901,902,903,904] as $id) $this->db->insert('wp_posts', ['ID'=>$id,'post_type'=>'attachment','post_status'=>'inherit','post_mime_type'=>'image/jpeg','post_parent'=>$id === 904 ? 600 : 500,'guid'=>'https://example.test/wp-content/uploads/' . $id . '.jpg']);
    foreach (['_thumbnail_id'=>'901','galeria-del-inmueble'=>serialize([902,903]),'video_del_inmueble'=>'https://youtu.be/abcdefghijk'] as $key=>$value) $this->db->insert('wp_postmeta', ['post_id'=>500,'meta_key'=>$key,'meta_value'=>$value]);
  }

  protected function tearDown(): void { $_SESSION = $this->previous; }

  public function testSavesMediaInWordPressAndCctPreservingOrderAndTaskState(): void
  {
    $input = ['portada_id'=>'902','galeria_ids'=>['903','901'],'video_del_inmueble'=>'https://www.youtube.com/watch?v=abcdefghijk'];
    $this->service->save(100,500,$input);
    $this->service->save(100,500,$input);
    $property = $this->db->getRow('SELECT * FROM wp_jet_cct_inmuebles WHERE codigo=500');
    self::assertSame('902', $property['foto_portada']); self::assertSame('903,901', $property['galeria']);
    self::assertSame($input['video_del_inmueble'], $property['video']);
    self::assertSame([903,901], unserialize($this->db->getVar("SELECT meta_value FROM wp_postmeta WHERE post_id=500 AND meta_key='galeria-del-inmueble'"), ['allowed_classes'=>false]));
    self::assertSame('Solicitando aviso', $this->db->getVar('SELECT estado_comercial FROM wp_jet_cct_tickets WHERE _ID=100'));
    self::assertSame(0, (int) $this->db->getVar('SELECT COUNT(*) FROM skc_notification_queue'));
    self::assertSame(1, (int) $this->db->getVar("SELECT COUNT(*) FROM wp_postmeta WHERE post_id=500 AND meta_key='_thumbnail_id'"));
  }

  public function testRejectsAttachmentsFromAnotherPropertyWithoutPartialChanges(): void
  {
    try { $this->service->save(100,500,['portada_id'=>901,'galeria_ids'=>[904]]); self::fail('Se aceptó una foto ajena.'); }
    catch (CommercialSignAccessDenied $exception) {}
    self::assertSame('901', $this->db->getVar("SELECT meta_value FROM wp_postmeta WHERE post_id=500 AND meta_key='_thumbnail_id'"));
    self::assertFalse($this->db->pdo()->inTransaction());
  }

  public function testRequiresTaskScopeAndPropertyAssociation(): void
  {
    foreach ([[200,600],[100,600]] as [$task,$property]) {
      try { $this->service->context($task,$property); self::fail('Se accedió a otro inmueble.'); } catch (CommercialSignAccessDenied $exception) {}
    }
    self::assertSame(500,$this->service->context(100)['id_inmueble']);
  }

  public function testRequiresCoverGalleryAndYouTubeVideo(): void
  {
    foreach ([['portada_id'=>0,'galeria_ids'=>[902]],['portada_id'=>901,'galeria_ids'=>[]],['portada_id'=>901,'galeria_ids'=>['../../x']],['portada_id'=>901,'galeria_ids'=>[902],'video_del_inmueble'=>'https://youtube.com.evil.test/watch?v=abcdefghijk']] as $input) {
      try { $this->service->save(100,500,$input); self::fail('Se aceptó multimedia inválida.'); } catch (\InvalidArgumentException $exception) {}
    }
    self::assertFalse($this->db->pdo()->inTransaction());
  }

  public function testGalleryLimitUsesPropertyTypeAndKeepsHistoricalGallery(): void
  {
    $this->db->insert('wp_term_relationships', ['object_id'=>500,'term_taxonomy_id'=>113]);
    self::assertSame(6,$this->service->context(100)['max_gallery']);
    $this->db->update('wp_postmeta', ['meta_value'=>serialize(range(901,915))], ['post_id'=>500,'meta_key'=>'galeria-del-inmueble']);
    self::assertSame(15,$this->service->context(100)['max_gallery']);
  }

  public function testAcceptsOnlySupportedYouTubeVideoUrls(): void
  {
    foreach (['','https://youtu.be/abcdefghijk?t=4','https://www.youtube.com/watch?v=abcdefghijk','https://youtube.com/shorts/abcdefghijk'] as $url) self::assertTrue(CommercialPropertyMediaService::validVideo($url), $url);
    foreach (['javascript:alert(1)','http://youtu.be/abcdefghijk','https://youtube.com.evil.test/watch?v=abcdefghijk','https://youtube.com/','https://user@youtube.com/watch?v=abcdefghijk','https://youtube.com/watch?v[]=abcdefghijk'] as $url) self::assertFalse(CommercialPropertyMediaService::validVideo($url), $url);
  }

  public function testImageValidationChecksActualDimensionsAndMime(): void
  {
    $file = tempnam(sys_get_temp_dir(),'scm-media-');
    try {
      file_put_contents($file,'<?php echo "image";');
      try { CommercialPropertyMediaService::validateImage($file); self::fail('Se aceptó un archivo falso.'); } catch (\InvalidArgumentException $exception) {}
      $png = static function (int $width, int $height): string {
        $chunk = static fn(string $type, string $data): string => pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
        return "\x89PNG\r\n\x1a\n" . $chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0)) . $chunk('IDAT', gzcompress(str_repeat("\0" . str_repeat("\xff", $width * 3), $height))) . $chunk('IEND', '');
      };
      file_put_contents($file,$png(900,600));
      self::assertSame(900,CommercialPropertyMediaService::validateImage($file)['width']);
      file_put_contents($file,$png(300,300));
      try { CommercialPropertyMediaService::validateImage($file); self::fail('Se aceptó una imagen pequeña.'); } catch (\InvalidArgumentException $exception) {}
    } finally { @unlink($file); }
  }
}
