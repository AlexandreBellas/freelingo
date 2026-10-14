<?php

namespace App\Models;

class User extends BaseModel
{
    protected $table = 'users';

    protected $hidden = [
        'hashed_password',
    ];

    protected $fillable = [
        'username',
        'email',
        'display_name',
        'hashed_password',
        'role',
        'native_language',
        'target_language',
        'ui_locale',
        'flashcard_back_content',
        'is_active',
        'is_verified',
        'conversation_max_duration',
        'conversation_inactivity_timeout',
        'conversation_speech_pause',
        'conversation_weekly_sessions',
        'conversation_daily_minutes',
        'conversation_weekly_minutes',
        'monthly_tokens_limit',
        'stripe_customer_id',
        'stripe_subscription_id',
        'subscription_status',
        'subscription_ends_at',
        'cancel_at_period_end',
        'trial_used',
        'assessment_voice_trial_used',
        'freemium_trial_ends_at',
        'freemium_trial_used',
        'avatar',
        'bio',
        'learning_goals',
        'dismissed_dashboard_banner_revision',
        'created_at',
        'last_login',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_verified' => 'boolean',
        'cancel_at_period_end' => 'boolean',
        'trial_used' => 'boolean',
        'assessment_voice_trial_used' => 'boolean',
        'freemium_trial_used' => 'boolean',
        'learning_goals' => 'array',
        'subscription_ends_at' => 'datetime',
        'freemium_trial_ends_at' => 'datetime',
        'created_at' => 'datetime',
        'last_login' => 'datetime',
    ];
}
