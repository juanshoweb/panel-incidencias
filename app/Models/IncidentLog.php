<?php

namespace App\Models;

use App\Enums\IncidentLogAction;
use Database\Factories\IncidentLogFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IncidentLog extends Model
{
    /** @use HasFactory<IncidentLogFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'incident_id',
        'action',
        'old_value',
        'new_value',
        'user_name',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'action' => IncidentLogAction::class,
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Incident, $this>
     */
    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    /**
     * Compact gap since the previous history entry, in the timeline style (+2 min).
     */
    public function elapsedSince(?self $previous): ?string
    {
        if ($previous === null) {
            return null;
        }

        $seconds = (int) $previous->created_at->diffInSeconds($this->created_at, absolute: true);

        if ($seconds < 1) {
            return null;
        }

        if ($seconds < 60) {
            return '+1 min';
        }

        $minutes = intdiv($seconds, 60);

        if ($minutes < 60) {
            return '+'.$minutes.' min';
        }

        $hours = intdiv($minutes, 60);
        $minuteRemainder = $minutes % 60;

        if ($hours < 24) {
            return $minuteRemainder === 0
                ? '+'.$hours.' h'
                : '+'.$hours.' h '.$minuteRemainder.' min';
        }

        $days = intdiv($hours, 24);
        $hourRemainder = $hours % 24;

        return $hourRemainder === 0
            ? '+'.$days.' d'
            : '+'.$days.' d '.$hourRemainder.' h';
    }
}
