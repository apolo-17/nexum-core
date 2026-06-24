<?php

namespace Database\Seeders;

use App\Enums\LegalAgentTypeEnum;
use App\Models\LegalAgent;
use App\Models\Registration;
use Illuminate\Database\Seeder;

/**
 * Seeds the catalog of legal representatives and commissaries with sample data,
 * and assigns a few of them to existing demo actas with share percentages.
 *
 * Demo data only — run from local/staging. Production starts with an empty catalog
 * that the notary team fills with real profiles.
 */
class LegalAgentsSeeder extends Seeder
{
    /**
     * Sample legal representatives.
     *
     * @var list<array<string, string>>
     */
    private const REPRESENTATIVES = [
        ['name' => 'María Fernanda Gutiérrez Solís', 'rfc' => 'GUSM850214AB1', 'curp' => 'GUSM850214MDFTLR06'],
        ['name' => 'Roberto Carlos Mendoza Lara', 'rfc' => 'MELR790901XY2', 'curp' => 'MELR790901HDFNRB04'],
        ['name' => 'Ana Patricia Villalobos Cruz', 'rfc' => 'VICA881130QW3', 'curp' => 'VICA881130MDFLRN09'],
    ];

    /**
     * Sample commissaries.
     *
     * @var list<array<string, string>>
     */
    private const COMMISSARIES = [
        ['name' => 'Jorge Luis Hernández Peña', 'rfc' => 'HEPJ760620ZX4', 'curp' => 'HEPJ760620HDFRRG02'],
        ['name' => 'Claudia Isabel Ramírez Ortega', 'rfc' => 'RAOC830408LM5', 'curp' => 'RAOC830408MDFMRL01'],
    ];

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $representatives = collect(self::REPRESENTATIVES)->map(
            fn (array $data): LegalAgent => $this->createAgent($data, LegalAgentTypeEnum::LEGAL_REPRESENTATIVE),
        );

        $commissaries = collect(self::COMMISSARIES)->map(
            fn (array $data): LegalAgent => $this->createAgent($data, LegalAgentTypeEnum::COMMISSARY),
        );

        $this->assignToDemoActas($representatives->all(), $commissaries->all());
    }

    /**
     * Create or update a single catalog entry, idempotently keyed by RFC.
     *
     * @param  array<string, string>  $data  Name, RFC and CURP for the profile.
     * @param  LegalAgentTypeEnum  $type  Whether it is a representative or commissary.
     */
    private function createAgent(array $data, LegalAgentTypeEnum $type): LegalAgent
    {
        return LegalAgent::firstOrCreate(
            ['rfc' => $data['rfc']],
            [
                'type' => $type,
                'name' => $data['name'],
                'nationality' => 'mexicana',
                'curp' => $data['curp'],
                'email' => str($data['name'])->ascii()->slug('.')->append('@notaria.mx')->value(),
                'phone' => '55'.fake()->numerify('########'),
                'birthplace' => 'Ciudad de México, México',
                'address' => fake()->streetAddress().', Ciudad de México',
                'is_active' => true,
            ],
        );
    }

    /**
     * Assign one representative and one commissary to a handful of demo actas.
     *
     * Skips silently when there are no registrations (e.g. ChineseCompaniesSeeder
     * was not run). Percentages are illustrative only.
     *
     * @param  list<LegalAgent>  $representatives
     * @param  list<LegalAgent>  $commissaries
     */
    private function assignToDemoActas(array $representatives, array $commissaries): void
    {
        if (empty($representatives) || empty($commissaries)) {
            return;
        }

        $registrations = Registration::query()->limit(8)->get();

        foreach ($registrations as $index => $registration) {
            $representative = $representatives[$index % count($representatives)];
            $commissary = $commissaries[$index % count($commissaries)];

            $registration->legalAgents()->syncWithoutDetaching([
                $representative->id => ['participation_percentage' => 60.00],
                $commissary->id => ['participation_percentage' => 0.00],
            ]);
        }
    }
}
