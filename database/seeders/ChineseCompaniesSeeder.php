<?php

namespace Database\Seeders;

use App\Enums\DocumentTypeEnum;
use App\Enums\EfirmaAppointmentStatusEnum;
use App\Enums\LegalNameStatusEnum;
use App\Enums\RegistrationStageEnum;
use App\Enums\RegistrationStatusEnum;
use App\Enums\ShareholderRoleEnum;
use App\Enums\TaskPriorityEnum;
use App\Enums\TaskTypeEnum;
use App\Models\Document;
use App\Models\LegalName;
use App\Models\Note;
use App\Models\Registration;
use App\Models\Shareholder;
use App\Models\StageTransition;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

/**
 * Seeds 15 realistic Chinese company registration expedients for development and demo.
 *
 * Creates a representative distribution across all 8 registration stages,
 * including shareholders with Chinese names, legal denominations, KYC and manual
 * documents, stage transition audit trails, pending tasks, and internal notes.
 * All data is fictional but structurally and contextually realistic.
 *
 * Document types generated:
 *   - naturalTaxCertificate  → JPEG image (Chinese national ID card simulation)
 *   - naturalSpousePassport  → JPEG image (Chinese passport data page simulation)
 *   - naturalProofAddress    → PDF
 *   - naturalMarriageCert    → PDF
 *   - Later stage docs       → PDF
 *
 * Users created:
 *   - admin@nexum.mx           (super_admin)
 *   - notario.garcia@nexum.mx  (notario)
 *   - notario.lopez@nexum.mx   (notario)
 *   - asistente.torres@nexum.mx    (asistente_notario)
 *   - asistente.ramirez@nexum.mx   (asistente_notario)
 *
 * All passwords: 'password'
 */
class ChineseCompaniesSeeder extends Seeder
{
    // -------------------------------------------------------------------------
    // Team members (populated in createUsers())
    // -------------------------------------------------------------------------

    private User $admin;

    private User $notario1;

    private User $notario2;

    private User $asistente1;

    private User $asistente2;

    // -------------------------------------------------------------------------
    // Static data pools
    // -------------------------------------------------------------------------

    /**
     * 60 company entries: legal name (with accents), relay folder name (no accents), type.
     *
     * Type values map to: 'sa' → SA de CV, 'srl' → SRL de CV, 'sapi' → SAPI de CV.
     *
     * @var list<array{name: string, folder: string, type: string}>
     */
    private const COMPANIES = [
        // DATA_RECEIVED — codes 000001-000012
        ['name' => 'NOVA CONSULTORÍA EMPRESARIAL',        'folder' => 'NOVA CONSULTORA EMPRESARIAL',        'type' => 'sa'],
        ['name' => 'PACIFIC TRADING COMERCIAL',           'folder' => 'PACIFIC TRADING COMERCIAL',           'type' => 'sa'],
        ['name' => 'DRAGÓN DORADO IMPORTACIONES',         'folder' => 'DRAGON DORADO IMPORTACIONES',         'type' => 'srl'],
        ['name' => 'GOLDEN BRIDGE DISTRIBUCIONES',        'folder' => 'GOLDEN BRIDGE DISTRIBUCIONES',        'type' => 'sa'],
        ['name' => 'SINO-MEX SOLUCIONES INTEGRALES',      'folder' => 'SINO-MEX SOLUCIONES INTEGRALES',      'type' => 'srl'],
        ['name' => 'MERIDIAN COMERCIALIZADORA',           'folder' => 'MERIDIAN COMERCIALIZADORA',           'type' => 'sa'],
        ['name' => 'ORIENT LOGISTICS MEXICO',             'folder' => 'ORIENT LOGISTICS MEXICO',             'type' => 'sapi'],
        ['name' => 'JADE INVERSIONES HOLDING',            'folder' => 'JADE INVERSIONES HOLDING',            'type' => 'sa'],
        ['name' => 'HARMONY GROUP EMPRESARIAL',           'folder' => 'HARMONY GROUP EMPRESARIAL',           'type' => 'srl'],
        ['name' => 'CELESTIAL IMPORTS MEXICO',            'folder' => 'CELESTIAL IMPORTS MEXICO',            'type' => 'sa'],
        ['name' => 'PHOENIX RISE CONSTRUCTORES',          'folder' => 'PHOENIX RISE CONSTRUCTORES',          'type' => 'sa'],
        ['name' => 'GREAT WALL SOLUCIONES',               'folder' => 'GREAT WALL SOLUCIONES',               'type' => 'srl'],
        // IDENTITY_VALIDATION — codes 000013-000022
        ['name' => 'SUNRISE TECH MEXICO',                 'folder' => 'SUNRISE TECH MEXICO',                 'type' => 'sa'],
        ['name' => 'YANGTZE COMERCIO EXTERIOR',           'folder' => 'YANGTZE COMERCIO EXTERIOR',           'type' => 'srl'],
        ['name' => 'LOTUS FLOWER IMPORTACIONES',          'folder' => 'LOTUS FLOWER IMPORTACIONES',          'type' => 'srl'],
        ['name' => 'CHINA BRIGHT TECHNOLOGY',             'folder' => 'CHINA BRIGHT TECHNOLOGY',             'type' => 'sapi'],
        ['name' => 'IMPERIAL COURT INVERSIONES',          'folder' => 'IMPERIAL COURT INVERSIONES',          'type' => 'sa'],
        ['name' => 'BAMBOO GROVE COMERCIAL',              'folder' => 'BAMBOO GROVE COMERCIAL',              'type' => 'srl'],
        ['name' => 'MING DYNASTY TRADING',                'folder' => 'MING DYNASTY TRADING',                'type' => 'sa'],
        ['name' => 'HUAXIA VENTURES MEXICO',              'folder' => 'HUAXIA VENTURES MEXICO',              'type' => 'sapi'],
        ['name' => 'EASTERN PEARL GROUP',                 'folder' => 'EASTERN PEARL GROUP',                 'type' => 'sa'],
        ['name' => 'GOLDEN LOTUS DISTRIBUCIONES',         'folder' => 'GOLDEN LOTUS DISTRIBUCIONES',         'type' => 'srl'],
        // LEGAL_NAME — codes 000023-000032
        ['name' => 'GOLDEN PHOENIX EXPORTACIONES',        'folder' => 'GOLDEN PHOENIX EXPORTACIONES',        'type' => 'sa'],
        ['name' => 'SILK ROAD COMERCIALIZADORA',          'folder' => 'SILK ROAD COMERCIALIZADORA',          'type' => 'srl'],
        ['name' => 'RED DRAGON INVERSIONES',              'folder' => 'RED DRAGON INVERSIONES',              'type' => 'sa'],
        ['name' => 'GREAT PROSPERITY DISTRIBUCIONES',     'folder' => 'GREAT PROSPERITY DISTRIBUCIONES',     'type' => 'srl'],
        ['name' => 'TIANLONG IMPORT EXPORT',              'folder' => 'TIANLONG IMPORT EXPORT',              'type' => 'sa'],
        ['name' => 'SHIFU TECH SOLUTIONS',                'folder' => 'SHIFU TECH SOLUTIONS',                'type' => 'sapi'],
        ['name' => 'WUXI GLOBAL TRADING',                 'folder' => 'WUXI GLOBAL TRADING',                 'type' => 'sa'],
        ['name' => 'JINLONG CONSTRUCTORES MEXICO',        'folder' => 'JINLONG CONSTRUCTORES MEXICO',        'type' => 'srl'],
        ['name' => 'CHENGDU IMPORTACIONES GLOBALES',      'folder' => 'CHENGDU IMPORTACIONES GLOBALES',      'type' => 'sa'],
        ['name' => 'SUZHOU SOLUCIONES EMPRESARIALES',     'folder' => 'SUZHOU SOLUCIONES EMPRESARIALES',     'type' => 'srl'],
        // INCORPORATION — codes 000033-000040
        ['name' => 'HANGZHOU TRADING MEXICO',             'folder' => 'HANGZHOU TRADING MEXICO',             'type' => 'sa'],
        ['name' => 'NANJING IMPORT COMERCIO',             'folder' => 'NANJING IMPORT COMERCIO',             'type' => 'srl'],
        ['name' => 'TIANJIN ENTERPRISES MEXICO',          'folder' => 'TIANJIN ENTERPRISES MEXICO',          'type' => 'sa'],
        ['name' => 'WUHAN SOLUCIONES GLOBALES',           'folder' => 'WUHAN SOLUCIONES GLOBALES',           'type' => 'sapi'],
        ['name' => 'XIAMEN LOGISTICS MEXICO',             'folder' => 'XIAMEN LOGISTICS MEXICO',             'type' => 'sa'],
        ['name' => 'QINGDAO TECH INTERNATIONAL',          'folder' => 'QINGDAO TECH INTERNATIONAL',          'type' => 'srl'],
        ['name' => 'ZHENGZHOU DISTRIBUCIONES MEXICO',     'folder' => 'ZHENGZHOU DISTRIBUCIONES MEXICO',     'type' => 'sa'],
        ['name' => 'KUNMING INVESTMENTS MEXICO',          'folder' => 'KUNMING INVESTMENTS MEXICO',          'type' => 'srl'],
        // PARTNER_SIGNATURE — codes 000041-000043
        ['name' => 'GUANGZHOU IMPORT MEXICO',             'folder' => 'GUANGZHOU IMPORT MEXICO',             'type' => 'sa'],
        ['name' => 'SHENZHEN VENTURES MEXICO',            'folder' => 'SHENZHEN VENTURES MEXICO',            'type' => 'sapi'],
        ['name' => 'BEIJING CONSULTORÍA MEXICO',          'folder' => 'BEIJING CONSULTORIA MEXICO',          'type' => 'sa'],
        // TAX_ADDRESS — codes 000044-000046
        ['name' => 'SHANGHAI TRADING MEXICO',             'folder' => 'SHANGHAI TRADING MEXICO',             'type' => 'srl'],
        ['name' => 'CHONGQING GROUP MEXICO',              'folder' => 'CHONGQING GROUP MEXICO',              'type' => 'sa'],
        ['name' => 'XIAN EMPRESARIAL MEXICO',             'folder' => 'XIAN EMPRESARIAL MEXICO',             'type' => 'srl'],
        // SAT_REGISTRATION — codes 000047-000051
        ['name' => 'DONGFANG GLOBAL VENTURES',            'folder' => 'DONGFANG GLOBAL VENTURES',            'type' => 'sa'],
        ['name' => 'ZHONGHUA INVERSIONES MEXICO',         'folder' => 'ZHONGHUA INVERSIONES MEXICO',         'type' => 'srl'],
        ['name' => 'TAISHAN ENTERPRISE MEXICO',           'folder' => 'TAISHAN ENTERPRISE MEXICO',           'type' => 'sapi'],
        ['name' => 'QIANLONG TECH SOLUTIONS',             'folder' => 'QIANLONG TECH SOLUTIONS',             'type' => 'sa'],
        ['name' => 'LONGHUA TRADING MEXICO',              'folder' => 'LONGHUA TRADING MEXICO',              'type' => 'srl'],
        // EFIRMA_APPOINTMENT — codes 000052-000056
        ['name' => 'HONG KONG CONNECT MEXICO',            'folder' => 'HONG KONG CONNECT MEXICO',            'type' => 'sa'],
        ['name' => 'ASIA PACIFIC PARTNERS MEXICO',        'folder' => 'ASIA PACIFIC PARTNERS MEXICO',        'type' => 'sapi'],
        ['name' => 'CHINA STAR TRADING MEXICO',           'folder' => 'CHINA STAR TRADING MEXICO',           'type' => 'sa'],
        ['name' => 'EASTERN SUN DISTRIBUCIONES',          'folder' => 'EASTERN SUN DISTRIBUCIONES',          'type' => 'srl'],
        ['name' => 'ORIENT SUNRISE HOLDINGS',             'folder' => 'ORIENT SUNRISE HOLDINGS',             'type' => 'sa'],
        // COMPLETED — codes 000057-000060
        ['name' => 'PIONEER EAST EMPRESARIAL',            'folder' => 'PIONEER EAST EMPRESARIAL',            'type' => 'sa'],
        ['name' => 'GREATWALL SOLUTIONS MEXICO',          'folder' => 'GREATWALL SOLUTIONS MEXICO',          'type' => 'srl'],
        ['name' => 'ORIENT PROSPERITY GROUP',             'folder' => 'ORIENT PROSPERITY GROUP',             'type' => 'sa'],
        ['name' => 'CHINA BRIDGE CONSULTING',             'folder' => 'CHINA BRIDGE CONSULTING',             'type' => 'sapi'],
    ];

