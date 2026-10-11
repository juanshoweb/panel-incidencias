<?php

namespace App\Livewire\Incidents;

use App\Services\IncidentIndex;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Reactive;
use Livewire\Component;

class IncidentHistory extends Component
{
    #[Reactive]
    public ?int $incidentId = null;

    public function close(): void
    {
        $this->dispatch('close-history');
    }

    public function render(IncidentIndex $index): View
    {
        return view('livewire.incidents.incident-history', [
            'incident' => $this->incidentId === null ? null : $index->history($this->incidentId),
        ]);
    }
}
