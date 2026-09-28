<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Commune extends Model
{
    protected $primaryKey = 'code';
    public $incrementing = false;
    protected $keyType = 'string';
    public function province(): BelongsTo
    {
        return $this->belongsTo(Province::class, 'province_code', 'code');
    }
    public function villages(): HasMany
    {
        return $this->hasMany(Village::class, 'commune_code', 'code');
    }

    public function households(): HasMany
    {
        return $this->hasMany(Household::class, 'commune_code', 'code');
    }

    public function schools(): HasMany
    {
        return $this->hasMany(School::class, 'commune_code', 'code');
    }
}
