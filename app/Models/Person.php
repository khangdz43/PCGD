<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Person extends Model
{
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
}
