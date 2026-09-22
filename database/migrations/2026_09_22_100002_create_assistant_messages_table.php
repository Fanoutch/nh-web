<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assistant_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('assistant_conversations')->cascadeOnDelete();
            $table->enum('role', ['utilisateur', 'assistant']);
            $table->text('contenu');
            $table->json('sources')->nullable();          // [{document_id, nom, page}]
            $table->enum('statut', ['complet', 'erreur', 'interrompu'])->default('complet');
            $table->text('detail')->nullable();           // erreur technique (journal d'audit)
            $table->timestamp('signale_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_messages');
    }
};
