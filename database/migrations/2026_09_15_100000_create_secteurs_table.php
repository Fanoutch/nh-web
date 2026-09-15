<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('secteurs', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();   // utilisé dans l'URL : /secteurs/{slug}
            $table->string('nom');
            $table->timestamps();
        });

        // Premier secteur : présent partout (local, tests, boulot) sans action manuelle.
        DB::table('secteurs')->insert([
            'slug' => 'bmn',
            'nom' => 'BMN',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('secteurs');
    }
};
