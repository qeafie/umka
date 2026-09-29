<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('houses', function (Blueprint $table): void {
            $table->json('layout')->nullable();
        });
        Schema::table('house_memberships', function (Blueprint $table): void {
            $table->unsignedSmallInteger('entrance')->nullable();
            $table->unsignedSmallInteger('floor')->nullable();
        });
        Schema::table('incident_responses', function (Blueprint $table): void {
            $table->unsignedSmallInteger('entrance')->nullable();
            $table->unsignedSmallInteger('floor')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('incident_responses', function (Blueprint $table): void {
            $table->dropColumn(['entrance', 'floor']);
        });
        Schema::table('house_memberships', function (Blueprint $table): void {
            $table->dropColumn(['entrance', 'floor']);
        });
        Schema::table('houses', function (Blueprint $table): void {
            $table->dropColumn('layout');
        });
    }
};
