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

/**
 * Seeds 60 realistic Chinese company registration expedients for development and demo.
 *
 * Creates a representative distribution across all 8 registration stages,
 * including shareholders with Chinese names, legal denominations, KYC and manual
 * documents, stage transition audit trails, pending tasks, and internal notes.
 * All data is fictional but structurally and contextually realistic.
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
     * Seed 5 team users and 60 Chinese company registrations across all 8 stages.
     */
    public function run(): void
    {
        $this->createUsers();

        // Distribution: list of [stage, count] pairs. Enum cases cannot be array keys
        // in PHP (they are objects), so we use indexed tuples instead.
        // Total must equal count(self::COMPANIES) = 60.
        $distribution = [
            [RegistrationStageEnum::DATA_RECEIVED,        12],
            [RegistrationStageEnum::IDENTITY_VALIDATION,  10],
            [RegistrationStageEnum::LEGAL_NAME,           10],
            [RegistrationStageEnum::PARTNER_SIGNATURE,     3],
            [RegistrationStageEnum::INCORPORATION,         8],
            [RegistrationStageEnum::TAX_ADDRESS,           3],
            [RegistrationStageEnum::SAT_REGISTRATION,      5],
            [RegistrationStageEnum::EFIRMA_APPOINTMENT,    5],
            [RegistrationStageEnum::COMPLETED,             4],
        ];

        // Codes (1-based int) that receive a non-ACTIVE status.
        $onHoldCodes = [5, 15, 27, 42];
        $cancelledCodes = [3, 19];

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

        $registration = Registration::create([
            'singapur_client_code' => $clientCode,
            'singapur_package_id' => fake()->uuid(),
            'singapur_folder_name' => "{$clientCode}_{$company['folder']}",
            'stage' => $stage,
            'status' => $status,
            'assigned_notario_id' => $notario->id,
            'assigned_asistente_id' => $asistente->id,
            'company_type' => $companyType,
            'rfc' => $rfc,
            'efirma_status' => $efirmaStatus,
            'efirma_appointment_at' => $efirmaAppointment,
            'completed_at' => $completedAt,
            'created_at' => now()->subDays($arrivedDaysAgo),
            'updated_at' => now()->subDays(max(0, $arrivedDaysAgo - 1)),
        ]);

        $shareholderNames = $this->pickShareholderNames($codeInt);

        $this->createLegalNames($registration, $company['name'], $stage, $stageIndex);
        $this->createShareholders($registration, $shareholderNames, $codeInt);
        $this->createDocuments($registration, $clientCode, $shareholderNames, $stageIndex, $notario);
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
     * @param  list<string>  $names  Two Chinese names from CHINESE_NAMES pool.
     * @param  int  $codeInt  Numeric company code used to deterministically vary splits.
     */
    private function createShareholders(Registration $registration, array $names, int $codeInt): void
    {
        $split = self::PARTICIPATION_SPLITS[$codeInt % count(self::PARTICIPATION_SPLITS)];
        $passportBase = $codeInt % count(self::PASSPORT_NUMBERS);

        Shareholder::create([
            'registration_id' => $registration->id,
            'name' => $names[0],
            'nationality' => 'china',
            'passport_number' => self::PASSPORT_NUMBERS[$passportBase],
            'participation_percentage' => $split[0],
            'role' => ShareholderRoleEnum::LEGAL_REPRESENTATIVE,
            'email' => "user{$codeInt}a@qq.com",
        ]);

        Shareholder::create([
            'registration_id' => $registration->id,
            'name' => $names[1],
            'nationality' => 'china',
            'passport_number' => self::PASSPORT_NUMBERS[($passportBase + 1) % count(self::PASSPORT_NUMBERS)],
            'participation_percentage' => $split[1],
            'role' => ShareholderRoleEnum::SHAREHOLDER,
            'email' => "user{$codeInt}b@163.com",
        ]);
    }

    // -------------------------------------------------------------------------
    // Documents
    // -------------------------------------------------------------------------

    /**
     * Create documents appropriate for the stage the expedient has reached.
     *
     * Documents added per stage milestone:
     * - DATA_RECEIVED (0+)      : 2 relay KYC files (tax certificates).
     * - IDENTITY_VALIDATION (1+): 2 passport uploads to Drive.
     * - INCORPORATION (3+)      : incorporation act upload to Drive.
     * - SAT_REGISTRATION (5+)   : RFC document upload to Drive.
     * - COMPLETED (7)           : e.firma certificate upload to Drive.
     *
     * @param  list<string>  $shareholderNames  Two Chinese names (for filenames).
     * @param  int  $stageIndex  Numeric position in STAGE_ORDER.
     * @param  User  $uploader  Notario responsible for manual uploads.
     */
    private function createDocuments(
        Registration $registration,
        string $clientCode,
        array $shareholderNames,
        int $stageIndex,
        User $uploader,
    ): void {
        $identityIdx = $this->stageIndex(RegistrationStageEnum::IDENTITY_VALIDATION);
        $incorporationIdx = $this->stageIndex(RegistrationStageEnum::INCORPORATION);
        $satIdx = $this->stageIndex(RegistrationStageEnum::SAT_REGISTRATION);
        $completedIdx = $this->stageIndex(RegistrationStageEnum::COMPLETED);

        $isVerifiedByNow = fn (int $fromIndex): bool => $stageIndex > $fromIndex;

        // DATA_RECEIVED: relay KYC tax certificates — both shareholders.
        foreach ([1, 2] as $shareholderIndex) {
            $field = "naturalTaxCertificate{$shareholderIndex}";
            $relayName = "{$clientCode}__{$field}__tax.pdf";

            Document::create([
                'registration_id' => $registration->id,
                'type' => DocumentTypeEnum::CSF,
                'name' => $relayName,
                'storage_path' => "KYC/shareholder_{$shareholderIndex}/{$relayName}",
                'stage' => RegistrationStageEnum::DATA_RECEIVED,
                'uploaded_by' => null,
                'verified_at' => $isVerifiedByNow(0) ? now()->subDays(rand(1, 15)) : null,
                'verified_by' => $isVerifiedByNow(0) ? $uploader->id : null,
            ]);
        }

        // IDENTITY_VALIDATION+: passport uploads for each shareholder.
        if ($stageIndex >= $identityIdx) {
            foreach ([$shareholderNames[0], $shareholderNames[1]] as $idx => $holderName) {
                $driveFileId = $this->fakeDriveFileId();

                Document::create([
                    'registration_id' => $registration->id,
                    'type' => DocumentTypeEnum::PASSPORT,
                    'name' => 'Pasaporte_Accionista_'.($idx + 1)."_{$clientCode}.pdf",
                    'storage_path' => null,
                    'google_drive_file_id' => $driveFileId,
                    'google_drive_url' => "https://drive.google.com/file/d/{$driveFileId}/view",
                    'stage' => RegistrationStageEnum::IDENTITY_VALIDATION,
                    'uploaded_by' => $uploader->id,
                    'verified_at' => $isVerifiedByNow($identityIdx) ? now()->subDays(rand(1, 20)) : null,
                    'verified_by' => $isVerifiedByNow($identityIdx) ? $uploader->id : null,
                ]);
            }
        }

        // INCORPORATION+: acta constitutiva.
        if ($stageIndex >= $incorporationIdx) {
            $driveFileId = $this->fakeDriveFileId();

            Document::create([
                'registration_id' => $registration->id,
                'type' => DocumentTypeEnum::INCORPORATION_ACT,
                'name' => "Acta_Constitutiva_{$clientCode}.pdf",
                'storage_path' => null,
                'google_drive_file_id' => $driveFileId,
                'google_drive_url' => "https://drive.google.com/file/d/{$driveFileId}/view",
                'stage' => RegistrationStageEnum::INCORPORATION,
                'uploaded_by' => $uploader->id,
                'verified_at' => $isVerifiedByNow($incorporationIdx) ? now()->subDays(rand(1, 25)) : null,
                'verified_by' => $isVerifiedByNow($incorporationIdx) ? $uploader->id : null,
            ]);
        }

        // SAT_REGISTRATION+: RFC document.
        if ($stageIndex >= $satIdx) {
            $driveFileId = $this->fakeDriveFileId();

            Document::create([
                'registration_id' => $registration->id,
                'type' => DocumentTypeEnum::RFC_DOCUMENT,
                'name' => "RFC_{$clientCode}.pdf",
                'storage_path' => null,
                'google_drive_file_id' => $driveFileId,
                'google_drive_url' => "https://drive.google.com/file/d/{$driveFileId}/view",
                'stage' => RegistrationStageEnum::SAT_REGISTRATION,
                'uploaded_by' => $uploader->id,
                'verified_at' => $isVerifiedByNow($satIdx) ? now()->subDays(rand(1, 15)) : null,
                'verified_by' => $isVerifiedByNow($satIdx) ? $uploader->id : null,
            ]);
        }

        // COMPLETED: e.firma certificate (.cer file).
        if ($stageIndex >= $completedIdx) {
            $driveFileId = $this->fakeDriveFileId();

            Document::create([
                'registration_id' => $registration->id,
                'type' => DocumentTypeEnum::EFIRMA,
                'name' => "Efirma_{$clientCode}.cer",
                'storage_path' => null,
                'google_drive_file_id' => $driveFileId,
                'google_drive_url' => "https://drive.google.com/file/d/{$driveFileId}/view",
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
            RegistrationStageEnum::PARTNER_SIGNATURE => rand(45, 60),
            RegistrationStageEnum::INCORPORATION => rand(60, 85),
            RegistrationStageEnum::TAX_ADDRESS => rand(85, 110),
            RegistrationStageEnum::SAT_REGISTRATION => rand(110, 145),
            RegistrationStageEnum::EFIRMA_APPOINTMENT => rand(145, 170),
            RegistrationStageEnum::COMPLETED => rand(170, 210),
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
     * Generate a fake Google Drive file ID (33-character base64url string).
     */
    private function fakeDriveFileId(): string
    {
        return '1'.substr(str_replace(['+', '/', '='], ['A', 'B', 'C'], base64_encode(random_bytes(24))), 0, 32);
    }
}
