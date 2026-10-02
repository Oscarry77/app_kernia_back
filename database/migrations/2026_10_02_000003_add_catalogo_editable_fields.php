<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catálogo de productos y planes editable desde el panel (02-oct-2026).
 * Aditiva.
 *  - productos: nombre corto, descripción y si admite WS-CNTPAQi.Net
 *    (decisión del dueño: solo Comercializa y HRM).
 *  - producto_modulos: dependencias (`requiere`), p. ej. Viáticos → Tesorería,
 *    validadas al guardar un plan.
 *  - suscripciones: WS-CNTPAQi.Net habilitado para ese cliente en esa app.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('productos', function (Blueprint $table) {
            $table->string('nombre_corto', 20)->nullable()->after('nombre');
            $table->text('descripcion')->nullable()->after('nombre_corto');
            $table->boolean('permite_ws_cntpaq')->default(false)->after('descripcion');
        });

        Schema::table('producto_modulos', function (Blueprint $table) {
            $table->json('requiere')->nullable()->after('nombre');
        });

        Schema::table('suscripciones', function (Blueprint $table) {
            $table->boolean('ws_cntpaq_habilitado')->default(false)->after('plan');
        });
    }

    public function down(): void
    {
        Schema::table('suscripciones', fn (Blueprint $t) => $t->dropColumn('ws_cntpaq_habilitado'));
        Schema::table('producto_modulos', fn (Blueprint $t) => $t->dropColumn('requiere'));
        Schema::table('productos', fn (Blueprint $t) => $t->dropColumn(['nombre_corto', 'descripcion', 'permite_ws_cntpaq']));
    }
};
