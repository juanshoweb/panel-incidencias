<?php

namespace App\Services\IncidentIntegrity;

use App\Enums\IntegrityIssue;

class IntegrityFinding
{
    public bool $corrected = false;

    public function __construct(
        public readonly IntegrityIssue $issue,
        public readonly int $incidentId,
        public readonly string $title,
        public readonly ?int $logId,
        public readonly string $summary,
    ) {}
}
