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
        Schema::create('flights', function (Blueprint $table) {
            $table->id();
            $table->foreignId('machine_id')->constrained('machines')->cascadeOnDelete();
            $table->string('dsn');
            $table->string('num');
            $table->timestamp('start_datetime');
            $table->timestamp('end_datetime');
            $table->string('flight_type');
            $table->decimal('flight_hours', 10, 4)->default(0);
            $table->decimal('consumed_fuel', 10, 2)->nullable();
            $table->boolean('is_non_vol')->default(false);
            $table->boolean('flagged_as_error')->default(false);
            $table->timestamp('flagged_at')->nullable();
            $table->foreignId('flagged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('xml_path')->nullable();
            $table->timestamp('processed_at')->useCurrent();
            $table->timestamps();

            $table->unique(['machine_id', 'dsn', 'num']);
            $table->index(['machine_id', 'start_datetime']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('flights');
    }
};
