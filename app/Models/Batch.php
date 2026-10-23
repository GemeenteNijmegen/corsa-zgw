<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
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
     *
     * @return HasMany<Notification, $this>
     */
    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class, 'batch_id');
    }

    /**
     * Add the number of failed notifications and of notifications that are not processed yet, in the
     * same query, so an overview does not count them per row.
     *
     * @param  Builder<Batch>  $query
     * @return Builder<Batch>
     */
    public function scopeWithNotificationOutcome(Builder $query): Builder
    {
        return $query->withCount([
            'notifications as failed_notifications_count' => self::failedForGood(...),
            'notifications as not_processed_notifications_count' => self::notProcessedYet(...),
        ]);
    }

    /**
     * Batches with at least one notification whose job failed for good.
     *
     * @param  Builder<Batch>  $query
     * @return Builder<Batch>
     */
    public function scopeWithFailedNotifications(Builder $query): Builder
    {
        return $query->whereHas('notifications', self::failedForGood(...));
    }

    /**
     * Batches that are marked as processed while a notification in them is not processed, not failed
     * and not ignored.
     *
     * @param  Builder<Batch>  $query
     * @return Builder<Batch>
     */
    public function scopeIncomplete(Builder $query): Builder
    {
        return $query->where('status', 'processed')
            ->whereHas('notifications', self::notProcessedYet(...));
    }

    /**
     * @param  Builder<Notification>  $query
     * @return Builder<Notification>
     */
    private static function failedForGood(Builder $query): Builder
    {
        return $query->failedForGood();
    }

    /**
     * @param  Builder<Notification>  $query
     * @return Builder<Notification>
     */
    private static function notProcessedYet(Builder $query): Builder
    {
        return $query->notProcessedYet();
    }

    public function failedNotificationsCount(): int
    {
        if (array_key_exists('failed_notifications_count', $this->getAttributes())) {
            return (int) $this->getAttribute('failed_notifications_count');
        }

        return $this->notifications()->failedForGood()->count();
    }

    public function notProcessedNotificationsCount(): int
    {
        if (array_key_exists('not_processed_notifications_count', $this->getAttributes())) {
            return (int) $this->getAttribute('not_processed_notifications_count');
        }

        return $this->notifications()->notProcessedYet()->count();
    }

    /**
     * Marked as processed while a notification in it is not processed, not failed and not ignored.
     */
    public function isIncomplete(): bool
    {
        return $this->status === 'processed' && $this->notProcessedNotificationsCount() > 0;
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
     * Lock the batch and set it to processing, unless it is locked already.
     *
     * One conditional update, so of two jobs that try this at the same time only one changes the
     * row. On PostgreSQL the update also waits for a notification that is being added to the batch
     * (see BatchingService::getOrCreateBatch()), so the notifications read after it include that one.
     *
     * @return bool whether this call locked the batch
     */
    public function lockForProcessing(): bool
    {
        $locked = self::whereKey($this->getKey())
            ->whereNull('locked_at')
            ->update([
                'locked_at' => now(),
                'status' => 'processing',
            ]) === 1;

        $this->refresh();

        return $locked;
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
     * Mark the batch as processed, unless it is processed already. One conditional update, so a
     * second chain for the same batch keeps the time the first one finished.
     *
     * @return bool whether this call marked the batch
     */
    public function markProcessedOnce(): bool
    {
        $marked = self::whereKey($this->getKey())
            ->whereNull('processed_at')
            ->update([
                'processed_at' => now(),
                'status' => 'processed',
            ]) === 1;

        $this->refresh();

        return $marked;
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
     * Get the notifications in the order the batch processes them (see Notification::processingGroup()).
     */
    public function getNotificationsSorted(): \Illuminate\Database\Eloquent\Collection
    {
        return $this->notifications()->inProcessingOrder()->get();
    }
}
