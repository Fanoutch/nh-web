<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->string('modele_slug', 100)->nullable()->after('user_id');     // agent du catalogue dont c'est la copie
            $table->string('modele_version', 64)->nullable()->after('modele_slug'); // empreinte du modèle à la dernière copie
            $table->timestamp('retire_at')->nullable();                            // retiré par le super admin : masqué, historique gardé
            $table->index('modele_slug');
        });
    }

    public function down(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->dropIndex(['modele_slug']);
            $table->dropColumn(['modele_slug', 'modele_version', 'retire_at']);
        });
    }
};
