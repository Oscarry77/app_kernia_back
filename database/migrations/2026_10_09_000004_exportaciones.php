<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * (09-oct-2026) Orquestación de la exportación v2.3 desde Kernia (estándar
 * v2.3 §4.2 y §7; REGISTRO_DECISIONES_KERNIA.md §3, 05-oct). Kernia solo
 * guarda METADATOS (estado, tamaño, huella, conteos); el archivo vive en la
 * app y su contraseña en la bóveda (`respaldo.{id}`).
 * Aditiva.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exportaciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('suscripcion_id')->constrained('suscripciones')->cascadeOnDelete();
            $table->foreignId('solicitud_salida_id')->nullable()->constrained('solicitudes_salida')->nullOnDelete();
            $table->string('motivo', 12);                    // finiquito | copia | archivo
            $table->string('alcance', 10);                   // cliente | empresa
            $table->json('empresas')->nullable();            // ids de la app, solo con alcance empresa
            $table->unsignedTinyInteger('intento')->default(1);
            $table->string('exportacion_app', 100)->nullable(); // id que devuelve la app
            $table->string('estado', 12)->default('pendiente'); // pendiente | processing | ready | failed
            $table->unsignedBigInteger('tamano_bytes')->nullable();
            $table->char('sha256', 64)->nullable();
            $table->json('conteos')->nullable();
            $table->date('disponible_hasta');
            $table->timestamp('descargada_en')->nullable();
            $table->date('retencion_hasta')->nullable();
            $table->timestamp('carta_enviada_en')->nullable();
            $table->timestamp('clave_enviada_en')->nullable();
            $table->json('recordatorios')->nullable();       // días ya avisados: [7, 2]
            $table->string('error', 300)->nullable();
            $table->timestamp('revisada_en')->nullable();
            $table->timestamps();

            $table->index(['estado', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exportaciones');
    }
};
