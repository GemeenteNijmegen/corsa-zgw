<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ZaaktypeMapping extends Model
{
    use HasFactory, HasUlids;

    protected $fillable = [
        'catalogus_id',
        'zaaktype_url',
        'zaaktype_identificatie',
        'zaaktype_omschrijving',
        'corsa_zaaktype_code',
        'is_active',
        'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'synced_at' => 'datetime',
        ];
    }

    public function catalogus(): BelongsTo
    {
        return $this->belongsTo(Catalogus::class);
    }
}
