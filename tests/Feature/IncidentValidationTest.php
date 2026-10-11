<?php

namespace Tests\Feature;

use App\Enums\IncidentPriority;
use App\Enums\IncidentStatus;
use App\Models\Incident;
use App\Validation\IncidentValidation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class IncidentValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_creation_rejects_incomplete_or_forced_status_in_spanish(): void
    {
        try {
            $data = IncidentValidation::validateCreation([
                'description' => 'Texto',
                'priority' => 'urgent',
                'requester_name' => 'Ana',
                'status' => 'resolved',
            ]);
            Incident::query()->create($data);
            $this->fail('Invalid creation data should be rejected.');
        } catch (ValidationException $exception) {
            $this->assertSame(0, Incident::query()->count());
            $this->assertSame('El título es obligatorio.', $exception->errors()['title'][0]);
            $this->assertSame('La prioridad seleccionada no es válida.', $exception->errors()['priority'][0]);
            $this->assertSame('El estado no se puede indicar al crear la incidencia.', $exception->errors()['status'][0]);
        }
    }

    public function test_valid_creation_stays_open_and_ignores_mass_assigned_status(): void
    {
        $data = IncidentValidation::validateCreation([
            'title' => '  Caída del enlace  ',
            'description' => 'Se corta cada pocos minutos.',
            'priority' => 'high',
            'requester_name' => 'Laura Méndez',
            'assigned_to' => 'Carlos Ruiz',
        ]);

        $incident = Incident::query()->create($data);

        $this->assertSame('Caída del enlace', $incident->title);
        $this->assertSame(IncidentStatus::Open, $incident->status);
        $this->assertSame(IncidentPriority::High, $incident->priority);
        $this->assertNull($incident->closed_at);

        $forced = Incident::query()->create([
            'title' => 'Intento de nacer cerrada',
            'requester_name' => 'Ana',
            'priority' => IncidentPriority::Low,
            'status' => IncidentStatus::Resolved,
            'closed_at' => now(),
        ]);

        $this->assertSame(IncidentStatus::Open, $forced->status);
        $this->assertNull($forced->closed_at);
    }

    public function test_blank_comment_is_rejected_in_spanish(): void
    {
        $incident = Incident::factory()->create();

        try {
            $data = IncidentValidation::validateComment([
                'author_name' => 'Ana',
                'body' => '   ',
            ]);
            $incident->comments()->create($data);
            $this->fail('A blank comment should be rejected.');
        } catch (ValidationException $exception) {
            $this->assertSame(0, $incident->comments()->count());
            $this->assertSame('El comentario no puede estar vacío.', $exception->errors()['body'][0]);
        }
    }

    public function test_valid_comment_is_stored(): void
    {
        $incident = Incident::factory()->create();

        $data = IncidentValidation::validateComment([
            'author_name' => '  Ana  ',
            'body' => '  El fallo sigue apareciendo.  ',
        ]);

        $comment = $incident->comments()->create($data);

        $this->assertSame('Ana', $comment->author_name);
        $this->assertSame('El fallo sigue apareciendo.', $comment->body);
    }
}
