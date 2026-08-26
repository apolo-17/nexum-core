<?php

namespace Tests\Feature\Services\Registration;

use App\Services\Registration\GenerateActaDocxService;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

/**
 * The S. de R.L. de C.V. branch of GenerateActaDocxService maps the compiled template_data
 * to the srl.docx placeholders: 2 fixed socios, the inline apoderados list, dates spelled out,
 * and the default special delegate.
 */
class GenerateSrlActaTest extends TestCase
{
    private function invoke(string $method, mixed ...$args): mixed
    {
        $ref = new ReflectionMethod(GenerateActaDocxService::class, $method);
        $ref->setAccessible(true);

        return $ref->invoke(new GenerateActaDocxService(), ...$args);
    }

    private function sampleData(): array
    {
        return [
            'company_type' => 'SRL de CV',
            'autorizacion_denominacion' => 'yunmall méxico',
            'folio_denominacion' => 'A202602271840258356',
            'fecha_denominacion' => '27/02/2026',
            'socios' => [
                ['socio_nombre' => 'zhang wei', 'socio_nacionalidad' => 'china', 'socio_estado_nacimiento' => 'Shanghái', 'pais_residencia' => 'China', 'socio_fecha_nacimiento' => '01/01/1990', 'socio_direccion' => 'Calle X 1', 'tax_id' => 'CN123', 'socio_tipo_identificacion_numero' => 'E1234', 'socio_correo' => 'a@b.com'],
                ['socio_nombre' => 'li na', 'socio_nacionalidad' => 'china', 'socio_estado_nacimiento' => 'Pekín', 'pais_residencia' => 'China', 'socio_fecha_nacimiento' => '15/03/1992', 'socio_direccion' => 'Calle Y 2', 'tax_id' => 'CN456', 'socio_tipo_identificacion_numero' => 'E5678', 'socio_correo' => 'c@d.com'],
            ],
            'apoderados' => [
                ['apoderado_nombre' => 'ignacio bermúdez casco', 'apoderado_rfc' => 'BECI920125GF2'],
                ['apoderado_nombre' => 'ulises apolinar morales', 'apoderado_rfc' => 'AOMU960129V22'],
            ],
        ];
    }

    #[Test]
    public function it_detects_srl_from_company_type(): void
    {
        $this->assertTrue($this->invoke('isSrl', ['company_type' => 'SRL de CV']));
        $this->assertTrue($this->invoke('isSrl', ['company_type' => 'S. de R.L. de C.V.']));
        $this->assertFalse($this->invoke('isSrl', ['company_type' => 'SA de CV']));
        $this->assertFalse($this->invoke('isSrl', ['company_type' => 'S.A. de C.V.']));
    }

    #[Test]
    public function it_maps_template_data_to_the_srl_placeholders(): void
    {
        $values = $this->invoke('buildSrlValues', $this->sampleData());

        $this->assertSame('YUNMALL MÉXICO', $values['company_name']);
        $this->assertSame('A202602271840258356', $values['CUD']);
        $this->assertSame('27', $values['CUD_day']);
        $this->assertSame('veintisiete', $values['CUD_day_words']);
        $this->assertSame('febrero', $values['CUD_month']);
        $this->assertSame('dos mil veintiséis', $values['CUD_year_words']);

        $this->assertSame('ZHANG WEI', $values['shareholder1_full_name']);
        $this->assertSame('uno', $values['shareholder1_birth_day_words']);
        $this->assertSame('enero', $values['shareholder1_birth_month']);
        $this->assertSame('China', $values['shareholder1_country']);
        $this->assertSame('E1234', $values['shareholder1_passport_number']);
        $this->assertSame('LI NA', $values['shareholder2_full_name']);

        $this->assertSame(
            'IGNACIO BERMÚDEZ CASCO cuyo RFC es "BECI920125GF2", ULISES APOLINAR MORALES cuyo RFC es "AOMU960129V22"',
            $values['apoderados_lista']
        );

        // Delegate defaults to the recurring one when template_data does not override it.
        $this->assertSame('LINDA CECILIA FAVELA MORENO', $values['delegado_nombre']);
        $this->assertSame('FAML020304QS1', $values['delegado_rfc']);
    }

    #[Test]
    public function it_lets_template_data_override_the_delegate(): void
    {
        $data = $this->sampleData();
        $data['delegado_nombre'] = 'marcos garcia gonzalez';
        $data['delegado_rfc'] = 'GAGM931202TZ5';

        $values = $this->invoke('buildSrlValues', $data);

        $this->assertSame('MARCOS GARCIA GONZALEZ', $values['delegado_nombre']);
        $this->assertSame('GAGM931202TZ5', $values['delegado_rfc']);
    }

    #[Test]
    public function it_throws_when_not_exactly_two_socios(): void
    {
        $data = $this->sampleData();
        $data['socios'] = [$data['socios'][0]]; // solo 1

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('requiere exactamente 2 socios');

        $this->invoke('buildSrlValues', $data);
    }
}
