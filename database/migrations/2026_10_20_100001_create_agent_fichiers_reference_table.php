<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_fichiers_reference', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->constrained('agents')->cascadeOnDelete();
            $table->string('nom');
            $table->string('chemin');                  // relatif au disque « local »
            $table->unsignedBigInteger('taille');      // octets
            $table->string('role', 100)->nullable();   // ex. « stock », « liste des commandes »
            $table->timestamps();

            $table->unique(['agent_id', 'nom']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_fichiers_reference');
    }
};
