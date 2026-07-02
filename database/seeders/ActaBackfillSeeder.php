<?php

namespace Database\Seeders;

use App\Enums\LegalNameStatusEnum;
use App\Enums\ShareholderRoleEnum;
use App\Models\LegalName;
use App\Models\Registration;
use App\Models\Shareholder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Backfills acta constitutiva data for the four Chinese S. de R.L. de C.V.
 * clients whose deed was drafted externally before onboarding.
 *
 * For every registration (matched by singapur_client_code) it fills:
 *   - registrations : company_type, capital_social, company_object
 *   - legal_names   : denomination (priority 1) + SE clave unica + APPROVED
 *   - shareholders  : both socios with full acta data. The Consejo de Gerentes
 *                     Presidente is stored as LEGAL_REPRESENTATIVE and the
 *                     Secretario as SHAREHOLDER.
 *
 * Idempotent: registrations/legal_names are updated in place and shareholders
 * are upserted by (registration_id, passport_number); re-running never dupes.
 *
 * DATA NOTE: HUA DIAN (000003) subscribes 7,500 + 2,300 = 9,800 MXN in its acta,
 * which contradicts the 10,000 statutory minimum quoted in the same document.
 * capital_social below mirrors the acta (9,800.00); fix the source acta if wrong.
 * The JUN LIU email 'liujun.@nuolianhuanyu.com' also has a suspicious dot before
 * the '@' as written in the acta — verify before relying on it for delivery.
 */
