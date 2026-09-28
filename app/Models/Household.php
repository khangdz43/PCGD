<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Household extends Model
{
    protected $fillable = [
        'household_code',
        'head_first_name',
        'head_last_name',
        'province_code',
        'commune_code',
        'village_code',
        'address',
        'residence_type',
        'residence_status',
        'import_log_id'
    ];
    public function province(): BelongsTo{
        return $this->belongsTo(Province::class, 'province_code', 'code');
    }

    public function commune():BelongsTo{
        return $this->belongsTo(Commune::class, 'commune_code', 'code');
    }

    public function village(): BelongsTo
    {
        return $this->belongsTo(Village::class, 'village_code', 'code');
    }

    public function persons(): HasMany
    {
        return $this->hasMany(Person::class);
    }

    public function importLog(): BelongsTo
    {
        return $this->belongsTo(ImportLog::class);
    }




}
