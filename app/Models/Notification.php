<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

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
        'zaak_identificatie',
        'notification',
        'processed',
    ];

    protected $casts = [
        'notification' => 'array',
        'processed' => 'boolean',
    ];
}
