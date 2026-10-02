<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vigencias (fase 2, 02-oct-2026). Aditiva.
 *  - pagos: cada ingreso registrado por un operador (no es pasarela) y el
 *    periodo que cubre.
 *  - dias_inhabiles: calendario para contar días hábiles del aviso.
 *  - suscripciones.suspension_motivo: 'vencimiento' o 'manual'; un pago solo
 *    levanta una suspensión por vencimiento.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pagos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('suscripcion_id')->constrained('suscripciones')->cascadeOnDelete();
            $table->date('fecha_pago');
            $table->date('periodo_desde');
            $table->date('periodo_hasta');
            $table->string('modalidad', 20);
            $table->decimal('monto', 12, 2)->nullable();
            $table->string('moneda', 3)->default('MXN');
            $table->string('referencia', 120);
            $table->text('notas')->nullable();
            $table->foreignId('registrado_por')->nullable()->constrained('landlord_admins')->nullOnDelete();
            $table->timestamps();

            $table->index(['suscripcion_id', 'periodo_hasta']);
        });

        Schema::create('dias_inhabiles', function (Blueprint $table) {
            $table->id();
            $table->date('fecha')->unique();
            $table->string('descripcion', 150);
            $table->timestamps();
        });

        Schema::table('suscripciones', function (Blueprint $table) {
            $table->string('suspension_motivo', 20)->nullable()->after('estatus');
        });
    }

    public function down(): void
    {
        Schema::table('suscripciones', fn (Blueprint $t) => $t->dropColumn('suspension_motivo'));
        Schema::dropIfExists('dias_inhabiles');
        Schema::dropIfExists('pagos');
    }
};
