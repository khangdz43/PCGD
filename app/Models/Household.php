<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Household extends Model
{
    use SoftDeletes;

    protected $appends = ['school_year'];

    protected $hidden = ['schoolYearRecord'];

    protected $fillable = [
        'household_code',
        'school_year',
        'school_year_id',
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

    public function getSchoolYearAttribute(): ?string
    {
        return $this->getRelationValue('schoolYearRecord')?->label();
    }

    public function setSchoolYearAttribute(?string $value): void
    {
        if ($value === null) {
            $this->attributes['school_year_id'] = null;
            $this->unsetRelation('schoolYearRecord');

            return;
        }

        $schoolYear = SchoolYear::resolveOrCreateFromLabel($value);
        $this->attributes['school_year_id'] = $schoolYear->id;
        $this->setRelation('schoolYearRecord', $schoolYear);
    }

    public function schoolYearRecord(): BelongsTo
    {
        return $this->belongsTo(SchoolYear::class, 'school_year_id');
    }

    public function province(): BelongsTo
    {
        return $this->belongsTo(Province::class, 'province_code', 'code');
    }

    public function commune(): BelongsTo
    {
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

    public function deletedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by');
    }
}
