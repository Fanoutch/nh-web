<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assistant_conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete(); // propriétaire (seul à la voir)
            $table->foreignId('secteur_id')->constrained('secteurs')->restrictOnDelete();
            $table->string('titre');                      // première question, raccourcie
            $table->timestamps();                         // updated_at = dernier message (tri)

            $table->index(['user_id', 'secteur_id', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_conversations');
    }
};
