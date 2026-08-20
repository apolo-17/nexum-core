<?php

declare(strict_types=1);

namespace App\Services\Efirma;

/**
 * Result of validating an e.firma (FIEL) set (.cer + .key + password).
 *
 * `valid` is true only when every check passed. `errors` holds human-readable,
 * Spanish messages to show the soldado when something is wrong.
 */
final class EfirmaValidationResult
{
    /**
     * @param  list<string>  $errors  Human-readable reasons the set was rejected (empty if valid).
     */
    public function __construct(
        public readonly bool $valid,
        public readonly ?string $rfc,
        public readonly ?\DateTimeImmutable $validFrom,
        public readonly ?\DateTimeImmutable $validTo,
        public readonly array $errors,
    ) {}

    public static function invalid(string ...$errors): self
    {
        return new self(false, null, null, null, array_values($errors));
    }
}