class ActaBackfillSeeder extends Seeder
{
    /**
     * Corporate purpose (objeto social) shared verbatim by the four actas.
     *
     * @var string
     */
    private const OBJETO = <<<'OBJETO'
A).- La distribución, importación, exportación y comercialización de toda clase de productos físicos y/o digitales, así como bienes tangibles o intangibles, de manera física o en línea (“e-commerce”). B).- La compra, venta, distribución, representación, importación y/ exportación, comercialización al por mayor y menor de productos de todo tipo físicos o digitales y de diversas marcas nacionales y/o extranjeras por medio de redes externas ("Internet"). C).- El desarrollo, implementación, operación y comercialización de plataformas digitales, software y aplicaciones móviles. D).- El diseño, adquisición, explotación y uso de patentes, marcas, nombres comerciales, derechos de autor y demás figuras de propiedad intelectual relacionadas con productos o servicios que comercialice la sociedad. E).- Con fundamento en lo dispuesto en el segundo párrafo del artículo cuarto de la Ley General de Sociedades Mercantiles, la sociedad tendrá capacidad para realizar todos los actos de comercio necesarios para el cumplimiento de su objeto social, por lo que de manera enunciativa y no limitativa podrá: i.- La realización de operaciones de otorgamiento de crédito, de arrendamiento financiero y de factoraje financiero para todas las actividades contenidas en los incisos anteriores del objeto social, las cuales se sujetarán al régimen de la Ley General de Títulos y Operaciones de Crédito, al de las sociedades financieras de objeto múltiple, previstas en la Ley General de Organizaciones y Actividades Auxiliares del Crédito. ii.- Constituir y participar en el capital social de otras sociedades civiles o mercantiles, nacionales o extranjeras, al momento de su constitución o en cualquier momento posterior, así como transferir por cualquier título dichas acciones o partes sociales. iii.- Emitir, girar, endosar, aceptar, librar, avalar, descontar, suscribir y negociar toda clase de títulos de crédito, sin que se ubique en los supuestos de la fracción décima quinta del artículo segundo de la Ley del Mercado de Valores. iv.- Adquirir por cualquier título, dar o tomar en arrendamiento, y disponer de todos los bienes muebles que fueren necesarios o convenientes para la consecución del objeto social. v.- Adquirir por cualquier título, dar o tomar en arrendamiento y disponer de todos los bienes inmuebles que fueren necesarios o convenientes para la consecución del objeto social. vi.- Adquirir y explotar patentes, certificados de invención, diseños y modelos industriales, marcas, denominaciones de origen, nombres comerciales, slogans, franquicias y derechos de autor. vii.- Otorgar y obtener todo tipo de financiamientos permitidos por la ley. viii.- Otorgar todo tipo de garantías reales o personales, así como obligarse solidariamente, en relación con obligaciones propias o a cargo de terceros. ix.- En general, la realización y la celebración de toda clase de actos, operaciones, convenios y contratos, ya sean administrativos, civiles o mercantiles, necesarios para el cumplimiento de su objeto social.
OBJETO;

    /**
     * Company type as stored on registrations/legal_names for these deeds.
     *
     * @var string
     */
    private const COMPANY_TYPE = 'SRL de CV';

    /**
     * Acta data keyed by singapur_client_code.
     *
     * @var array<string, array<string, mixed>>
     */
    private const COMPANIES = [
        '000003' => [
            'denomination' => 'HUA DIAN TIENDA DIGITAL',
            'clave_unica_denominacion' => 'A202602231247558811',
            'authorization_timestamp' => '2026-02-23 00:00:00',
            'capital_social' => '9800.00',
            'socios' => [
                [
                    'name' => 'HENGYI CHEN',
                    'passport_number' => 'EN9908330',
                    'tax_id' => '445281197912061517',
                    'email' => 'wei@samgle.com',
                    'gender' => 'M',
                    'birthdate' => '1979-12-06',
                    'birthplace' => 'Guangdong, China',
                    'civil_status' => 'soltero',
                    'participation_percentage' => '76.53',
                    'role' => ShareholderRoleEnum::LEGAL_REPRESENTATIVE,
                ],
                [
                    'name' => 'YISEN CHEN',
                    'passport_number' => 'EM9013306',
                    'tax_id' => '445281198705271532',
                    'email' => 'cys99777@outlook.com',
                    'gender' => 'M',
                    'birthdate' => '1987-05-27',
                    'birthplace' => 'Guangdong, China',
                    'civil_status' => 'soltero',
                    'participation_percentage' => '23.47',
                    'role' => ShareholderRoleEnum::SHAREHOLDER,
                ],
            ],
        ],
        '000004' => [
            'denomination' => 'DONGHAI COMERCIO INTERNACIONAL',
            'clave_unica_denominacion' => 'A202605081157107029',
            'authorization_timestamp' => '2026-05-08 00:00:00',
            'capital_social' => '10000.00',
            'socios' => [
                [
                    'name' => 'LIRAN LIU',
                    'passport_number' => 'ED6103553',
                    'tax_id' => '410527199307033427',
                    'email' => 'jytchk2026@outlook.com',
                    'gender' => 'F',
                    'birthdate' => '1993-07-03',
                    'birthplace' => 'Henan, China',
                    'civil_status' => 'soltera',
                    'participation_percentage' => '80.00',
                    'role' => ShareholderRoleEnum::LEGAL_REPRESENTATIVE,
                ],
                [
                    'name' => 'FEI PENG',
                    'passport_number' => 'EK3466753',
                    'tax_id' => '14232519930811001X',
                    'email' => 'andre_peng@petalmail.com',
                    'gender' => 'M',
                    'birthdate' => '1993-08-11',
                    'birthplace' => 'Shanxi, China',
                    'civil_status' => 'soltero',
                    'participation_percentage' => '20.00',
                    'role' => ShareholderRoleEnum::SHAREHOLDER,
                ],
            ],
        ],
        '000005' => [
            'denomination' => 'SHANG LI COMERCIO DIGITAL',
            'clave_unica_denominacion' => 'A202602230947090188',
            'authorization_timestamp' => '2026-02-23 00:00:00',
            'capital_social' => '10000.00',
            'socios' => [
                [
                    'name' => 'LINGFANG CHEN',
                    'passport_number' => 'EC0651451',
                    'tax_id' => '36243219930903002X',
                    'email' => 'clf0626@outlook.com',
                    'gender' => 'F',
                    'birthdate' => '1993-09-03',
                    'birthplace' => 'Jiangxi, China',
                    'civil_status' => 'soltera',
                    'participation_percentage' => '80.00',
                    'role' => ShareholderRoleEnum::LEGAL_REPRESENTATIVE,
                ],
                [
                    'name' => 'LINGLI CHEN',
                    'passport_number' => 'EC4214192',
                    'tax_id' => '14232519930811001X',
                    'email' => 'cll0626@outlook.com',
                    'gender' => 'F',
                    'birthdate' => '1991-11-10',
                    'birthplace' => 'Jiangxi, China',
                    'civil_status' => 'soltera',
                    'participation_percentage' => '20.00',
                    'role' => ShareholderRoleEnum::SHAREHOLDER,
                ],
            ],
        ],
        '000006' => [
            'denomination' => 'GUANGHUA DISTRIBUCIÓN',
            'clave_unica_denominacion' => 'A202605081157300840',
            'authorization_timestamp' => '2026-05-08 00:00:00',
            'capital_social' => '10000.00',
            'socios' => [
                [
                    'name' => 'JUN LIU',
                    'passport_number' => 'ER7655966',
                    'tax_id' => '340404198711300619',
                    'email' => 'liujun.@nuolianhuanyu.com',
                    'gender' => 'M',
                    'birthdate' => '1987-11-30',
                    'birthplace' => 'Anhui, China',
                    'civil_status' => 'soltero',
                    'participation_percentage' => '80.00',
                    'role' => ShareholderRoleEnum::LEGAL_REPRESENTATIVE,
                ],
                [
                    'name' => 'BINGYA YU',
                    'passport_number' => 'EH2292707',
                    'tax_id' => '331082199911026942',
                    'email' => 'mia.yu@nuolianhuanyu.com',
                    'gender' => 'F',
                    'birthdate' => '1999-11-02',
                    'birthplace' => 'Zhejiang, China',
                    'civil_status' => 'soltera',
                    'participation_percentage' => '20.00',
                    'role' => ShareholderRoleEnum::SHAREHOLDER,
                ],
            ],
        ],
    ];

    /**
     * Run the backfill for every configured company.
     */
    public function run(): void
    {
        foreach (self::COMPANIES as $code => $data) {
            $this->backfillCompany((string) $code, $data);
        }
    }

    /**
     * Backfill a single registration and its legal name and shareholders.
     *
     * Matches the registration by its Singapur client code, updates the
     * corporate fields, upserts the priority-1 denomination and each socio.
     *
     * @param  string  $code  Singapur client code, e.g. "000004".
     * @param  array<string, mixed>  $data  Acta data for this company.
     */
    private function backfillCompany(string $code, array $data): void
    {
        $registration = Registration::query()
            ->where('singapur_client_code', $code)
            ->first();

        if ($registration === null) {
            $this->command->warn("[{$code}] registration not found — skipped.");

            return;
        }

        DB::transaction(function () use ($registration, $data): void {
            $registration->update([
                'company_type' => self::COMPANY_TYPE,
                'capital_social' => $data['capital_social'],
                'company_object' => self::OBJETO,
            ]);

            LegalName::updateOrCreate(
                [
                    'registration_id' => $registration->id,
                    'priority' => 1,
                ],
                [
                    'name' => $data['denomination'],
                    'company_type' => self::COMPANY_TYPE,
                    'status' => LegalNameStatusEnum::APPROVED,
                    'clave_unica_denominacion' => $data['clave_unica_denominacion'],
                    'authorization_timestamp' => $data['authorization_timestamp'],
                ],
            );

            foreach ($data['socios'] as $socio) {
                Shareholder::updateOrCreate(
                    [
                        'registration_id' => $registration->id,
                        'passport_number' => $socio['passport_number'],
                    ],
                    [
                        'name' => $socio['name'],
                        'nationality' => 'china',
                        'participation_percentage' => $socio['participation_percentage'],
                        'role' => $socio['role'],
                        'email' => $socio['email'],
                        'phone_country_code' => '+86',
                        'is_married' => false,
                        'gender' => $socio['gender'],
                        'birthdate' => $socio['birthdate'],
                        'birthplace' => $socio['birthplace'],
                        'civil_status' => $socio['civil_status'],
                        'tax_id' => $socio['tax_id'],
                    ],
                );
            }
        });

        $this->command->info("[{$code}] {$data['denomination']} — denominación, empresa y ".count($data['socios']).' socios cargados.');
    }
}
