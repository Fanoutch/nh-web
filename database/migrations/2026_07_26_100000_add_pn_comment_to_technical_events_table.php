<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('technical_events', function (Blueprint $table) {
            $table->text('pn_comment')->nullable()->after('pn_validated_at');
        });
    }

    public function down(): void
    {
        Schema::table('technical_events', function (Blueprint $table) {
            $table->dropColumn('pn_comment');
        });
    }
};
