<?php

namespace Tests\Feature;

use App\Enums\IncidentStatus;
use App\Enums\IntegrityIssue;
use App\Models\Incident;
use App\Models\IncidentLog;
use App\Services\IncidentIntegrity\IncidentIntegrityDiagnoser;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\IncidentIntegrityDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DiagnoseIncidentsTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_does_not_modify_data(): void
    {
        $missingClosedAt = $this->resolvedIncident();
        DB::table('incidents')->where('id', $missingClosedAt->id)->update(['closed_at' => null]);
        $missingClosedAt->comments()->create([
            'author_name' => 'Ana',
            'body' => 'El comentario no forma parte de la reparación.',
        ]);

        $resolvedWithoutLog = Incident::factory()->resolved()->create([
            'title' => 'Resuelta sin historial',
        ]);

        $divergent = Incident::factory()->create();
        $divergent->transitionTo(IncidentStatus::Review, 'Ana');
        DB::table('incidents')->where('id', $divergent->id)->update([
            'status' => IncidentStatus::Blocked->value,
        ]);

        $before = $this->fingerprint();
        $writes = $this->recordWrites();

        $report = $this->diagnoser()->diagnose(apply: false);

        $this->assertSame($before, $this->fingerprint());
        $this->assertSame([], $writes);
        $this->assertSame(0, $report->corrected());
        $this->assertGreaterThan(0, $report->withheldByDryRun());
        $this->assertNotEmpty($report->of(IntegrityIssue::MissingClosedAt));
        $this->assertNotEmpty($report->of(IntegrityIssue::ResolvedWithoutResolutionLog));
        $this->assertNotEmpty($report->of(IntegrityIssue::StatusDivergesFromLastLog));
        $this->assertNull($missingClosedAt->fresh()->closed_at);
        $this->assertSame(IncidentStatus::Blocked, $divergent->fresh()->status);
        $this->assertSame(0, $resolvedWithoutLog->logs()->count());

        $this->artisan('incidents:diagnose', ['--dry-run' => true])
            ->expectsOutputToContain('solo lectura')
            ->expectsOutputToContain('Pendientes de revisión')
            ->assertExitCode(1);
    }

    public function test_safe_corrections_use_existing_evidence_and_can_be_repeated(): void
    {
        $missingClosedAt = $this->resolvedIncident();
        $resolution = $missingClosedAt->logs()->where('new_value', 'resolved')->sole();
        DB::table('incidents')->where('id', $missingClosedAt->id)->update(['closed_at' => null]);

        $reopened = $this->resolvedIncident();
        $reopened->reopen('El fallo volvió.', 'Ana');
        DB::table('incidents')->where('id', $reopened->id)->update(['closed_at' => now()]);

        $duplicated = Incident::factory()->create();
        $duplicated->transitionTo(IncidentStatus::Review, 'Ana');
        $original = DB::table('incident_logs')->where('incident_id', $duplicated->id)->sole();
        DB::table('incident_logs')->insert([
            'incident_id' => $original->incident_id,
            'action' => $original->action,
            'old_value' => $original->old_value,
            'new_value' => $original->new_value,
            'user_name' => $original->user_name,
            'metadata' => json_encode(['b' => 1, 'a' => 2]),
            'created_at' => $original->created_at,
        ]);
        DB::table('incident_logs')->where('id', $original->id)->update([
            'metadata' => json_encode(['a' => 2, 'b' => 1]),
        ]);
        $laterId = DB::table('incident_logs')->insertGetId([
            'incident_id' => $original->incident_id,
            'action' => $original->action,
            'old_value' => $original->old_value,
            'new_value' => $original->new_value,
            'user_name' => $original->user_name,
            'metadata' => null,
            'created_at' => now()->addMinutes(10),
        ]);

        $namesBefore = DB::table('incident_logs')->pluck('user_name')->unique()->all();
        $maxLogId = (int) DB::table('incident_logs')->max('id');

        $this->travel(3)->hours();
        $report = $this->diagnoser()->diagnose(apply: true);
        $this->travelBack();

        $missingClosedAt->refresh();
        $reopened->refresh();

        $this->assertTrue($missingClosedAt->closed_at->equalTo($resolution->created_at));
        $this->assertSame(IncidentStatus::Resolved, $missingClosedAt->status);
        $this->assertNull($reopened->closed_at);
        $this->assertSame(IncidentStatus::Open, $reopened->status);
        $this->assertSame(3, $duplicated->logs()->count());
        $this->assertNotNull(IncidentLog::query()->find($laterId));
        $this->assertFalse($report->of(IntegrityIssue::ExactDuplicateLog)[0]->corrected);
        $this->assertFalse($report->of(IntegrityIssue::PotentialDuplicateLog)[0]->corrected);
        $this->assertFalse($report->of(IntegrityIssue::IncoherentLog)[0]->corrected);

        $storedNames = DB::table('incident_logs')->pluck('user_name');
        foreach ($storedNames as $name) {
            $this->assertContains($name, $namesBefore);
        }
        $this->assertLessThanOrEqual($maxLogId, (int) DB::table('incident_logs')->max('id'));

        $second = $this->diagnoser()->diagnose(apply: true);
        $this->assertSame([], $second->of(IntegrityIssue::MissingClosedAt));
        $this->assertSame([], $second->of(IntegrityIssue::StaleClosedAt));
        $this->assertNotEmpty($second->of(IntegrityIssue::ExactDuplicateLog));
        $this->assertSame(0, $second->corrected());
        $this->assertTrue($missingClosedAt->fresh()->closed_at->equalTo($resolution->created_at));
        $this->assertNull($reopened->fresh()->closed_at);
    }

    public function test_unsafe_cases_stay_pending_and_do_not_rewrite_history_or_status(): void
    {
        $withoutLog = Incident::factory()->resolved()->create();
        $closedAt = $withoutLog->closed_at->format('Y-m-d H:i:s');

        $divergent = Incident::factory()->create();
        $divergent->transitionTo(IncidentStatus::Review, 'Ana');
        DB::table('incidents')->where('id', $divergent->id)->update([
            'status' => IncidentStatus::Blocked->value,
        ]);

        $forcedBack = $this->resolvedIncident();
        $forcedBack->reopen('Se reabrió y alguien marcó resuelta la fila.', 'Ana');
        DB::table('incidents')->where('id', $forcedBack->id)->update([
            'status' => IncidentStatus::Resolved->value,
            'closed_at' => null,
        ]);

        $disagreement = $this->resolvedIncident();
        $manualClosedAt = now()->subHours(2)->toDateTimeString();
        DB::table('incidents')->where('id', $disagreement->id)->update([
            'closed_at' => $manualClosedAt,
        ]);

        $unexplained = Incident::factory()->create();
        $unexplained->transitionTo(IncidentStatus::Review, 'Ana');
        DB::table('incidents')->where('id', $unexplained->id)->update([
            'closed_at' => now(),
        ]);

        $logCount = IncidentLog::query()->count();
        $report = $this->diagnoser()->diagnose(apply: true);

        $this->assertSame($logCount, IncidentLog::query()->count());
        $this->assertSame(IncidentStatus::Resolved, $withoutLog->fresh()->status);
        $this->assertSame($closedAt, $withoutLog->fresh()->closed_at->format('Y-m-d H:i:s'));
        $this->assertSame(0, $withoutLog->logs()->count());
        $this->assertSame(IncidentStatus::Blocked, $divergent->fresh()->status);
        $this->assertSame(IncidentStatus::Resolved, $forcedBack->fresh()->status);
        $this->assertNull($forcedBack->fresh()->closed_at);
        $this->assertSame($manualClosedAt, $disagreement->fresh()->closed_at->format('Y-m-d H:i:s'));
        $this->assertNotNull($unexplained->fresh()->closed_at);
        $this->assertSame(IncidentStatus::Review, $unexplained->fresh()->status);
        $this->assertSame(0, $report->corrected());
        $this->assertGreaterThan(0, $report->pendingReview());
        $this->assertNotEmpty($report->of(IntegrityIssue::ResolvedWithoutResolutionLog));
        $this->assertNotEmpty($report->of(IntegrityIssue::StatusDivergesFromLastLog));
        $this->assertNotEmpty($report->of(IntegrityIssue::ClosedAtDisagreesWithLog));
        $this->assertNotEmpty($report->of(IntegrityIssue::UnexplainedClosedAt));
    }

    public function test_a_normal_workflow_and_the_normal_seeder_are_consistent(): void
    {
        $incident = Incident::factory()->create();
        $incident->transitionTo(IncidentStatus::Review, 'Ana');
        $incident->transitionTo(IncidentStatus::Blocked, 'Ana');
        $incident->transitionTo(IncidentStatus::Review, 'Ana');
        $incident->transitionTo(IncidentStatus::Resolved, 'Ana');
        $incident->reopen('Quedó pendiente una comprobación.', 'Ana');

        $this->assertSame(0, $this->diagnoser()->diagnose(apply: true)->detected());
        $this->assertSame(IncidentStatus::Open, $incident->fresh()->status);
        $this->assertNull($incident->fresh()->closed_at);

        $this->seed(DatabaseSeeder::class);

        $this->assertSame(0, Incident::query()->where('title', 'like', 'Demo ·%')->count());
        $this->assertSame(0, $this->diagnoser()->diagnose(apply: true)->detected());

        $this->artisan('incidents:diagnose')->assertSuccessful();
    }

    public function test_demo_seeder_reproduces_the_three_inconsistencies_outside_the_normal_flow(): void
    {
        $this->seed(IncidentIntegrityDemoSeeder::class);

        $dryRun = $this->diagnoser()->diagnose(apply: false);
        $titles = array_map(
            fn ($finding) => $finding->title,
            $dryRun->findings,
        );

        $this->assertContains(IncidentIntegrityDemoSeeder::RESOLVED_WITHOUT_LOG, $titles);
        $this->assertContains(IncidentIntegrityDemoSeeder::STATUS_DIVERGES, $titles);
        $this->assertContains(IncidentIntegrityDemoSeeder::DUPLICATE_LOGS, $titles);
        $this->assertNotContains(IncidentIntegrityDemoSeeder::CONSISTENT, $titles);
        $this->assertNotEmpty($dryRun->of(IntegrityIssue::ResolvedWithoutResolutionLog));
        $this->assertNotEmpty($dryRun->of(IntegrityIssue::StatusDivergesFromLastLog));
        $this->assertNotEmpty($dryRun->of(IntegrityIssue::ExactDuplicateLog));
        $this->assertNotEmpty($dryRun->of(IntegrityIssue::PotentialDuplicateLog));
        $this->assertNotEmpty($dryRun->of(IntegrityIssue::IncoherentLog));
        $this->assertNotEmpty($dryRun->of(IntegrityIssue::MissingClosedAt));
        $this->assertNotEmpty($dryRun->of(IntegrityIssue::StaleClosedAt));

        $divergent = Incident::query()->where('title', IncidentIntegrityDemoSeeder::STATUS_DIVERGES)->sole();
        $duplicates = Incident::query()->where('title', IncidentIntegrityDemoSeeder::DUPLICATE_LOGS)->sole();
        $duplicateLogCount = $duplicates->logs()->count();
        $this->assertSame(3, $duplicateLogCount);

        $applied = $this->diagnoser()->diagnose(apply: true);

        $this->assertSame(IncidentStatus::Blocked, $divergent->fresh()->status);
        $this->assertSame(0, Incident::query()->where('title', IncidentIntegrityDemoSeeder::RESOLVED_WITHOUT_LOG)->sole()->logs()->count());
        $this->assertSame($duplicateLogCount, $duplicates->fresh()->logs()->count());
        $this->assertNotNull(Incident::query()->where('title', IncidentIntegrityDemoSeeder::MISSING_CLOSED_AT)->sole()->closed_at);
        $this->assertNull(Incident::query()->where('title', IncidentIntegrityDemoSeeder::STALE_CLOSED_AT)->sole()->closed_at);
        $this->assertFalse($applied->of(IntegrityIssue::ExactDuplicateLog)[0]->corrected);
        $this->assertFalse($applied->of(IntegrityIssue::PotentialDuplicateLog)[0]->corrected);
        $this->assertFalse($applied->of(IntegrityIssue::ResolvedWithoutResolutionLog)[0]->corrected);
        $this->assertFalse($applied->of(IntegrityIssue::StatusDivergesFromLastLog)[0]->corrected);

        $second = $this->diagnoser()->diagnose(apply: true);
        $this->assertNotEmpty($second->of(IntegrityIssue::ExactDuplicateLog));
        $this->assertSame([], $second->of(IntegrityIssue::MissingClosedAt));
        $this->assertSame([], $second->of(IntegrityIssue::StaleClosedAt));
        $this->assertNotEmpty($second->of(IntegrityIssue::ResolvedWithoutResolutionLog));
        $this->assertNotEmpty($second->of(IntegrityIssue::StatusDivergesFromLastLog));
        $this->assertNotEmpty($second->of(IntegrityIssue::PotentialDuplicateLog));
        $this->assertNotEmpty($second->of(IntegrityIssue::IncoherentLog));
    }

    private function diagnoser(): IncidentIntegrityDiagnoser
    {
        return app(IncidentIntegrityDiagnoser::class);
    }

    private function resolvedIncident(): Incident
    {
        $incident = Incident::factory()->create();
        $incident->transitionTo(IncidentStatus::Review, 'Ana');
        $incident->transitionTo(IncidentStatus::Resolved, 'Ana');

        return $incident->fresh();
    }

    private function fingerprint(): string
    {
        $tables = ['incidents', 'incident_logs', 'incident_comments'];
        $snapshot = [];

        foreach ($tables as $table) {
            $snapshot[$table] = DB::table($table)->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all();
        }

        return json_encode($snapshot, JSON_THROW_ON_ERROR);
    }

    /**
     * @return list<string>
     */
    private function recordWrites(): array
    {
        $writes = [];

        DB::listen(function ($query) use (&$writes): void {
            if (preg_match('/^\s*(insert|update|delete|replace)\b/i', $query->sql)) {
                $writes[] = $query->sql;
            }
        });

        return $writes;
    }
}
