<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('house_memberships', function (Blueprint $table): void {
            $table->boolean('notifications_enabled')->default(false);
        });
        Schema::create('max_notifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('incident_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('membership_id');
            $table->string('token', 32)->unique();
            $table->string('kind', 16);
            $table->unsignedInteger('round');
            $table->string('expected_status', 32);
            $table->string('event_key', 64)->nullable();
            $table->unsignedSmallInteger('entrance')->nullable();
            $table->unsignedSmallInteger('floor')->nullable();
            $table->string('status', 16)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('last_error', 32)->nullable();
            $table->timestamp('available_at');
            $table->timestamp('expires_at');
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'available_at']);
            $table->index(['incident_id', 'user_id', 'kind', 'round']);
        });
        Schema::create('max_callback_receipts', function (Blueprint $table): void {
            $table->string('id', 64)->primary();
            $table->string('result', 32);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('max_callback_receipts');
        Schema::dropIfExists('max_notifications');
        Schema::table('house_memberships', fn (Blueprint $table) => $table->dropColumn('notifications_enabled'));
    }
};
