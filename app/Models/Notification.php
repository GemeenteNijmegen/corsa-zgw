<?php

namespace App\Models;

use App\Enums\NotificationIgnoreReason;
use App\Enums\NotificationRoute;
use App\Enums\NotificationStatus;
use App\Support\FailureDescription;
use App\ValueObjects\OpenNotification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Throwable;

class Notification extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'id',
        'batch_id',
        'route',
        'zaak_identificatie',
        'notification',
        'processed',
        'processed_at',
        'failed_at',
        'failure',
        'failed_job_id',
        'ignored_at',
        'ignored_reason',
    ];

    protected $casts = [
        'notification' => 'array',
        'route' => NotificationRoute::class,
        'processed' => 'boolean',
        'processed_at' => 'datetime',
        'failed_at' => 'datetime',
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
     * Mark the notification as processed, and clear a failure recorded by an earlier attempt.
     */
    public function markProcessed(): void
    {
        $this->update([
            'processed' => true,
            'processed_at' => now(),
            ...self::clearedFailure(),
        ]);
    }

    /**
     * Record that the job that processed this notification failed for good.
     *
     * @param  string|null  $jobId  id of the failed job on the queue, which the job monitor uses for its failed job page
     */
    public function markFailed(Throwable $e, ?string $jobId): void
    {
        $this->update([
            'failed_at' => now(),
            'failure' => FailureDescription::from($e),
            'failed_job_id' => $jobId,
        ]);
    }

    /**
     * Attributes that clear a recorded failure.
     *
     * @return array<string, null>
     */
    public static function clearedFailure(): array
    {
        return [
            'failed_at' => null,
            'failure' => null,
            'failed_job_id' => null,
        ];
    }

    public function status(): NotificationStatus
    {
        return match (true) {
            $this->processed => NotificationStatus::Processed,
            $this->ignored_at !== null => NotificationStatus::Ignored,
            $this->failed_at !== null => NotificationStatus::Failed,
            default => NotificationStatus::Pending,
        };
    }

    /**
     * Page of the failed job in the job monitor, when the job id is known. The job monitor keeps
     * failed jobs for a limited time (horizon.trim.failed), so the page may be gone.
     */
    public function failedJobUrl(): ?string
    {
        if ($this->failed_job_id === null) {
            return null;
        }

        // The job monitor's own route, so its configured path (horizon.path) and domain are used.
        return route('horizon.index', ['view' => 'failed/'.$this->failed_job_id]);
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

    /**
     * The group in which a batch processes this notification: the zaak first, then the other
     * notifications, then changes to documents (after the links that add them), and the resultaat
     * last (after which the zaak may be closed).
     */
    public static function processingGroup(?string $actie, ?string $resource): int
    {
        return match (true) {
            $actie === 'create' && $resource === 'zaak' => 0,
            OpenNotification::isDocumentUpdateAction($actie, $resource) => 2,
            $actie === 'create' && $resource === 'resultaat' => 3,
            default => 1,
        };
    }

    /**
     * Order notifications the way a batch processes them: by processingGroup(), and within a group
     * in the order they were received.
     *
     * @param  Builder<Notification>  $query
     * @return Builder<Notification>
     */
    public function scopeInProcessingOrder(Builder $query): Builder
    {
        // Let the query grammar write the JSON paths, so the order also holds on PostgreSQL,
        // where the MySQL style path '$.actie' does not match.
        $grammar = $query->getQuery()->getGrammar();
        $actie = $grammar->wrap('notification->actie');
        $resource = $grammar->wrap('notification->resource');

        return $query
            ->orderByRaw(
                "CASE
                    WHEN {$actie} = 'create' AND {$resource} = 'zaak' THEN 0
                    WHEN {$actie} IN ('update', 'partial_update') AND {$resource} = 'enkelvoudiginformatieobject' THEN 2
                    WHEN {$actie} = 'create' AND {$resource} = 'resultaat' THEN 3
                    ELSE 1
                END ASC"
            )
            ->orderBy('created_at')
            ->orderBy('id');
    }

    /**
     * Scope to notifications whose job failed for good: the ones with the status "Mislukt".
     *
     * @param  Builder<Notification>  $query
     * @return Builder<Notification>
     */
    public function scopeFailedForGood(Builder $query): Builder
    {
        return $query->whereNull('ignored_at')
            ->where('processed', false)
            ->whereNotNull('failed_at');
    }

    /**
     * Scope to notifications that are not processed, not failed and not ignored: the ones with the
     * status "Nog niet verwerkt".
     *
     * @param  Builder<Notification>  $query
     * @return Builder<Notification>
     */
    public function scopeNotProcessedYet(Builder $query): Builder
    {
        return $query->whereNull('ignored_at')
            ->where('processed', false)
            ->whereNull('failed_at');
    }
}
