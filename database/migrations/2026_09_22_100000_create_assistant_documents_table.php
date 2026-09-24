<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assistant_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('secteur_id')->constrained('secteurs')->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete(); // déposé par
            $table->string('nom');                        // nom d'origine du fichier
            $table->string('chemin');                     // disque local (storage/app/private/...)
            $table->unsignedBigInteger('taille');
            $table->string('format', 10);
            $table->enum('statut', ['en_attente', 'en_cours', 'pret', 'erreur'])->default('en_attente');
            $table->text('message')->nullable();          // erreur lisible (affichée)
            $table->text('detail')->nullable();           // erreur technique (journal d'audit)
            $table->unsignedInteger('nb_morceaux')->nullable(); // diagnostic, non affiché
            $table->timestamp('signale_at')->nullable();
            $table->timestamps();

            $table->index(['secteur_id', 'nom']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_documents');
    }
};
