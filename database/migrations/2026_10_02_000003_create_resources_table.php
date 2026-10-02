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
        // The domain calls these Sources. The table and the wire format say resources.
        Schema::create('resources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('kind');
            $table->string('title');
            $table->string('url', 2048);
            $table->text('annotation');
            // Pruning is a status, never a delete, because old lessons cite these rows.
            $table->string('status')->default('active');
            $table->timestamps();

            $table->index(['workspace_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('resources');
    }
};
