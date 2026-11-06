<?php

namespace App\Posters\Exceptions;

use RuntimeException;

class SyncAlreadyRunningException extends RuntimeException
{
    public function __construct(public readonly string $source)
    {
        parent::__construct("A poster sync for [{$source}] is already running.");
    }
}
