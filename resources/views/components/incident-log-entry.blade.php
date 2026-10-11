@props(['log', 'previous' => null])

@php
    $created = $log->action === \App\Enums\IncidentLogAction::Created;
    $old = \App\Enums\IncidentStatus::tryFrom((string) $log->old_value);
    $new = \App\Enums\IncidentStatus::tryFrom((string) $log->new_value);
    $priority = \App\Enums\IncidentPriority::tryFrom((string) ($log->metadata['priority'] ?? ''));
    $when = $log->created_at->timezone(config('app.timezone'))->format('d/m/Y · H:i');
    $elapsed = $log->elapsedSince($previous);
@endphp

@once
    <style>
        .incident-timeline-item {
            --timeline-avatar: 2.25rem;
            position: relative;
            padding-bottom: 1.25rem;
        }

        .incident-timeline-item:last-child {
            padding-bottom: 0;
        }

        .incident-timeline-item:not(:last-child)::before {
            content: "";
            position: absolute;
            left: calc(var(--timeline-avatar) / 2);
            top: var(--timeline-avatar);
            bottom: 0;
            width: 2px;
            transform: translateX(-50%);
            background-color: var(--bs-border-color);
        }

        .incident-timeline-avatar {
            width: var(--timeline-avatar);
            height: var(--timeline-avatar);
            z-index: 1;
        }
    </style>
@endonce

<article {{ $attributes->merge(['class' => 'incident-timeline-item d-flex gap-3']) }}>
    <span
        class="incident-timeline-avatar d-inline-flex align-items-center justify-content-center rounded-circle bg-body-secondary text-body-secondary flex-shrink-0 position-relative"
        aria-hidden="true"
    >
        @if ($created)
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
                <path d="M8 4a.5.5 0 0 1 .5.5v3h3a.5.5 0 0 1 0 1h-3v3a.5.5 0 0 1-1 0v-3h-3a.5.5 0 0 1 0-1h3v-3A.5.5 0 0 1 8 4"/>
            </svg>
        @else
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
                <path d="M8 8a3 3 0 1 0 0-6 3 3 0 0 0 0 6m2-3a2 2 0 1 1-4 0 2 2 0 0 1 4 0m4 8c0 1-1.5 1.5-3.5 1.5h-5C3.5 14.5 2 14 2 13c0-2 2.5-3 6-3s6 1 6 3m-1-.004c-.001-.246-.154-.986-.832-1.664C11.516 10.68 10.289 10 8 10s-3.516.68-4.168 1.332c-.678.678-.83 1.418-.832 1.664z"/>
            </svg>
        @endif
    </span>
    <div class="d-flex flex-grow-1 justify-content-between align-items-start gap-3 min-w-0">
        <div class="min-w-0">
            <div>
                <span class="fw-semibold">{{ $log->user_name }}</span>
                {{ $log->action->timelinePhrase() }}
            </div>
            @if ($created && $priority)
                <span class="badge rounded-pill text-bg-light border mt-1">Prioridad: {{ $priority->label() }}</span>
            @endif
            @if (! $created && ($log->old_value || $log->new_value))
                <div class="d-flex flex-wrap align-items-center gap-2 mt-1">
                    <span class="badge rounded-pill text-bg-light border">{{ $old?->label() ?? $log->old_value }}</span>
                    <span aria-hidden="true">→</span>
                    <span @class(['badge rounded-pill', $new?->historyBadgeClass() ?? 'text-bg-secondary'])>{{ $new?->label() ?? $log->new_value }}</span>
                </div>
            @endif
            @if (is_string($log->metadata['reason'] ?? null) && $log->metadata['reason'] !== '')
                <div class="mt-1" style="white-space: pre-wrap;">{{ $log->metadata['reason'] }}</div>
            @endif
        </div>
        <div class="small text-end text-nowrap flex-shrink-0">
            <time class="text-body-secondary" datetime="{{ $log->created_at->toIso8601String() }}">{{ $when }}</time>
            @if ($elapsed)
                <span class="text-info">· {{ $elapsed }}</span>
            @endif
        </div>
    </div>
</article>
