<?php

namespace App\Services\IncidentIntegrity;

use App\Enums\IncidentLogAction;
use App\Enums\IncidentStatus;
use App\Enums\IntegrityIssue;
use Illuminate\Support\Facades\DB;

/**
 * Read-only detection plus the few repairs whose evidence is already stored.
 *
 * The command never inserts or deletes a log, never writes incidents.status,
 * and never invents an actor or a timestamp. Criteria and SQL live in
 * docs/integridad-incidencias.md.
 */
class IncidentIntegrityDiagnoser
{
    /**
     * closed_at and the resolution log are written in the same request.
     * A gap inside this window is the same event, not a conflict.
     */
    public const CLOSED_AT_TOLERANCE_SECONDS = 5;

    public function diagnose(bool $apply): IntegrityReport
    {
        $findings = DB::transaction(fn (): array => $this->detect());

        if ($apply) {
            foreach ($findings as $finding) {
                if (! $finding->issue->correctable()) {
                    continue;
                }

                $finding->corrected = $this->apply($finding);
            }
        }

        return new IntegrityReport($findings, $apply);
    }

    /**
     * @return list<IntegrityFinding>
     */
    private function detect(): array
    {
        $findings = [];

        foreach (DB::select(IncidentIntegrityQueries::resolvedWithoutResolutionLog()) as $row) {
            $closedAt = $this->nullableString($row->closed_at);
            $detail = 'El estado es resolved y no existe un log status_changed con new_value resolved.';

            if ($closedAt === null) {
                $detail .= ' closed_at también está vacío, así que no hay una fecha demostrable.';
            } else {
                $detail .= " closed_at es {$closedAt}, pero esa columna no identifica a quién resolvió.";
            }

            $detail .= ' No se reconstruye el log.';

            $findings[] = $this->finding(
                IntegrityIssue::ResolvedWithoutResolutionLog,
                (int) $row->incident_id,
                (string) $row->title,
                null,
                $detail,
            );
        }

        $latestByIncident = [];

        foreach (DB::select(IncidentIntegrityQueries::latestStatusLogPerIncident()) as $row) {
            $latestByIncident[(int) $row->incident_id] = $row;

            if ($row->current_status === $row->logged_status) {
                continue;
            }

            $loggedStatus = $row->logged_status ?? 'null';

            $findings[] = $this->finding(
                IntegrityIssue::StatusDivergesFromLastLog,
                (int) $row->incident_id,
                (string) $row->title,
                (int) $row->log_id,
                "El estado actual es {$row->current_status} y el último log de cambio de estado (#{$row->log_id}, {$row->logged_at}) terminó en {$loggedStatus}. No se cambia el estado: o falta el log más reciente, o la fila se actualizó fuera del workflow, y no se puede distinguir.",
            );
        }

        $titles = [];

        foreach (DB::select(IncidentIntegrityQueries::incidents()) as $incident) {
            $titles[(int) $incident->incident_id] = (string) $incident->title;
            $findings = array_merge(
                $findings,
                $this->closedAtFindings($incident, $latestByIncident[(int) $incident->incident_id] ?? null),
            );
        }

        $findings = array_merge($findings, $this->logFindings(
            DB::select(IncidentIntegrityQueries::logsInOrder()),
            $titles,
        ));

        $order = [];

        foreach (IntegrityIssue::cases() as $index => $case) {
            $order[$case->value] = $index;
        }

        usort($findings, function (IntegrityFinding $left, IntegrityFinding $right) use ($order): int {
            return [$order[$left->issue->value], $left->incidentId, $left->logId ?? 0]
                <=> [$order[$right->issue->value], $right->incidentId, $right->logId ?? 0];
        });

        return $findings;
    }

