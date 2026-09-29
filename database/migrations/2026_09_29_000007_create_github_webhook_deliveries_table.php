<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('github_webhook_deliveries', function (Blueprint $table) {
            $table->id();
            $table->string('delivery_id')->unique();
            $table->string('event', 50);
            $table->string('repository_name')->nullable();
            $table->text('ref')->nullable();
            $table->string('status', 20);
            $table->text('message')->nullable();
            $table->unsignedInteger('deployments_queued')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('github_webhook_deliveries');
    }
};
