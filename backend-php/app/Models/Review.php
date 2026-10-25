<?php

namespace App\Models;

class Review extends BaseModel
{
    protected $table = 'reviews';

    protected $fillable = [
        'user_id',
        'user_display_name',
        'target_language',
        'rating',
        'comment',
        'is_approved',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'is_approved' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
