<?php

namespace App\Models;

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
    ];

    protected $casts = [
        'notification' => 'array',
        'processed' => 'boolean',
        'processed_at' => 'datetime',
    ];

    /**
     * Get the batch this notification belongs to
     */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class, 'batch_id');
    }
}