    /**
     * Chinese name pool for shareholders.
     * Names are stored as they arrive from the relay (Chinese characters).
     *
     * @var list<string>
     */
    private const CHINESE_NAMES = [
        '王伟',   '李芳',   '张磊',   '刘晶',   '陈明',
        '杨静',   '黄强',   '赵丽',   '吴佳鑫', '周雷',
        '徐倩',   '孙浩',   '马丽娜', '朱伟东', '胡晓燕',
        '何建国', '罗晨',   '宋玉',   '高晓明', '郑博',
        '林思雨', '谢小龙', '唐翠',   '邓振宇', '许可',
        '冯军',   '韩梅',   '曾志伟', '彭丽娟', '蔡明',
        '董海涛', '叶云',   '肖凡',   '程旭',   '袁媛',
        '顾奇',   '江涵',   '史伟',   '侯震',   '邵婷',
        '熊飞',   '孟阳',   '秦峰',   '沈爱玲', '贺斌',
        '莫阳',   '薛华',   '文军',   '傅元',   '邹桦',
        '贾思思', '裴晓',   '崔伟',   '康俊',   '钱博',
        '石汉',   '廖辉',   '雷鸣',   '方红',   '戴涵',
        '丁云',   '卢峰',   '魏敏',   '田浩',   '苏丽',
        '潘军',   '黎静',   '余超',   '钟晶',   '涂志伟',
    ];

    /**
     * Chinese passport numbers (format: letter + 8 digits, standard PRC).
     *
     * @var list<string>
     */
    private const PASSPORT_NUMBERS = [
        'E12345678', 'G87654321', 'E23456789', 'G76543210',
        'E34567890', 'G65432109', 'E45678901', 'G54321098',
        'E56789012', 'G43210987', 'E67890123', 'G32109876',
        'E78901234', 'G21098765', 'E89012345', 'G10987654',
        'E90123456', 'G09876543', 'E01234567', 'G98765432',
        'E11223344', 'G44332211', 'E55667788', 'G88776655',
        'E99001122', 'G22110099', 'E33445566', 'G66554433',
        'E77889900', 'G00998877', 'E12312312', 'G32132132',
        'E45645645', 'G54654654', 'E78978978', 'G98798798',
        'E21021021', 'G10110110', 'E65065065', 'G56056056',
        'E13579246', 'G24680135', 'E97531864', 'G86420975',
        'E14725836', 'G85296147', 'E36914725', 'G47025836',
        'E58103692', 'G69214703', 'E70325814', 'G81436925',
        'E92547036', 'G03658147', 'E15926374', 'G26037485',
        'E37148596', 'G48259607', 'E59360718', 'G60471829',
        'E71582930', 'G82693041', 'E93704152', 'G04815263',
        'E16937485', 'G27048596', 'E38159607', 'G49260718',
        'E50371829', 'G61482930', 'E72593041', 'G83604152',
    ];

    /**
     * Ordered stage values — mirrors RegistrationStageEnum::orderedStages().
     *
     * @var list<string>
     */
    private const STAGE_ORDER = [
        'data_received',
        'identity_validation',
        'legal_name',
        'acta_preparation',
        'partner_signature',
        'incorporation',
        'tax_address',
        'sat_registration',
        'efirma_appointment',
        'completed',
    ];

    /**
     * Participation split options (legal_rep%, shareholder%).
     *
     * @var list<array{0: float, 1: float}>
     */
    private const PARTICIPATION_SPLITS = [
        [70.00, 30.00],
        [50.00, 50.00],
        [60.00, 40.00],
        [80.00, 20.00],
        [55.00, 45.00],
        [65.00, 35.00],
    ];

