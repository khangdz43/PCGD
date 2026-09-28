<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PersonDisability extends Model
{
    protected $fillable = [
        'person_id',
        'mobility_disability',
        'hearing_speech_disability',
        'visual_disability',
        'mental_disability',
        'intellectual_disability',
        'learning_disability',
        'autism',
        'other_disability',
        'has_disability_cert',
        'can_study',
        'special_circumstance',
        'special_circumstance_detail',
    ];

    protected $casts = [
        'mobility_disability' => 'boolean',
        'hearing_speech_disability' => 'boolean',
        'visual_disability' => 'boolean',
        'mental_disability' => 'boolean',
        'intellectual_disability' => 'boolean',
        'learning_disability' => 'boolean',
        'autism' => 'boolean',
        'other_disability' => 'boolean',
        'has_disability_cert' => 'boolean',
        'can_study' => 'boolean',
    ];

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }
}