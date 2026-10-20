<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletes;

class Person extends Model
{
    use SoftDeletes;

    protected $table = 'persons';

    protected $fillable = [
        'household_id',
        'import_log_id',
        'first_name',
        'last_name',
        'dob',
        'dob_str',
        'gender',
        'ethnicity_code',
        'religion',
        'priority_type',
        'relationship_with_head',
        'parent_name',
        'phone',
        'note',
    ];

    // mỗi khi laasys dữ liệu dob ra thì tự động được gán làm đối tượng Date nên có thể dùng được các method của nó
    protected $casts = [
        'dob' => 'date'
    ];

    protected static function booted(): void
    {
        static::addGlobalScope('active_household', function (Builder $query): void {
            $query->whereHas('household');
        });
    }

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    public function ethnicity(): BelongsTo
    {
        return $this->belongsTo(Ethnicity::class, 'ethnicity_code', 'code');
    }

    public function educations(): HasMany
    {
        return $this->hasMany(PersonEducation::class);
    }

    public function disability(): HasOne
    {
        return $this->hasOne(PersonDisability::class);
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
