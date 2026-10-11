<div>
    @if ($open)
        <div
            class="modal fade show d-block"
            tabindex="-1"
            role="dialog"
            aria-modal="true"
            aria-labelledby="create-incident-title"
            wire:click.self="close"
            wire:keydown.escape="close"
        >
            <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
                <div class="modal-content">
                    <div class="modal-header">
                        <h2 class="modal-title h5" id="create-incident-title">Nueva incidencia</h2>
                        <button type="button" class="btn-close" wire:click="close" aria-label="Cerrar"></button>
                    </div>
                    <div class="modal-body">
                        <p class="text-body-secondary">La incidencia se crea abierta. El estado se cambia después, desde su ficha.</p>

                        <form wire:submit="save" novalidate>
                            <div class="row g-3">
                                <div class="col-12 col-md-8">
                                    <label class="form-label" for="incident-title">Título</label>
                                    <input
                                        id="incident-title"
                                        type="text"
                                        maxlength="255"
                                        class="form-control @error('title') is-invalid @enderror"
                                        wire:model="title"
                                    >
                                    @error('title')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>
                                <div class="col-12 col-md-4">
                                    <label class="form-label" for="incident-priority">Prioridad</label>
                                    <select
                                        id="incident-priority"
                                        class="form-select @error('priority') is-invalid @enderror"
                                        wire:model="priority"
                                    >
                                        @foreach ($priorities as $item)
                                            <option value="{{ $item->value }}">{{ $item->label() }}</option>
                                        @endforeach
                                    </select>
                                    @error('priority')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>
                                <div class="col-12">
                                    <label class="form-label" for="incident-description">Descripción</label>
                                    <textarea
                                        id="incident-description"
                                        rows="3"
                                        class="form-control @error('description') is-invalid @enderror"
                                        wire:model="description"
                                    ></textarea>
                                    @error('description')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>
                                <div class="col-12 col-md-6">
                                    <label class="form-label" for="incident-requester">Solicitante</label>
                                    <input
                                        id="incident-requester"
                                        type="text"
                                        maxlength="255"
                                        class="form-control @error('requester_name') is-invalid @enderror"
                                        wire:model="requester_name"
                                    >
                                    @error('requester_name')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>
                                <div class="col-12 col-md-6">
                                    <label class="form-label" for="incident-assignee">Responsable</label>
                                    <input
                                        id="incident-assignee"
                                        type="text"
                                        maxlength="255"
                                        class="form-control @error('assigned_to') is-invalid @enderror"
                                        wire:model="assigned_to"
                                    >
                                    @error('assigned_to')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>
                            </div>
                            <div class="mt-3">
                                <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="save">
                                    Crear incidencia
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
        <div class="modal-backdrop fade show"></div>
    @endif
</div>
