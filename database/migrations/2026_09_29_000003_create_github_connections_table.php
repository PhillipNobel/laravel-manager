<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('github_connections', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('github_user_id')->unique();
            $table->string('login');
            $table->string('name')->nullable();
            $table->text('avatar_url')->nullable();
            $table->text('scopes')->nullable();
            $table->longText('access_token');
            $table->timestamp('connected_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('github_connections');
    }
};
