<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_rappels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->unique()->constrained('agents')->cascadeOnDelete();
            $table->string('periodicite', 20);                 // hebdomadaire | mensuel | trimestriel
            $table->date('prochaine_echeance');
            $table->timestamp('notifie_at')->nullable();       // l'agent est remonté dans « À faire »
            $table->timestamps();

            $table->index('prochaine_echeance');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_rappels');
    }
};
