<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Ethnicity extends Model
{
    protected $primaryKey = 'code';
    public $incrementing = false;
    protected $keyType = 'string';

    public function persons(): HasMany
    {
        return $this->hasMany(Person::class, 'ethnicity_code', 'code');
    }
}
