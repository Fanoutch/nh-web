<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('flights', function (Blueprint $table) {
            // Postgres bytea via Laravel's binary() type. TOAST handles large
            // payloads out-of-line so SELECTs that don't project this column
            // are not impacted. ~100-500 KB per row is typical for clean_xml.
            $table->binary('xml_blob')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('flights', function (Blueprint $table) {
            $table->dropColumn('xml_blob');
        });
    }
};
