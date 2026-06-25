<?php

namespace Tests\Unit\Filament;

use App\Filament\Resources\MuaAccountResource;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Unit tests for MuaAccountResource::uploadedFileToBase64().
 *
 * The FIEL form now takes the raw .cer / .key files (FileUpload) instead of
 * pasted base64; this guard encodes the uploaded bytes server-side.
 */
class MuaAccountFileUploadTest extends TestCase
{
    #[Test]
    public function it_encodes_an_uploaded_file_to_base64(): void
    {
        $file = UploadedFile::fake()->createWithContent('cert.cer', 'BINARY-CERT-DATA');

        $this->assertSame(
            base64_encode('BINARY-CERT-DATA'),
            MuaAccountResource::uploadedFileToBase64($file),
        );
    }

    #[Test]
    public function it_handles_the_array_form_of_the_field_state(): void
    {
        $file = UploadedFile::fake()->createWithContent('key.key', 'PRIVATE-KEY-BYTES');

        $this->assertSame(
            base64_encode('PRIVATE-KEY-BYTES'),
            MuaAccountResource::uploadedFileToBase64(['abc123' => $file]),
        );
    }

    #[Test]
    public function it_returns_null_when_no_file_is_provided(): void
    {
        $this->assertNull(MuaAccountResource::uploadedFileToBase64(null));
        $this->assertNull(MuaAccountResource::uploadedFileToBase64([]));
        $this->assertNull(MuaAccountResource::uploadedFileToBase64('not-a-file'));
    }

    #[Test]
    public function it_validates_the_uploaded_file_extension(): void
    {
        $cer = UploadedFile::fake()->create('00001000000721265245.cer');
        $key = UploadedFile::fake()->create('Claveprivada.key');
        $bad = UploadedFile::fake()->create('virus.txt');

        // Correct extensions (case-insensitive), in single and array form.
        $this->assertTrue(MuaAccountResource::uploadedHasExtension($cer, 'cer'));
        $this->assertTrue(MuaAccountResource::uploadedHasExtension(['x' => $key], 'key'));
        $this->assertTrue(MuaAccountResource::uploadedHasExtension(
            UploadedFile::fake()->create('CERT.CER'),
            'cer',
        ));

        // Wrong extension or no file.
        $this->assertFalse(MuaAccountResource::uploadedHasExtension($cer, 'key'));
        $this->assertFalse(MuaAccountResource::uploadedHasExtension($bad, 'cer'));
        $this->assertFalse(MuaAccountResource::uploadedHasExtension(null, 'cer'));
    }
}