    /**
     * Pending task templates keyed by stage value.
     *
     * @var array<string, list<array{title: string, priority: TaskPriorityEnum}>>
     */
    private const TASKS_BY_STAGE = [
        'data_received' => [
            ['title' => 'Revisar documentos KYC recibidos del relay', 'priority' => TaskPriorityEnum::HIGH],
            ['title' => 'Contactar al cliente para confirmar recepción del expediente', 'priority' => TaskPriorityEnum::MEDIUM],
        ],
        'identity_validation' => [
            ['title' => 'Verificar autenticidad del pasaporte del representante legal', 'priority' => TaskPriorityEnum::HIGH],
            ['title' => 'Validar visa vigente del accionista principal', 'priority' => TaskPriorityEnum::MEDIUM],
        ],
        'legal_name' => [
            ['title' => 'Enviar denominación social a la SE para dictamen', 'priority' => TaskPriorityEnum::HIGH],
            ['title' => 'Notificar resultado del dictamen al cliente', 'priority' => TaskPriorityEnum::MEDIUM],
        ],
        'acta_preparation' => [
            ['title' => 'Revisar borrador del acta constitutiva generado automáticamente', 'priority' => TaskPriorityEnum::HIGH],
            ['title' => 'Verificar RFC/CURP y datos fiscales de los socios', 'priority' => TaskPriorityEnum::MEDIUM],
        ],
        'incorporation' => [
            ['title' => 'Revisar borrador del acta constitutiva con el notario', 'priority' => TaskPriorityEnum::HIGH],
            ['title' => 'Coordinar firma del acta con el representante legal', 'priority' => TaskPriorityEnum::HIGH],
        ],
        'partner_signature' => [
            ['title' => 'Enviar documentos a socios para firma electrónica vía DocuSign', 'priority' => TaskPriorityEnum::HIGH],
            ['title' => 'Verificar que todos los socios hayan firmado correctamente', 'priority' => TaskPriorityEnum::HIGH],
        ],
        'tax_address' => [
            ['title' => 'Registrar domicilio fiscal de la empresa ante el SAT', 'priority' => TaskPriorityEnum::HIGH],
            ['title' => 'Obtener comprobante de domicilio fiscal actualizado', 'priority' => TaskPriorityEnum::MEDIUM],
        ],
        'sat_registration' => [
            ['title' => 'Tramitar RFC ante el SAT', 'priority' => TaskPriorityEnum::HIGH],
            ['title' => 'Obtener Constancia de Situación Fiscal actualizada', 'priority' => TaskPriorityEnum::MEDIUM],
        ],
        'efirma_appointment' => [
            ['title' => 'Confirmar asistencia del cliente a cita e.firma SAT', 'priority' => TaskPriorityEnum::HIGH],
            ['title' => 'Enviar recordatorio con documentos requeridos para cita SAT', 'priority' => TaskPriorityEnum::MEDIUM],
        ],
    ];

    /**
     * Internal note templates covering the full workflow lifecycle.
     *
     * @var list<string>
     */
    private const NOTE_TEMPLATES = [
        'Documentación inicial revisada. Se contactó al cliente para confirmar el avance.',
        'Se solicitaron documentos adicionales. Pendiente de respuesta del representante legal.',
        'Cliente confirmó que enviará información faltante antes del viernes.',
        'Denominación social enviada a la SE. En espera de dictamen.',
        'Se realizó llamada de seguimiento con el representante legal. Proceso en curso.',
        'Documentos apostillados y legalizados recibidos correctamente.',
        'Pendiente confirmación de apertura bancaria. Cliente en trámite con HSBC.',
        'RFC tramitado satisfactoriamente ante el SAT. Se notificó al cliente.',
        'Cita e.firma agendada para la próxima semana. Recordatorio enviado.',
        'Acta constitutiva revisada y firmada por todas las partes. Proceso concluido.',
    ];

    /**
     * Reasons recorded when a stage transition is performed.
     *
     * @var list<string>
     */
    private const TRANSITION_REASONS = [
        'Documentación revisada y validada. Expediente avanzado a la siguiente etapa.',
        'Proceso completado satisfactoriamente. Continuando.',
        'Revisión aprobada. Notificación enviada al cliente.',
        'Etapa completada. Expediente listo para la siguiente fase.',
    ];

    // -------------------------------------------------------------------------
    // Entry point
    // -------------------------------------------------------------------------

    /**
     * Seed 5 team users and 16 Chinese company registrations across all 10 stages.
     */
    public function run(): void
    {
        $this->createUsers();

        // Distribution: list of [stage, count] pairs. Enum cases cannot be array keys
        // in PHP (they are objects), so we use indexed tuples instead.
        // Total: 16 expedients — one or two per stage for a representative demo set.
        $distribution = [
            [RegistrationStageEnum::DATA_RECEIVED,        3],
            [RegistrationStageEnum::IDENTITY_VALIDATION,  2],
            [RegistrationStageEnum::LEGAL_NAME,           2],
            [RegistrationStageEnum::ACTA_PREPARATION,     1],
            [RegistrationStageEnum::PARTNER_SIGNATURE,    1],
            [RegistrationStageEnum::INCORPORATION,        2],
            [RegistrationStageEnum::TAX_ADDRESS,          1],
            [RegistrationStageEnum::SAT_REGISTRATION,     1],
            [RegistrationStageEnum::EFIRMA_APPOINTMENT,   2],
            [RegistrationStageEnum::COMPLETED,            1],
        ];

        // Codes (1-based int) that receive a non-ACTIVE status.
        $onHoldCodes = [5, 12];
        $cancelledCodes = [3];

        $companyIndex = 0;
        $codeInt = 1;

        foreach ($distribution as [$stage, $count]) {
            for ($i = 0; $i < $count; $i++) {
                $clientCode = str_pad((string) $codeInt, 6, '0', STR_PAD_LEFT);

                $status = match (true) {
                    in_array($codeInt, $cancelledCodes, true) => RegistrationStatusEnum::CANCELLED,
                    in_array($codeInt, $onHoldCodes, true) => RegistrationStatusEnum::ON_HOLD,
                    $stage === RegistrationStageEnum::COMPLETED => RegistrationStatusEnum::COMPLETED,
                    default => RegistrationStatusEnum::ACTIVE,
                };

                $this->createCompany(
                    clientCode: $clientCode,
                    company: self::COMPANIES[$companyIndex],
                    stage: $stage,
                    status: $status,
                );

                $companyIndex++;
                $codeInt++;
            }
        }
    }

    // -------------------------------------------------------------------------
    // Users
    // -------------------------------------------------------------------------

    /**
     * Create or find the 5 dashboard team members and assign their roles.
     */
    private function createUsers(): void
    {
        $this->admin = User::firstOrCreate(
            ['email' => 'admin@nexum.mx'],
            [
                'name' => 'Administrador del Sistema',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ],
        );
        $this->admin->syncRoles(['super_admin']);

        $this->notario1 = User::firstOrCreate(
            ['email' => 'notario.garcia@nexum.mx'],
            [
                'name' => 'Lic. María García Reyes',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ],
        );
        $this->notario1->syncRoles(['notario']);

        $this->notario2 = User::firstOrCreate(
            ['email' => 'notario.lopez@nexum.mx'],
            [
                'name' => 'Lic. Carlos López Hernández',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ],
        );
        $this->notario2->syncRoles(['notario']);

        $this->asistente1 = User::firstOrCreate(
            ['email' => 'asistente.torres@nexum.mx'],
            [
                'name' => 'Ana Torres Méndez',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ],
        );
        $this->asistente1->syncRoles(['asistente_notario']);

        $this->asistente2 = User::firstOrCreate(
            ['email' => 'asistente.ramirez@nexum.mx'],
            [
                'name' => 'Roberto Ramírez Jiménez',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ],
        );
        $this->asistente2->syncRoles(['asistente_notario']);
    }

    // -------------------------------------------------------------------------
    // Company creation orchestrator
    // -------------------------------------------------------------------------

