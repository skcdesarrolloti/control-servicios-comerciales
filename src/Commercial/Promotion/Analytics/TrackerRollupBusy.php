<?php

declare(strict_types=1);

namespace SCM\Commercial\Promotion\Analytics;

/** Otro proceso tiene el candado del rollup; quien lo recibe sigue sin esperar. */
final class TrackerRollupBusy extends \RuntimeException
{
}
