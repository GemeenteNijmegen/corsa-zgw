<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Batch extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'id',
        'zaak_identificatie',
        'locked_at',
        'processed_at',
        'status',
    ];

    protected $casts = [
        'locked_at' => 'datetime',
        'processed_at' => 'datetime',
    ];

    /**
     * Get all notifications in this batch
     */
    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class, 'batch_id');
    }

    /**
     * Determine if this batch is locked
     */
    public function isLocked(): bool
    {
        return $this->locked_at !== null;
    }

    /**
     * Determine if this batch has been processed
     */
    public function isProcessed(): bool
    {
        return $this->processed_at !== null;
    }

    /**
     * Lock the batch
     */
    public function lock(): void
    {
        $this->update(['locked_at' => now()]);
    }

    /**
     * Mark batch as processed
     */
    public function markProcessed(): void
    {
        $this->update([
            'processed_at' => now(),
            'status' => 'processed',
        ]);
    }

    /**
     * Get the primary notification types in this batch
     */
    public function getNotificationTypes(): array
    {
        return $this->notifications()
            ->pluck('notification->actie')
            ->unique()
            ->values()
            ->toArray();
    }

    /**
     * Check if batch contains 'zaak aangemaakt' notification
     */
    public function hasZaakAangemaakt(): bool
    {
        return $this->notifications()
            ->whereJsonContains('notification->actie', 'create')
            ->whereJsonContains('notification->resource', 'zaak')
            ->exists();
    }

    /**
     * Check if batch contains a 'resultaat aangemaakt' notification
     */
    public function hasResultaatAangemaakt(): bool
    {
        return $this->notifications()
            ->whereJsonContains('notification->actie', 'create')
            ->whereJsonContains('notification->resource', 'resultaat')
            ->exists();
    }

    /**
     * Get notifications sorted by type and creation date
     */
    public function getNotificationsSorted(): \Illuminate\Database\Eloquent\Collection
    {
        $notifications = $this->notifications()
            ->get()
            ->sortBy(function (Model $notification) {
                // 'create' (zaak aangemaakt) should come first
                /** @var \App\Models\Notification $notification */
                $actie = $notification->notification['actie'] ?? '';
                $resource = $notification->notification['resource'] ?? '';
                if ($actie === 'create' && $resource === 'zaak') {
                    return 0;
                }

                // Resultaat must always be last
                if ($actie === 'create' && $resource === 'resultaat') {
                    return 2;
                }

                // Other notifications can be processed in the order they arrived
                return 1;
            })
            ->values();

        return $notifications;
    }
}
