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
        Schema::create('missing_pannes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flight_id')->constrained('flights')->cascadeOnDelete();
            $table->string('failure_code');
            $table->text('description')->nullable();
            $table->text('comment')->nullable();
            $table->foreignId('reported_by')->constrained('users')->cascadeOnDelete();
            $table->timestamp('reported_at')->useCurrent();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('missing_pannes');
    }
};