    /**
     * Create a single registration expedient with all related records.
     *
     * @param  string  $clientCode  Six-digit zero-padded code (e.g. '000001').
     * @param  array{name: string, folder: string, type: string}  $company  Company data.
     * @param  RegistrationStageEnum  $stage  Current stage of the expedient.
     * @param  RegistrationStatusEnum  $status  Operational status.
     */
    private function createCompany(
        string $clientCode,
        array $company,
        RegistrationStageEnum $stage,
        RegistrationStatusEnum $status,
    ): void {
        $codeInt = (int) $clientCode;

        // Alternate notario/asistente assignment between even and odd codes.
        $notario = ($codeInt % 2 === 0) ? $this->notario1 : $this->notario2;
        $asistente = ($codeInt % 2 === 0) ? $this->asistente1 : $this->asistente2;

        $companyTypeMap = ['sa' => 'SA de CV', 'srl' => 'SRL de CV', 'sapi' => 'SAPI de CV'];
        $companyType = $companyTypeMap[$company['type']];

        $arrivedDaysAgo = $this->estimateArrivalDays($stage);
        $stageIndex = $this->stageIndex($stage);
        $satIndex = $this->stageIndex(RegistrationStageEnum::SAT_REGISTRATION);

        // RFC is only available after SAT_REGISTRATION.
        $rfc = ($stageIndex >= $satIndex) ? $this->generateRfc($company['name']) : null;

        // e.firma fields only for EFIRMA_APPOINTMENT stage.
        $efirmaStatus = null;
        $efirmaAppointment = null;

        if ($stage === RegistrationStageEnum::EFIRMA_APPOINTMENT) {
            $efirmaStatuses = EfirmaAppointmentStatusEnum::cases();
            $efirmaStatus = $efirmaStatuses[$codeInt % count($efirmaStatuses)];
            $efirmaAppointment = now()->addDays(rand(3, 21));
        }

        $completedAt = ($stage === RegistrationStageEnum::COMPLETED)
            ? now()->subDays(rand(5, 60))
            : null;

        $companyObjects = [
            'Importación, exportación, distribución y comercialización de productos tecnológicos y electrónicos.',
            'Prestación de servicios de consultoría empresarial, asesoría financiera y gestión de proyectos.',
            'Desarrollo, fabricación y comercialización de productos industriales y manufacturas.',
            'Importación y distribución de materias primas, productos químicos y materiales de construcción.',
            'Prestación de servicios de logística, almacenamiento y distribución de mercancías.',
        ];

        $registration = Registration::create([
            'singapur_client_code' => $clientCode,
            'singapur_package_id' => fake()->uuid(),
            'singapur_folder_name' => "{$clientCode}_{$company['folder']}",
            'stage' => $stage,
            'status' => $status,
            'assigned_notario_id' => $notario->id,
            'assigned_asistente_id' => $asistente->id,
            'company_type' => $companyType,
            'company_object' => $companyObjects[$codeInt % count($companyObjects)],
            'capital_social' => [50000, 100000, 200000, 500000][$codeInt % 4],
            'rfc' => $rfc,
            'efirma_status' => $efirmaStatus,
            'efirma_appointment_at' => $efirmaAppointment,
            'completed_at' => $completedAt,
            'created_at' => now()->subDays($arrivedDaysAgo),
            'updated_at' => now()->subDays(max(0, $arrivedDaysAgo - 1)),
        ]);

        $shareholderNames = $this->pickShareholderNames($codeInt);
        $isMarriedFlags = $this->createShareholders($registration, $shareholderNames, $codeInt);

        $this->createLegalNames($registration, $company['name'], $stage, $stageIndex);
        $this->createDocuments($registration, $clientCode, $shareholderNames, $isMarriedFlags, $stageIndex, $notario);
        $this->createStageTransitions($registration, $stage, $stageIndex, $notario, $arrivedDaysAgo);

        if ($status === RegistrationStatusEnum::ACTIVE && $stage !== RegistrationStageEnum::COMPLETED) {
            $this->createTask($registration, $stage, $notario);
        }

        // Add a note for roughly two-thirds of the companies.
        if ($codeInt % 3 !== 0) {
            $this->createNote($registration, $codeInt);
        }
    }

    // -------------------------------------------------------------------------
    // Legal names
    // -------------------------------------------------------------------------

    /**
     * Create the primary (and possibly alternate) legal denominations for the company.
     *
     * The primary denomination status depends on how far the expedient has progressed:
     * - Before LEGAL_NAME stage → WAIT (not yet submitted).
     * - At LEGAL_NAME stage     → PROCESS (sent to SE, awaiting dictamen).
     * - Past LEGAL_NAME stage   → APPROVED (dictamen resolved before incorporation).
     *
     * @param  string  $companyName  Base denomination (without type suffix).
     * @param  int  $stageIndex  Numeric position in STAGE_ORDER.
     */
    private function createLegalNames(
        Registration $registration,
        string $companyName,
        RegistrationStageEnum $stage,
        int $stageIndex,
    ): void {
        $legalNameIdx = $this->stageIndex(RegistrationStageEnum::LEGAL_NAME);

        $primaryStatus = match (true) {
            $stageIndex < $legalNameIdx => LegalNameStatusEnum::WAIT,
            $stageIndex === $legalNameIdx => LegalNameStatusEnum::PROCESS,
            default => LegalNameStatusEnum::APPROVED,
        };

        $clave = null;
        $authorizationTimestamp = null;
        $submittedAt = null;

        if ($primaryStatus === LegalNameStatusEnum::APPROVED) {
            $prefix = strtoupper(substr(preg_replace('/[^A-Z]/i', '', $companyName), 0, 3));
            $clave = $prefix.'-'.fake()->numerify('######');
            $authorizationTimestamp = now()->subDays(rand(5, 40));
            $submittedAt = $authorizationTimestamp->copy()->subDays(rand(5, 15));
        } elseif ($primaryStatus === LegalNameStatusEnum::PROCESS) {
            $submittedAt = now()->subDays(rand(3, 10));
        }

        LegalName::create([
            'registration_id' => $registration->id,
            'name' => $companyName,
            'priority' => 1,
            'status' => $primaryStatus,
            'clave_unica_denominacion' => $clave,
            'authorization_timestamp' => $authorizationTimestamp,
            'submitted_at' => $submittedAt,
        ]);

        // Companies at LEGAL_NAME stage often have 1-2 alternate denominations as backup.
        if ($stage === RegistrationStageEnum::LEGAL_NAME) {
            $alternates = [' INTERNACIONAL', ' GLOBAL'];
            $count = rand(1, 2);

            foreach (array_slice($alternates, 0, $count) as $i => $suffix) {
                LegalName::create([
                    'registration_id' => $registration->id,
                    'name' => $companyName.$suffix,
                    'priority' => $i + 2,
                    'status' => LegalNameStatusEnum::WAIT,
                ]);
            }
        }
    }

    // -------------------------------------------------------------------------
    // Shareholders
    // -------------------------------------------------------------------------

