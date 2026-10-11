<?php

namespace Tests\Feature;

use App\Enums\IncidentLogAction;
use App\Enums\IncidentPriority;
use App\Enums\IncidentStatus;
use App\Livewire\Incidents\CreateIncident;
use App\Livewire\Incidents\IncidentBoard;
use App\Livewire\Incidents\IncidentDetail;
use App\Livewire\Incidents\IncidentHistory;
use App\Models\Incident;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class IncidentBoardTest extends TestCase
{
    use RefreshDatabase;

    public function test_board_shows_header_counters_and_rows_from_the_database(): void
    {
        Incident::factory()->create([
            'title' => 'Enlace caído',
            'status' => IncidentStatus::Open,
            'priority' => IncidentPriority::High,
            'requester_name' => 'Laura Méndez',
            'assigned_to' => 'Carlos Ruiz',
        ]);
        Incident::factory()->inReview()->create(['title' => 'Factura bloqueada en revisión']);
        Incident::factory()->blocked()->create(['title' => 'Acceso bloqueado']);
        Incident::factory()->resolved()->create(['title' => 'Impresora resuelta']);

        Livewire::test(IncidentBoard::class)
            ->assertSee('Panel de incidencias')
            ->assertSee('Seguimiento operativo de incidencias')
            ->assertSee('Abiertas')
            ->assertSee('En revisión')
            ->assertSee('Bloqueadas')
            ->assertSee('Resueltas')
            ->assertSee('Enlace caído')
            ->assertSee('Alta')
            ->assertSee('Laura Méndez')
            ->assertSee('Carlos Ruiz')
            ->assertSee('Abierta');
    }

    public function test_filters_limit_the_table_and_keep_global_counters(): void
    {
        Incident::factory()->create([
            'title' => '100% caído',
            'description' => 'Corte del enlace',
            'status' => IncidentStatus::Open,
            'priority' => IncidentPriority::Critical,
            'requester_name' => 'Laura Méndez',
            'assigned_to' => 'Carlos Ruiz',
        ]);
        Incident::factory()->resolved()->create([
            'title' => 'Impresora',
            'description' => 'Sin tóner',
            'priority' => IncidentPriority::Low,
            'requester_name' => 'Sofía Navarro',
            'assigned_to' => 'Luis Ortega',
        ]);

        Livewire::test(IncidentBoard::class)
            ->set('status', IncidentStatus::Open->value)
            ->assertSee('100% caído')
            ->assertDontSee('Impresora')
            ->assertSee('Resueltas')
            ->set('status', '')
            ->set('priority', IncidentPriority::Low->value)
            ->assertSee('Impresora')
            ->assertDontSee('100% caído')
            ->set('priority', '')
            ->set('search', '%')
            ->assertSee('100% caído')
            ->assertDontSee('Impresora')
            ->set('search', 'Sofía')
            ->assertSee('Impresora')
            ->assertDontSee('100% caído')
            ->call('filterByStatus', IncidentStatus::Resolved->value)
            ->assertSet('status', IncidentStatus::Resolved->value)
            ->assertSee('Impresora');
    }

    public function test_table_is_paginated(): void
    {
        Incident::factory()->count(11)->create([
            'title' => 'Incidencia paginada',
            'requester_name' => 'Ana',
            'assigned_to' => 'Luis',
        ]);

        Livewire::test(IncidentBoard::class)
            ->assertSeeHtml('page-item')
            ->call('gotoPage', 2)
            ->assertSee('Incidencia paginada');
    }

    public function test_creation_shows_spanish_errors_and_stores_an_open_incident(): void
    {
        Livewire::test(CreateIncident::class)
            ->call('open')
            ->set('title', '')
            ->set('priority', 'urgent')
            ->set('requester_name', 'Ana')
            ->call('save')
            ->assertHasErrors(['title', 'priority'])
            ->assertSet('open', true)
            ->assertSee('El título es obligatorio.')
            ->assertSee('La prioridad seleccionada no es válida.');

        $this->assertSame(0, Incident::query()->count());

        Livewire::test(CreateIncident::class)
            ->call('open')
            ->set('title', '  Caída del enlace  ')
            ->set('description', 'Se corta cada pocos minutos.')
            ->set('priority', IncidentPriority::High->value)
            ->set('requester_name', 'Laura Méndez')
            ->set('assigned_to', 'Carlos Ruiz')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('title', '')
            ->assertSet('open', false);

        $incident = Incident::query()->first();

        $this->assertNotNull($incident);
        $this->assertSame('Caída del enlace', $incident->title);
        $this->assertSame(IncidentStatus::Open, $incident->status);
        $this->assertSame(IncidentPriority::High, $incident->priority);

        $log = $incident->logs()->sole();

        $this->assertSame(IncidentLogAction::Created, $log->action);
        $this->assertSame('Laura Méndez', $log->user_name);
        $this->assertNull($log->old_value);
        $this->assertNull($log->new_value);
        $this->assertSame(IncidentPriority::High->value, $log->metadata['priority']);

        Livewire::test(IncidentHistory::class, ['incidentId' => $incident->id])
            ->assertSeeInOrder([
                'Laura Méndez',
                'creó la incidencia',
                'Prioridad: Alta',
            ])
            ->assertDontSeeHtml('historyExpanded');
    }

    public function test_user_content_is_escaped(): void
    {
        Incident::factory()->create([
            'title' => '<script>alert(1)</script>',
            'requester_name' => 'Ana',
        ]);

        Livewire::test(IncidentBoard::class)
            ->assertSee('<script>alert(1)</script>')
            ->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_detail_shows_history_and_comments_without_lazy_loading(): void
    {
        $incident = Incident::factory()->create([
            'title' => 'VPN rechazada',
            'description' => 'Falla el certificado',
            'requester_name' => 'Elena Cruz',
            'assigned_to' => 'Carlos Ruiz',
        ]);
        $incident->comments()->create([
            'author_name' => 'Carlos Ruiz',
            'body' => 'Revisando el certificado.',
        ]);
        $incident->transitionTo(IncidentStatus::Review, 'Carlos Ruiz');

        Model::preventLazyLoading();

        try {
            Livewire::test(IncidentDetail::class, ['incidentId' => $incident->id])
                ->assertSet('historyExpanded', false)
                ->assertSee('VPN rechazada')
                ->assertSee('Falla el certificado')
                ->assertSee('Revisando el certificado.')
                ->assertDontSee('cambió el estado')
                ->assertDontSee('Abierta')
                ->set('historyExpanded', true)
                ->assertSee('cambió el estado')
                ->assertSee('Carlos Ruiz')
                ->assertSee('Abierta')
                ->assertSee('En revisión')
                ->tap(fn ($component) => $component->instance()->updatedIncidentId())
                ->assertSet('historyExpanded', false);
        } finally {
            Model::preventLazyLoading(false);
        }
    }

    public function test_comment_form_validates_and_stores(): void
    {
        $incident = Incident::factory()->create();

        Livewire::test(IncidentDetail::class, ['incidentId' => $incident->id])
            ->set('author_name', 'Ana')
            ->set('body', '   ')
            ->call('addComment')
            ->assertHasErrors(['body'])
            ->assertSee('El comentario no puede estar vacío.')
            ->set('body', '  El fallo sigue apareciendo.  ')
            ->call('addComment')
            ->assertHasNoErrors()
            ->assertSee('El fallo sigue apareciendo.');

        $this->assertSame('El fallo sigue apareciendo.', $incident->comments()->first()->body);
    }

    public function test_status_actions_follow_the_workflow_and_ask_before_blocking_or_resolving(): void
    {
        $open = Incident::factory()->create();

        Livewire::test(IncidentDetail::class, ['incidentId' => $open->id])
            ->assertSee('Pasar a revisión')
            ->assertDontSeeHtml('wire:confirm')
            ->call('changeStatus', IncidentStatus::Resolved->value)
            ->assertSee('Indica quién realiza el cambio.')
            ->set('operatorName', 'Ana')
            ->call('changeStatus', IncidentStatus::Resolved->value)
            ->assertSee('No se puede cambiar la incidencia de [open] a [resolved].');

        $this->assertSame(IncidentStatus::Open, $open->fresh()->status);

        Livewire::test(IncidentDetail::class, ['incidentId' => $open->id])
            ->set('operatorName', 'Ana')
            ->call('changeStatus', IncidentStatus::Review->value)
            ->assertHasNoErrors();

        $this->assertSame(IncidentStatus::Review, $open->fresh()->status);

        $review = Incident::factory()->inReview()->create();

        Livewire::test(IncidentDetail::class, ['incidentId' => $review->id])
            ->assertSeeHtml('wire:confirm="¿Bloquear esta incidencia?"')
            ->assertSeeHtml('wire:confirm="¿Resolver esta incidencia?"')
            ->set('operatorName', 'Ana')
            ->call('changeStatus', IncidentStatus::Blocked->value);

        $this->assertSame(IncidentStatus::Blocked, $review->fresh()->status);
        $this->assertNotNull($review->logs()->where('new_value', IncidentStatus::Blocked->value)->first());
    }

    public function test_reopening_requires_confirmation_and_a_reason(): void
    {
        $incident = Incident::factory()->resolved()->create();

        Livewire::test(IncidentDetail::class, ['incidentId' => $incident->id])
            ->assertSeeHtml('wire:confirm="¿Reabrir esta incidencia?"')
            ->set('operatorName', 'Ana')
            ->set('reopenReason', '   ')
            ->call('reopen')
            ->assertHasErrors(['reopenReason'])
            ->assertSee('Indica el motivo de la reapertura.')
            ->set('reopenReason', 'El fallo volvió a aparecer.')
            ->call('reopen')
            ->assertHasNoErrors();

        $fresh = $incident->fresh();

        $this->assertSame(IncidentStatus::Open, $fresh->status);
        $this->assertNull($fresh->closed_at);
    }

    public function test_each_row_opens_only_its_own_history(): void
    {
        $first = Incident::factory()->create(['title' => 'Primera incidencia']);
        $second = Incident::factory()->create(['title' => 'Segunda incidencia']);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $board = Livewire::test(IncidentBoard::class)
            ->assertSee('Ver historial')
            ->assertSeeHtml('wire:click="showHistory('.$first->id.')"')
            ->assertSeeHtml('wire:click="showHistory('.$second->id.')"');

        $listingQueries = implode(' ', array_column(DB::getQueryLog(), 'query'));
        $this->assertStringNotContainsString('incident_logs', $listingQueries);
        $this->assertStringNotContainsString('incident_comments', $listingQueries);

        $board->call('showHistory', $first->id)
            ->assertSet('historyId', $first->id)
            ->call('showHistory', $second->id)
            ->assertSet('historyId', $second->id)
            ->dispatch('close-history')
            ->assertSet('historyId', null);
    }

    public function test_history_modal_shows_empty_states_without_lazy_loading(): void
    {
        $incident = Incident::factory()->create(['title' => 'Sin movimientos']);

        Model::preventLazyLoading();

        try {
            Livewire::test(IncidentHistory::class, ['incidentId' => $incident->id])
                ->assertSee('Historial de la incidencia #'.$incident->id)
                ->assertSee('Sin movimientos')
                ->assertSee('Abierta')
                ->assertSee('Todavía no hay cambios de estado.')
                ->assertSee('Todavía no hay comentarios.')
                ->assertDontSee('cambió el estado');

            Livewire::test(IncidentHistory::class)
                ->assertDontSee('Historial de la incidencia');
        } finally {
            Model::preventLazyLoading(false);
        }
    }

    public function test_history_modal_reads_real_logs_and_comments_in_chronological_order(): void
    {
        $incident = Incident::factory()->create([
            'title' => 'Error al procesar un pedido',
        ]);
        $incident->comments()->create([
            'author_name' => 'Elena Cruz',
            'body' => 'El pedido sigue fallando.',
        ]);
        $incident->transitionTo(IncidentStatus::Review, 'Carlos López');
        $incident->transitionTo(IncidentStatus::Resolved, 'María García');
        $incident->reopen('El fallo volvió a aparecer.', 'Ana Soler');

        $incident->logs()->orderBy('id')->each(function ($log, int $index): void {
            $log->forceFill([
                'created_at' => now()->startOfMinute()->addMinutes($index * 2),
            ])->save();
        });

        Model::preventLazyLoading();

        try {
            Livewire::test(IncidentHistory::class, ['incidentId' => $incident->id])
                ->assertSee('Historial de la incidencia #'.$incident->id)
                ->assertSee('Error al procesar un pedido')
                ->assertSeeInOrder([
                    'Historial',
                    'Carlos López',
                    'cambió el estado',
                    'Abierta',
                    'En revisión',
                    'María García',
                    'cambió el estado',
                    'En revisión',
                    'Resuelta',
                    '+2 min',
                    'Ana Soler',
                    'reabrió la incidencia',
                    'Resuelta',
                    'Abierta',
                    'El fallo volvió a aparecer.',
                    'Comentarios operativos',
                    'Elena Cruz',
                    'El pedido sigue fallando.',
                ])
                ->assertDontSee('Todavía no hay cambios de estado.')
                ->assertDontSee('Todavía no hay comentarios.');
        } finally {
            Model::preventLazyLoading(false);
        }
    }
}
