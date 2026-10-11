<?php

namespace App\Livewire\Incidents;

use App\Enums\IncidentLogAction;
use App\Enums\IncidentPriority;
use App\Models\Incident;
use App\Validation\IncidentValidation;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\On;
use Livewire\Component;

class CreateIncident extends Component
{
    public bool $open = false;

    public string $title = '';

    public string $description = '';

    public string $priority = 'medium';

    public string $requester_name = '';

    public string $assigned_to = '';

    #[On('open-create-incident')]
    public function open(): void
    {
        $this->resetForm();
        $this->resetErrorBag();
        $this->open = true;
    }

    public function close(): void
    {
        $this->open = false;
        $this->resetForm();
        $this->resetErrorBag();
    }

    public function save(): void
    {
        $this->resetErrorBag();

        $data = IncidentValidation::validateCreation([
            'title' => $this->title,
            'description' => $this->description,
            'priority' => $this->priority,
            'requester_name' => $this->requester_name,
            'assigned_to' => $this->assigned_to,
        ]);

        DB::transaction(function () use ($data): void {
            $incident = Incident::query()->create($data);

            $priority = $data['priority'];

            $incident->logs()->create([
                'action' => IncidentLogAction::Created,
                'old_value' => null,
                'new_value' => null,
                'user_name' => $data['requester_name'],
                'metadata' => [
                    'priority' => $priority instanceof IncidentPriority ? $priority->value : (string) $priority,
                ],
            ]);
        });

        $this->resetForm();
        $this->open = false;

        $this->dispatch('incident-created');
    }

    public function render(): View
    {
        return view('livewire.incidents.create-incident', [
            'priorities' => IncidentPriority::cases(),
        ]);
    }

    private function resetForm(): void
    {
        $this->reset('title', 'description', 'requester_name', 'assigned_to');
        $this->priority = IncidentPriority::Medium->value;
    }
}