    /**
     * Create two shareholders for the registration: legal representative and a socio.
     *
     * The legal representative (index 1) is always married.
     * The second shareholder varies deterministically:
     *   - codeInt % 4 === 0 → not married (only 2 KYC docs expected, no spousal docs).
     *   - codeInt % 7 === 0 → married but spousal docs intentionally omitted in seeder
     *                         to trigger the missing-document warning in the dashboard.
     *   - all others        → married, full 4 KYC docs.
     *
     * @param  list<string>  $names  Two Chinese names from CHINESE_NAMES pool.
     * @param  int  $codeInt  Numeric company code used to deterministically vary splits.
     * @return list<bool> is_married flags for [shareholder1, shareholder2].
     */
    private function createShareholders(Registration $registration, array $names, int $codeInt): array
    {
        $split = self::PARTICIPATION_SPLITS[$codeInt % count(self::PARTICIPATION_SPLITS)];
        $passportBase = $codeInt % count(self::PASSPORT_NUMBERS);

        // Legal representative is always married.
        $sh1Married = true;

        // Second shareholder varies to produce different demo scenarios.
        $sh2Married = match (true) {
            $codeInt % 4 === 0 => false,    // not married → 2 KYC docs, no warning
            default => true,     // married → 4 KYC docs (or missing, see createDocuments)
        };

        // Fake birth years spread across a realistic range (30–60 years old).
        $birthYear1 = 2026 - (30 + ($codeInt % 30));
        $birthYear2 = 2026 - (30 + (($codeInt + 7) % 30));

        $genders = ['M', 'F'];

        Shareholder::create([
            'registration_id' => $registration->id,
            'name' => $names[0],
            'nationality' => 'china',
            'passport_number' => self::PASSPORT_NUMBERS[$passportBase],
            'participation_percentage' => $split[0],
            'role' => ShareholderRoleEnum::LEGAL_REPRESENTATIVE,
            'email' => "user{$codeInt}a@qq.com",
            'phone' => '138'.str_pad($codeInt * 7, 8, '0', STR_PAD_LEFT),
            'phone_country_code' => '+86',
            'is_married' => $sh1Married,
            'gender' => $genders[$codeInt % 2],
            'birthdate' => "{$birthYear1}-".str_pad(($codeInt % 12) + 1, 2, '0', STR_PAD_LEFT).'-15',
            'birthplace' => 'Beijing, China',
            'civil_status' => $sh1Married ? 'casado' : 'soltero',
        ]);

        Shareholder::create([
            'registration_id' => $registration->id,
            'name' => $names[1],
            'nationality' => 'china',
            'passport_number' => self::PASSPORT_NUMBERS[($passportBase + 1) % count(self::PASSPORT_NUMBERS)],
            'participation_percentage' => $split[1],
            'role' => ShareholderRoleEnum::SHAREHOLDER,
            'email' => "user{$codeInt}b@163.com",
            'phone' => '139'.str_pad($codeInt * 13, 8, '0', STR_PAD_LEFT),
            'phone_country_code' => '+86',
            'is_married' => $sh2Married,
            'gender' => $genders[($codeInt + 1) % 2],
            'birthdate' => "{$birthYear2}-".str_pad((($codeInt + 3) % 12) + 1, 2, '0', STR_PAD_LEFT).'-20',
            'birthplace' => 'Shanghai, China',
            'civil_status' => $sh2Married ? 'casado' : 'soltero',
        ]);

        return [$sh1Married, $sh2Married];
    }

    // -------------------------------------------------------------------------
    // Documents
    // -------------------------------------------------------------------------

    /**
     * Create documents for the expedient and upload placeholder files to MinIO.
     *
     * Respects the is_married flag for each shareholder: married shareholders
     * get 4 KYC docs (tax cert, proof of address, marriage cert, spouse passport),
     * while unmarried shareholders only get 2 (tax cert, proof of address).
     * This mirrors what China actually sends, so the missing-document detection
     * in Registration::missingKycDocuments() behaves correctly in the demo.
     *
     * One exception: for companies where codeInt % 7 === 0, the second shareholder's
     * spousal documents are intentionally omitted even though is_married = true. This
     * seeds the ⚠️ missing-document warning for demo purposes.
     *
     * Documents added per stage milestone:
     * - DATA_RECEIVED (always)    : 2 or 4 KYC docs per shareholder (see above).
     * - ACTA_PREPARATION (index 3): compiled acta draft (template_data JSON).
     * - INCORPORATION (index 5+)  : signed acta constitutiva.
     * - SAT_REGISTRATION (index 7+): RFC constancia from SAT.
     * - COMPLETED (index 9)       : e.firma certificate (.cer).
     *
     * @param  list<string>  $shareholderNames  Two Chinese names used in file labels.
     * @param  list<bool>  $isMarriedFlags  Married status for each shareholder by index.
     * @param  int  $stageIndex  Numeric position in STAGE_ORDER.
     * @param  User  $uploader  Notario credited as the uploader for manual documents.
     */
    private function createDocuments(
        Registration $registration,
        string $clientCode,
        array $shareholderNames,
        array $isMarriedFlags,
        int $stageIndex,
        User $uploader,
    ): void {
        $identityIdx = $this->stageIndex(RegistrationStageEnum::IDENTITY_VALIDATION);
        $actaIdx = $this->stageIndex(RegistrationStageEnum::ACTA_PREPARATION);
        $incorporationIdx = $this->stageIndex(RegistrationStageEnum::INCORPORATION);
        $satIdx = $this->stageIndex(RegistrationStageEnum::SAT_REGISTRATION);
        $completedIdx = $this->stageIndex(RegistrationStageEnum::COMPLETED);

        $codeInt = (int) $clientCode;

        // Helper: documents past the identity stage are verified.
        $isVerifiedByNow = fn (int $fromIndex): bool => $stageIndex > $fromIndex;

        // Helper: returns evaluation state for a document.
        // A small deterministic subset of KYC docs are marked rejected to seed
        // the rejection flow for demo and testing purposes.
        // Pattern: every 5th document of the 1st shareholder in identity+ stages is rejected.
        $evalState = function (int $sharIdx, DocumentTypeEnum $type) use ($codeInt, $stageIndex, $identityIdx, $uploader): array {
            // Only apply rejection to expedients that are past identity_validation.
            if ($stageIndex <= $identityIdx) {
                return ['verified_at' => null, 'verified_by' => null, 'rejected_at' => null, 'rejected_by' => null, 'rejection_reason' => null];
            }

            // Deterministically reject one document per shareholder in every 5th expedient.
            $shouldReject = ($codeInt % 5 === 0) && $sharIdx === 1 && $type === DocumentTypeEnum::KYC_PROOF_OF_ADDRESS;

            if ($shouldReject) {
                $reasons = [
                    'El documento está ilegible. Se requiere una copia con mayor resolución.',
                    'La dirección del comprobante no coincide con la declarada por el cliente.',
                    'El comprobante tiene más de 3 meses de antigüedad. Se requiere uno reciente.',
                ];

                return [
                    'verified_at' => null,
                    'verified_by' => null,
                    'rejected_at' => now()->subDays(rand(1, 10)),
                    'rejected_by' => $uploader->id,
                    'rejection_reason' => $reasons[$codeInt % count($reasons)],
                ];
            }

            // Remaining docs past identity stage are approved.
            return [
                'verified_at' => now()->subDays(rand(1, 15)),
                'verified_by' => $uploader->id,
                'rejected_at' => null,
                'rejected_by' => null,
                'rejection_reason' => null,
            ];
        };

        // -----------------------------------------------------------------------
        // KYC documents — arrive all together in the initial package from China.
        // Married shareholders send 4 docs; unmarried send only 2.
        // codeInt % 7 === 0 → second shareholder's spousal docs intentionally
        // omitted (married but docs missing) to seed the ⚠️ warning in the demo.
        // -----------------------------------------------------------------------
        foreach ([1, 2] as $idx) {
            $name = $shareholderNames[$idx - 1];
            $isMarried = $isMarriedFlags[$idx - 1];

            // Omit spousal docs for the "missing docs" demo scenario.
            $omitSpousalDocs = ($codeInt % 7 === 0) && $idx === 2;

            // naturalTaxCertificate{N} — Chinese National ID card (arrives as photo/scan → JPEG).
            $field = "naturalTaxCertificate{$idx}";
            $relayName = "{$clientCode}__{$field}__tax_id.jpg";
            $path = "documents/{$registration->id}/{$field}_{$relayName}";
            Storage::put($path, $this->fakeIdCardImage('ID Fiscal chino (Tax Certificate)', "{$name} — {$clientCode}"));
            Document::create(array_merge([
                'registration_id' => $registration->id,
                'type' => DocumentTypeEnum::KYC_TAX_CERTIFICATE,
                'name' => $relayName,
                'storage_path' => $path,
                'stage' => RegistrationStageEnum::DATA_RECEIVED,
                'shareholder_index' => $idx,
                'uploaded_by' => null,
            ], $evalState($idx, DocumentTypeEnum::KYC_TAX_CERTIFICATE)));

            // naturalProofAddress{N} — Chinese proof of address (PDF).
            $field = "naturalProofAddress{$idx}";
            $relayName = "{$clientCode}__{$field}__proof_address.pdf";
            $path = "documents/{$registration->id}/{$field}_{$relayName}";
            Storage::put($path, $this->fakePdfContent('Comprobante de domicilio (China)', "{$name} — {$clientCode}"));
            Document::create(array_merge([
                'registration_id' => $registration->id,
                'type' => DocumentTypeEnum::KYC_PROOF_OF_ADDRESS,
                'name' => $relayName,
                'storage_path' => $path,
                'stage' => RegistrationStageEnum::DATA_RECEIVED,
                'shareholder_index' => $idx,
                'uploaded_by' => null,
            ], $evalState($idx, DocumentTypeEnum::KYC_PROOF_OF_ADDRESS)));

            // Spousal docs — only for married shareholders and not in the omit scenario.
            if ($isMarried && ! $omitSpousalDocs) {
                // naturalMarriageCertificate{N} — Chinese marriage certificate (PDF).
                $field = "naturalMarriageCertificate{$idx}";
                $relayName = "{$clientCode}__{$field}__marriage_cert.pdf";
                $path = "documents/{$registration->id}/{$field}_{$relayName}";
                Storage::put($path, $this->fakePdfContent('Acta de matrimonio (China)', "{$name} — {$clientCode}"));
                Document::create(array_merge([
                    'registration_id' => $registration->id,
                    'type' => DocumentTypeEnum::KYC_MARRIAGE_CERTIFICATE,
                    'name' => $relayName,
                    'storage_path' => $path,
                    'stage' => RegistrationStageEnum::DATA_RECEIVED,
                    'shareholder_index' => $idx,
                    'uploaded_by' => null,
                ], $evalState($idx, DocumentTypeEnum::KYC_MARRIAGE_CERTIFICATE)));

                // naturalSpousePassport{N} — Spouse passport data page (JPEG).
                $field = "naturalSpousePassport{$idx}";
                $relayName = "{$clientCode}__{$field}__spouse_passport.jpg";
                $path = "documents/{$registration->id}/{$field}_{$relayName}";
                Storage::put($path, $this->fakePassportImage('Pasaporte del cónyuge', "{$name} (cónyuge) — {$clientCode}"));
                Document::create(array_merge([
                    'registration_id' => $registration->id,
                    'type' => DocumentTypeEnum::KYC_SPOUSE_PASSPORT,
                    'name' => $relayName,
                    'storage_path' => $path,
                    'stage' => RegistrationStageEnum::DATA_RECEIVED,
                    'shareholder_index' => $idx,
                    'uploaded_by' => null,
                ], $evalState($idx, DocumentTypeEnum::KYC_SPOUSE_PASSPORT)));
            }
        }

        // ACTA_PREPARATION+: compiled acta draft (template_data JSON, no physical file).
        if ($stageIndex >= $actaIdx) {
            Document::create([
                'registration_id' => $registration->id,
                'type' => DocumentTypeEnum::ACTA_DRAFT,
                'name' => "Borrador_Acta_{$clientCode}.json",
                'storage_path' => null,
                'stage' => RegistrationStageEnum::ACTA_PREPARATION,
                'shareholder_index' => null,
                'uploaded_by' => $uploader->id,
                'verified_at' => null,
                'template_data' => [
                    'autorizacion_denominacion' => strtoupper($registration->legalNames()->where('priority', 1)->value('name') ?? 'PENDIENTE DENOMINACIÓN'),
                    'company_type' => $registration->company_type ?? 'SA de CV',
                    'capital_social' => (float) ($registration->capital_social ?? 50000.00),
                    'domicilio_social' => 'la Ciudad de México',
                    'comisario' => 'JACOB ZAZUETA FRAUSTO',
                    'comisario_rfc' => 'ZAFJ890626DI0',
                    'comisario_extranjero' => false,
                    'numero_socios' => 2,
                    'compiled_at' => now()->subDays(rand(1, 3))->toIso8601String(),
                    'compiled_by_service' => 'App\\Services\\Registration\\ActaPreparationService',
                    'registration_id' => $registration->id,
                    'singapur_client_code' => $clientCode,
                    '_seeder_placeholder' => true,
                ],
            ]);
        }

        // INCORPORATION+: signed acta constitutiva.
        if ($stageIndex >= $incorporationIdx) {
            $filename = "Acta_Constitutiva_{$clientCode}.pdf";
            $path = "documents/{$registration->id}/{$filename}";

            Storage::put($path, $this->fakePdfContent(
                'Acta Constitutiva',
                "Expediente {$clientCode}",
            ));

            Document::create([
                'registration_id' => $registration->id,
                'type' => DocumentTypeEnum::INCORPORATION_ACT,
                'name' => $filename,
                'storage_path' => $path,
                'stage' => RegistrationStageEnum::INCORPORATION,
                'uploaded_by' => $uploader->id,
                'verified_at' => $isVerifiedByNow($incorporationIdx) ? now()->subDays(rand(1, 25)) : null,
                'verified_by' => $isVerifiedByNow($incorporationIdx) ? $uploader->id : null,
            ]);
        }

        // SAT_REGISTRATION+: RFC constancia from SAT.
        if ($stageIndex >= $satIdx) {
            $filename = "RFC_{$clientCode}.pdf";
            $path = "documents/{$registration->id}/{$filename}";

            Storage::put($path, $this->fakePdfContent(
                'Constancia de RFC (SAT)',
                "Expediente {$clientCode}",
            ));

            Document::create([
                'registration_id' => $registration->id,
                'type' => DocumentTypeEnum::RFC_DOCUMENT,
                'name' => $filename,
                'storage_path' => $path,
                'stage' => RegistrationStageEnum::SAT_REGISTRATION,
                'uploaded_by' => $uploader->id,
                'verified_at' => $isVerifiedByNow($satIdx) ? now()->subDays(rand(1, 15)) : null,
                'verified_by' => $isVerifiedByNow($satIdx) ? $uploader->id : null,
            ]);
        }

        // COMPLETED: e.firma certificate (.cer).
        if ($stageIndex >= $completedIdx) {
            $filename = "Efirma_{$clientCode}.cer";
            $path = "documents/{$registration->id}/{$filename}";

            Storage::put($path, $this->fakePdfContent(
                'Certificado e.firma (.cer)',
                "Expediente {$clientCode}",
            ));

            Document::create([
                'registration_id' => $registration->id,
                'type' => DocumentTypeEnum::EFIRMA,
                'name' => $filename,
                'storage_path' => $path,
                'stage' => RegistrationStageEnum::EFIRMA_APPOINTMENT,
                'uploaded_by' => $uploader->id,
                'verified_at' => now()->subDays(rand(1, 10)),
                'verified_by' => $uploader->id,
            ]);
        }
    }

