<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('excel_reports', function (Blueprint $table) {
            $table->foreignId('secteur_id')->nullable()->after('user_id')
                ->constrained('secteurs')->restrictOnDelete();
        });

        // Les dispos générées avant l'espace Secteurs appartiennent à BMN.
        $bmnId = DB::table('secteurs')->where('slug', 'bmn')->value('id');
        DB::table('excel_reports')->whereNull('secteur_id')->update(['secteur_id' => $bmnId]);

        Schema::table('excel_reports', function (Blueprint $table) {
            $table->unsignedBigInteger('secteur_id')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('excel_reports', function (Blueprint $table) {
            $table->dropConstrainedForeignId('secteur_id');
        });
    }
};
