<?php

namespace Tests\Unit\Services\Efirma;

use App\Services\Efirma\EfirmaCredentialValidator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Exercises the OpenSSL-based FIEL validation with certificates/keys generated on the
 * fly (a self-signed pair stands in for a real SAT e.firma — same crypto).
 */
class EfirmaCredentialValidatorTest extends TestCase
{
    private const PASSWORD = 'sup3r-secreta';

    private const RFC = 'BME920101QK3';

    /**
     * Generate a fresh {cerPem, keyPem} pair with the RFC in the subject serialNumber.
     *
     * @return array{cer: string, key: string}
     */
    private function makePair(string $rfc = self::RFC, string $password = self::PASSWORD): array
    {
        $config = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
        $dn = ['countryName' => 'MX', 'commonName' => 'EMPRESA DE PRUEBA', 'serialNumber' => $rfc];

        $privateKey = openssl_pkey_new($config);
        $csr = openssl_csr_new($dn, $privateKey, $config);
        $cert = openssl_csr_sign($csr, null, $privateKey, 365, $config);

        openssl_x509_export($cert, $cerPem);
        openssl_pkey_export($privateKey, $keyPem, $password, $config);

        return ['cer' => $cerPem, 'key' => $keyPem];
    }

    #[Test]
    public function a_matching_pair_with_the_right_password_is_valid(): void
    {
        $pair = $this->makePair();

        $result = (new EfirmaCredentialValidator)->validate(
            $pair['cer'],
            $pair['key'],
            self::PASSWORD,
            self::RFC,
        );

        $this->assertTrue($result->valid, implode(' | ', $result->errors));
        $this->assertSame(self::RFC, $result->rfc);
        $this->assertSame([], $result->errors);
    }

    #[Test]
    public function a_wrong_password_is_rejected(): void
    {
        $pair = $this->makePair();

        $result = (new EfirmaCredentialValidator)->validate(
            $pair['cer'],
            $pair['key'],
            'contraseña-incorrecta',
            self::RFC,
        );

        $this->assertFalse($result->valid);
        $this->assertStringContainsStringIgnoringCase('contraseña', $result->errors[0]);
    }

    #[Test]
    public function a_cer_and_key_from_different_efirmas_are_rejected(): void
    {
        $a = $this->makePair();
        $b = $this->makePair();

        // .cer of A with .key of B → not a pair.
        $result = (new EfirmaCredentialValidator)->validate(
            $a['cer'],
            $b['key'],
            self::PASSWORD,
            self::RFC,
        );

        $this->assertFalse($result->valid);
        $this->assertTrue(
            collect($result->errors)->contains(fn (string $e): bool => str_contains($e, 'no corresponden')),
            'Debe reportar que el cer y el key no corresponden.',
        );
    }

    #[Test]
    public function an_rfc_that_does_not_match_the_company_is_rejected(): void
    {
        $pair = $this->makePair(self::RFC);

        $result = (new EfirmaCredentialValidator)->validate(
            $pair['cer'],
            $pair['key'],
            self::PASSWORD,
            'XAXX010101000', // RFC distinto al del certificado
        );

        $this->assertFalse($result->valid);
        $this->assertTrue(
            collect($result->errors)->contains(fn (string $e): bool => str_contains($e, 'no coincide')),
        );
    }

    #[Test]
    public function an_expired_certificate_is_rejected(): void
    {
        $pair = $this->makePair();
        $future = (new \DateTimeImmutable)->modify('+400 days');

        $result = (new EfirmaCredentialValidator)->validate(
            $pair['cer'],
            $pair['key'],
            self::PASSWORD,
            self::RFC,
            $future,
        );

        $this->assertFalse($result->valid);
        $this->assertTrue(
            collect($result->errors)->contains(fn (string $e): bool => str_contains($e, 'vencido')),
        );
    }

    #[Test]
    public function garbage_bytes_for_the_cer_are_rejected(): void
    {
        $result = (new EfirmaCredentialValidator)->validate(
            'esto no es un certificado',
            'esto tampoco',
            self::PASSWORD,
            self::RFC,
        );

        $this->assertFalse($result->valid);
        $this->assertStringContainsStringIgnoringCase('.cer', $result->errors[0]);
    }
}
