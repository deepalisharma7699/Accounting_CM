<?php

namespace App\Http\Resources;

use App\Models\Passkey;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One enrolled device, as its owner sees it.
 *
 * The list this fills is the only place a person can notice a device they do
 * not recognise, so what it carries is chosen for that job: what it is called,
 * when it was added, when it was last used, and whether it is synced to their
 * account or lives on that one device. The credential itself is left out — it
 * is a blob nobody can read, and a field nobody can act on is noise in a list
 * whose whole purpose is being read.
 *
 * @mixin Passkey
 */
class PasskeyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,

            /*
            | Whether losing this device loses the passkey.
            |
            | A synced passkey (iCloud Keychain, Google Password Manager) comes
            | back on the replacement phone; a device-bound one does not, and
            | its owner needs to know that before it is the only one they have.
            */
            'backed_up' => (bool) $this->backed_up,

            'created_at' => $this->created_at?->toIso8601String(),
            'last_used_at' => $this->last_used_at?->toIso8601String(),
        ];
    }
}
