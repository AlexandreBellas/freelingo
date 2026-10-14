<?php

namespace App\Models;

class Flashcard extends BaseModel
{
    protected $table = 'flashcards';

    protected $fillable = [
        'user_id',
        'word',
        'definition',
        'example_sentence',
        'translation',
        'source',
        'is_suspended',
        'ease_factor',
        'interval',
        'repetitions',
        'next_review',
        'created_at',
        'study_plan_id',
    ];

    protected $casts = [
        'is_suspended' => 'boolean',
        'ease_factor' => 'float',
        'next_review' => 'date',
        'created_at' => 'datetime',
    ];
}
