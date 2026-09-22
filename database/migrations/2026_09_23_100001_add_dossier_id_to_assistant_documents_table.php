<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assistant_documents', function (Blueprint $table) {
            $table->foreignId('dossier_id')->nullable()->after('secteur_id')
                ->constrained('assistant_dossiers')->restrictOnDelete();
            $table->index(['secteur_id', 'dossier_id']);
        });
    }

    public function down(): void
    {
        Schema::table('assistant_documents', function (Blueprint $table) {
            $table->dropIndex(['secteur_id', 'dossier_id']);
            $table->dropConstrainedForeignId('dossier_id');
        });
    }
};
