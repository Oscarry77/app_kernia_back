<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * (08-oct-2026) Estados de salida de una suscripción — REGISTRO_DECISIONES_KERNIA.md
 * §3 y §6 (05-oct) y estándar v2.3 §4.1 y §7:
 *  - "Retirar app" (`retirado`, reversible por el escalafón) y "Finiquitar"
 *    (`en_finiquito` → `finiquitado` → `eliminado`) son solicitudes distintas,
 *    autorizadas por el escalafón y aplicadas en el corte de las 00:00.
 *  - El finiquito exige la conformidad del cliente (referencia) y escribir el slug.
 *  - Kernia no manda estos estatus a una app hasta que ella confirma que los
 *    reconoce (`productos.estatus_salida`).
 * Aditiva.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('productos', function (Blueprint $table) {
            $table->boolean('estatus_salida')->default(false)->after('permite_ws_cntpaq');
        });

        Schema::table('suscripciones', function (Blueprint $table) {
            // En `en_finiquito`: último día para que el administrador descargue su respaldo.
            $table->date('descarga_hasta')->nullable()->after('activa_hasta');
        });

        Schema::create('solicitudes_salida', function (Blueprint $table) {
            $table->id();
            $table->foreignId('suscripcion_id')->constrained('suscripciones')->cascadeOnDelete();
            $table->string('tipo', 15);                       // retiro | reactivacion | finiquito
            $table->string('estatus_anterior', 30)->nullable(); // estatus de la suscripción al solicitar
            $table->text('motivo');
            $table->string('conformidad_tipo', 20)->nullable(); // finiquito: correo | documento
            $table->string('conformidad_referencia', 500)->nullable();
            $table->string('estado', 20)->default('solicitada'); // solicitada|programada|aplicada|rechazada|cancelada|fallida
            $table->date('fecha_efectiva')->nullable();
            $table->foreignId('solicitada_por')->nullable()->constrained('landlord_admins')->nullOnDelete();
            $table->foreignId('resuelta_por')->nullable()->constrained('landlord_admins')->nullOnDelete();
            $table->unsignedTinyInteger('nivel_autorizacion')->nullable();
            $table->text('comentario_resolucion')->nullable();
            $table->timestamp('resuelta_en')->nullable();
            $table->timestamp('aplicada_en')->nullable();
            $table->string('error', 300)->nullable();
            $table->timestamps();

            $table->index(['suscripcion_id', 'estado']);
            $table->index(['estado', 'fecha_efectiva']);
        });

        // Cada intento de autorizar o rechazar, incluidos los fallidos.
        Schema::create('solicitud_salida_intentos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('solicitud_salida_id')->constrained('solicitudes_salida')->cascadeOnDelete();
            $table->foreignId('sesion_usuario_id')->nullable()->constrained('landlord_admins')->nullOnDelete();
            $table->string('email_tecleado', 150);
            $table->string('accion', 12);            // autorizar | rechazar
            $table->string('resultado', 30);         // credencial_invalida | sin_nivel | autoriza_propia | autorizada | rechazada
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('solicitud_salida_intentos');
        Schema::dropIfExists('solicitudes_salida');
        Schema::table('suscripciones', fn (Blueprint $t) => $t->dropColumn('descarga_hasta'));
        Schema::table('productos', fn (Blueprint $t) => $t->dropColumn('estatus_salida'));
    }
};
