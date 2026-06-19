<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;

/**
 * Stores one component of a FIEL (e.firma) credential set for a MuaAccount.
 *
 * The three required types are:
 *   - certificate  → base64-encoded content of the .cer file
 *   - private_key  → base64-encoded content of the .key file
 *   - password     → passphrase to decrypt the private key
 *
 * Values are encrypted at rest via Laravel's Crypt facade (AES-256-CBC).
 */
class MuaCredential extends Model
{
    use HasUlids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'mua_account_id',
        'type',
        'credential',
    ];

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    /**
     * Get the MUA account this credential belongs to.
     *
     * @return BelongsTo<MuaAccount, $this>
     */
    public function muaAccount(): BelongsTo
    {
        return $this->belongsTo(MuaAccount::class);
    }

    // -------------------------------------------------------------------------
    // Encryption helpers
    // -------------------------------------------------------------------------

    /**
     * Encrypt and set the credential value before saving.
     *
     * Usage: $credential->setEncryptedValue($rawValue)->save();
     *
     * @param  string  $value  Raw (unencrypted) credential string.
     *
     * @return static
     */
    public function setEncryptedValue(string $value): static
    {
        $this->credential = Crypt::encryptString($value);

        return $this;
    }

    /**
     * Decrypt and return the stored credential value.
     *
     * @return string|null  Null when no credential is stored yet.
     */
    public function decryptedValue(): ?string
    {
        if (empty($this->credential)) {
            return null;
        }

        return Crypt::decryptString($this->credential);
    }
}
