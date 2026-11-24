<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SCM\Commercial\Quotes\CommercialQuotesRepository;

/** TEMPORAL: los inmuebles de prueba se cotizan en borrador; los demás borradores no. Borrar junto con TEST_DRAFT_CODES. */
final class CommercialQuotesTestDraftsTest extends TestCase
{
  public function testOnlyTheTestPropertiesCanBeQuotedAsDrafts(): void
  {
    self::assertTrue(CommercialQuotesRepository::isQuotable(['estado' => 'Publico', 'codigo' => '1']));
    self::assertTrue(CommercialQuotesRepository::isQuotable(['estado' => 'En borrador', 'codigo' => '152715']));
    self::assertFalse(CommercialQuotesRepository::isQuotable(['estado' => 'En borrador', 'codigo' => '1']));
    self::assertFalse(CommercialQuotesRepository::isQuotable(['estado' => 'Arrendado', 'codigo' => '84016']));
  }
}
