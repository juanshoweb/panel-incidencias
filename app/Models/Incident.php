<?php

namespace App\Models;

use App\Enums\IncidentPriority;
use App\Enums\IncidentStatus;
use App\Exceptions\InvalidIncidentTransitionException;
use App\Services\IncidentWorkflowService;
use Database\Factories\IncidentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Incident extends Model
{
    /** @use HasFactory<IncidentFactory> */
    use HasFactory;

    /**
     * Set only by IncidentWorkflowService so status changes stay audited.
     */
    public bool $authorizeStatusChange = false;

    /**
     * Status is intentionally not fillable. A create from request input stays open.
     *
     * @var list<string>
     */
    protected $fillable = [
        'title',
        'description',
        'priority',
        'requester_name',
        'assigned_to',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'open',
        'priority' => 'medium',
    ];

    protected static function booted(): void
    {
        static::creating(function (Incident $incident): void {
            if ($incident->status === IncidentStatus::Resolved && $incident->closed_at === null) {
                $incident->closed_at = now();
            }
        });

        static::updating(function (Incident $incident): void {
            if (! $incident->isDirty('status') || $incident->authorizeStatusChange) {
                return;
            }

            throw InvalidIncidentTransitionException::unguarded();
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => IncidentStatus::class,
            'priority' => IncidentPriority::class,
            'closed_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<IncidentLog, $this>
     */
    public function logs(): HasMany
    {
        return $this->hasMany(IncidentLog::class);
    }

    /**
     * @return HasMany<IncidentComment, $this>
     */
    public function comments(): HasMany
    {
        return $this->hasMany(IncidentComment::class);
    }

    /**
     * @param  Builder<Incident>  $query
     */
    public function scopeStatus(Builder $query, IncidentStatus $status): void
    {
        $query->where('status', $status->value);
    }

    /**
     * @param  Builder<Incident>  $query
     */
    public function scopePriority(Builder $query, IncidentPriority $priority): void
    {
        $query->where('priority', $priority->value);
    }

    /**
     * @param  Builder<Incident>  $query
     */
    public function scopeSearch(Builder $query, string $term): void
    {
        $term = trim($term);

        if ($term === '') {
            return;
        }

        $like = '%'.addcslashes($term, '%_\\').'%';

        $query->where(function (Builder $query) use ($like): void {
            $columns = ['title', 'description', 'requester_name', 'assigned_to'];
            $query->whereRaw(array_shift($columns).' LIKE ? ESCAPE ?', [$like, '\\']);

            foreach ($columns as $column) {
                $query->orWhereRaw($column.' LIKE ? ESCAPE ?', [$like, '\\']);
            }
        });
    }

    /**
     * Move the incident along the normal workflow and record the change.
     *
     * @param  array<string, mixed>|null  $metadata
     */
    public function transitionTo(IncidentStatus $status, string $userName, ?array $metadata = null): self
    {
        return app(IncidentWorkflowService::class)->transition($this, $status, $userName, $metadata);
    }

    /**
     * Reopen a resolved incident. This path is outside the normal workflow.
     */
    public function reopen(string $reason, string $userName): self
    {
        return app(IncidentWorkflowService::class)->reopen($this, $reason, $userName);
    }
}
