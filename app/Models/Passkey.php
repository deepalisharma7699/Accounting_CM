<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One device somebody has enrolled to sign in with.
 *
 * The row is a public key and some housekeeping. Everything that could sign an
 * assertion stays in the device's secure element, so this model is unusual
 * among the security-relevant ones: there is nothing in it to protect from
 * being read. What has to be protected is *writing* it — an attacker who can
 * add a row here has added a way in, which is why enrolment always happens
 * inside an already-authenticated session and never as part of signing in.
 *
 * @property int $id
 * @property int $user_id
 * @property string $credential_id Base64url of the raw credential id.
 * @property string $credential Serialized CredentialRecord; opaque here.
 * @property string $label
 * @property string|null $aaguid
 * @property int $sign_count
 * @property bool $backed_up
 * @property Carbon|null $last_used_at
 * @property string|null $last_used_ip
 * @property User $user
 */
#[Fillable([
    'user_id',
    'credential_id',
    'credential',
    'label',
    'aaguid',
    'sign_count',
    'backed_up',
])]
/*
| `credential` is hidden for tidiness rather than secrecy: it is a few hundred
| bytes of serialized CBOR that no client has any use for, and leaving it in
| every response would be noise in a payload a person is meant to read.
*/
#[Hidden(['credential'])]
class Passkey extends Model
{
    use Auditable;

    /**
     * Enrolling a device and removing one are both security events — the first
     * adds a way in, the second takes one away — so both belong in the trail an
     * owner can read. The label is what makes an entry mean anything a month
     * later; `credential` is deliberately absent, because a blob nobody can
     * read is not evidence of anything.
     *
     * @return array<int, string>
     */
    public function auditAttributes(): array
    {
        return ['label', 'user_id'];
    }

    public function auditLabel(): string
    {
        return $this->label;
    }

    /**
     * The workshop whose history this belongs in.
     *
     * A passkey carries no `tenant_id` of its own — it belongs to a person, and
     * the person belongs to a workshop — so the default would file every
     * enrolment and removal under no tenant at all, where the owner who needs
     * to see it cannot. Read through the user instead. Null stays null for a
     * platform administrator, who is in no workshop, which is correct.
     */
    public function auditTenantId(): ?int
    {
        $tenantId = $this->user()->withTrashed()->value('tenant_id');

        return $tenantId === null ? null : (int) $tenantId;
    }

    protected function casts(): array
    {
        return [
            'sign_count' => 'integer',
            'backed_up' => 'boolean',
            'last_used_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