    /**
     * @return list<IntegrityFinding>
     */
    private function closedAtFindings(object $incident, ?object $latest): array
    {
        $status = (string) $incident->status;
        $closedAt = $this->nullableString($incident->closed_at);
        $incidentId = (int) $incident->incident_id;
        $title = (string) $incident->title;
        $findings = [];

        $agrees = $latest !== null && $latest->logged_status === $status;
        $latestIsResolution = $agrees
            && $latest->logged_action === IncidentLogAction::StatusChanged->value
            && $latest->logged_status === IncidentStatus::Resolved->value;

        if ($latestIsResolution && $closedAt === null) {
            $findings[] = $this->finding(
                IntegrityIssue::MissingClosedAt,
                $incidentId,
                $title,
                (int) $latest->log_id,
                "El estado resolved coincide con el último log #{$latest->log_id} ({$latest->logged_at}). closed_at está vacío; la reparación copia esa fecha, que ya está en el evento.",
            );
        }

        if ($latestIsResolution && $closedAt !== null && $this->timestampsDiffer($closedAt, (string) $latest->logged_at)) {
            $findings[] = $this->finding(
                IntegrityIssue::ClosedAtDisagreesWithLog,
                $incidentId,
                $title,
                (int) $latest->log_id,
                "El estado resolved coincide con el log #{$latest->log_id} ({$latest->logged_at}), pero closed_at es {$closedAt}. La diferencia supera los ".self::CLOSED_AT_TOLERANCE_SECONDS.' segundos. No se sobrescribe: cualquiera de las dos fechas puede ser la buena.',
            );
        }

        if ($status === IncidentStatus::Resolved->value || $closedAt === null) {
            return $findings;
        }

        $leftResolved = $agrees && $latest->old_value === IncidentStatus::Resolved->value;

        if ($leftResolved) {
            $findings[] = $this->finding(
                IntegrityIssue::StaleClosedAt,
                $incidentId,
                $title,
                (int) $latest->log_id,
                "El estado {$status} coincide con el último log #{$latest->log_id} ({$latest->old_value} → {$latest->logged_status}). closed_at sigue informado; se anula porque las dos fuentes dicen que la incidencia ya no está resuelta.",
            );

            return $findings;
        }

        $reason = $latest === null
            ? 'no hay logs de cambio de estado'
            : "el último log #{$latest->log_id} no demuestra una salida de resolved que coincida con el estado {$status}";

        $findings[] = $this->finding(
            IntegrityIssue::UnexplainedClosedAt,
            $incidentId,
            $title,
            $latest === null ? null : (int) $latest->log_id,
            "closed_at es {$closedAt} y el estado es {$status}, pero {$reason}. No se borra la fecha: puede ser el único rastro de un cierre.",
        );

        return $findings;
    }

