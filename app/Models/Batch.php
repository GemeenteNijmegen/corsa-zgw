<?php

namespace App\Models;

use Carbon\CarbonInterface;
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

    /**
     * Batches that have been processing for longer than the configured threshold without a failed
     * notification: the chain may have stopped without a failure, for instance because a job is gone
     * from the queue. Derived when reading; the status of the batch does not change.
     *
     * @param  Builder<Batch>  $query
     * @return Builder<Batch>
     */
    public function scopeStuck(Builder $query): Builder
    {
        return $query->where('status', 'processing')
            ->where('locked_at', '<', self::stuckBefore())
            ->whereDoesntHave('notifications', self::failedForGood(...));
    }

    /**
     * The moment before which a batch that is still processing counts as taking long.
     */
    public static function stuckBefore(): CarbonInterface
    {
        return now()->subMinutes((int) config('batching.stuck_after_minutes', 30));
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
     * Processing for longer than the configured threshold without a failed notification. Uses the
     * failed count from the overview query when it is there, so an overview does not query per row.
     */
    public function isStuck(): bool
    {
        return $this->status === 'processing'
            && $this->locked_at !== null
            && $this->locked_at->lt(self::stuckBefore())
            && $this->failedNotificationsCount() === 0;
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
     * second chain for the same batch keeps the time the first one finished. A failed batch is not
     * processed, so a chain that goes on after its failed job succeeded on a retry still marks it.
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
     * Mark the batch with the given id as failed, unless it is processed already. This is the catch
     * callback of the chain of a batch, which is serialized with the chain, so it takes the id and
     * not the model. The condition is the same as in markProcessedOnce(): a batch that a chain has
     * finished stays processed, and a failed batch whose failed job later succeeds on a retry can
     * still be marked as processed by the last job of its chain.
     *
     * @return bool whether this call marked the batch
     */
    public static function markFailedUnlessProcessed(string $id): bool
    {
        return self::whereKey($id)
            ->whereNull('processed_at')
            ->update(['status' => 'failed']) === 1;
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
