<?php

namespace App\Livewire\Incidents;

use App\Enums\IncidentPriority;
use App\Enums\IncidentStatus;
use App\Services\IncidentIndex;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.layouts.app', ['title' => 'Panel de incidencias'])]
class IncidentBoard extends Component
{
    use WithPagination;

    protected string $paginationTheme = 'bootstrap';

    public string $status = '';

    public string $priority = '';

    public string $search = '';

    public ?int $selectedId = null;

    public ?int $historyId = null;

    public function updating(string $property): void
    {
        if (in_array($property, ['status', 'priority', 'search'], true)) {
            $this->resetPage();
        }
    }

    public function filterByStatus(string $status): void
    {
        $this->status = $this->status === $status ? '' : $status;
        $this->resetPage();
    }

    public function select(int $incidentId): void
    {
        $this->selectedId = $incidentId;
    }

    public function showHistory(int $incidentId): void
    {
        $this->historyId = $incidentId;
    }

    #[On('close-incident')]
    public function closeIncident(): void
    {
        $this->selectedId = null;
    }

    #[On('close-history')]
    public function closeHistory(): void
    {
        $this->historyId = null;
    }

    #[On('incident-created')]
    public function onIncidentCreated(): void
    {
        $this->resetPage();
    }

    #[On('incident-updated')]
    public function onIncidentUpdated(): void
    {
        // Re-render so counters and the table pick up the new status.
    }

    public function render(IncidentIndex $index): View
    {
        return view('livewire.incidents.incident-board', [
            'counts' => $index->counts(),
            'incidents' => $index->paginate(
                IncidentStatus::tryFrom($this->status),
                IncidentPriority::tryFrom($this->priority),
                $this->search,
            ),
            'statuses' => IncidentStatus::cases(),
            'priorities' => IncidentPriority::cases(),
        ]);
    }
}
