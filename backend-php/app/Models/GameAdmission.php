<?php

namespace App\Models;

class GameAdmission extends BaseModel
{
    protected $table = 'game_admissions';

    public $incrementing = false;
    protected $keyType = 'string';
    protected $fillable = [
        'id',
        'user_id',
        'date',
        'status',
        'deadline',
    ];

    protected $casts = [
        'date' => 'date',
        'deadline' => 'datetime',
    ];
}
