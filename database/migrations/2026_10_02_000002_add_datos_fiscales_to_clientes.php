<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Datos fiscales del cliente según la Constancia de Situación Fiscal
 * (02-oct-2026), con tipo de persona (moral / física). Aditiva y nullable:
 * los clientes existentes quedan "datos fiscales pendientes" hasta que se
 * capturen. `nombre` se conserva como nombre para mostrar y se arma al guardar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clientes', function (Blueprint $table) {
            $table->string('tipo_persona', 10)->nullable()->after('nombre');      // moral | fisica
            // Persona moral
            $table->string('razon_social', 255)->nullable()->after('tipo_persona');
            $table->string('regimen_capital', 40)->nullable()->after('razon_social');
            $table->string('nombre_comercial', 255)->nullable()->after('regimen_capital');
            // Persona física
            $table->string('curp', 18)->nullable()->after('rfc');
            $table->string('nombres', 120)->nullable()->after('curp');
            $table->string('primer_apellido', 80)->nullable()->after('nombres');
            $table->string('segundo_apellido', 80)->nullable()->after('primer_apellido');
            // Ambos
            $table->date('fecha_inicio_operaciones')->nullable()->after('segundo_apellido');
            $table->string('estatus_padron', 12)->nullable()->after('fecha_inicio_operaciones'); // activo | suspendido (SAT)
            $table->string('regimen_fiscal', 3)->nullable()->after('estatus_padron');          // c_RegimenFiscal
            // Domicilio fiscal
            $table->string('codigo_postal', 5)->nullable();
            $table->string('tipo_vialidad', 40)->nullable();
            $table->string('nombre_vialidad', 150)->nullable();
            $table->string('numero_exterior', 30)->nullable();
            $table->string('numero_interior', 30)->nullable();
            $table->string('colonia', 150)->nullable();
            $table->string('localidad', 150)->nullable();
            $table->string('municipio', 150)->nullable();
            $table->string('entidad_federativa', 60)->nullable();
            $table->string('entre_calle', 150)->nullable();
            $table->string('y_calle', 150)->nullable();
            $table->string('correo', 150)->nullable();
            $table->string('telefono_lada', 3)->nullable();
            $table->string('telefono_numero', 8)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('clientes', function (Blueprint $table) {
            $table->dropColumn([
                'tipo_persona', 'razon_social', 'regimen_capital', 'nombre_comercial',
                'curp', 'nombres', 'primer_apellido', 'segundo_apellido',
                'fecha_inicio_operaciones', 'estatus_padron', 'regimen_fiscal',
                'codigo_postal', 'tipo_vialidad', 'nombre_vialidad', 'numero_exterior', 'numero_interior',
                'colonia', 'localidad', 'municipio', 'entidad_federativa', 'entre_calle', 'y_calle',
                'correo', 'telefono_lada', 'telefono_numero',
            ]);
        });
    }
};
