<?php

namespace App\Services;

use App\Enums\IncidentPriority;
use App\Enums\IncidentStatus;
use App\Models\Incident;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class IncidentIndex
{
    /**
     * Global totals for every status, including those with no rows.
     *
     * @return array<string, int>
     */
    public function counts(): array
    {
        $rows = Incident::query()
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $counts = [];

        foreach (IncidentStatus::cases() as $status) {
            $counts[$status->value] = (int) ($rows[$status->value] ?? 0);
        }

        return $counts;
    }

    /**
     * @return LengthAwarePaginator<int, Incident>
     */
    public function paginate(
        ?IncidentStatus $status,
        ?IncidentPriority $priority,
        ?string $search,
        int $perPage = 10,
    ): LengthAwarePaginator {
        return Incident::query()
            ->when($status !== null, fn ($query) => $query->status($status))
            ->when($priority !== null, fn ($query) => $query->priority($priority))
            ->when(is_string($search) && trim($search) !== '', fn ($query) => $query->search($search))
            ->latest()
            ->paginate($perPage);
    }

    public function findWithHistory(int $id): Incident
    {
        return Incident::query()
            ->with([
                'comments' => fn ($query) => $query->oldest(),
                'logs' => fn ($query) => $query->oldest(),
            ])
            ->findOrFail($id);
    }

    /**
     * One incident plus the columns the history modal renders.
     * Logs and comments stay on the existing relations, oldest first.
     */
    public function history(int $id): Incident
    {
        return Incident::query()
            ->select(['id', 'title', 'status'])
            ->with([
                'logs' => fn ($query) => $query
                    ->select(['id', 'incident_id', 'action', 'old_value', 'new_value', 'user_name', 'metadata', 'created_at'])
                    ->orderBy('created_at')
                    ->orderBy('id'),
                'comments' => fn ($query) => $query
                    ->select(['id', 'incident_id', 'author_name', 'body', 'created_at'])
                    ->orderBy('created_at')
                    ->orderBy('id'),
            ])
            ->findOrFail($id);
    }
}
