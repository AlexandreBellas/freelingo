<?php

namespace App\Models;

class Conversation extends BaseModel
{
    protected $table = 'conversations';

    protected $fillable = [
        'user_id',
        'title',
        'source',
        'created_at',
        'updated_at',
        'study_plan_id',
        'target_language',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
