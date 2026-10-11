<?php

namespace App\Console\Commands;

use App\Enums\IntegrityIssue;
use App\Services\IncidentIntegrity\IncidentIntegrityDiagnoser;
use App\Services\IncidentIntegrity\IntegrityFinding;
use App\Services\IncidentIntegrity\IntegrityReport;
use Illuminate\Console\Command;

class DiagnoseIncidents extends Command
{
    protected $signature = 'incidents:diagnose
                            {--dry-run : Detecta inconsistencias sin modificar datos}';

    protected $description = 'Diagnostica la integridad entre el estado de las incidencias y su historial. El detalle está en docs/integridad-incidencias.md.';

    public function handle(IncidentIntegrityDiagnoser $diagnoser): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $report = $diagnoser->diagnose(apply: ! $dryRun);

        $this->line($dryRun
            ? 'Modo: solo lectura (--dry-run). No se ha escrito nada.'
            : 'Modo: corrección. Solo se aplican reparaciones con evidencia ya almacenada.');

        $this->newLine();
        $this->table(
            ['Criterio', 'Detectados', 'Corregidos', 'Pendientes de revisión'],
            $this->rows($report),
        );

        $this->line("Detectados: {$report->detected()}");
        $this->line("Corregidos: {$report->corrected()}");
        $this->line("Pendientes de revisión: {$report->pendingReview()}");

        if ($report->withheldByDryRun() > 0) {
            $this->line("Correcciones seguras no aplicadas por --dry-run: {$report->withheldByDryRun()}");
        }

        if ($report->findings === []) {
            $this->newLine();
            $this->info('No se detectaron inconsistencias.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->line('Detalle:');

        foreach ($report->findings as $finding) {
            $this->line($this->detailLine($finding, $report));
        }

        $this->newLine();
        $this->line('Los datos de demostración se cargan con: php artisan db:seed --class=IncidentIntegrityDemoSeeder');

        return ($report->pendingReview() > 0 || $report->withheldByDryRun() > 0)
            ? self::FAILURE
            : self::SUCCESS;
    }

    /**
     * @return list<list<int|string>>
     */
    private function rows(IntegrityReport $report): array
    {
        $rows = [];

        foreach (IntegrityIssue::cases() as $issue) {
            $items = $report->of($issue);
            $corrected = count(array_filter(
                $items,
                fn (IntegrityFinding $finding) => $finding->corrected,
            ));
            $pending = count(array_filter(
                $items,
                fn (IntegrityFinding $finding) => $report->isPending($finding),
            ));

            $rows[] = [$issue->label(), count($items), $corrected, $pending];
        }

        return $rows;
    }

    private function detailLine(IntegrityFinding $finding, IntegrityReport $report): string
    {
        $tag = match (true) {
            $finding->corrected => 'corregido',
            $finding->issue->correctable() && ! $report->applied => 'dry-run',
            $finding->issue->correctable() => 'omitido',
            default => 'revisión',
        };

        $suffix = $tag === 'omitido'
            ? ' No se aplicó: al bloquear la fila ya no se cumplía la condición.'
            : '';

        return "[{$tag}] incidencia {$finding->incidentId} ({$finding->title}): {$finding->summary}{$suffix}";
    }
}
