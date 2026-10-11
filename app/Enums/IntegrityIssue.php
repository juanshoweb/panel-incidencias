<?php

namespace App\Enums;

enum IntegrityIssue: string
{
    case ResolvedWithoutResolutionLog = 'resolved_without_resolution_log';
    case StatusDivergesFromLastLog = 'status_diverges_from_last_log';
    case ExactDuplicateLog = 'exact_duplicate_log';
    case PotentialDuplicateLog = 'potential_duplicate_log';
    case IncoherentLog = 'incoherent_log';
    case MissingClosedAt = 'missing_closed_at';
    case StaleClosedAt = 'stale_closed_at';
    case ClosedAtDisagreesWithLog = 'closed_at_disagrees_with_log';
    case UnexplainedClosedAt = 'unexplained_closed_at';

    public function label(): string
    {
        return match ($this) {
            self::ResolvedWithoutResolutionLog => 'Resuelta sin log de resolución',
            self::StatusDivergesFromLastLog => 'Estado distinto del último log',
            self::ExactDuplicateLog => 'Log duplicado exacto',
            self::PotentialDuplicateLog => 'Log posiblemente duplicado',
            self::IncoherentLog => 'Log incoherente',
            self::MissingClosedAt => 'closed_at vacío con resolución demostrada',
            self::StaleClosedAt => 'closed_at residual tras dejar resolved',
            self::ClosedAtDisagreesWithLog => 'closed_at distinto del log de resolución',
            self::UnexplainedClosedAt => 'closed_at sin respaldo suficiente',
        };
    }

    /**
     * Only these issues have a repair that copies a timestamp already stored.
     * Matching log rows stay in the manual review queue.
     */
    public function correctable(): bool
    {
        return match ($this) {
            self::MissingClosedAt, self::StaleClosedAt => true,
            default => false,
        };
    }
}
