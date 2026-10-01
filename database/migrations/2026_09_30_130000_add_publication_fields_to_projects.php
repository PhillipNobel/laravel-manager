<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->boolean('automatic_deployment')->default(false);
            $table->string('repository_source')->default('existing');
            $table->boolean('repository_initialized')->default(true);
            $table->boolean('repository_creation_attempted')->default(false);
            $table->string('publication_status')->nullable();
            $table->string('publication_step')->nullable();
            $table->unsignedInteger('publication_version')->default(0);
            $table->text('publication_message')->nullable();
            $table->unsignedBigInteger('publication_deployment_id')->nullable();
        });
        // Existing projects keep their previous webhook behavior.
        DB::table('projects')->update(['automatic_deployment' => true]);
    }

    public function down(): void
    {
        Schema::table('projects', fn (Blueprint $table) => $table->dropColumn([
            'automatic_deployment', 'repository_source', 'repository_initialized', 'repository_creation_attempted',
            'publication_status', 'publication_step', 'publication_version', 'publication_message', 'publication_deployment_id',
        ]));
    }
};
