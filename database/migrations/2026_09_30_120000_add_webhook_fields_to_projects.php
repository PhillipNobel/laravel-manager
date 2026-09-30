<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->unsignedBigInteger('webhook_id')->nullable();
            $table->string('webhook_url')->nullable();
            $table->string('webhook_status')->default('pending');
            $table->string('webhook_message')->nullable();
            $table->timestamp('webhook_checked_at')->nullable();
            $table->timestamp('webhook_verified_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('projects', fn (Blueprint $table) => $table->dropColumn([
            'webhook_id', 'webhook_url', 'webhook_status', 'webhook_message',
            'webhook_checked_at', 'webhook_verified_at',
        ]));
    }
};
