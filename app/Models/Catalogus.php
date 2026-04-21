<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Catalogus extends Model
{
    use HasFactory, HasUlids;

    protected $table = 'catalogi';

    protected $fillable = [
        'url',
        'omschrijving',
        'is_active',
        'last_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'last_synced_at' => 'datetime',
        ];
    }

    public function zaaktypeMappings(): HasMany
    {
        return $this->hasMany(ZaaktypeMapping::class);
    }
}
