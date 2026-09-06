# Passkeys

Signing in with a fingerprint, a face or a screen lock, instead of an email
address and a password.

The people who use this are a handful of staff in one workshop, several times a
day, mostly on their own phones, often with oily hands and one bar of signal.
Typing a twelve-character password with a symbol in it, on that phone, at that
counter, is the thing this removes — and the password policy it removes it from
is not going to be weakened to make typing easier, because a weaker password on
a workshop's books is the wrong trade.

> A passkey is not a shortcut past the password. It is a stronger credential
> that happens to be faster, which is the only kind worth adopting.

## The two rules

Everything below follows from these.

1. **The private key never exists on this server.** What is stored is a public
   key, which is useless to whoever steals it. A dump of `passkeys` signs
   nobody in. This is the difference from `users.password`, which is a hash of
   something replayable.
2. **The origin is signed, and checked.** The browser writes the origin it is
   *actually* on into the data the device signs. A convincing copy of this site
   on another domain therefore produces assertions that fail here.

Rule 2 is the one that matters most and is the easiest to lose. It is the whole
of the phishing resistance: a password can be typed into anything that looks
right, and a passkey cannot be handed to anything but the origin it was made
for.

## The shape

```
        ┌─ enrolment (inside a session) ────────────────────────┐
        │  POST /auth/passkeys/options   → challenge + state    │
        │  device creates a key pair, keeps the private half    │
        │  POST /auth/passkeys           → public half stored   │
        └───────────────────────────────────────────────────────┘

        ┌─ signing in (no session) ─────────────────────────────┐
        │  POST /auth/passkeys/login/options → challenge + state │
        │  device signs (challenge ‖ origin ‖ rpId), unlocked    │
        │    by fingerprint / face / PIN                         │
        │  POST /auth/passkeys/login        → session            │
        └───────────────────────────────────────────────────────┘
```

`state` is an opaque handle. The challenge itself stays on the server, in the
cache, and is **pulled** — read and deleted in one operation — when the answer
comes back. Sending the challenge to the client and trusting it on return would
let anybody choose the challenge their own recorded assertion already satisfies.

## Where the boundary is

**Using** a passkey is public. **Enrolling** one is not.

That asymmetry is the design. Signing in has to be reachable by somebody with no
session — that is what signing in means. But a device registered from the
sign-in screen would be a way into an account that anybody who could load that
screen could grant themselves. So enrolment lives behind `auth.jwt` with the
rest of the management endpoints, and the only thing an unauthenticated caller
can do is present a signature this server already holds the public half of.

## No account is named, in either direction

Credentials are **discoverable** (`residentKey: required`), so the device stores
which account each key belongs to and says so at sign-in. That is what removes
the email field: the browser already knows what it holds for this site.

`allowCredentials` is therefore sent **empty**. Filling it would require knowing
who is signing in before the ceremony starts — which puts the email field back —
and it would publish, to anyone who asked, which credentials belong to a given
address.

Because nobody is named beforehand, the assertion's own user handle is the only
thing tying a credential to a person, and it is checked against the stored
record. `AuthenticatorAssertionResponseValidator::check()` is called with a
**null** `$userHandle` for exactly this reason: null means "no account was
identified before this began", which makes the library *require* the response to
carry a handle and compare it. Passing a handle instead would let the caller
choose what the answer is checked against — and the only handle to hand is the
one on the record, which is comparing the record with itself.

The handle is a random UUID on `users.passkey_handle`, not the primary key and
not the email: it is written to hardware this application does not control.

## User verification is required, at both ends

Enrolment sets `userVerification: required`, and so does every sign-in. An
assertion therefore means the device was **unlocked by its owner**, not merely
that it was present. Without it a passkey would prove what a pickpocket has.

## The trusted session

| Signed in with | Session lives | Why |
|---|---|---|
| Passkey | 90 days | Bound to one device, re-verified by fingerprint, face or PIN at every use, revocable per device |
| Password | 7 days | A secret that can be watched, reused from another site, or written inside a cupboard door |

This is the second half of "smooth as butter", and the bigger half in practice:
the daily case is no interaction at all, because the session is still alive.

It is a **property of the credential, not a checkbox**. There is deliberately no
"keep me signed in" on the password form — a box like that lets somebody trade
the whole safety margin for one fewer tap, on the screen least likely to be read
carefully. The long lifetime is something a passkey earns.

