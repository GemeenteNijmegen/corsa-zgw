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
