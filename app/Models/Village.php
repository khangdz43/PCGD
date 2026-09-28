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
        'name'
    ];
    public function commune(): BelongsTo
    {
        return $this->belongsTo(Commune::class, 'commune_code', 'code');
    }

    public function households(): HasMany
    {
        return $this->hasMany(Household::class, 'village_code', 'code');
    }
}
