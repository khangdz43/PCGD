<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VillageMergeHistory extends Model
{
    protected $fillable = [
        'commune_code',
        'source_codes',
        'source_names',
        'target_code',
        'target_name',
        'reason',
        'merged_by',
    ];

    protected function casts(): array
    {
        return [
            'source_codes' => 'array',
            'source_names' => 'array',
        ];
    }

    public function mergedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'merged_by');
    }
}
