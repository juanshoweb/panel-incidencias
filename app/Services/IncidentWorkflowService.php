<?php

namespace App\Services;

use App\Enums\IncidentLogAction;
use App\Enums\IncidentStatus;
use App\Exceptions\InvalidIncidentTransitionException;
use App\Models\Incident;
use Closure;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use Throwable;

class IncidentWorkflowService
{
    /**
     * Move the incident along the normal workflow and record the change.
     *
     * The locked row is the source of truth: a stale in-memory status, or one
     * posted from the browser, cannot authorize the transition.
     *
     * @param  array<string, mixed>|null  $metadata
     */
    public function transition(Incident $incident, IncidentStatus $status, string $userName, ?array $metadata = null): Incident
    {
        $this->assertPersisted($incident);
        $userName = $this->actorName($userName);

        return $this->attempt($incident, function (Incident $locked) use ($status, $userName, $metadata): void {
            $from = $locked->status;

            if (! $from->canTransitionTo($status)) {
                throw InvalidIncidentTransitionException::disallowed($from, $status);
            }

            $this->commit(
                $locked,
                $from,
                $status,
                IncidentLogAction::StatusChanged,
                $userName,
                $metadata,
                function (Incident $locked) use ($status): void {
                    if ($status === IncidentStatus::Resolved) {
                        $locked->closed_at = now();
                    }
                },
            );
        });
    }

    /**
     * Reopen a resolved incident. This path is outside the normal workflow.
     */
    public function reopen(Incident $incident, string $reason, string $userName): Incident
    {
        $this->assertPersisted($incident);
        $reason = trim($reason);
        $userName = $this->actorName($userName);

        if ($reason === '') {
            throw new InvalidArgumentException('Indica el motivo de la reapertura.');
        }

        return $this->attempt($incident, function (Incident $locked) use ($reason, $userName): void {
            $from = $locked->status;

            if ($from !== IncidentStatus::Resolved) {
                throw InvalidIncidentTransitionException::notReopenable($from);
            }

            $this->commit(
                $locked,
                $from,
                IncidentStatus::Open,
                IncidentLogAction::Reopened,
                $userName,
                ['reason' => $reason],
                function (Incident $locked): void {
                    $locked->closed_at = null;
                },
            );
        });
    }

    private function assertPersisted(Incident $incident): void
    {
        if (! $incident->exists) {
            throw new LogicException('La incidencia debe estar guardada antes de cambiar su estado.');
        }
    }

    private function actorName(string $userName): string
    {
        $userName = trim($userName);

        if ($userName === '') {
            throw new InvalidArgumentException('Indica quién realiza el cambio.');
        }

        return $userName;
    }

    /**
     * @param  Closure(Incident): void  $callback
     */
    private function attempt(Incident $incident, Closure $callback): Incident
    {
        try {
            DB::transaction(function () use ($incident, $callback): void {
                $locked = Incident::query()
                    ->whereKey($incident->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                $callback($locked);
            });
        } catch (Throwable $exception) {
            if ($incident->exists) {
                $incident->refresh();
            }

            throw $exception;
        }

        return $incident->refresh();
    }

    /**
     * Persist the status change and its log in the current transaction.
     *
     * @param  array<string, mixed>|null  $metadata
     * @param  Closure(Incident): void  $adjust
     */
    private function commit(
        Incident $locked,
        IncidentStatus $from,
        IncidentStatus $to,
        IncidentLogAction $action,
        string $userName,
        ?array $metadata,
        Closure $adjust,
    ): void {
        $locked->authorizeStatusChange = true;

        try {
            $locked->status = $to;
            $adjust($locked);
            $locked->save();

            $locked->logs()->create([
                'action' => $action,
                'old_value' => $from->value,
                'new_value' => $to->value,
                'user_name' => $userName,
                'metadata' => $metadata,
            ]);
        } finally {
            $locked->authorizeStatusChange = false;
        }
    }
}
