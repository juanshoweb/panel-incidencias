<?php

namespace App\Exceptions;

use App\Enums\IncidentStatus;
use RuntimeException;

class InvalidIncidentTransitionException extends RuntimeException
{
    public static function disallowed(IncidentStatus $from, IncidentStatus $to): self
    {
        return new self("No se puede cambiar la incidencia de [{$from->value}] a [{$to->value}].");
    }

    public static function notReopenable(IncidentStatus $from): self
    {
        return new self("Solo se pueden reabrir incidencias resueltas. El estado actual es [{$from->value}].");
    }

    public static function unguarded(): self
    {
        return new self('El estado de la incidencia solo puede cambiar mediante transitionTo() o reopen().');
    }
}
