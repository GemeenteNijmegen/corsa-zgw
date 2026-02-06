<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Notification extends Model
{
    use HasUlids;

    protected $keyType = 'string';

    public $incrementing = false;

    public function newUniqueId()
    {
        return strtolower((string) \Illuminate\Support\Str::uuid());
    }

    protected $fillable = [
        'id',
        'batch_id',
        'zaak_identificatie',
        'notification',
        'processed',
    ];

    protected $casts = [
        'notification' => 'array',
        'processed' => 'boolean',
    ];

    /**
     * Get the batch this notification belongs to
     */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class, 'batch_id');
    }
}
