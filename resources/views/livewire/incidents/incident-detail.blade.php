<div>
    @if ($incident)
        <div
            class="offcanvas offcanvas-end show"
            tabindex="-1"
            style="visibility: visible; width: min(36rem, 100vw);"
            role="dialog"
            aria-modal="true"
            aria-labelledby="incident-detail-title"
            wire:keydown.escape="close"
        >
            <div class="offcanvas-header border-bottom">
                <h2 class="offcanvas-title h5" id="incident-detail-title">{{ $incident->title }}</h2>
                <button type="button" class="btn-close" wire:click="close" aria-label="Cerrar"></button>
            </div>
            <div class="offcanvas-body">
                <div class="d-flex flex-wrap gap-2 mb-3">
                    <span class="badge {{ $incident->status->badgeClass() }}">{{ $incident->status->label() }}</span>
                    <span class="badge text-bg-light border">{{ $incident->priority->label() }}</span>
                </div>

                <p class="mb-1"><span class="fw-semibold">Solicitante:</span> {{ $incident->requester_name }}</p>
                <p class="mb-3"><span class="fw-semibold">Responsable:</span> {{ $incident->assigned_to ?: 'Sin responsable' }}</p>
                <p class="mb-4" style="white-space: pre-wrap;">{{ $incident->description ?: 'Sin descripción.' }}</p>

                <section class="mb-4" aria-label="Cambiar estado">
                    <h3 class="h6">Estado</h3>
                    <label class="form-label" for="operator-name">Nombre del operador</label>
                    <input
                        id="operator-name"
                        type="text"
                        maxlength="255"
                        class="form-control @error('operatorName') is-invalid @enderror"
                        wire:model="operatorName"
                    >
                    @error('operatorName')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror

                    <div class="d-flex flex-wrap gap-2 mt-3">
                        @foreach ($incident->status->allowedTransitions() as $next)
                            <button
                                type="button"
                                wire:click="changeStatus('{{ $next->value }}')"
                                wire:loading.attr="disabled"
                                wire:target="changeStatus, reopen"
                                @class([
                                    'btn btn-sm',
                                    'btn-warning' => $next === \App\Enums\IncidentStatus::Review,
                                    'btn-danger' => $next === \App\Enums\IncidentStatus::Blocked,
                                    'btn-success' => $next === \App\Enums\IncidentStatus::Resolved,
                                ])
                                @if ($message = $next->confirmationMessage())
                                    wire:confirm="{{ $message }}"
                                @endif
                            >
                                {{ $next->actionLabel($incident->status) }}
                            </button>
                        @endforeach
                    </div>

                    @if ($incident->status === \App\Enums\IncidentStatus::Resolved)
                        <div class="mt-3">
                            <label class="form-label" for="reopen-reason">Motivo de la reapertura</label>
                            <textarea
                                id="reopen-reason"
                                rows="2"
                                class="form-control @error('reopenReason') is-invalid @enderror"
                                wire:model="reopenReason"
                            ></textarea>
                            @error('reopenReason')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                            <button
                                type="button"
                                class="btn btn-sm btn-outline-primary mt-2"
                                wire:click="reopen"
                                wire:confirm="¿Reabrir esta incidencia?"
                                wire:loading.attr="disabled"
                                wire:target="reopen"
                            >
                                Reabrir
                            </button>
                        </div>
                    @endif

                    @if ($actionError)
                        <div class="alert alert-danger mt-3 mb-0" role="alert">{{ $actionError }}</div>
                    @endif
                </section>

                <section class="mb-4" aria-label="Historial">
                    <button
                        type="button"
                        class="btn btn-link p-0 text-body text-decoration-none d-inline-flex align-items-center gap-2 h6 mb-0"
                        wire:click="$toggle('historyExpanded')"
                        aria-expanded="{{ $historyExpanded ? 'true' : 'false' }}"
                    >
                        Historial
                        <svg
                            xmlns="http://www.w3.org/2000/svg"
                            width="16"
                            height="16"
                            fill="currentColor"
                            viewBox="0 0 16 16"
                            aria-hidden="true"
                            @style(['transform: rotate(180deg)' => $historyExpanded])
                        >
                            <path fill-rule="evenodd" d="M1.646 4.646a.5.5 0 0 1 .708 0L8 10.293l5.646-5.647a.5.5 0 0 1 .708.708l-6 6a.5.5 0 0 1-.708 0l-6-6a.5.5 0 0 1 0-.708"/>
                        </svg>
                    </button>
                    @if ($historyExpanded)
                        <div class="incident-timeline mt-3">
                            @forelse ($incident->logs as $log)
                                <x-incident-log-entry
                                    :log="$log"
                                    :previous="$loop->first ? null : $incident->logs->values()[$loop->index - 1]"
                                    wire:key="log-{{ $log->id }}"
                                />
                            @empty
                                <p class="text-body-secondary mb-0">Todavía no hay cambios de estado.</p>
                            @endforelse
                        </div>
                    @endif
                </section>

                <section aria-label="Comentarios">
                    <h3 class="h6">Comentarios</h3>
                    @forelse ($incident->comments as $comment)
                        <article class="card mb-3" wire:key="comment-{{ $comment->id }}">
                            <div class="card-body">
                                <div class="fw-semibold">{{ $comment->author_name }}</div>
                                <div class="small text-body-secondary">{{ $comment->created_at->timezone(config('app.timezone'))->format('d/m/Y H:i') }}</div>
                                <p class="mb-0 mt-2" style="white-space: pre-wrap;">{{ $comment->body }}</p>
                            </div>
                        </article>
                    @empty
                        <p class="text-body-secondary">Todavía no hay comentarios.</p>
                    @endforelse

                    <form wire:submit="addComment" novalidate>
                        <div class="mb-3">
                            <label class="form-label" for="comment-author">Autor</label>
                            <input
                                id="comment-author"
                                type="text"
                                maxlength="255"
                                class="form-control @error('author_name') is-invalid @enderror"
                                wire:model="author_name"
                            >
                            @error('author_name')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="comment-body">Comentario</label>
                            <textarea
                                id="comment-body"
                                rows="3"
                                class="form-control @error('body') is-invalid @enderror"
                                wire:model="body"
                            ></textarea>
                            @error('body')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                        <button type="submit" class="btn btn-outline-primary" wire:loading.attr="disabled" wire:target="addComment">
                            Añadir comentario
                        </button>
                    </form>
                </section>
            </div>
        </div>
        <div class="offcanvas-backdrop fade show" wire:click="close"></div>
    @endif
</div>
