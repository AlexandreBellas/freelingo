<?php

namespace App\Models;

class GameRequest extends BaseModel
{
    protected $table = 'game_requests';

    public $incrementing = false;
    protected $keyType = 'string';
    protected $fillable = [
        'id',
        'user_id',
        'session_id',
        'study_plan_id',
        'mode',
        'game_type',
    ];

    protected $casts = [];
}
