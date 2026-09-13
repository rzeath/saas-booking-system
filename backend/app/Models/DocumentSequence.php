<?php

namespace App\Models;

use App\Enums\DocumentType;
use Database\Factories\DocumentSequenceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentSequence extends Model
{
    /** @use HasFactory<DocumentSequenceFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'document_type' => DocumentType::class,
            'year' => 'integer',
            'next_number' => 'integer',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
