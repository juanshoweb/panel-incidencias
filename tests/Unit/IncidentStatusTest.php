<?php

namespace Tests\Unit;

use App\Enums\IncidentStatus;
use PHPUnit\Framework\TestCase;

class IncidentStatusTest extends TestCase
{
    public function test_normal_workflow_allows_only_the_defined_transitions(): void
    {
        $this->assertSame([IncidentStatus::Review], IncidentStatus::Open->allowedTransitions());
        $this->assertEqualsCanonicalizing(
            [IncidentStatus::Blocked, IncidentStatus::Resolved],
            IncidentStatus::Review->allowedTransitions(),
        );
        $this->assertSame([IncidentStatus::Review], IncidentStatus::Blocked->allowedTransitions());
        $this->assertSame([], IncidentStatus::Resolved->allowedTransitions());
    }

    public function test_resolved_incidents_cannot_reenter_the_normal_workflow(): void
    {
        foreach (IncidentStatus::cases() as $status) {
            $this->assertFalse(IncidentStatus::Resolved->canTransitionTo($status));
        }
    }
}
