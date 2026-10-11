<div>
    @if ($incident)
        <div
            class="modal fade show d-block"
            tabindex="-1"
            role="dialog"
            aria-modal="true"
            aria-labelledby="incident-history-title"
            wire:keydown.escape="close"
        >
            <div class="modal-dialog modal-dialog-scrollable modal-lg">
                <div class="modal-content">
                    <div class="modal-header align-items-start">
                        <div>
                            <h2 class="modal-title h5" id="incident-history-title">Historial de la incidencia #{{ $incident->id }}</h2>
                            <p class="mb-2">{{ $incident->title }}</p>
                            <span class="badge {{ $incident->status->badgeClass() }}">{{ $incident->status->label() }}</span>
                        </div>
                        <button type="button" class="btn-close" wire:click="close" aria-label="Cerrar"></button>
                    </div>
                    <div class="modal-body">
                        <section class="mb-4" aria-label="Historial">
                            <h3 class="h6">Historial</h3>
                            <div class="incident-timeline">
                                @forelse ($incident->logs as $log)
                                    <x-incident-log-entry
                                        :log="$log"
                                        :previous="$loop->first ? null : $incident->logs->values()[$loop->index - 1]"
                                        wire:key="history-log-{{ $log->id }}"
                                    />
                                @empty
                                    <p class="text-body-secondary mb-0">Todavía no hay cambios de estado.</p>
                                @endforelse
                            </div>
                        </section>

                        <section aria-label="Comentarios operativos">
                            <h3 class="h6">Comentarios operativos</h3>
                            @forelse ($incident->comments as $comment)
                                <article class="card mb-3" wire:key="history-comment-{{ $comment->id }}">
                                    <div class="card-body">
                                        <div class="fw-semibold">{{ $comment->author_name }}</div>
                                        <div class="small text-body-secondary">
                                            {{ $comment->created_at->timezone(config('app.timezone'))->format('d/m/Y · H:i') }}
                                        </div>
                                        <p class="mb-0 mt-2" style="white-space: pre-wrap;">{{ $comment->body }}</p>
                                    </div>
                                </article>
                            @empty
                                <p class="text-body-secondary mb-0">Todavía no hay comentarios.</p>
                            @endforelse
                        </section>
                    </div>
                </div>
            </div>
        </div>
        <div class="modal-backdrop fade show" wire:click="close"></div>
    @endif
</div>
