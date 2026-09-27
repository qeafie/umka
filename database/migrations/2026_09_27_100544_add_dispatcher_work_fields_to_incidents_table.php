<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('incidents', function (Blueprint $table): void {
            $table->string('assigned_to', 120)->nullable();
            $table->string('next_action', 500)->nullable();
            $table->timestamp('next_update_at')->nullable();
            $table->timestamp('work_completed_at')->nullable();
            $table->unsignedSmallInteger('recovery_round')->default(0);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('incidents', function (Blueprint $table): void {
            $table->dropColumn([
                'assigned_to',
                'next_action',
                'next_update_at',
                'work_completed_at',
                'recovery_round',
            ]);
        });
    }
};
