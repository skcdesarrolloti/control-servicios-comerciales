<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SCM\Commercial\CommercialStatusCatalog;

final class CommercialStatusCatalogTest extends TestCase
{
  public function testCatalogContainsEveryStatusFromTheCommercialDefinition(): void
  {
    self::assertCount(23, CommercialStatusCatalog::all());
    self::assertContains('Pendiente colocar aviso', CommercialStatusCatalog::all());
    self::assertContains('En actividad comercial', CommercialStatusCatalog::all());
  }

  public function testEveryStatusBelongsToExactlyOneBucket(): void
  {
    $bucketStatuses = array_merge(
      CommercialStatusCatalog::OPEN,
      CommercialStatusCatalog::POSTPONED,
      CommercialStatusCatalog::CLOSED
    );

    self::assertSameSize(array_unique($bucketStatuses), $bucketStatuses);
    foreach (CommercialStatusCatalog::all() as $status) {
      self::assertContains(CommercialStatusCatalog::bucketForStatus($status), ['abiertos', 'postergados', 'cerrados']);
    }
  }

  public function testUnknownStatusesAreRejected(): void
  {
    self::assertFalse(CommercialStatusCatalog::isValid('Estado inventado'));
    self::assertTrue(CommercialStatusCatalog::isValid('Contactado'));
  }
}
