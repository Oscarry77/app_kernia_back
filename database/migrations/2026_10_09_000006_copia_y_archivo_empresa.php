<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * (09-oct-2026) Estándar v2.3 casos A y C (REGISTRO_DECISIONES_KERNIA.md §3, 05-oct):
 *  - Copia a petición: una por trimestre incluida; se borra al vencer su plazo.
 *  - Archivo de una empresa: solicitud de salida tipo `archivo` (conformidad,
 *    motivo, escalafón, 00:00) → exportación de esa empresa → la app la archiva
 *    cuando se descargó o venció el plazo (§4.5) → retención de 90 días.
 * Aditiva.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('solicitudes_salida', function (Blueprint $table) {
            $table->unsignedBigInteger('empresa_id')->nullable()->after('tipo'); // id de la empresa en la app (archivo)
            $table->string('empresa_nombre', 255)->nullable()->after('empresa_id');
            $table->string('empresa_rfc', 20)->nullable()->after('empresa_nombre');
        });

        Schema::table('exportaciones', function (Blueprint $table) {
            // Archivo de empresa (v2.3 §4.5): null | archivando | archivada
            $table->string('archivo', 12)->nullable()->after('eliminada_en');
            $table->timestamp('archivada_en')->nullable()->after('archivo');
        });
    }

    public function down(): void
    {
        Schema::table('exportaciones', fn (Blueprint $t) => $t->dropColumn(['archivo', 'archivada_en']));
        Schema::table('solicitudes_salida', fn (Blueprint $t) => $t->dropColumn(['empresa_id', 'empresa_nombre', 'empresa_rfc']));
    }
};
