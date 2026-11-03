<?php

declare(strict_types=1);

namespace SCM\Commercial\Promotion;

/** La reunión no existe, se eliminó o el cargo no la puede ver (el controlador responde 404). */
final class MeetingNotFound extends \RuntimeException
{
}
