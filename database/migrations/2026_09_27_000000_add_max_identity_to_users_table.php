<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable()->change();
            $table->string('password')->nullable()->change();
            $table->string('max_user_id')->nullable()->unique();
            $table->string('role')->default('resident');
        });
    }

    public function down(): void
    {
        DB::table('users')->whereNotNull('max_user_id')->delete();

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['max_user_id']);
            $table->dropColumn(['max_user_id', 'role']);
            $table->string('email')->nullable(false)->change();
            $table->string('password')->nullable(false)->change();
        });
    }
};