    // -------------------------------------------------------------------------
    // Stage transitions (audit trail)
    // -------------------------------------------------------------------------

    /**
     * Create an immutable audit trail of stage transitions up to the current stage.
     *
     * The first transition (null → DATA_RECEIVED) is system-generated and has no performer.
     * All subsequent transitions are credited to the assigned notario, spaced out in time.
     *
     * @param  int  $currentStageIndex  Position of currentStage in STAGE_ORDER.
     * @param  User  $notario  Assigned notario for performed_by.
     * @param  int  $arrivedDaysAgo  How many days ago the webhook arrived.
     */
    private function createStageTransitions(
        Registration $registration,
        RegistrationStageEnum $currentStage,
        int $currentStageIndex,
        User $notario,
        int $arrivedDaysAgo,
    ): void {
        $orderedStages = RegistrationStageEnum::orderedStages();

        // First entry: webhook arrival — system-generated, no performer.
        StageTransition::create([
            'registration_id' => $registration->id,
            'from_stage' => null,
            'to_stage' => RegistrationStageEnum::DATA_RECEIVED,
            'performed_by' => null,
            'reason' => 'Expediente recibido vía webhook del relay Singapur.',
            'created_at' => now()->subDays($arrivedDaysAgo),
        ]);

        // Subsequent advances — one per stage step, evenly spaced over the elapsed time.
        for ($i = 0; $i < $currentStageIndex; $i++) {
            $elapsed = $arrivedDaysAgo - max(0, $arrivedDaysAgo - (int) round($arrivedDaysAgo * ($i + 1) / $currentStageIndex));
            $daysAgo = max(1, $arrivedDaysAgo - $elapsed);
            $reasonIdx = $i % count(self::TRANSITION_REASONS);

            StageTransition::create([
                'registration_id' => $registration->id,
                'from_stage' => $orderedStages[$i],
                'to_stage' => $orderedStages[$i + 1],
                'performed_by' => $notario->id,
                'reason' => self::TRANSITION_REASONS[$reasonIdx],
                'created_at' => now()->subDays($daysAgo),
            ]);
        }
    }

    // -------------------------------------------------------------------------
    // Tasks and notes
    // -------------------------------------------------------------------------

