<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('secteur_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('secteur_id')->constrained('secteurs')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->enum('role', ['chef', 'utilisateur']);
            $table->timestamps();

            $table->unique(['secteur_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('secteur_user');
    }
};
