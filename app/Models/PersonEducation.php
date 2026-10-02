<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PersonEducation extends Model
{
    protected $table = 'person_educations';

    protected $fillable = [
        'person_id',
        'import_log_id',
        'school_year',
        'academic_block',
        'current_class',
        'school_code',
        'graduation_level',
        'is_complementary',
        'graduation_year',
        'vocational_grad_level',
        'vocational_grad_year',
        'finished_class',
        'finished_year',
        'dropped_class',
        'dropped_year',
        'literacy_current_class',
        'literacy_completed_class',
        'literacy_relapse_level',
        'learning_capacity',
    ];

    // ép sang boolean để sql nó biết lưu chữ nó lưu 0 1
    protected $casts = [
        'is_complementary' => 'boolean',
    ];

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class, 'school_code', 'code');
    }

    public function importLog(): BelongsTo
    {
        return $this->belongsTo(ImportLog::class);
    }
}