    /**
     * Create one pending task appropriate for the current stage.
     *
     * @param  User  $assignee  Notario responsible for this task.
     */
    private function createTask(Registration $registration, RegistrationStageEnum $stage, User $assignee): void
    {
        $pool = self::TASKS_BY_STAGE[$stage->value] ?? [];

        if (empty($pool)) {
            return;
        }

        $taskData = $pool[array_rand($pool)];

        Task::create([
            'registration_id' => $registration->id,
            'title' => $taskData['title'],
            'description' => null,
            'priority' => $taskData['priority'],
            'type' => TaskTypeEnum::MANUAL,
            'assigned_to' => $assignee->id,
            'created_by' => $this->admin->id,
            'due_date' => now()->addDays(rand(3, 14)),
        ]);
    }

    /**
     * Create one internal note for the registration.
     *
     * @param  int  $codeInt  Numeric company code, used to select content deterministically.
     */
    private function createNote(Registration $registration, int $codeInt): void
    {
        $noteContent = self::NOTE_TEMPLATES[$codeInt % count(self::NOTE_TEMPLATES)];
        $author = ($codeInt % 2 === 0) ? $this->notario1 : $this->notario2;

        Note::create([
            'registration_id' => $registration->id,
            'content' => $noteContent,
            'created_by' => $author->id,
            'is_pinned' => ($codeInt % 7 === 0),
        ]);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Return the numeric index of a stage within STAGE_ORDER.
     */
    private function stageIndex(RegistrationStageEnum $stage): int
    {
        return (int) array_search($stage->value, self::STAGE_ORDER, true);
    }

    /**
     * Estimate how many calendar days ago the relay webhook arrived for a given stage.
     *
     * More advanced stages imply older arrival dates, giving a realistic timeline.
     */
    private function estimateArrivalDays(RegistrationStageEnum $stage): int
    {
        return match ($stage) {
            RegistrationStageEnum::DATA_RECEIVED => rand(1, 10),
            RegistrationStageEnum::IDENTITY_VALIDATION => rand(10, 25),
            RegistrationStageEnum::LEGAL_NAME => rand(25, 45),
            RegistrationStageEnum::ACTA_PREPARATION => rand(45, 60),
            RegistrationStageEnum::PARTNER_SIGNATURE => rand(60, 75),
            RegistrationStageEnum::INCORPORATION => rand(75, 100),
            RegistrationStageEnum::TAX_ADDRESS => rand(100, 125),
            RegistrationStageEnum::SAT_REGISTRATION => rand(125, 160),
            RegistrationStageEnum::EFIRMA_APPOINTMENT => rand(160, 185),
            RegistrationStageEnum::COMPLETED => rand(185, 225),
        };
    }

    /**
     * Generate a realistic Mexican company RFC based on the company name.
     *
     * Format: 3-letter prefix + 6-digit date + 3 alphanumeric homoclave.
     *
     * @param  string  $companyName  Base denomination for deriving the prefix.
     */
    private function generateRfc(string $companyName): string
    {
        $letters = preg_replace('/[^A-Za-z]/u', '', $companyName);
        $prefix = strtoupper(substr($letters, 0, 3));
        $date = fake()->dateTimeBetween('-3 years', '-6 months')->format('ymd');
        $suffix = strtoupper(fake()->bothify('##?'));

        return "{$prefix}{$date}{$suffix}";
    }

    /**
     * Pick two shareholder names deterministically from the pool by company code.
     *
     * @param  int  $codeInt  Numeric company code.
     * @return list<string> Two distinct Chinese names.
     */
    private function pickShareholderNames(int $codeInt): array
    {
        $total = count(self::CHINESE_NAMES);
        $index1 = ($codeInt * 2) % $total;
        $index2 = ($codeInt * 2 + 1) % $total;

        return [self::CHINESE_NAMES[$index1], self::CHINESE_NAMES[$index2]];
    }

    /**
     * Generate a JPEG image simulating a Chinese national ID card (居民身份证).
     *
     * The image is 1050×660px (landscape, matching physical card proportions at screen
     * resolution). Uses GD2 to draw a red-bordered card with labeled fields, a photo
     * placeholder, and a national-emblem circle. Includes a visible SEEDER watermark.
     *
     * @param  string  $documentType  Human-readable label shown on the card.
     * @param  string  $reference  Reference string (shareholder name + code).
     * @return string Raw JPEG bytes ready to be written to storage.
     */
    private function fakeIdCardImage(string $documentType, string $reference): string
    {
        $w = 1050;
        $h = 660;
        $im = imagecreatetruecolor($w, $h);

        $white = imagecolorallocate($im, 255, 255, 255);
        $red = imagecolorallocate($im, 190, 15, 25);
        $gold = imagecolorallocate($im, 185, 148, 35);
        $gray = imagecolorallocate($im, 155, 155, 155);
        $darkGray = imagecolorallocate($im, 40, 40, 40);
        $lightBlue = imagecolorallocate($im, 210, 220, 240);
        $lightRed = imagecolorallocate($im, 252, 238, 238);

        // Background and border.
        imagefill($im, 0, 0, $red);
        imagefilledrectangle($im, 14, 14, $w - 14, $h - 14, $white);

        // Header bar.
        imagefilledrectangle($im, 14, 14, $w - 14, 82, $red);
        imagestring($im, 4, 130, 22, 'PEOPLES REPUBLIC OF CHINA', imagecolorallocate($im, 245, 215, 50));
        imagestring($im, 5, 90, 46, 'RESIDENT IDENTITY CARD / TAX CERTIFICATE', imagecolorallocate($im, 255, 250, 200));

        // Photo placeholder (left).
        imagefilledrectangle($im, 30, 100, 210, 320, $lightBlue);
        imagerectangle($im, 30, 100, 210, 320, $gray);
        imagefilledellipse($im, 120, 178, 68, 72, $gray);
        imagefilledrectangle($im, 45, 228, 195, 320, $gray);
        imagestring($im, 2, 76, 328, 'PHOTO', $gray);

        // National emblem circle (right).
        imagefilledellipse($im, $w - 98, 188, 112, 112, imagecolorallocate($im, 238, 212, 78));
        imagefilledellipse($im, $w - 98, 188, 84, 84, $red);
        imagefilledellipse($im, $w - 98, 188, 38, 38, imagecolorallocate($im, 238, 212, 78));
        imagestring($im, 1, $w - 152, 232, 'NATIONAL EMBLEM', $gold);

        // Data fields.
        $ref = substr(preg_replace('/[^\x20-\x7E]/', '?', $reference), 0, 28);
        $fields = [
            ['Name',          $ref,                       230, 105],
            ['Sex',           'Male',                     230, 150],
            ['Nationality',   'HAN CHINESE',              230, 195],
            ['Date of Birth', '1985-03-15',               230, 240],
            ['Address',       '18 GUANGZHOU RD, GD',      230, 285],
        ];

        foreach ($fields as [$label, $value, $x, $y]) {
            imagestring($im, 2, $x, $y, $label, $gray);
            imagestring($im, 4, $x, $y + 16, $value, $darkGray);
            imageline($im, $x, $y + 36, $x + 370, $y + 36, $lightBlue);
        }

        // ID number.
        imagestring($im, 2, 230, 348, 'ID Number / Numero de Identificacion:', $gray);
        imagestring($im, 5, 230, 368, '440102198503152351', $red);

        // Issuing info (left column bottom).
        imagestring($im, 2, 30, 365, 'Issuing Authority:', $gray);
        imagestring($im, 3, 30, 383, 'GUANGZHOU PUBLIC SECURITY', $darkGray);
        imagestring($im, 2, 30, 408, 'Valid Period:', $gray);
        imagestring($im, 3, 30, 426, '2020.01.10 - 2030.01.10', $darkGray);

        // Footer watermark.
        imagefilledrectangle($im, 14, $h - 58, $w - 14, $h - 14, $lightRed);
        imagestring($im, 2, 40, $h - 48, "DOCUMENT: {$documentType}", $gray);
        imagestring($im, 2, 40, $h - 32, 'NEXUM CORE SEEDER — PLACEHOLDER — NOT A REAL DOCUMENT', $gray);

        return $this->imageToJpeg($im);
    }

    /**
     * Generate a JPEG image simulating a Chinese passport data page.
     *
     * The image is 850×1200px (portrait, matching a standard biometric passport
     * page). Includes a navy header, photo placeholder, labelled data fields,
     * an MRZ-style zone at the bottom, and a visible SEEDER watermark.
     *
     * @param  string  $documentType  Human-readable label shown on the page.
     * @param  string  $reference  Reference string (shareholder name + code).
     * @return string Raw JPEG bytes ready to be written to storage.
     */
    private function fakePassportImage(string $documentType, string $reference): string
    {
        $w = 850;
        $h = 1200;
        $im = imagecreatetruecolor($w, $h);

        $cream = imagecolorallocate($im, 252, 250, 240);
        $navy = imagecolorallocate($im, 0, 28, 98);
        $gold = imagecolorallocate($im, 185, 148, 35);
        $gray = imagecolorallocate($im, 160, 160, 160);
        $darkGray = imagecolorallocate($im, 35, 35, 35);
        $lightBlue = imagecolorallocate($im, 205, 215, 238);
        $mrzBg = imagecolorallocate($im, 238, 238, 238);
        $black = imagecolorallocate($im, 0, 0, 0);

        imagefill($im, 0, 0, $cream);

        // Top header bar.
        imagefilledrectangle($im, 0, 0, $w, 78, $navy);
        imagestring($im, 5, 165, 14, 'PEOPLES REPUBLIC OF CHINA', imagecolorallocate($im, 218, 192, 42));
        imagestring($im, 4, 330, 46, 'PASSPORT', imagecolorallocate($im, 200, 172, 38));

        // Gold side stripes.
        imagefilledrectangle($im, 0, 78, 10, $h, $gold);
        imagefilledrectangle($im, $w - 10, 78, $w, $h, $gold);
        imagefilledrectangle($im, 10, 78, 18, $h, $navy);
        imagefilledrectangle($im, $w - 18, 78, $w - 10, $h, $navy);

        // Photo placeholder (top right).
        $px = $w - 205;
        $py = 96;
        $pw = 168;
        $ph = 218;
        imagefilledrectangle($im, $px, $py, $px + $pw, $py + $ph, $lightBlue);
        imagerectangle($im, $px, $py, $px + $pw, $py + $ph, $gray);
        imagefilledellipse($im, (int) ($px + $pw / 2), $py + 72, 62, 68, $gray);
        imagefilledrectangle($im, $px + 28, $py + 112, $px + $pw - 28, $py + $ph, $gray);
        imagestring($im, 2, $px + 56, $py + $ph + 5, 'PHOTO', $gray);

        // Data fields.
        $ref = substr(preg_replace('/[^\x20-\x7E]/', '?', $reference), 0, 20);
        $fields = [
            ['Type',             'P',            95, 112],
            ['Country Code',     'CHN',           95, 150],
            ['Passport No.',     'E12345678',     95, 188],
            ['Surname',          'LI',            95, 226],
            ['Given Names',      $ref,            95, 264],
            ['Nationality',      'CHINESE',       95, 302],
            ['Date of Birth',    '15 MAR 1985',   95, 340],
            ['Sex',              'M',             95, 378],
            ['Place of Birth',   'GUANGDONG',     95, 416],
            ['Date of Issue',    '10 JAN 2020',   95, 454],
            ['Date of Expiry',   '09 JAN 2030',   95, 492],
            ['Personal Number',  '<<<<<<<<<<<',   95, 530],
        ];

        foreach ($fields as [$label, $value, $x, $y]) {
            imagestring($im, 2, $x, $y, strtoupper($label), $gray);
            imagestring($im, 4, $x, $y + 17, $value, $darkGray);
            imageline($im, $x, $y + 36, $x + 390, $y + 36, $lightBlue);
        }

        // MRZ zone (machine-readable zone).
        $mrzY = $h - 128;
        imagefilledrectangle($im, 18, $mrzY - 8, $w - 18, $h - 18, $mrzBg);
        imagestring($im, 2, 26, $mrzY - 4, 'MACHINE READABLE ZONE', $gray);
        $mrz1 = 'P<CHNLI<<GIVEN<NAMES<<<<<<<<<<<<<<<<<<<<<<<<<';
        $mrz2 = 'E123456780CHN8503151M3001094<<<<<<<<<<<<<<<6';
        imagestring($im, 3, 26, $mrzY + 14, $mrz1, $black);
        imagestring($im, 3, 26, $mrzY + 38, $mrz2, $black);

        // Footer watermark.
        imagestring($im, 2, 26, $h - 20, "NEXUM SEEDER — {$documentType} — PLACEHOLDER", $gray);

        return $this->imageToJpeg($im);
    }

    /**
     * Capture a GdImage as a JPEG byte string and release the image resource.
     *
     * Uses output buffering to capture imagejpeg() output to a string instead
     * of writing to disk, which avoids creating temp files on the host system.
     *
     * @param  \GdImage  $im  The GD image resource to encode.
     * @return string Raw JPEG bytes.
     */
    private function imageToJpeg(\GdImage $im): string
    {
        ob_start();
        imagejpeg($im, null, 88);
        $bytes = (string) ob_get_clean();
        imagedestroy($im);

        return $bytes;
    }

    /**
     * Generate a structurally valid minimal PDF for a seeded document.
     *
     * The PDF contains one page with readable text identifying the document
     * type and reference. It is rendered correctly by browser PDF viewers,
     * allowing the preview modal to work end-to-end in local development.
     *
     * The xref table byte offsets are calculated dynamically so the file
     * passes strict PDF validation without any external library.
     *
     * @param  string  $documentType  Human-readable document type label.
     * @param  string  $reference  Company code or name for identification.
     * @return string Raw PDF bytes ready to be written to storage.
     */
    private function fakePdfContent(string $documentType, string $reference): string
    {
        // Escape PDF string literals: ( ) \ must be backslash-escaped.
        $escape = static fn (string $s): string => str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $s);

        $lines = [
            'BT',
            '/F1 13 Tf',
            '72 760 Td',
            '(DOCUMENTO PLACEHOLDER — NEXUM CORE SEEDER) Tj',
            '0 -30 Td',
            '/F1 11 Tf',
            '('.$escape("Tipo       : {$documentType}").') Tj',
            '0 -18 Td',
            '('.$escape("Referencia : {$reference}").') Tj',
            '0 -18 Td',
            '('.$escape('Generado   : '.now()->toDateTimeString()).') Tj',
            '0 -30 Td',
            '/F1 9 Tf',
            '(Este archivo fue generado automaticamente por ChineseCompaniesSeeder.) Tj',
            '0 -14 Td',
            '(No contiene datos reales de ningun cliente.) Tj',
            'ET',
        ];

        $stream = implode("\n", $lines);
        $streamLen = strlen($stream);

        // Build each object string.
        $obj1 = "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n";
        $obj2 = "2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n";
        $obj3 = "3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792]"
            ." /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>\nendobj\n";
        $obj4 = "4 0 obj\n<< /Length {$streamLen} >>\nstream\n{$stream}\nendstream\nendobj\n";
        $obj5 = "5 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>\nendobj\n";

        $header = "%PDF-1.4\n";

        // Calculate exact byte offsets for the xref table.
        $off1 = strlen($header);
        $off2 = $off1 + strlen($obj1);
        $off3 = $off2 + strlen($obj2);
        $off4 = $off3 + strlen($obj3);
        $off5 = $off4 + strlen($obj4);

        $body = $header.$obj1.$obj2.$obj3.$obj4.$obj5;
        $xrefStart = strlen($body);

        // Each xref entry must be exactly 20 bytes: "nnnnnnnnnn ggggg n \n"
        $entry = static fn (int $offset): string => str_pad((string) $offset, 10, '0', STR_PAD_LEFT)." 00000 n \n";

        $xref = "xref\n0 6\n";
        $xref .= "0000000000 65535 f \n";
        $xref .= $entry($off1);
        $xref .= $entry($off2);
        $xref .= $entry($off3);
        $xref .= $entry($off4);
        $xref .= $entry($off5);

        $trailer = "trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n{$xrefStart}\n%%EOF\n";

        return $body.$xref.$trailer;
    }
}
