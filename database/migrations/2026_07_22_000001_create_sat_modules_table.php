<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catalog of SAT offices (módulos) where appointments can be formed.
 *
 * Mirrors the SAT's own catalog (POST /api/filtros/servicio returns entidad, nombreMod,
 * direccionMod, coordinates, modulo id and whether the office supports the virtual
 * queue). Seeded with the Ciudad de México offices; refreshable from the portal with
 * scripts/probe_filtros.py in the nexum-citas-sat repo.
 *
 * `sat_id` is the SAT's own module id — it is what the bot sends as tcModuloId.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sat_modules', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('sat_id')->unique()
                ->comment("SAT's own module id (tcModuloId sent when forming)");
            $table->unsignedInteger('entidad')->default(10)
                ->comment('SAT entidad id — 10 = Ciudad de México (NOT the 09 INEGI code)');
            $table->string('name');
            $table->text('address')->nullable();
            $table->decimal('latitude', 10, 6)->nullable();
            $table->decimal('longitude', 10, 6)->nullable();
            $table->boolean('supports_virtual_queue')->default(true);
            $table->boolean('is_active')->default(true)
                ->comment('Whether Nexum offers this office when scheduling');
            $table->timestamps();

            $table->index(['entidad', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sat_modules');
    }
};
