<?php

declare(strict_types=1);

namespace App\Services\Efirma;

/**
 * Validates a Mexican e.firma (FIEL) set purely with OpenSSL — offline, no SAT calls.
 *
 * The SAT hands the citizen a DER-encoded X.509 certificate (.cer) and a DER-encoded
 * PKCS#8 encrypted private key (.key) protected by a password. This validator proves,
 * cryptographically and locally, that the uploaded files are a real, matching, current
 * FIEL for the expected RFC:
 *
 *   1. The .cer parses as a real X.509 certificate.
 *   2. The password actually opens the .key (wrong password → the key won't load).
 *   3. The .cer and .key are a pair (their public key — the RSA modulus — matches).
 *   4. The certificate is within its validity window (not expired / not yet valid).
 *   5. (optional) The RFC embedded in the certificate matches the company's RFC.
 *
 * No private material ever leaves the server; this is the same math the SAT uses.
 */
class EfirmaCredentialValidator
{
    /**
     * @param  string  $cerBytes  Raw bytes of the .cer file (DER or PEM).
     * @param  string  $keyBytes  Raw bytes of the .key file (DER or PEM, encrypted).
     * @param  string  $password  The private key password.
     * @param  string|null  $expectedRfc  Company RFC to match against the certificate (optional).
     * @param  \DateTimeImmutable|null  $now  Reference time for the validity check (defaults to now).
     */
    public function validate(
        string $cerBytes,
        string $keyBytes,
        string $password,
        ?string $expectedRfc = null,
        ?\DateTimeImmutable $now = null,
    ): EfirmaValidationResult {
        $now ??= new \DateTimeImmutable;
        $errors = [];

        // 1. Certificate must parse as X.509.
        $certPem = $this->derToPem($cerBytes, 'CERTIFICATE');
        $cert = @openssl_x509_read($certPem);

        if ($cert === false) {
            return EfirmaValidationResult::invalid(
                'El archivo .cer no es un certificado válido. Verifica que subiste el .cer de tu e.firma.',
            );
        }

        $parsed = openssl_x509_parse($cert) ?: [];
        $rfc = $this->extractRfc($parsed);
        $validFrom = isset($parsed['validFrom_time_t']) ? (new \DateTimeImmutable)->setTimestamp((int) $parsed['validFrom_time_t']) : null;
        $validTo = isset($parsed['validTo_time_t']) ? (new \DateTimeImmutable)->setTimestamp((int) $parsed['validTo_time_t']) : null;

        // 4. Validity window.
        if ($validTo !== null && $validTo < $now) {
            $errors[] = 'El certificado (.cer) está vencido (venció el '.$validTo->format('d/m/Y').'). Necesitas una e.firma vigente.';
        }

        if ($validFrom !== null && $validFrom > $now) {
            $errors[] = 'El certificado (.cer) aún no es vigente (empieza el '.$validFrom->format('d/m/Y').').';
        }

        // 2. Password must open the private key.
        $keyPem = $this->derToPem($keyBytes, 'ENCRYPTED PRIVATE KEY');
        $privateKey = @openssl_pkey_get_private($keyPem, $password);

        if ($privateKey === false) {
            $errors[] = 'La contraseña no abre la llave (.key), o el archivo .key no es válido. Revisa la contraseña y que sea el .key de tu e.firma.';
        }

        // 3. The .cer and .key must be a pair (same public key / RSA modulus).
        if ($privateKey !== false) {
            if (! $this->keyMatchesCertificate($privateKey, $cert)) {
                $errors[] = 'El .cer y el .key no corresponden a la misma e.firma. Asegúrate de subir el par que va junto.';
            }
        }

        // 5. RFC match (only when we could read both).
        if ($expectedRfc !== null && $expectedRfc !== '' && $rfc !== null
            && ! hash_equals(strtoupper($expectedRfc), strtoupper($rfc))) {
            $errors[] = "El RFC de la e.firma ({$rfc}) no coincide con el de la empresa ({$expectedRfc}). ¿Subiste la e.firma correcta?";
        }

        return new EfirmaValidationResult($errors === [], $rfc, $validFrom, $validTo, array_values($errors));
    }

    /**
     * True if the private key and the certificate share the same public key (RSA modulus).
     *
     * @param  \OpenSSLAsymmetricKey  $privateKey  The loaded private key.
     * @param  \OpenSSLCertificate  $cert  The parsed certificate.
     */
    private function keyMatchesCertificate(\OpenSSLAsymmetricKey $privateKey, \OpenSSLCertificate $cert): bool
    {
        $certPublic = @openssl_pkey_get_public($cert);

        if ($certPublic === false) {
            return false;
        }

        $privDetails = openssl_pkey_get_details($privateKey);
        $certDetails = openssl_pkey_get_details($certPublic);

        $privModulus = $privDetails['rsa']['n'] ?? null;
        $certModulus = $certDetails['rsa']['n'] ?? null;

        if ($privModulus === null || $certModulus === null) {
            return false;
        }

        return hash_equals($certModulus, $privModulus);
    }

    /**
     * Extract the RFC embedded in the SAT certificate subject.
     *
     * The SAT stores the RFC in the subject (typically under x500UniqueIdentifier /
     * serialNumber). We look in the likely fields and, as a fallback, scan the whole
     * subject for the RFC pattern. Returns the uppercased RFC or null.
     *
     * @param  array<string, mixed>  $parsed  Output of openssl_x509_parse().
     */
    private function extractRfc(array $parsed): ?string
    {
        $subject = $parsed['subject'] ?? [];
        $pattern = '/\b([A-ZÑ&]{3,4}\d{6}[A-Z0-9]{3})\b/u';

        $preferred = [];

        foreach (['x500UniqueIdentifier', 'serialNumber', 'UID', 'uniqueIdentifier'] as $field) {
            if (isset($subject[$field])) {
                $value = $subject[$field];
                $preferred[] = is_array($value) ? implode(' ', $value) : (string) $value;
            }
        }

        foreach ($preferred as $candidate) {
            if (preg_match($pattern, strtoupper($candidate), $matches)) {
                return $matches[1];
            }
        }

        // Fallback: scan the full subject.
        $flat = strtoupper((string) json_encode($subject, JSON_UNESCAPED_UNICODE));

        if (preg_match($pattern, $flat, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Wrap raw DER bytes in a PEM envelope. If the bytes already look like PEM
     * (contain a BEGIN header), they are returned unchanged.
     *
     * @param  string  $bytes  Raw file bytes (DER or PEM).
     * @param  string  $label  PEM label, e.g. 'CERTIFICATE' or 'ENCRYPTED PRIVATE KEY'.
     */
    private function derToPem(string $bytes, string $label): string
    {
        if (str_contains($bytes, '-----BEGIN')) {
            return $bytes;
        }

        return "-----BEGIN {$label}-----\n"
            .chunk_split(base64_encode($bytes), 64, "\n")
            ."-----END {$label}-----\n";
    }
}
