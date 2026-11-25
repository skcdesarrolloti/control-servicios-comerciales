<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SCM\Commercial\CommercialTaskAssistant;

final class CommercialTaskAssistantTest extends TestCase
{
  public function testTemplatePlaceholdersAreNotSentNorShown(): void
  {
    self::assertSame('La cliente Katoen Natie busca oficinas.', CommercialTaskAssistant::stripNoise('La cliente Katoen Natie-${solicitante} busca oficinas.'));
    self::assertSame('Hola, Suad.', CommercialTaskAssistant::stripNoise('Hola, {{1}} Suad.'));
  }

  public function testWordsFromOtherAlphabetsAreRemoved(): void
  {
    self::assertSame('Hola, Suad. hemos remitido las propuestas P8 y P9.', CommercialTaskAssistant::stripNoise('Hola, Suad. 가치 hemos remitido las propuestas P8 y P9.'));
    self::assertSame('Ubicación, área y precio.', CommercialTaskAssistant::stripNoise('Ubicación, área 价值 y precio.'));
  }
}
