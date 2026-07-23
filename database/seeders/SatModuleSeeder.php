<?php

namespace Database\Seeders;

use App\Models\SatModule;
use Illuminate\Database\Seeder;

/**
 * Seeds the SAT offices of Ciudad de México (entidad 10).
 *
 * Values captured live from the SAT portal (POST /api/filtros/servicio, 2026-07-22).
 * All six support the virtual queue. Refresh with scripts/probe_filtros.py in the
 * nexum-citas-sat repo if the SAT adds or removes offices.
 */
class SatModuleSeeder extends Seeder
{
    /**
     * The CDMX offices, ordered by how central they are.
     *
     * @var list<array{sat_id:int, name:string, address:string, latitude:float, longitude:float}>
     */
    private const MODULES = [
        [
            'sat_id' => 66,
            'name' => 'ADSC Distrito Federal "2" Centro',
            'address' => 'Av. Paseo de la Reforma Norte núm. 10, piso 2, edif. Torre Caballito, Col. Tabacalera, 06030, Deleg. Cuauhtémoc, Ciudad de México.',
            'latitude' => 19.436296,
            'longitude' => -99.149000,
        ],
        [
            'sat_id' => 72,
            'name' => 'MST Del Valle',
            'address' => 'Planta Baja, Av. Cuauhtémoc 602, Narvarte Poniente, Benito Juárez, 03020 Ciudad de México, CDMX.',
            'latitude' => 19.396973,
            'longitude' => -99.155815,
        ],
        [
            'sat_id' => 68,
            'name' => 'ADSC Distrito Federal "1" Norte',
            'address' => 'Bahía de Santa Bárbara núm. 23 p.b., Col. Verónica Anzures, 11300, Deleg. Miguel Hidalgo, Ciudad de México.',
            'latitude' => 19.437967,
            'longitude' => -99.177590,
        ],
        [
            'sat_id' => 334,
            'name' => 'MST Oasis',
            'address' => 'Av. Universidad No. 1778, acceso secundario en Av. Miguel Ángel de Quevedo No. 227, Col. Romero de Terreros, Deleg. Coyoacán, 04310, Ciudad de México. Sótano 1, frente a la zona de bancos.',
            'latitude' => 19.345884,
            'longitude' => -99.179770,
        ],
        [
            'sat_id' => 70,
            'name' => 'ADSC Distrito Federal "3" Oriente',
            'address' => 'Viaducto Río de la Piedad núm. 507, Col. Granjas México, 08400, Deleg. Iztacalco, Ciudad de México.',
            'latitude' => 19.405840,
            'longitude' => -99.111570,
        ],
        [
            'sat_id' => 71,
            'name' => 'ADSC Distrito Federal "4" Sur',
            'address' => 'Av. San Lorenzo núm. 104, Col. San Lorenzo La Cebada, Deleg. Xochimilco, 16035, Ciudad de México. Entre Majuelos y Juan Sarabia.',
            'latitude' => 19.276684,
            'longitude' => -99.126260,
        ],
    ];

    /**
     * Insert the CDMX offices, leaving any manual edits (is_active) untouched.
     */
    public function run(): void
    {
        foreach (self::MODULES as $module) {
            SatModule::updateOrCreate(
                ['sat_id' => $module['sat_id']],
                $module + ['entidad' => 10, 'supports_virtual_queue' => true],
            );
        }
    }
}
