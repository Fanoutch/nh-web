<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_executions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->constrained('agents')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('mode', 20);                       // conception | execution
            $table->string('etat', 20)->default('en_cours');  // en_cours | termine | arret
            $table->text('demande');
            $table->json('fichiers_apportes')->nullable();    // noms
            $table->json('fichiers_produits')->nullable();    // noms
            $table->json('etapes')->nullable();               // appels d'outils et résultats (jamais de jeton)
            $table->text('message')->nullable();              // réponse finale ou explication de l'arrêt
            $table->text('detail')->nullable();               // cause technique, pour l'administrateur
            $table->boolean('arret_demande')->default(false); // bouton « Arrêter »
            $table->timestamp('debut_at')->nullable();
            $table->timestamp('fin_at')->nullable();
            $table->timestamps();

            $table->index(['agent_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_executions');
    }
};
