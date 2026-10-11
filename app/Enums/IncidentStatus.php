<?php

namespace App\Enums;

enum IncidentStatus: string
{
    case Open = 'open';
    case Review = 'review';
    case Blocked = 'blocked';
    case Resolved = 'resolved';

    /**
     * Normal workflow. Resolved has no outgoing transition; reopening is explicit.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Open => [self::Review],
            self::Review => [self::Blocked, self::Resolved],
            self::Blocked => [self::Review],
            self::Resolved => [],
        };
    }

    public function canTransitionTo(self $status): bool
    {
        return in_array($status, $this->allowedTransitions(), true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Abierta',
            self::Review => 'En revisión',
            self::Blocked => 'Bloqueada',
            self::Resolved => 'Resuelta',
        };
    }

    public function countLabel(): string
    {
        return match ($this) {
            self::Open => 'Abiertas',
            self::Review => 'En revisión',
            self::Blocked => 'Bloqueadas',
            self::Resolved => 'Resueltas',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Open => 'text-bg-primary',
            self::Review => 'text-bg-warning',
            self::Blocked => 'text-bg-danger',
            self::Resolved => 'text-bg-success',
        };
    }

    /**
     * History chips use a blue review state. The table badge stays yellow.
     */
    public function historyBadgeClass(): string
    {
        return match ($this) {
            self::Open => 'text-bg-primary',
            self::Review => 'text-bg-info',
            self::Blocked => 'text-bg-danger',
            self::Resolved => 'text-bg-success',
        };
    }

    public function actionLabel(self $from): string
    {
        return match ($this) {
            self::Review => $from === self::Blocked ? 'Volver a revisión' : 'Pasar a revisión',
            self::Blocked => 'Bloquear',
            self::Resolved => 'Resolver',
            self::Open => 'Reabrir',
        };
    }

    public function confirmationMessage(): ?string
    {
        return match ($this) {
            self::Blocked => '¿Bloquear esta incidencia?',
            self::Resolved => '¿Resolver esta incidencia?',
            default => null,
        };
    }
}
