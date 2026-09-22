<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assistant_pieces_jointes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('assistant_conversations')->cascadeOnDelete();
            $table->string('nom');
            $table->string('chemin');
            $table->unsignedBigInteger('taille');
            $table->string('format', 10);
            $table->enum('statut', ['en_attente', 'en_cours', 'pret', 'erreur'])->default('en_attente');
            $table->text('message')->nullable();
            $table->text('detail')->nullable();
            $table->unsignedInteger('nb_morceaux')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_pieces_jointes');
    }
};
