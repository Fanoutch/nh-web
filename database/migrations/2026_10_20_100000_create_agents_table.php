<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete(); // propriétaire (seul à le voir)
            $table->string('nom', 100);
            $table->text('description')->nullable();
            $table->text('consignes')->nullable();          // consignes permanentes
            $table->json('recette')->nullable();            // étapes et outils validés, figés à l'enregistrement
            $table->json('outils')->nullable();             // outils autorisés
            $table->string('format_sortie', 255)->nullable();
            $table->string('etat', 20)->default('brouillon'); // brouillon | actif | en_veille
            $table->timestamp('dernier_lancement_at')->nullable(); // seul un lancement compte comme usage
            $table->timestamp('veille_prevenue_at')->nullable();   // notification « 2 mois et 3 semaines » envoyée
            $table->timestamp('en_veille_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'etat']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agents');
    }
};
