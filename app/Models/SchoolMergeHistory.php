<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SchoolMergeHistory extends Model
{
    protected $fillable = [
        'province_code',
        'commune_code',
        'source_codes',
        'source_names',
        'target_code',
        'target_name',
        'qd_sap_nhap',
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