    /**
     * @param  list<object>  $logs
     * @param  array<int, string>  $titles
     * @return list<IntegrityFinding>
     */
    private function logFindings(array $logs, array $titles): array
    {
        $groups = [];

        foreach ($logs as $log) {
            $groups[$this->exactKey($log)][] = $log;
        }

        $extraIds = [];
        $findings = [];

        foreach ($groups as $group) {
            if (count($group) < 2) {
                continue;
            }

            usort($group, fn (object $left, object $right): int => (int) $left->id <=> (int) $right->id);

            $keep = $group[0];
            $extras = array_slice($group, 1);
            $removeIds = array_map(fn (object $log): int => (int) $log->id, $extras);

            foreach ($removeIds as $id) {
                $extraIds[$id] = true;
            }

            $incidentId = (int) $keep->incident_id;

            $findings[] = $this->finding(
                IntegrityIssue::ExactDuplicateLog,
                $incidentId,
                $titles[$incidentId] ?? '',
                (int) $keep->id,
                'Los logs #'.$keep->id.' y #'.implode(', #', $removeIds).' coinciden en acción, valores, usuario, fecha y metadatos. Dos eventos pueden tener los mismos valores; no se borra ninguno.',
            );
        }

        $previous = null;

        foreach ($logs as $log) {
            if (! $this->isStatusAction((string) $log->action)) {
                continue;
            }

            if ($previous !== null
                && (int) $previous->incident_id === (int) $log->incident_id
                && $this->isSameTransition($previous, $log)
                && $this->exactKey($previous) !== $this->exactKey($log)
            ) {
                $incidentId = (int) $log->incident_id;
                $old = $log->old_value ?? 'null';
                $new = $log->new_value ?? 'null';

                $findings[] = $this->finding(
                    IntegrityIssue::PotentialDuplicateLog,
                    $incidentId,
                    $titles[$incidentId] ?? '',
                    (int) $log->id,
                    "El log #{$log->id} ({$old} → {$new}, {$log->user_name}, {$log->created_at}) repite la transición consecutiva del log #{$previous->id}. La fecha o los metadatos difieren, así que no está demostrado que sea el mismo evento.",
                );
            }

            $previous = $log;
        }

        $previous = null;

        foreach ($logs as $log) {
            // An identical copy is already reported as a group. Classifying it
            // again as a broken link would name the higher id as the false step.
            if (isset($extraIds[(int) $log->id])) {
                continue;
            }

            if (! $this->isStatusAction((string) $log->action)) {
                if (IncidentLogAction::tryFrom((string) $log->action) === null) {
                    $incidentId = (int) $log->incident_id;
                    $findings[] = $this->finding(
                        IntegrityIssue::IncoherentLog,
                        $incidentId,
                        $titles[$incidentId] ?? '',
                        (int) $log->id,
                        "Log #{$log->id}: la acción [{$log->action}] no existe en el historial de la aplicación.",
                    );
                }

                continue;
            }

            if ($previous === null || (int) $previous->incident_id !== (int) $log->incident_id) {
                $previous = null;
            }

            $problems = $this->problems($log, $previous);

            if ($problems !== []) {
                $incidentId = (int) $log->incident_id;
                $old = $log->old_value ?? 'null';
                $new = $log->new_value ?? 'null';

                $findings[] = $this->finding(
                    IntegrityIssue::IncoherentLog,
                    $incidentId,
                    $titles[$incidentId] ?? '',
                    (int) $log->id,
                    "Log #{$log->id} ({$log->action}, {$old} → {$new}): ".implode(' ', $problems),
                );
            }

            $previous = $log;
        }

        return $findings;
    }

    private function apply(IntegrityFinding $finding): bool
    {
        return match ($finding->issue) {
            IntegrityIssue::MissingClosedAt => $this->restoreClosedAt($finding),
            IntegrityIssue::StaleClosedAt => $this->clearStaleClosedAt($finding),
            default => false,
        };
    }

    private function restoreClosedAt(IntegrityFinding $finding): bool
    {
        return DB::transaction(function () use ($finding): bool {
            $incident = DB::table('incidents')->where('id', $finding->incidentId)->lockForUpdate()->first();

            if ($incident === null
                || $incident->status !== IncidentStatus::Resolved->value
                || $incident->closed_at !== null
            ) {
                return false;
            }

            $latest = $this->lockLatestStatusLog($finding->incidentId);

            if (! $this->isProvenResolution($latest, $incident->status)) {
                return false;
            }

            DB::table('incidents')->where('id', $incident->id)->update([
                'closed_at' => $latest->created_at,
                'updated_at' => now(),
            ]);

            return true;
        });
    }

    private function clearStaleClosedAt(IntegrityFinding $finding): bool
    {
        return DB::transaction(function () use ($finding): bool {
            $incident = DB::table('incidents')->where('id', $finding->incidentId)->lockForUpdate()->first();

            if ($incident === null
                || $incident->status === IncidentStatus::Resolved->value
                || $incident->closed_at === null
            ) {
                return false;
            }

            $latest = $this->lockLatestStatusLog($finding->incidentId);

            if ($latest === null
                || $latest->new_value !== $incident->status
                || $latest->old_value !== IncidentStatus::Resolved->value
            ) {
                return false;
            }

            DB::table('incidents')->where('id', $incident->id)->update([
                'closed_at' => null,
                'updated_at' => now(),
            ]);

            return true;
        });
    }

