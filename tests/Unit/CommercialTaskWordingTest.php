<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SCM\Commercial\CommercialTicketsRepository;

/** En el panel comercial se dice "tarea", también en los registros viejos del historial que empiezan con "Ticket …". */
final class CommercialTaskWordingTest extends TestCase
{
  public function testOldHistoryPrefixesReadAsTask(): void
  {
    self::assertSame('Tarea activada: Se activa para prueba', CommercialTicketsRepository::taskWording('Ticket activado: Se activa para prueba'));
    self::assertSame('Tarea postergada: Prueba postergado.', CommercialTicketsRepository::taskWording('Ticket postergado: Prueba postergado.'));
    self::assertSame('<p>Tarea cerrada: listo</p>', CommercialTicketsRepository::taskWording('<p>Ticket cerrado: listo</p>'));
    self::assertSame('Tarea administrativa creada: Revisión', CommercialTicketsRepository::taskWording('Ticket administrativo creado: Revisión'));
    self::assertSame('El cliente pidió un ticket activado', CommercialTicketsRepository::taskWording('El cliente pidió un ticket activado'), 'Solo el prefijo del sistema, no lo que escribió la persona.');
  }
}
