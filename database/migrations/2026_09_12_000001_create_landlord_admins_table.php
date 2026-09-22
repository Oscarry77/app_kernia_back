<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Movida de bridge-com-api el 13-sep-2026, mismo nombre/timestamp original.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('landlord_admins')) {
            return;
        }

        Schema::create('landlord_admins', function (Blueprint $table) {
            $table->id();
            $table->string('nombre');
            $table->string('email')->unique();
            $table->string('password');
            $table->boolean('activo')->default(true);
            $table->timestamp('ultimo_acceso')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('landlord_admins');
    }
};
