<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('deposits', function (Blueprint $table) {
            // Ajouter les nouveaux champs
            $table->boolean('test_mode')->default(false)->after('last_error');
            $table->string('release_reason')->nullable()->after('released_at');
            $table->string('capture_reason')->nullable()->after('captured_at');
            
            // Mettre à jour l'enum des statuts si nécessaire
            // Laravel n'a pas de vrai support pour ALTER ENUM, donc on utilise string
        });
    }

    public function down()
    {
        Schema::table('deposits', function (Blueprint $table) {
            $table->dropColumn(['test_mode', 'release_reason', 'capture_reason']);
        });
    }
};