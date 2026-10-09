<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class School extends Model
{
    protected $fillable = [
        'code',
        'name',
        'level',
        'province_code',
        'commune_code',
    ];

    public function getDisplayNameAttribute(): string
    {
        $name = trim((string) $this->name);

        return $name !== '' ? $name : "Chưa cập nhật tên ({$this->code})";
    }

    public function province(): BelongsTo
    {
        return $this->belongsTo(Province::class, 'province_code', 'code');
    }

    public function commune(): BelongsTo
    {
        return $this->belongsTo(Commune::class, 'commune_code', 'code');
    }

    public function educations(): HasMany
    {
        return $this->hasMany(PersonEducation::class, 'school_code', 'code');
    }
}
