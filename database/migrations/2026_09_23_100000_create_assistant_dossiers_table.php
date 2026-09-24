<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assistant_dossiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('secteur_id')->constrained('secteurs')->restrictOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('assistant_dossiers')->restrictOnDelete();
            $table->string('nom', 100);
            $table->timestamps();

            $table->index(['secteur_id', 'parent_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_dossiers');
    }
};
