<?php

use App\Enums\ProjectStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('domain')->unique();
            $table->string('path')->nullable();
            $table->string('repository_url')->nullable();
            $table->string('repository_name')->nullable();
            $table->string('branch')->default('main');
            $table->string('php_version')->nullable();
            $table->string('database_name')->nullable();
            $table->string('database_username')->nullable();
            $table->string('status')->default(ProjectStatus::Pending->value);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
