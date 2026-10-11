<?php

namespace App\Livewire\Incidents;

use App\Enums\IncidentStatus;
use App\Exceptions\InvalidIncidentTransitionException;
use App\Models\Incident;
use App\Services\IncidentIndex;
use App\Validation\IncidentValidation;
use Illuminate\Contracts\View\View;
use InvalidArgumentException;
use Livewire\Attributes\Reactive;
use Livewire\Component;

class IncidentDetail extends Component
{
    #[Reactive]
    public ?int $incidentId = null;

    public string $operatorName = '';

    public string $author_name = '';

    public string $body = '';

    public string $reopenReason = '';

    public ?string $actionError = null;

    public bool $historyExpanded = false;

    public function updatedIncidentId(): void
    {
        $this->reset('body', 'reopenReason', 'actionError', 'historyExpanded');
        $this->resetErrorBag();
    }

    public function close(): void
    {
        $this->dispatch('close-incident');
    }

    public function changeStatus(string $status): void
    {
        $this->actionError = null;
        $this->resetErrorBag();
        $target = IncidentStatus::tryFrom($status);

        if ($target === null) {
            $this->actionError = 'El estado seleccionado no es válido.';

            return;
        }

        if (trim($this->operatorName) === '') {
            $this->addError('operatorName', 'Indica quién realiza el cambio.');

            return;
        }

        try {
            $this->incident()->transitionTo($target, $this->operatorName);
        } catch (InvalidIncidentTransitionException $exception) {
            $this->actionError = $exception->getMessage();

            return;
        }

        $this->dispatch('incident-updated');
    }

    public function reopen(): void
    {
        $this->actionError = null;
        $this->resetErrorBag();

        if (trim($this->operatorName) === '') {
            $this->addError('operatorName', 'Indica quién realiza el cambio.');
        }

        if (trim($this->reopenReason) === '') {
            $this->addError('reopenReason', 'Indica el motivo de la reapertura.');
        }

        if ($this->getErrorBag()->isNotEmpty()) {
            return;
        }

        try {
            $this->incident()->reopen($this->reopenReason, $this->operatorName);
        } catch (InvalidIncidentTransitionException|InvalidArgumentException $exception) {
            $this->actionError = $exception->getMessage();

            return;
        }

        $this->reset('reopenReason');
        $this->dispatch('incident-updated');
    }

    public function addComment(): void
    {
        $this->resetErrorBag();

        $data = IncidentValidation::validateComment([
            'author_name' => $this->author_name,
            'body' => $this->body,
        ]);

        $this->incident()->comments()->create($data);
        $this->reset('body');
        $this->dispatch('incident-updated');
    }

    public function render(IncidentIndex $index): View
    {
        return view('livewire.incidents.incident-detail', [
            'incident' => $this->incidentId === null ? null : $index->findWithHistory($this->incidentId),
        ]);
    }

    private function incident(): Incident
    {
        return app(IncidentIndex::class)->findWithHistory((int) $this->incidentId);
    }
}
