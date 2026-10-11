<?php

namespace Database\Seeders;

use App\Enums\IncidentPriority;
use App\Enums\IncidentStatus;
use App\Models\Incident;
use Illuminate\Database\Seeder;

class IncidentSeeder extends Seeder
{
    /**
     * Seed operational incidents across the workflow.
     */
    public function run(): void
    {
        $network = Incident::factory()->create([
            'title' => 'Caída intermitente del enlace principal',
            'description' => 'El sitio remoto pierde conectividad cada pocos minutos.',
            'priority' => IncidentPriority::High,
            'requester_name' => 'Laura Méndez',
            'assigned_to' => 'Carlos Ruiz',
            'status' => IncidentStatus::Open,
        ]);

        $network->comments()->create([
            'author_name' => 'Carlos Ruiz',
            'body' => 'Revisando el router de borde.',
        ]);

        $billing = Incident::factory()->create([
            'title' => 'Error al cerrar el lote de facturación',
            'description' => 'El cierre nocturno termina con un código 500.',
            'priority' => IncidentPriority::Critical,
            'requester_name' => 'Andrés Paredes',
            'assigned_to' => 'Marta Gil',
        ]);
        $billing->transitionTo(IncidentStatus::Review, 'Marta Gil');
        $billing->transitionTo(IncidentStatus::Blocked, 'Marta Gil', [
            'reason' => 'Esperando credenciales del proveedor.',
        ]);

        $printer = Incident::factory()->create([
            'title' => 'Impresora de almacén sin tóner',
            'description' => null,
            'priority' => IncidentPriority::Low,
            'requester_name' => 'Sofía Navarro',
            'assigned_to' => 'Luis Ortega',
        ]);
        $printer->transitionTo(IncidentStatus::Review, 'Luis Ortega');
        $printer->transitionTo(IncidentStatus::Resolved, 'Luis Ortega');

        $vpn = Incident::factory()->create([
            'title' => 'VPN de proveedores rechaza el certificado',
            'description' => 'El acceso de terceros falla desde el último despliegue.',
            'priority' => IncidentPriority::Medium,
            'requester_name' => 'Elena Cruz',
            'assigned_to' => 'Carlos Ruiz',
        ]);
        $vpn->transitionTo(IncidentStatus::Review, 'Carlos Ruiz');
        $vpn->transitionTo(IncidentStatus::Resolved, 'Carlos Ruiz');
        $vpn->reopen('El certificado volvió a caducar tras el despliegue.', 'Elena Cruz');
    }
}
