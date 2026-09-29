<?php

use App\Enums\SslStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->string('ssl_status')->default(SslStatus::Pending->value);
            $table->timestamp('ssl_expires_at')->nullable();
            $table->text('ssl_message')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->dropColumn(['ssl_status', 'ssl_expires_at', 'ssl_message']);
        });
    }
};