    private function lockLatestStatusLog(int $incidentId): ?object
    {
        return DB::table('incident_logs')
            ->where('incident_id', $incidentId)
            ->whereIn('action', [
                IncidentLogAction::StatusChanged->value,
                IncidentLogAction::Reopened->value,
            ])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->lockForUpdate()
            ->first();
    }

    private function isProvenResolution(?object $latest, string $status): bool
    {
        return $latest !== null
            && $status === IncidentStatus::Resolved->value
            && $latest->action === IncidentLogAction::StatusChanged->value
            && $latest->new_value === IncidentStatus::Resolved->value
            && $latest->new_value === $status;
    }

    private function finding(
        IntegrityIssue $issue,
        int $incidentId,
        string $title,
        ?int $logId,
        string $summary,
    ): IntegrityFinding {
        return new IntegrityFinding($issue, $incidentId, $title, $logId, $summary);
    }

    private function isStatusAction(string $action): bool
    {
        return in_array($action, [
            IncidentLogAction::StatusChanged->value,
            IncidentLogAction::Reopened->value,
        ], true);
    }

    private function isSameTransition(object $left, object $right): bool
    {
        return $left->action === $right->action
            && $left->old_value === $right->old_value
            && $left->new_value === $right->new_value
            && $left->user_name === $right->user_name;
    }

    private function exactKey(object $log): string
    {
        return implode("\0", [
            (string) $log->incident_id,
            (string) $log->action,
            $log->old_value ?? "\0",
            $log->new_value ?? "\0",
            (string) $log->user_name,
            (string) $log->created_at,
            $this->canonicalMetadata($log->metadata),
        ]);
    }

    private function canonicalMetadata(mixed $metadata): string
    {
        if ($metadata === null || $metadata === '') {
            return 'null';
        }

        if (is_string($metadata)) {
            $decoded = json_decode($metadata, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                return 'raw:'.$metadata;
            }

            $metadata = $decoded;
        }

        if (! is_array($metadata)) {
            return 'raw:'.json_encode($metadata);
        }

        $this->sortRecursive($metadata);

        return json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: 'null';
    }

    /**
     * @param  array<mixed>  $value
     */
    private function sortRecursive(array &$value): void
    {
        foreach ($value as &$item) {
            if (is_array($item)) {
                $this->sortRecursive($item);
            }
        }

        unset($item);

        if (array_is_list($value)) {
            return;
        }

        ksort($value);
    }

    private function timestampsDiffer(string $left, string $right): bool
    {
        $leftTime = strtotime($left);
        $rightTime = strtotime($right);

        if ($leftTime === false || $rightTime === false) {
            return true;
        }

        return abs($leftTime - $rightTime) > self::CLOSED_AT_TOLERANCE_SECONDS;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return (string) $value;
    }

    /**
     * @return list<string>
     */
    private function problems(object $log, ?object $previous): array
    {
        $problems = [];
        $old = is_string($log->old_value) ? IncidentStatus::tryFrom($log->old_value) : null;
        $new = is_string($log->new_value) ? IncidentStatus::tryFrom($log->new_value) : null;

        if ($old === null || $new === null) {
            $problems[] = 'el valor de estado no existe en el workflow.';
        } else {
            if ($old === $new) {
                $problems[] = 'old_value y new_value son iguales.';
            }

            if ($log->action === IncidentLogAction::Reopened->value
                && ($old !== IncidentStatus::Resolved || $new !== IncidentStatus::Open)
            ) {
                $problems[] = 'una reapertura tiene que ir de resolved a open.';
            }

            if ($log->action === IncidentLogAction::StatusChanged->value && ! $old->canTransitionTo($new)) {
                $problems[] = "el workflow no permite pasar de {$old->value} a {$new->value} con status_changed.";
            }
        }

        if ($previous !== null && $log->old_value !== $previous->new_value) {
            $previousNew = $previous->new_value ?? 'null';
            $oldValue = $log->old_value ?? 'null';
            $problems[] = "old_value es {$oldValue}, pero el log #{$previous->id} terminó en {$previousNew}.";
        }

        return $problems;
    }
}
