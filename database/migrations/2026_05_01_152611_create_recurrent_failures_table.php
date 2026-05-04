<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recurrent_failures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('machine_id')->constrained('machines')->cascadeOnDelete();
            $table->string('technical_event_id');
            $table->enum('status', ['active', 'archived'])->default('active');

            $table->text('te_description')->nullable();
            $table->text('description')->nullable();
            $table->string('system_description')->nullable();
            $table->string('type_description')->nullable();
            $table->string('failure_code')->nullable();

            $table->string('active_depuis_vol')->nullable();
            $table->date('active_depuis_date')->nullable();
            $table->timestamp('first_apparition')->nullable();

            $table->unsignedTinyInteger('score')->default(1);
            $table->jsonb('details')->nullable();

            $table->timestamps();
            $table->index(['machine_id', 'status']);
        });

        DB::statement("CREATE UNIQUE INDEX recurrent_failures_active_unique
                       ON recurrent_failures (machine_id, technical_event_id)
                       WHERE status = 'active'");
    }

    public function down(): void
    {
        Schema::dropIfExists('recurrent_failures');
    }
};
