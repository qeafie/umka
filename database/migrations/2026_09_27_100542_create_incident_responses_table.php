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
        Schema::create('incident_responses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('incident_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('stage', 20);
            $table->unsignedSmallInteger('round')->default(0);
            $table->string('answer', 32);
            $table->timestamps();
            $table->unique(['incident_id', 'user_id', 'stage', 'round']);
            $table->index(['incident_id', 'stage', 'round', 'answer']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('incident_responses');
    }
};
