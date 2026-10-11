<?php

namespace Database\Seeders;

use App\Enums\IncidentPriority;
use App\Enums\IncidentStatus;
use App\Models\Incident;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Opt-in inconsistencies for incidents:diagnose.
 *
 * DatabaseSeeder does not call this class. The normal board, workflow and
 * IncidentSeeder stay consistent. Corruption is written with the query
 * builder, outside IncidentWorkflowService, because that is the realistic
 * source of these rows: a script or a manual update that bypassed the app.
 */
class IncidentIntegrityDemoSeeder extends Seeder
{
    public const RESOLVED_WITHOUT_LOG = 'Demo · resuelta sin log de resolución';

    public const STATUS_DIVERGES = 'Demo · estado distinto del último log';

    public const DUPLICATE_LOGS = 'Demo · logs duplicados e incoherentes';

    public const MISSING_CLOSED_AT = 'Demo · resolución sin closed_at';

    public const STALE_CLOSED_AT = 'Demo · reapertura con closed_at residual';

    public const CONSISTENT = 'Demo · historial coherente';

    public function run(): void
    {
        DB::transaction(function (): void {
            $this->resolvedWithoutResolutionLog();
            $this->statusDivergesFromLastLog();
            $this->duplicateAndIncoherentLogs();
            $this->resolutionMissingClosedAt();
            $this->reopenWithStaleClosedAt();
            $this->consistentControl();
        });
    }

    /**
     * Resolved row, zero resolution logs. There is no actor to attach to a
     * synthetic log, so the diagnosis only reports it.
     */
    private function resolvedWithoutResolutionLog(): void
    {
        Incident::factory()->resolved()->create([
            'title' => self::RESOLVED_WITHOUT_LOG,
            'description' => 'El estado se grabó resuelto sin pasar por el workflow.',
            'priority' => IncidentPriority::Medium,
            'requester_name' => 'Laura Méndez',
            'assigned_to' => null,
        ]);
    }

    /**
     * The log says review. A later SQL update moved the row to blocked and
     * did not append a log. The command must not pick a winner.
     */
    private function statusDivergesFromLastLog(): void
    {
        $incident = $this->openIncident(self::STATUS_DIVERGES, 'Andrés Paredes');
        $incident->transitionTo(IncidentStatus::Review, 'Marta Gil');

        DB::table('incidents')->where('id', $incident->id)->update([
            'status' => IncidentStatus::Blocked->value,
        ]);
    }

    /**
     * One identical copy and a later identical transition with another
     * timestamp. Neither is deleted: equal values are not proof that one
     * event is false. The later row also breaks the chain. The incident
     * status stays review, matching the last log.
     */
    private function duplicateAndIncoherentLogs(): void
    {
        $incident = $this->openIncident(self::DUPLICATE_LOGS, 'Sofía Navarro');
        $incident->transitionTo(IncidentStatus::Review, 'Luis Ortega');

        $original = DB::table('incident_logs')->where('incident_id', $incident->id)->sole();

        DB::table('incident_logs')->insert([
            'incident_id' => $original->incident_id,
            'action' => $original->action,
            'old_value' => $original->old_value,
            'new_value' => $original->new_value,
            'user_name' => $original->user_name,
            'metadata' => $original->metadata,
            'created_at' => $original->created_at,
        ]);

        DB::table('incident_logs')->insert([
            'incident_id' => $original->incident_id,
            'action' => $original->action,
            'old_value' => $original->old_value,
            'new_value' => $original->new_value,
            'user_name' => $original->user_name,
            'metadata' => $original->metadata,
            'created_at' => Carbon::parse($original->created_at)->addMinutes(10)->toDateTimeString(),
        ]);
    }

    /**
     * The resolution log exists and matches the current status. Only closed_at
     * was cleared. The repair copies the log timestamp.
     */
    private function resolutionMissingClosedAt(): void
    {
        $incident = $this->openIncident(self::MISSING_CLOSED_AT, 'Elena Cruz');
        $incident->transitionTo(IncidentStatus::Review, 'Carlos Ruiz');
        $incident->transitionTo(IncidentStatus::Resolved, 'Carlos Ruiz');

        DB::table('incidents')->where('id', $incident->id)->update([
            'closed_at' => null,
        ]);
    }

    /**
     * Reopen already wrote resolved → open. closed_at was put back afterwards.
     * Status and the latest log agree, so the residual timestamp can be cleared.
     */
    private function reopenWithStaleClosedAt(): void
    {
        $incident = $this->openIncident(self::STALE_CLOSED_AT, 'Laura Méndez');
        $incident->transitionTo(IncidentStatus::Review, 'Carlos Ruiz');
        $incident->transitionTo(IncidentStatus::Resolved, 'Carlos Ruiz');
        $incident->reopen('El fallo volvió a aparecer tras el despliegue.', 'Elena Cruz');

        DB::table('incidents')->where('id', $incident->id)->update([
            'closed_at' => now(),
        ]);
    }

    private function consistentControl(): void
    {
        $incident = $this->openIncident(self::CONSISTENT, 'Sofía Navarro');
        $incident->transitionTo(IncidentStatus::Review, 'Luis Ortega');
        $incident->transitionTo(IncidentStatus::Resolved, 'Luis Ortega');
    }

    private function openIncident(string $title, string $requester): Incident
    {
        return Incident::factory()->create([
            'title' => $title,
            'description' => null,
            'priority' => IncidentPriority::Low,
            'requester_name' => $requester,
            'assigned_to' => 'Carlos Ruiz',
            'status' => IncidentStatus::Open,
        ]);
    }
}
