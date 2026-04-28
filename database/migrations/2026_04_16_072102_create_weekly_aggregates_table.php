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
        Schema::create('weekly_aggregates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('machine_id')->constrained('machines')->cascadeOnDelete();
            $table->smallInteger('year');
            $table->string('iso_week');
            $table->integer('total_pannes')->default(0);
            $table->decimal('total_flight_hours', 10, 4)->default(0);
            $table->timestamps();

            $table->unique(['machine_id', 'iso_week']);
            $table->index(['machine_id', 'year']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('weekly_aggregates');
    }
};
