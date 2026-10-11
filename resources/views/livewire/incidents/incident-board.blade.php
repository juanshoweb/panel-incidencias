<div>
    <div class="container py-4">
        <header class="mb-4 d-flex flex-wrap justify-content-between align-items-center gap-3">
            <div>
                <h1 class="h3 mb-1">Panel de incidencias</h1>
                <p class="text-body-secondary mb-0">Seguimiento operativo de incidencias: estado, prioridad, responsable e historial.</p>
            </div>
            <button
                type="button"
                class="btn btn-primary"
                wire:click="$dispatchTo('incidents.create-incident', 'open-create-incident')"
            >
                Nueva incidencia
            </button>
        </header>

        <section class="mb-4" aria-label="Contadores por estado">
            <div class="row row-cols-2 row-cols-md-4 g-3">
                @foreach ($statuses as $item)
                    <div class="col">
                        <button
                            type="button"
                            wire:click="filterByStatus('{{ $item->value }}')"
                            aria-pressed="{{ $status === $item->value ? 'true' : 'false' }}"
                            @class([
                                'btn w-100 h-100 text-start p-3',
                                'btn-primary' => $status === $item->value,
                                'bg-white border' => $status !== $item->value,
                            ])
                        >
                            <span @class(['d-block text-uppercase small', 'text-body-secondary' => $status !== $item->value])>{{ $item->countLabel() }}</span>
                            <span class="d-block fs-3 fw-semibold">{{ $counts[$item->value] }}</span>
                        </button>
                    </div>
                @endforeach
            </div>
        </section>

        <section class="card shadow-sm mb-4">
            <div class="card-body">
                <h2 class="h5">Filtros</h2>
                <div class="row g-3">
                    <div class="col-12 col-md-4">
                        <label class="form-label" for="filter-status">Estado</label>
                        <select id="filter-status" class="form-select" wire:model.live="status">
                            <option value="">Todos los estados</option>
                            @foreach ($statuses as $item)
                                <option value="{{ $item->value }}">{{ $item->label() }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-12 col-md-4">
                        <label class="form-label" for="filter-priority">Prioridad</label>
                        <select id="filter-priority" class="form-select" wire:model.live="priority">
                            <option value="">Todas las prioridades</option>
                            @foreach ($priorities as $item)
                                <option value="{{ $item->value }}">{{ $item->label() }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-12 col-md-4">
                        <label class="form-label" for="filter-search">Texto libre</label>
                        <input
                            id="filter-search"
                            type="search"
                            class="form-control"
                            placeholder="Título, descripción, solicitante o responsable"
                            wire:model.live.debounce.300ms="search"
                        >
                    </div>
                </div>
            </div>
        </section>

        <section class="card shadow-sm" aria-label="Listado de incidencias">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th scope="col">Título</th>
                            <th scope="col">Prioridad</th>
                            <th scope="col">Estado</th>
                            <th scope="col">Fecha</th>
                            <th scope="col">Solicitante</th>
                            <th scope="col">Responsable</th>
                            <th scope="col"><span class="visually-hidden">Acciones</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($incidents as $incident)
                            <tr wire:key="incident-{{ $incident->id }}" @class(['table-active' => $selectedId === $incident->id])>
                                <td>
                                    <button
                                        type="button"
                                        class="btn btn-link p-0 text-start text-decoration-none"
                                        wire:click="select({{ $incident->id }})"
                                    >
                                        {{ $incident->title }}
                                    </button>
                                </td>
                                <td>{{ $incident->priority->label() }}</td>
                                <td>
                                    <span class="badge {{ $incident->status->badgeClass() }}">{{ $incident->status->label() }}</span>
                                </td>
                                <td class="text-nowrap">{{ $incident->created_at->timezone(config('app.timezone'))->format('d/m/Y H:i') }}</td>
                                <td>{{ $incident->requester_name }}</td>
                                <td>{{ $incident->assigned_to ?: 'Sin responsable' }}</td>
                                <td class="text-nowrap">
                                    <button
                                        type="button"
                                        class="btn btn-sm btn-outline-secondary"
                                        wire:click="showHistory({{ $incident->id }})"
                                    >
                                        Ver historial
                                    </button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="text-body-secondary">No hay incidencias con estos filtros.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($incidents->hasPages())
                <div class="card-body border-top">
                    {{ $incidents->links() }}
                </div>
            @endif
        </section>
    </div>

    <livewire:incidents.create-incident />
    <livewire:incidents.incident-detail :incident-id="$selectedId" />
    <livewire:incidents.incident-history :incident-id="$historyId" />
</div>
