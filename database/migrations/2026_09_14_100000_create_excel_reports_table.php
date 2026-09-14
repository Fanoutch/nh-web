<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('excel_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('filename');                       // nom original du CSV déposé
            $table->enum('status', ['pending', 'processing', 'ok', 'error'])->default('pending');
            $table->string('output_path')->nullable();        // chemin absolu de l'Excel généré
            $table->string('output_name')->nullable();        // nom de fichier proposé au téléchargement
            $table->unsignedInteger('rows_written')->nullable();
            $table->text('message')->nullable();              // message d'erreur lisible du script
            $table->timestamps();

            $table->index(['status', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('excel_reports');
    }
};
