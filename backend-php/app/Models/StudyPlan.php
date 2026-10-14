<?php

namespace App\Models;

class StudyPlan extends BaseModel
{
    protected $table = 'study_plans';

    protected $fillable = [
        'user_id',
        'user_language_id',
        'cefr_level',
        'target_language',
        'goals',
        'duration_weeks',
        'days_per_week',
        'current_unit',
        'progress_day',
        'generated_plan',
        'is_active',
        'completion_test_taken',
        'completion_test_score',
        'completion_test_recommendation',
        'created_at',
    ];

    protected $casts = [
        'goals' => 'array',
        'generated_plan' => 'array',
        'is_active' => 'boolean',
        'completion_test_taken' => 'boolean',
        'completion_test_score' => 'float',
        'created_at' => 'datetime',
    ];
}
