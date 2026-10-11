<?php

namespace App\Services\IncidentIntegrity;

use App\Enums\IntegrityIssue;

class IntegrityReport
{
    /**
     * @param  list<IntegrityFinding>  $findings
     */
    public function __construct(
        public array $findings,
        public bool $applied,
    ) {}

    public function detected(): int
    {
        return count($this->findings);
    }

    public function corrected(): int
    {
        return count(array_filter(
            $this->findings,
            fn (IntegrityFinding $finding) => $finding->corrected,
        ));
    }

    /**
     * Issues that still need a person: not safe to repair, or a safe repair
     * that no longer matched its precondition when the row was locked.
     */
    public function pendingReview(): int
    {
        return count(array_filter(
            $this->findings,
            fn (IntegrityFinding $finding) => $this->isPending($finding),
        ));
    }

    public function withheldByDryRun(): int
    {
        if ($this->applied) {
            return 0;
        }

        return count(array_filter(
            $this->findings,
            fn (IntegrityFinding $finding) => $finding->issue->correctable(),
        ));
    }

    /**
     * @return list<IntegrityFinding>
     */
    public function of(IntegrityIssue $issue): array
    {
        return array_values(array_filter(
            $this->findings,
            fn (IntegrityFinding $finding) => $finding->issue === $issue,
        ));
    }

    public function isPending(IntegrityFinding $finding): bool
    {
        if ($finding->corrected) {
            return false;
        }

        if ($finding->issue->correctable() && ! $this->applied) {
            return false;
        }

        return true;
    }
}
