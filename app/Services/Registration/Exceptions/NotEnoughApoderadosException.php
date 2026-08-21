<?php

declare(strict_types=1);

namespace App\Services\Registration\Exceptions;

use RuntimeException;

/**
 * Thrown when a registration cannot be given the minimum number of apoderados because
 * too few soldados are currently eligible. The caller should alert the team rather than
 * failing silently, since a human must free up or onboard more soldados.
 */
class NotEnoughApoderadosException extends RuntimeException
{
    public function __construct(
        public readonly int $available,
        public readonly int $required,
    ) {
        parent::__construct(
            "Only {$available} eligible soldado(s) available; at least {$required} are required to assign apoderados.",
        );
    }
}
