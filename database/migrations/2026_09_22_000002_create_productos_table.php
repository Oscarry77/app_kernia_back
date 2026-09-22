<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Fase 1 de orquestación multi-app (ver GUIA_INTEGRACION_APP_KERNIA_v1.md §2).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('productos', function (Blueprint $table) {
            $table->id();
            // comercializa | hrm | svi | tesoreria (Tesorería reservado; hoy
            // vive como módulo de Comercializa, ver guía §3.1)
            $table->string('slug', 60)->unique();
            $table->string('nombre');
            $table->string('base_url_interna');
            // dedicada | compartida
            $table->string('modo_datos', 20);
            $table->text('token_interno')->nullable();
            $table->string('prefijo_db', 20)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('productos');
    }
};
