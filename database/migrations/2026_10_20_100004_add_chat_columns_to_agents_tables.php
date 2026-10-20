<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_fichiers_reference', function (Blueprint $table) {
            $table->string('type', 10)->default('reference')->after('agent_id'); // reference | apport (document en attente de lancement)
            $table->json('lecture')->nullable()->after('role');                  // confirmation de lecture (structure, jamais de valeurs)
        });
        Schema::table('agent_executions', function (Blueprint $table) {
            $table->json('proposition')->nullable()->after('detail');            // changement proposé par l'agent
            $table->string('proposition_etat', 12)->nullable()->after('proposition'); // en_attente | acceptee | refusee
        });
    }

    public function down(): void
    {
        Schema::table('agent_executions', function (Blueprint $table) {
            $table->dropColumn(['proposition', 'proposition_etat']);
        });
        Schema::table('agent_fichiers_reference', function (Blueprint $table) {
            $table->dropColumn(['type', 'lecture']);
        });
    }
};
