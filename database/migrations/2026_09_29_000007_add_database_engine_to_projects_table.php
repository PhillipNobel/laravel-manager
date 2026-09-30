<?php

use App\Enums\DatabaseEngine;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->string('database_engine')->default(DatabaseEngine::MySql->value);
        });

        DB::table('projects')
            ->whereNull('php_version')
            ->update(['php_version' => '8.3']);
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('database_engine');
        });
    }
};
