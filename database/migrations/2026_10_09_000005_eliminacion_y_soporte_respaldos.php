<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * (09-oct-2026) Estándar v2.3 §4.3, §4.4, §4.6 y §7 — segunda parte del finiquito:
 *  - Eliminación al terminar la retención (pasos: exportación borrada → la app
 *    elimina → Kernia borra la base del patrón A → `eliminado`, con constancia).
 *  - Solicitudes de soporte sobre un respaldo, autorizadas por el escalafón:
 *    reenviar la contraseña al administrador y entregar el 7z cifrado a soporte.
 * Aditiva.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exportaciones', function (Blueprint $table) {
            // null | exportacion_borrada | app_eliminando | base_borrada | completa
            $table->string('eliminacion', 20)->nullable()->after('retencion_hasta');
            $table->timestamp('eliminada_en')->nullable()->after('eliminacion');
        });

        Schema::create('solicitudes_respaldo', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exportacion_id')->constrained('exportaciones')->cascadeOnDelete();
            $table->foreignId('suscripcion_id')->constrained('suscripciones')->cascadeOnDelete();
            $table->string('tipo', 20);                      // reenvio_clave | entrega_soporte
            $table->text('motivo');
            $table->string('estado', 20)->default('solicitada'); // solicitada | autorizada | aplicada | rechazada | cancelada
            $table->foreignId('solicitada_por')->nullable()->constrained('landlord_admins')->nullOnDelete();
            $table->foreignId('resuelta_por')->nullable()->constrained('landlord_admins')->nullOnDelete();
            $table->unsignedTinyInteger('nivel_autorizacion')->nullable();
            $table->text('comentario_resolucion')->nullable();
            $table->timestamp('resuelta_en')->nullable();
            $table->timestamp('aplicada_en')->nullable();
            $table->timestamp('vigente_hasta')->nullable(); // entrega a soporte: ventana para descargar (24 h)
            $table->timestamps();

            $table->index(['exportacion_id', 'estado']);
        });

        Schema::create('solicitud_respaldo_intentos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('solicitud_respaldo_id')->constrained('solicitudes_respaldo')->cascadeOnDelete();
            $table->foreignId('sesion_usuario_id')->nullable()->constrained('landlord_admins')->nullOnDelete();
            $table->string('email_tecleado', 150);
            $table->string('accion', 12);
            $table->string('resultado', 30);
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('solicitud_respaldo_intentos');
        Schema::dropIfExists('solicitudes_respaldo');
        Schema::table('exportaciones', fn (Blueprint $t) => $t->dropColumn(['eliminacion', 'eliminada_en']));
    }
};
