<?php

namespace App\Models;

use App\Enums\NotificationIgnoreReason;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Notification extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'id',
        'batch_id',
        'zaak_identificatie',
        'notification',
        'processed',
        'processed_at',
        'ignored_at',
        'ignored_reason',
    ];

    protected $casts = [
        'notification' => 'array',
        'processed' => 'boolean',
        'processed_at' => 'datetime',
        'ignored_at' => 'datetime',
        'ignored_reason' => NotificationIgnoreReason::class,
    ];

    /**
     * Get the batch this notification belongs to
     */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class, 'batch_id');
    }

    /**
     * Determine if this notification was accepted but deliberately not processed
     */
    public function isIgnored(): bool
    {
        return $this->ignored_at !== null;
    }

    /**
     * Mark the notification as ignored. It stays unprocessed so it can be processed later.
     */
    public function markIgnored(NotificationIgnoreReason $reason): void
    {
        $this->update([
            'processed' => false,
            'ignored_at' => now(),
            'ignored_reason' => $reason,
        ]);
    }

    /**
     * Scope to notifications that were accepted but deliberately not processed
     *
     * @param  Builder<Notification>  $query
     * @return Builder<Notification>
     */
    public function scopeIgnored(Builder $query, ?NotificationIgnoreReason $reason = null): Builder
    {
        return $query->whereNotNull('ignored_at')
            ->when($reason, fn (Builder $query) => $query->where('ignored_reason', $reason));
    }
}
