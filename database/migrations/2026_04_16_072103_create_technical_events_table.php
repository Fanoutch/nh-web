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
        Schema::create('technical_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flight_id')->constrained('flights')->cascadeOnDelete();
            $table->string('technical_event_id');
            $table->timestamp('raise_datetime');
            $table->enum('status', ['conservee', 'isolee']);
            $table->string('iso_week')->index();
            $table->integer('nombre_occurrences')->default(1);
            $table->jsonb('details');
            $table->enum('validation_status', ['pending', 'validated', 'rejected'])->default('pending');
            $table->foreignId('validated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('validated_at')->nullable();
            $table->text('technician_comment')->nullable();
            $table->timestamps();

            $table->index(['flight_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('technical_events');
    }
};
