<?php

namespace App\Models;

class DashboardBanner extends BaseModel
{
    protected $table = 'dashboard_banners';

    public $incrementing = false;
    protected $fillable = [
        'id',
        'translations',
        'source_locale',
        'is_active',
        'revision',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'translations' => 'array',
        'is_active' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
