<?php

namespace App\Enums;

enum IncidentLogAction: string
{
    case Created = 'created';
    case StatusChanged = 'status_changed';
    case Reopened = 'reopened';

    public function label(): string
    {
        return match ($this) {
            self::Created => 'Incidencia creada',
            self::StatusChanged => 'Cambio de estado',
            self::Reopened => 'Reapertura',
        };
    }

    public function timelinePhrase(): string
    {
        return match ($this) {
            self::Created => 'creó la incidencia',
            self::StatusChanged => 'cambió el estado',
            self::Reopened => 'reabrió la incidencia',
        };
    }
}
