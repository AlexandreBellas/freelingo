<?php

namespace App\Models;

class Memory extends BaseModel
{
    protected $table = 'memories';

    protected $fillable = [
        'user_id',
        'content',
        'source',
        'created_at',
        'study_plan_id',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];
}