`refresh_tokens.trusted` carries it, and **rotation inherits it**. Re-deciding
it per refresh would mean asking "was this a passkey session?" of a request
carrying only a cookie, and the honest answer then is "no" — which would shorten
every trusted session at its first rotation and sign people out a week later
with nothing to say why.

## The counter, and the thing that looks right

`passkeys.sign_count` is the authenticator's own counter. A counter that goes
*backwards* means two things are answering for one credential — a cloned
authenticator.

The subtle part: the stored `credential` blob is a serialized `CredentialRecord`
whose counter is frozen at whatever it was during enrolment, which is zero.
`PasskeyService::recordFor()` therefore re-applies the live column onto the
deserialized record. Without that line the comparison is against zero for ever,
so it passes every assertion, and **nothing looks wrong** — sign-in works, the
column even moves. The only thing it stops detecting is the thing it exists for.

Many passkeys — the synced ones — legitimately report zero and never move, so a
zero counter proves nothing either way. This is a comparison, not a requirement
that it increase.

## Attestation is deliberately not verified

Attestation answers "what make of authenticator is this". That matters to an
enterprise enforcing a hardware policy, and not at all to a workshop whose staff
use the phones they own. `attestation: none`, and the ceremony accepts it.
Requiring more would refuse exactly the devices this is meant to be easy on.

## One refusal, one message

Unknown credential, bad signature, wrong origin, wrong relying party, counter
gone backwards — all of them answer `PASSKEY_REJECTED` with the same words. A
response that distinguished them would tell whoever is probing which one to keep
working on, the same reason `AUTH_INVALID_CREDENTIALS` does not say whether the
email exists. The real cause goes to the log.

The one exception is `PASSKEY_CEREMONY_EXPIRED`, and it is not an oracle: it
reveals nothing about any account, only that a challenge this server issued has
been spent or has timed out. It is separated because it is the one failure that
is routinely innocent — a prompt left open while somebody went to find the
customer's motor — and the only one whose fix is to press the button again.

## Both halves are on the trail

Enrolling a device and removing one are audited, as `passkey` under the
workshop the person belongs to. They are the two acts that change who can open
an account, and neither leaves any other mark — the user's own row is untouched
either way — so without those entries a workshop could not answer "when did that
phone become able to open the books".

The stored public key is not in the trail. It is not evidence of anything a
person can read, and it is useless to whoever steals it; the label and the date
are the whole of what makes an entry mean something a month later. `Passkey`
carries no `tenant_id`, so `auditTenantId()` reads through the user — the
default would file both entries under no workshop at all, where the owner who
needs them cannot see them.

## What is not built, and why

**No "passkey only" mode.** The password stays. A workshop where everybody
enrolled a passkey and then lost their phones on the same day still has to get
in, and the first sign-in on a new device has to be *something*.

**No account recovery flow.** Losing every passkey falls back to the password,
which is the recovery. Building a second recovery path would be building a
second way in, and every one of those is a way in for somebody else too.

**Removing the last passkey is allowed.** Refusing until another exists would
leave somebody unable to revoke a device they have just lost — the exact moment
the control exists for.

**Not tied to a permission.** Managing your own devices is self-service, like
signing out everywhere. Every endpoint is scoped to the caller in the
repository, so a row belonging to somebody else is *not found* rather than found
and refused: the scoping is the authorization, and there is no branch to forget.

## Configuration

`config/webauthn.php`. Two values hold it shut:

- **`rp.id`** — the bare domain a credential is bound to. No scheme, no port, no
  path. Derived from `APP_URL` when unset. **Changing it invalidates every
  enrolled passkey**, because the binding is to the value used at creation.
- **`origins`** — the exact origins allowed to run a ceremony. Derived from
  `APP_URL` when unset. Exact, never a pattern.

## Testing it

`tests/Support/VirtualAuthenticator.php` is a passkey in PHP: an ES256 key pair
that produces real attestations and assertions. There is no way to drive a real
authenticator from a test — the whole design puts the private key somewhere no
software can reach — so the only way to exercise the server's half is to be the
other half.

It can deliberately produce **wrong** answers, and that is the point.
`tests/Feature/Auth/PasskeyTest.php` is mostly those: the phishing origin, the
stale challenge, the replayed assertion, the borrowed user handle, the missing
UV flag, the counter that went backwards. Every one of them looks like a
successful sign-in to anybody testing by hand on their own laptop, which is why
they are written down rather than trusted to a smoke test.
