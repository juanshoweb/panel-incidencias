<?php

namespace Tests\Feature;

use App\Enums\IncidentLogAction;
use App\Enums\IncidentStatus;
use App\Exceptions\InvalidIncidentTransitionException;
use App\Models\Incident;
use App\Models\IncidentComment;
use App\Models\IncidentLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class IncidentWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_incident_owns_logs_and_comments(): void
    {
        $incident = Incident::factory()->create();
        $log = IncidentLog::factory()->for($incident)->create();
        $comment = IncidentComment::factory()->for($incident)->create();

        $this->assertTrue($incident->logs()->first()->is($log));
        $this->assertTrue($incident->comments()->first()->is($comment));
        $this->assertTrue($log->incident->is($incident));
        $this->assertTrue($comment->incident->is($incident));
    }

    public function test_deleting_an_incident_removes_logs_and_comments(): void
    {
        $incident = Incident::factory()->create();
        $log = IncidentLog::factory()->for($incident)->create();
        $comment = IncidentComment::factory()->for($incident)->create();

        $incident->delete();

        $this->assertModelMissing($log);
        $this->assertModelMissing($comment);
    }

    public function test_normal_transitions_are_logged_and_closing_sets_closed_at(): void
    {
        $incident = Incident::factory()->create();

        $incident->transitionTo(IncidentStatus::Review, 'Ana');
        $incident->transitionTo(IncidentStatus::Blocked, 'Ana', ['reason' => 'Falta acceso']);
        $incident->transitionTo(IncidentStatus::Review, 'Ana');
        $incident->transitionTo(IncidentStatus::Resolved, 'Ana');

        $this->assertSame(IncidentStatus::Resolved, $incident->status);
        $this->assertNotNull($incident->closed_at);
        $this->assertCount(4, $incident->logs);
        $this->assertSame(IncidentLogAction::StatusChanged, $incident->logs->first()->action);
        $this->assertSame('open', $incident->logs->first()->old_value);
        $this->assertSame('review', $incident->logs->first()->new_value);

        $blocked = $incident->logs->firstWhere('new_value', 'blocked');
        $this->assertSame(['reason' => 'Falta acceso'], $blocked->metadata);
    }

    public function test_status_cannot_change_outside_the_workflow_methods(): void
    {
        $incident = Incident::factory()->create();

        $this->expectException(InvalidIncidentTransitionException::class);

        $incident->status = IncidentStatus::Review;
        $incident->save();
    }

    public function test_resolved_incidents_cannot_be_reopened_through_transition_to(): void
    {
        $incident = Incident::factory()->resolved()->create();

        $this->expectException(InvalidIncidentTransitionException::class);

        $incident->transitionTo(IncidentStatus::Open, 'Ana');
    }

    public function test_reopen_requires_a_reason_and_writes_a_log(): void
    {
        $incident = Incident::factory()->resolved()->create();
        $closedAt = $incident->closed_at;

        $this->assertNotNull($closedAt);

        try {
            $incident->reopen('   ', 'Ana');
            $this->fail('A blank reason should be rejected.');
        } catch (InvalidArgumentException) {
            $incident->refresh();
            $this->assertSame(IncidentStatus::Resolved, $incident->status);
        }

        $incident->reopen('El fallo volvió a aparecer.', 'Ana');

        $this->assertSame(IncidentStatus::Open, $incident->status);
        $this->assertNull($incident->closed_at);

        $log = $incident->logs()->sole();
        $this->assertSame(IncidentLogAction::Reopened, $log->action);
        $this->assertSame('resolved', $log->old_value);
        $this->assertSame('open', $log->new_value);
        $this->assertSame('Ana', $log->user_name);
        $this->assertSame(['reason' => 'El fallo volvió a aparecer.'], $log->metadata);
    }

    public function test_only_resolved_incidents_can_be_reopened(): void
    {
        $incident = Incident::factory()->inReview()->create();

        $this->expectException(InvalidIncidentTransitionException::class);

        $incident->reopen('Todavía no está resuelta.', 'Ana');
    }

    public function test_transition_rechecks_the_locked_row(): void
    {
        $incident = Incident::factory()->create();
        $stale = Incident::query()->findOrFail($incident->getKey());

        $incident->transitionTo(IncidentStatus::Review, 'Ana');

        try {
            $stale->transitionTo(IncidentStatus::Review, 'Ana');
            $this->fail('A stale open incident must not repeat the same transition.');
        } catch (InvalidIncidentTransitionException) {
            $this->assertSame(IncidentStatus::Review, $stale->status);
            $this->assertSame(IncidentStatus::Review, $stale->fresh()->status);
            $this->assertSame(1, $incident->logs()->count());
        }
    }

    public function test_a_failed_log_rolls_back_the_status_change(): void
    {
        $incident = Incident::factory()->inReview()->create();

        IncidentLog::creating(function (): void {
            throw new RuntimeException('No se pudo escribir el historial.');
        });

        try {
            $incident->transitionTo(IncidentStatus::Resolved, 'Ana');
            $this->fail('El fallo del historial debe revertir el cambio de estado.');
        } catch (RuntimeException) {
            $this->assertSame(IncidentStatus::Review, $incident->status);
            $this->assertNull($incident->closed_at);
            $this->assertSame(IncidentStatus::Review, $incident->fresh()->status);
            $this->assertNull($incident->fresh()->closed_at);
            $this->assertSame(0, IncidentLog::query()->count());
        } finally {
            IncidentLog::flushEventListeners();
        }
    }
}
