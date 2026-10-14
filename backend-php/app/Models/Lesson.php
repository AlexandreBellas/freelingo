<?php

namespace App\Models;

class Lesson extends BaseModel
{
    protected $table = 'lessons';

    protected $fillable = [
        'study_plan_id',
        'title',
        'lesson_type',
        'cefr_level',
        'week_number',
        'day_number',
        'unit_id',
        'content',
        'is_completed',
        'completed_at',
    ];

    protected $casts = [
        'content' => 'array',
        'is_completed' => 'boolean',
        'completed_at' => 'datetime',
    ];
}
