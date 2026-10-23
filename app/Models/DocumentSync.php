<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A document that was sent to the document management system, with what was sent.
 *
 * Used to recognise a document that is already there, so a notification that is delivered
 * more than once does not add the same document twice.
 *
 * @property int $id
 * @property string $document_url
 * @property string $zaak_identificatie
 * @property string $corsa_identificatie
 * @property int|null $versie
 * @property bool $placeholder
 * @property \Illuminate\Support\Carbon $synced_at
 */
class DocumentSync extends Model
{
    use HasFactory;

    protected $fillable = [
        'document_url',
        'zaak_identificatie',
        'corsa_identificatie',
        'versie',
        'placeholder',
        'synced_at',
    ];

    protected $casts = [
        'versie' => 'integer',
        'placeholder' => 'boolean',
        'synced_at' => 'datetime',
    ];
}
