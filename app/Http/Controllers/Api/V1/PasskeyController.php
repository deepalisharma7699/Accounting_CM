<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\IssuesSessions;
use App\Http\Controllers\Controller;
use App\Http\Resources\PasskeyResource;
use App\Models\User;
use App\Services\Auth\AuthService;
use App\Services\Auth\PasskeyService;
use App\Services\Auth\TokenService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Passkeys: two public endpoints to sign in with one, and four authenticated
 * ones to manage them.
 *
 * The split is the security boundary and is worth stating plainly. *Using* a
 * passkey has to be reachable by somebody with no session — that is what
 * signing in means. *Enrolling* one must not be: a device registered from the
 * sign-in screen would be a way in that anybody who reached that screen could
 * add. So enrolment sits behind `auth.jwt` with everything else here, and the
 * only thing an unauthenticated caller can do is present a signature this
 * server already holds the public half of.
 */
class PasskeyController extends Controller
{
    use IssuesSessions;

    public function __construct(
        private readonly PasskeyService $passkeys,
        private readonly AuthService $auth,
        private readonly TokenService $tokens,
    ) {}

    /* ---------------------------------------------------------------------
     | Signing in — public
     |-------------------------------------------------------------------- */

    /**
     * POST /api/v1/auth/passkeys/login/options
     *
     * A challenge, naming no account. Takes no input at all, deliberately:
     * anything it accepted would be something to probe it with.
     */
    public function loginOptions(): JsonResponse
    {
        return ApiResponse::success($this->passkeys->authenticationOptions());
    }

    /**
     * POST /api/v1/auth/passkeys/login
     *
     * Verify the assertion and start the session. The response is the same
     * shape the password login returns, refresh cookie and all, so the client
     * has one way to begin a session however it was proved.
     */
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'state' => ['required', 'string', 'max:128'],
            'credential' => ['required', 'array'],
        ]);

        $verified = $this->passkeys->authenticate($data['state'], $data['credential'], $request);

        ['user' => $user, 'tokens' => $tokens] = $this->auth->loginWithPasskey($verified, $request);

        return $this->withRefreshCookie(
            ApiResponse::success($this->sessionPayload($user, $tokens), 'Signed in successfully.'),
            $tokens
        );
    }

    /* ---------------------------------------------------------------------
     | Managing them — authenticated
     |-------------------------------------------------------------------- */

    /**
     * GET /api/v1/auth/passkeys
     */
    public function index(Request $request): JsonResponse
    {
        return ApiResponse::success(
            PasskeyResource::collection($this->passkeys->listFor($this->user($request)))->resolve($request)
        );
    }

    /**
     * POST /api/v1/auth/passkeys/options — begin enrolling this device.
     */
    public function registerOptions(Request $request): JsonResponse
    {
        return ApiResponse::success($this->passkeys->registrationOptions($this->user($request)));
    }

    /**
     * POST /api/v1/auth/passkeys — finish enrolling it.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'state' => ['required', 'string', 'max:128'],
            'credential' => ['required', 'array'],
            // Required rather than defaulted: four rows called "Passkey" is a
            // list nobody can revoke the right one from.
            'label' => ['required', 'string', 'max:80'],
        ]);

        $passkey = $this->passkeys->register(
            $this->user($request),
            $data['state'],
            $data['credential'],
            $data['label'],
            $request,
        );

        return ApiResponse::created(
            (new PasskeyResource($passkey))->resolve($request),
            'This device can now sign you in.'
        );
    }

    /**
     * PATCH /api/v1/auth/passkeys/{passkey}
     */
    public function update(Request $request, int $passkey): JsonResponse
    {
        $data = $request->validate([
            'label' => ['required', 'string', 'max:80'],
        ]);

        $record = $this->passkeys->rename($this->user($request), $passkey, $data['label']);

        return ApiResponse::success((new PasskeyResource($record))->resolve($request), 'Renamed.');
    }

    /**
     * DELETE /api/v1/auth/passkeys/{passkey}
     *
     * Removing the last one is allowed. The alternative — refusing until a
     * password is set, or keeping one on the account against its owner's wishes
     * — leaves somebody unable to revoke a device they have just lost, which is
     * the exact moment this control exists for.
     */
    public function destroy(Request $request, int $passkey): JsonResponse
    {
        $this->passkeys->remove($this->user($request), $passkey, $request);

        return ApiResponse::message('That device can no longer sign you in.');
    }

    protected function tokenService(): TokenService
    {
        return $this->tokens;
    }

    /**
     * The signed-in user, and the only account any of the above may touch.
     */
    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
