<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Village extends Model
{
    protected $primaryKey = 'code';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'code',
        'commune_code',
        'name',
        'intake_paused_at',
        'intake_paused_by',
        'merged_into_code',
        'merged_at',
    ];

    protected $casts = [
        'intake_paused_at' => 'datetime',
        'merged_at' => 'datetime',
    ];

    protected $appends = ['operational_status'];

    public function getOperationalStatusAttribute(): string
    {
        return $this->merged_into_code !== null || $this->intake_paused_at !== null
            ? 'paused'
            : 'active';
    }

    public function commune(): BelongsTo
    {
        return $this->belongsTo(Commune::class, 'commune_code', 'code');
    }

    public function mergedInto(): BelongsTo
    {
        return $this->belongsTo(self::class, 'merged_into_code', 'code');
    }

    public function households(): HasMany
    {
        return $this->hasMany(Household::class, 'village_code', 'code');
    }
}
