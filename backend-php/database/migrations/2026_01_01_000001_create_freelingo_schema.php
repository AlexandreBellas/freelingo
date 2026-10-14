<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('username', 50)->unique();
            $table->string('email', 255)->nullable()->unique();
            $table->string('display_name', 100);
            $table->string('hashed_password', 255);
            $table->string('role', 10)->default('user');
            $table->string('native_language', 10);
            $table->string('target_language', 10)->default('en-GB');
            $table->string('ui_locale', 10)->nullable();
            $table->string('flashcard_back_content', 20)->default('definition');
            $table->boolean('is_active')->default(true);
            $table->boolean('is_verified')->default(false);
            $table->integer('conversation_max_duration')->default(1800);
            $table->integer('conversation_inactivity_timeout')->default(180);
            $table->integer('conversation_speech_pause')->default(0);
            $table->integer('conversation_weekly_sessions')->default(0);
            $table->integer('conversation_daily_minutes')->default(30);
            $table->integer('conversation_weekly_minutes')->default(90);
            $table->integer('monthly_tokens_limit')->default(1000000);
            $table->string('stripe_customer_id', 255)->nullable();
            $table->string('stripe_subscription_id', 255)->nullable();
            $table->string('subscription_status', 20)->default('none');
            $table->dateTime('subscription_ends_at')->nullable();
            $table->boolean('cancel_at_period_end')->default(false);
            $table->boolean('trial_used')->default(false);
            $table->boolean('assessment_voice_trial_used')->default(false);
            $table->dateTime('freemium_trial_ends_at')->nullable();
            $table->boolean('freemium_trial_used')->default(false);
            $table->text('avatar')->nullable();
            $table->text('bio')->nullable();
            $table->text('learning_goals')->nullable();
            $table->integer('dismissed_dashboard_banner_revision')->nullable();
            $table->dateTime('created_at');
            $table->dateTime('last_login')->nullable();
        });

        Schema::create('user_languages', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('user_id');
            $table->string('target_language', 10);
            $table->boolean('is_active')->default(true);
            $table->dateTime('created_at');
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->unique(['user_id', 'target_language'], 'uq_user_language');
            $table->index(['user_id', 'is_active'], 'ix_user_language_user_active');
        });

        Schema::create('study_plans', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('user_id');
            $table->unsignedInteger('user_language_id');
            $table->string('cefr_level', 10);
            $table->string('target_language', 10)->default('en-GB');
            $table->json('goals');
            $table->integer('duration_weeks')->default(12);
            $table->integer('days_per_week')->default(4);
            $table->string('current_unit', 50)->default('');
            $table->integer('progress_day')->default(0);
            $table->json('generated_plan');
            $table->boolean('is_active')->default(true);
            $table->boolean('completion_test_taken')->default(false);
            $table->float('completion_test_score')->nullable();
            $table->string('completion_test_recommendation', 20)->nullable();
            $table->dateTime('created_at');
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('user_language_id')->references('id')->on('user_languages')->cascadeOnDelete();
            $table->index('user_id');
            $table->index('user_language_id');
        });

        Schema::create('lessons', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('study_plan_id');
            $table->string('title', 255);
            $table->string('lesson_type', 50);
            $table->string('cefr_level', 10);
            $table->integer('week_number');
            $table->integer('day_number');
            $table->string('unit_id', 50)->nullable();
            $table->json('content');
            $table->boolean('is_completed')->default(false);
            $table->dateTime('completed_at')->nullable();
            $table->foreign('study_plan_id')->references('id')->on('study_plans')->cascadeOnDelete();
            $table->index('study_plan_id');
            $table->index('unit_id');
            $table->unique(['study_plan_id', 'week_number', 'day_number', 'title'], 'uq_lessons_plan_week_day_title');
        });

        Schema::create('exercises', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('lesson_id');
            $table->string('exercise_type', 50);
            $table->text('question');
            $table->json('options')->nullable();
            $table->text('correct_answer');
            $table->text('user_answer')->nullable();
            $table->float('score')->nullable();
            $table->text('feedback')->nullable();
            $table->json('corrections')->nullable();
            $table->text('explanation')->nullable();
            $table->dateTime('answered_at')->nullable();
            $table->foreign('lesson_id')->references('id')->on('lessons')->cascadeOnDelete();
            $table->index('lesson_id');
        });

        Schema::create('flashcards', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('user_id');
            $table->string('word', 100);
            $table->text('definition');
            $table->text('example_sentence');
            $table->string('translation', 255);
            $table->string('source', 20)->nullable();
            $table->boolean('is_suspended')->default(false);
            $table->float('ease_factor')->default(2.5);
            $table->integer('interval')->default(0);
            $table->integer('repetitions')->default(0);
            $table->date('next_review');
            $table->dateTime('created_at');
            $table->unsignedInteger('study_plan_id');
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('study_plan_id')->references('id')->on('study_plans')->cascadeOnDelete();
            $table->index('user_id');
            $table->index('study_plan_id');
        });

        Schema::create('flashcard_review_receipts', function (Blueprint $table) {
            $table->unsignedInteger('flashcard_id');
            $table->string('review_id', 36);
            $table->integer('quality');
            $table->json('response');
            $table->primary(['flashcard_id', 'review_id']);
            $table->foreign('flashcard_id')->references('id')->on('flashcards')->cascadeOnDelete();
        });

        Schema::create('progress', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('user_id');
            $table->date('date');
            $table->integer('xp_earned')->default(0);
            $table->integer('lessons_completed')->default(0);
            $table->integer('exercises_correct')->default(0);
            $table->integer('exercises_total')->default(0);
            $table->integer('streak_day')->default(1);
            $table->json('skills');
            $table->json('skill_updates')->nullable();
            $table->unsignedInteger('study_plan_id');
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('study_plan_id')->references('id')->on('study_plans')->cascadeOnDelete();
            $table->unique(['user_id', 'study_plan_id', 'date'], 'uq_progress_user_plan_date');
            $table->index('user_id');
            $table->index('study_plan_id');
        });

        Schema::create('progress_rewards', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('user_id');
            $table->unsignedInteger('study_plan_id');
            $table->string('kind', 30);
            $table->string('source_key', 160);
            $table->date('date');
            $table->integer('xp');
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('study_plan_id')->references('id')->on('study_plans')->cascadeOnDelete();
            $table->unique(['study_plan_id', 'kind', 'source_key'], 'uq_progress_reward_source');
            $table->index('user_id');
            $table->index('study_plan_id');
            $table->index('date');
        });

        Schema::create('user_competencies', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('user_id');
            $table->string('unit_id', 50);
            $table->string('competency_text', 300);
            $table->float('score')->default(0);
            $table->boolean('mastered')->default(false);
            $table->dateTime('updated_at');
            $table->unsignedInteger('study_plan_id');
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('study_plan_id')->references('id')->on('study_plans')->cascadeOnDelete();
            $table->unique(['user_id', 'study_plan_id', 'unit_id', 'competency_text'], 'uq_competency_user_plan_unit_text');
            $table->index('user_id');
            $table->index('unit_id');
            $table->index('study_plan_id');
        });

        Schema::create('conversations', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('user_id');
            $table->string('title', 200)->default('New conversation');
            $table->string('source', 10)->default('chat');
            $table->dateTime('created_at');
            $table->dateTime('updated_at');
            $table->unsignedInteger('study_plan_id')->nullable();
            $table->string('target_language', 10)->nullable();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('study_plan_id')->references('id')->on('study_plans')->nullOnDelete();
            $table->index('user_id');
            $table->index('study_plan_id');
            $table->index('target_language');
        });

        Schema::create('chat_history', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('user_id');
            $table->unsignedInteger('conversation_id')->nullable();
            $table->string('role', 20);
            $table->text('content');
            $table->dateTime('created_at');
            $table->unsignedInteger('study_plan_id')->nullable();
            $table->string('target_language', 10)->nullable();
            $table->string('modality', 10)->nullable();
            $table->unsignedInteger('reply_to_id')->nullable()->unique();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('conversation_id')->references('id')->on('conversations')->cascadeOnDelete();
            $table->foreign('study_plan_id')->references('id')->on('study_plans')->nullOnDelete();
            $table->foreign('reply_to_id')->references('id')->on('chat_history')->nullOnDelete();
            $table->index('user_id');
            $table->index('conversation_id');
            $table->index('study_plan_id');
            $table->index('target_language');
        });

        Schema::create('memories', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('user_id');
            $table->text('content');
            $table->string('source', 10)->default('chat');
            $table->dateTime('created_at');
            $table->unsignedInteger('study_plan_id')->nullable();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('study_plan_id')->references('id')->on('study_plans')->nullOnDelete();
            $table->unique(['user_id', 'content'], 'uq_memories_user_content');
            $table->index('user_id');
            $table->index('study_plan_id');
        });

        Schema::create('llm_usage', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('user_id');
            $table->string('source', 20);
            $table->integer('prompt_tokens')->nullable();
            $table->integer('completion_tokens')->nullable();
            $table->integer('total_tokens')->nullable();
            $table->dateTime('created_at');
            $table->unsignedInteger('study_plan_id')->nullable();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('study_plan_id')->references('id')->on('study_plans')->nullOnDelete();
            $table->index('user_id');
            $table->index('source');
            $table->index('study_plan_id');
        });

        Schema::create('listening_exercises', function (Blueprint $table) {
            $table->increments('id');
            $table->string('level', 2);
            $table->string('target_language', 10);
            $table->string('exercise_type', 20);
            $table->string('topic', 200);
            $table->text('text');
            $table->string('audio_path', 500);
            $table->integer('duration_seconds')->default(0);
            $table->json('questions');
            $table->integer('play_count')->default(0);
            $table->dateTime('created_at');
            $table->index(['level', 'target_language'], 'ix_listening_exercises_level_lang');
            $table->index('level');
            $table->index('target_language');
        });

        Schema::create('listening_attempts', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('user_id');
            $table->unsignedInteger('exercise_id');
            $table->unsignedInteger('study_plan_id');
            $table->json('answers');
            $table->integer('score');
            $table->integer('xp_earned');
            $table->dateTime('completed_at');
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('exercise_id')->references('id')->on('listening_exercises')->cascadeOnDelete();
            $table->foreign('study_plan_id')->references('id')->on('study_plans')->cascadeOnDelete();
            $table->index('user_id');
            $table->index('exercise_id');
            $table->index('study_plan_id');
        });

        Schema::create('reading_exercises', function (Blueprint $table) {
            $table->increments('id');
            $table->string('level', 2);
            $table->string('target_language', 10);
            $table->string('exercise_type', 20);
            $table->string('topic', 200);
            $table->text('text');
            $table->json('questions');
            $table->integer('view_count')->default(0);
            $table->dateTime('created_at');
            $table->index(['level', 'target_language'], 'ix_reading_exercises_level_lang');
            $table->index('level');
            $table->index('target_language');
        });

        Schema::create('reading_attempts', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('user_id');
            $table->unsignedInteger('exercise_id');
            $table->unsignedInteger('study_plan_id');
            $table->json('answers');
            $table->integer('score');
            $table->integer('xp_earned');
            $table->dateTime('completed_at');
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('exercise_id')->references('id')->on('reading_exercises')->cascadeOnDelete();
            $table->foreign('study_plan_id')->references('id')->on('study_plans')->cascadeOnDelete();
            $table->index('user_id');
            $table->index('exercise_id');
            $table->index('study_plan_id');
        });

        Schema::create('game_sessions', function (Blueprint $table) {
            $table->string('id', 36)->primary();
            $table->unsignedInteger('user_id');
            $table->unsignedInteger('study_plan_id');
            $table->string('mode', 16);
            $table->string('game_type', 24)->default('detective');
            $table->string('target_language', 10);
            $table->string('native_language', 10);
            $table->string('level', 10);
            $table->string('status', 16)->default('generating');
            $table->json('context');
            $table->json('challenges');
            $table->json('answers');
            $table->dateTime('created_at');
            $table->dateTime('deadline');
            $table->dateTime('completed_at')->nullable();
            $table->integer('xp_earned')->default(0);
            $table->string('error', 32)->nullable();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('study_plan_id')->references('id')->on('study_plans')->cascadeOnDelete();
            $table->index('user_id');
            $table->index('study_plan_id');
        });

        Schema::create('game_requests', function (Blueprint $table) {
            $table->string('id', 36)->primary();
            $table->unsignedInteger('user_id');
            $table->string('session_id', 36)->nullable();
            $table->integer('study_plan_id');
            $table->string('mode', 16);
            $table->string('game_type', 24)->default('detective');
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('session_id')->references('id')->on('game_sessions')->nullOnDelete();
            $table->index('user_id');
            $table->index('session_id');
        });

        Schema::create('game_admissions', function (Blueprint $table) {
            $table->string('id', 36)->primary();
            $table->unsignedInteger('user_id');
            $table->date('date');
            $table->string('status', 16);
            $table->dateTime('deadline');
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->index(['user_id', 'date'], 'ix_game_admission_user_day');
        });

        Schema::create('feedback_entries', function (Blueprint $table) {
            $table->increments('id');
            $table->string('type', 10);
            $table->string('title', 200);
            $table->text('description');
            $table->string('status', 20)->default('pending');
            $table->unsignedInteger('author_id');
            $table->integer('vote_count')->default(0);
            $table->dateTime('created_at');
            $table->foreign('author_id')->references('id')->on('users')->cascadeOnDelete();
            $table->index('type');
            $table->index('status');
            $table->index('author_id');
            $table->index('created_at');
        });

        Schema::create('feedback_votes', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('entry_id');
            $table->unsignedInteger('user_id');
            $table->dateTime('created_at');
            $table->foreign('entry_id')->references('id')->on('feedback_entries')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->unique(['entry_id', 'user_id'], 'uq_feedback_vote');
            $table->index('entry_id');
            $table->index('user_id');
        });

        Schema::create('feedback_comments', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('entry_id');
            $table->unsignedInteger('author_id');
            $table->text('body');
            $table->dateTime('created_at');
            $table->foreign('entry_id')->references('id')->on('feedback_entries')->cascadeOnDelete();
            $table->foreign('author_id')->references('id')->on('users')->cascadeOnDelete();
            $table->index('entry_id');
            $table->index('author_id');
        });

        Schema::create('feedback_read_states', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('entry_id');
            $table->unsignedInteger('user_id');
            $table->dateTime('last_read_at');
            $table->foreign('entry_id')->references('id')->on('feedback_entries')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->unique(['entry_id', 'user_id'], 'uq_feedback_read_state');
            $table->index('entry_id');
            $table->index('user_id');
        });

        Schema::create('reviews', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('user_id')->unique();
            $table->string('user_display_name', 150);
            $table->string('target_language', 10);
            $table->integer('rating');
            $table->text('comment')->nullable();
            $table->boolean('is_approved')->default(false);
            $table->dateTime('created_at');
            $table->dateTime('updated_at');
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->index('target_language');
            $table->index('is_approved');
            $table->index('created_at');
        });

        Schema::create('dashboard_banners', function (Blueprint $table) {
            $table->integer('id')->primary();
            $table->json('translations');
            $table->string('source_locale', 2);
            $table->boolean('is_active')->default(false);
            $table->integer('revision')->default(1);
            $table->dateTime('created_at');
            $table->dateTime('updated_at');
        });

        Schema::create('resource_native_helps', function (Blueprint $table) {
            $table->increments('id');
            $table->string('resource_type', 30);
            $table->string('resource_key', 200);
            $table->string('target_language', 10);
            $table->string('native_language', 10);
            $table->string('source_hash', 64);
            $table->json('content');
            $table->dateTime('created_at');
            $table->dateTime('updated_at');
            $table->unique(['resource_type', 'resource_key', 'target_language', 'native_language'], 'uq_resource_native_helps_resource_lang');
            $table->index(['resource_type', 'target_language'], 'ix_resource_native_helps_lookup');
            $table->index('resource_type');
            $table->index('resource_key');
            $table->index('target_language');
            $table->index('native_language');
        });

        $driver = DB::getDriverName();
        if ($driver === 'sqlite') {
            DB::statement('CREATE UNIQUE INDEX uq_active_plan_per_lang ON study_plans (user_language_id) WHERE is_active = 1');
            DB::statement("CREATE UNIQUE INDEX uq_game_active_plan_type ON game_sessions (study_plan_id, game_type) WHERE status IN ('generating', 'ready')");
        } else {
            DB::statement('CREATE UNIQUE INDEX uq_active_plan_per_lang ON study_plans (user_language_id) WHERE is_active = true');
            DB::statement("CREATE UNIQUE INDEX uq_game_active_plan_type ON game_sessions (study_plan_id, game_type) WHERE status IN ('generating', 'ready')");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('resource_native_helps');
        Schema::dropIfExists('dashboard_banners');
        Schema::dropIfExists('reviews');
        Schema::dropIfExists('feedback_read_states');
        Schema::dropIfExists('feedback_comments');
        Schema::dropIfExists('feedback_votes');
        Schema::dropIfExists('feedback_entries');
        Schema::dropIfExists('game_admissions');
        Schema::dropIfExists('game_requests');
        Schema::dropIfExists('game_sessions');
        Schema::dropIfExists('reading_attempts');
        Schema::dropIfExists('reading_exercises');
        Schema::dropIfExists('listening_attempts');
        Schema::dropIfExists('listening_exercises');
        Schema::dropIfExists('llm_usage');
        Schema::dropIfExists('memories');
        Schema::dropIfExists('chat_history');
        Schema::dropIfExists('conversations');
        Schema::dropIfExists('user_competencies');
        Schema::dropIfExists('progress_rewards');
        Schema::dropIfExists('progress');
        Schema::dropIfExists('flashcard_review_receipts');
        Schema::dropIfExists('flashcards');
        Schema::dropIfExists('exercises');
        Schema::dropIfExists('lessons');
        Schema::dropIfExists('study_plans');
        Schema::dropIfExists('user_languages');
        Schema::dropIfExists('users');
    }
};
