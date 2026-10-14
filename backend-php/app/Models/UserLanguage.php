<?php

namespace App\Models;

class UserLanguage extends BaseModel
{
    protected $table = 'user_languages';

    protected $fillable = [
        'user_id',
        'target_language',
        'is_active',
        'created_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'created_at' => 'datetime',
    ];
}
