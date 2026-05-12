<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('technical_events', function (Blueprint $table) {
            $table->enum('pn_validation_status', ['pending', 'confirmed', 'rejected'])
                  ->default('pending')->after('validated_at');
            $table->foreignId('pn_validated_by')->nullable()
                  ->constrained('users')->nullOnDelete()->after('pn_validation_status');
            $table->timestamp('pn_validated_at')->nullable()->after('pn_validated_by');
        });
    }

    public function down(): void
    {
        Schema::table('technical_events', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pn_validated_by');
            $table->dropColumn(['pn_validation_status', 'pn_validated_at']);
        });
    }
};
