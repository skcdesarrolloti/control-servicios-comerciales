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

  public function testStageFollowsTheOperationalRecordsOfTheTask(): void
  {
    self::assertSame('Sin cotización', CommercialTaskAssistant::operationalContext([])['etapa']);

    $quote = ['_ID' => 12, 'tipo' => 'Arriendo', 'inmuebles' => serialize(['84016', '152715']), 'destinatario' => 'Ana Pérez', 'cct_created' => '2026-11-20 10:00:00'];
    $quoted = CommercialTaskAssistant::operationalContext(['cotizaciones' => [$quote]]);
    self::assertSame('Cotización enviada', $quoted['etapa']);
    self::assertSame(['84016', '152715'], $quoted['cotizaciones'][0]['inmuebles']);
    self::assertSame('20/11/2026', $quoted['cotizaciones'][0]['fecha']);

    $study = ['_ID' => 7, 'aseguradora' => 'El Libertador', 'estado' => 'En estudio', 'faltante' => 'Certificado laboral'];
    $studying = CommercialTaskAssistant::operationalContext(['cotizaciones' => [$quote], 'estudios' => [$study]]);
    self::assertSame('Estudio de aseguradora en curso', $studying['etapa']);
    self::assertSame('Certificado laboral', $studying['estudios_aseguradora'][0]['faltante']);

    $approved = ['estado' => 'Aprobado'] + $study;
    self::assertSame('Estudio aprobado', CommercialTaskAssistant::operationalContext(['estudios' => [$approved]])['etapa']);
    self::assertSame('Estudio de aseguradora en curso', CommercialTaskAssistant::operationalContext(['estudios' => [['estado_contrato' => 'Desistido'] + $approved]])['etapa']);

    $mandate = ['_ID' => 3, 'estado' => 'Enviado', 'rev_firma_pendiente' => 1, 'rev_pendientes' => 2];
    $withMandate = CommercialTaskAssistant::operationalContext(['estudios' => [$approved], 'mandatos' => [$mandate]]);
    self::assertSame('Contrato de mandato', $withMandate['etapa']);
    self::assertSame('sí', $withMandate['contratos_mandato'][0]['firma_pendiente']);
    self::assertSame('2', $withMandate['contratos_mandato'][0]['observaciones_pendientes']);

    $closing = CommercialTaskAssistant::operationalContext(['mandatos' => [$mandate], 'hoja_cierre' => ['id' => 9, 'estado' => 'En revisión']]);
    self::assertSame('Hoja de cierre (En revisión)', $closing['etapa']);
  }

  public function testWhatTheRoleCannotSeeIsFlaggedNotGuessed(): void
  {
    $context = CommercialTaskAssistant::operationalContext(['ocultos' => ['contratos de mandato']]);
    self::assertSame(['contratos de mandato'], $context['sin_acceso']);
    self::assertArrayNotHasKey('contratos_mandato', $context);
  }
}
