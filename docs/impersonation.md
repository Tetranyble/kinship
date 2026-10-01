# User impersonation

Kinship supports user impersonation separately from role assumption:

| Capability | Identity returned by `Auth::user()` | Authorization |
| --- | --- | --- |
| `assumeRole()` / `actAs()` | Original user | Selected role context |
| `impersonate()` | Target user | Target user's own access |

Impersonation is intended for an authorized support or administrative user who
needs to reproduce another user's dashboard experience. The target does not
authenticate, so a login-locked account can be inspected without clearing its
lock, changing its password, or bypassing MFA credentials.

## Enable the feature

Impersonation is disabled by default. Publish the configuration and enable it:

```php
// config/kinship.php
'impersonation' => [
    'enabled' => true,
    'ability' => 'kinship.impersonate',
    'require_reason' => true,
    'ttl' => 1800,
    // Keep the remaining published defaults.
],
```

Add the optional interaction trait to the application's user model:

```php
use Tetranyble\Kinship\Concerns\InteractsWithImpersonation;

class User extends Authenticatable
{
    use InteractsWithImpersonation;
}
```

The service API is also available directly through
`Tetranyble\Kinship\Impersonation\ImpersonationManager`.

## Authorize the switch

The default authorizer delegates to a Laravel Gate. Kinship deliberately does
not infer privileged role names:

```php
use App\Models\User;
use Illuminate\Support\Facades\Gate;

Gate::define(
    'kinship.impersonate',
    function (User $actor, User $target): bool {
        return $actor->hasPermission('support.impersonate')
            && $actor->hasRecentMfaConfirmation()
            && $target->isLoginLocked()
            && ! $target->isComplianceSuspended();
    },
);
```

The Gate is evaluated before authentication changes. It should distinguish a
recoverable login lock from deletion, fraud restrictions, compliance holds, or
other protected states. Applications can replace the
`ImpersonationAuthorizer` binding when a Gate is not the right integration.

## Start and stop

Require a support reference or reason from the actor:

```php
$actor = auth()->user();
$actor->impersonate($target, 'Ticket SUP-1234');

auth()->user();               // target user
auth()->user()->impersonator(); // original support user
auth()->user()->isImpersonating();

auth()->user()->stopImpersonating();
auth()->user();               // restored support user
```

Kinship stores only model types, identifiers, guard, reason, and timestamps in
the server-side session. It regenerates the session ID at both transitions,
rejects self and nested impersonation, requires a stateful guard, and restores
the original actor without copying permissions between users. By default the
actor and target must use the same user model type.

Starting impersonation clears assumed-role session state for the target. The
target therefore receives only their own ordinary roles, direct permissions,
and application grant sources.

## Out-of-the-box middleware

`ValidateImpersonationSession` is registered as
`kinship.impersonation.valid`, but it is not attached to any route by default.
Place it explicitly after Laravel's stateful authentication middleware so the
integration is visible during route review:

```php
Route::middleware(['auth:web', 'kinship.impersonation.valid'])
    ->group(function (): void {
        // Routes available during support impersonation.
    });
```

`auto_middleware=true` remains available as an explicit opt-in when an
application intentionally wants the configured middleware on an entire group.
The default is `false`.

Do not edit or replace the application's existing authentication, MFA, account
lock, or authorization middleware. Create a dedicated middleware for the
impersonation layer by extending Kinship's middleware:

```php
namespace App\Http\Middleware;

use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Tetranyble\Kinship\Http\Middleware\ValidateImpersonationSession;
use Tetranyble\Kinship\Impersonation\ImpersonationState;

final class ValidateSupportImpersonation extends ValidateImpersonationSession
{
    protected function allowsImpersonatedRequest(
        Request $request,
        Authenticatable $actor,
        Authenticatable $target,
        ImpersonationState $state,
    ): bool {
        return $actor instanceof User
            && $target instanceof User
            && $actor->hasRecentMfaConfirmation()
            && $actor->isOnSupportShift()
            && $target->isLoginLocked()
            && ! $target->isComplianceSuspended();
    }
}
```

Configure the subclass:

```php
'impersonation' => [
    'middleware' => App\Http\Middleware\ValidateSupportImpersonation::class,
    // ...
],
```

This is a new application class, not a modification to an existing middleware.
The configured class must extend Kinship's middleware. The protected
`allowsImpersonatedRequest()` hook runs on every impersonated request. Returning
`false` restores the actor and rejects the request. Override
`conditionDenied()` or `invalid()` only when the application needs a different
response format.

The Gate is the start-time authorization boundary; the middleware is the
continuous boundary. Use both. A condition such as recent MFA can expire after
the switch and will terminate the impersonation on the next request.

## Stateless authentication

Stateless impersonation is possible, but it is token-provider-specific. A
session guard can switch identities and later restore the actor; a stateless
guard cannot. Sanctum, Passport, and JWT implementations also have different
token issuance, claims, revocation, and rotation rules.

Kinship therefore does not mint a generic impersonation token. The
application's token layer must issue a short-lived, impersonation-specific
credential containing both actor and target identifiers, the guard, reason,
issued-at time, expiry, and a unique token identifier. Its authentication layer
must validate that credential and authenticate the target before an
impersonation middleware runs. Revocation, audit persistence, and restrictions
on sensitive actions remain mandatory.

Kinship provides `authorizeStateless()` and the
`StatelessImpersonationTokenBroker` contract. The following controller shows
every dependency explicitly; `$tokens` is the package contract injected through
the constructor:

```php
namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Tetranyble\Kinship\Contracts\StatelessImpersonationTokenBroker;
use Tetranyble\Kinship\Impersonation\ImpersonationManager;
use Tetranyble\Kinship\Impersonation\StatelessImpersonationCredential;

final readonly class StartStatelessImpersonationController
{
    public function __construct(
        private StatelessImpersonationTokenBroker $tokens,
    ) {}

    public function store(
        Request $request,
        User $target,
    ): StatelessImpersonationCredential
    {
        $actor = $request->user('api');
        abort_unless($actor instanceof Authenticatable, 401);

        $grant = app(ImpersonationManager::class)->authorizeStateless(
            actor: $actor,   // Support/admin user
            target: $target, // Customer being impersonated
            reason: (string) $request->input('reason'),
            guard: 'api',
        );

        return $this->tokens->issueImpersonationToken(
            subject: $target,
            grant: $grant,
        );
    }
}
```

The returned state has a unique `id` suitable for a `jti` claim and revocation
key. Calling this method does not issue a credential, change `Auth::user()`, or
write session data.

The host supplies one concrete broker for its token provider. The contract is:

```php
namespace App\Auth;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Tetranyble\Kinship\Contracts\StatelessImpersonationTokenBroker;
use Tetranyble\Kinship\Impersonation\ImpersonationState;
use Tetranyble\Kinship\Impersonation\StatelessImpersonationCredential;

final class JwtImpersonationTokenBroker implements StatelessImpersonationTokenBroker
{
    public function issueImpersonationToken(
        Authenticatable $subject,
        ImpersonationState $grant,
    ): StatelessImpersonationCredential {
        // Sign a provider token with subject=$subject->getAuthIdentifier(),
        // token_type=impersonation, and kinship=$grant->toArray().
        // Return that token and $grant->expiresAt in the credential DTO.
        throw new \LogicException('Implement with the application token provider.');
    }

    public function resolve(Request $request): ?ImpersonationState
    {
        // Ask the provider to verify the request token, then verify issuer,
        // audience, type=impersonation, and jti revocation. Return null on any
        // failure; otherwise pass the protected kinship claims to fromArray().
        throw new \LogicException('Implement with the application token provider.');
    }

    public function revoke(string $grantId): void
    {
        // Persist this jti in the provider's revocation store until expiry.
        throw new \LogicException('Implement with the application token provider.');
    }
}
```

These three methods are intentionally implemented by the application because
their internals differ for Sanctum, Passport, and each JWT library. There is no
unshown Kinship token service.

The package stateless middleware uses that broker automatically. Extend it only
to add application conditions:

```php
namespace App\Http\Middleware;

use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Tetranyble\Kinship\Http\Middleware\ValidateStatelessImpersonation;
use Tetranyble\Kinship\Impersonation\ImpersonationState;

final class ValidateApiImpersonation extends ValidateStatelessImpersonation
{
    protected function allowsImpersonatedRequest(
        Request $request,
        Authenticatable $actor,
        Authenticatable $target,
        ImpersonationState $state,
    ): bool {
        return $actor instanceof User
            && $target instanceof User
            && $actor->hasRecentMfaConfirmation()
            && ! $target->isComplianceSuspended();
    }
}
```

Configure and explicitly place this separate API middleware:

```php
// config/kinship.php
'stateless_broker' => App\Auth\JwtImpersonationTokenBroker::class,
'stateless_middleware' => App\Http\Middleware\ValidateApiImpersonation::class,

// routes/api.php
Route::middleware(['auth:api', 'kinship.impersonation.stateless'])
    ->group(function (): void {
        // Stateless support routes.
    });
```

`auth:api` must first authenticate the target from the verified impersonation
credential. Kinship then verifies that the authenticated target matches the
signed grant, resolves both participants, checks expiry, and exposes the state
as `$request->attributes->get('kinship.impersonation')`. The stateless alias is
never placed in the `web` group or attached automatically.

### Required application implementation

The host application must provide all of the following:

1. A token issuer that authenticates the support actor, calls
   `authorizeStateless()`, makes the target the token subject, and signs the full
   Kinship grant as protected claims.
2. A dedicated token type, short lifetime, issuer, audience, and key policy. Do
   not issue an ordinary unrestricted target-user token.
3. An `auth:api` integration that verifies the credential and authenticates its
   target subject before Kinship's stateless middleware runs.
4. A `StatelessImpersonationTokenBroker` implementation that issues, resolves,
   and revokes the provider credential. A middleware subclass is optional and
   overrides `allowsImpersonatedRequest()` for current MFA, account,
   support-shift, compliance, or route conditions.
5. Persistent audit records for issuance, use, denial, and revocation, always
   retaining actor ID, target ID, reason, guard, grant ID, and timestamps.
6. A stop endpoint that revokes the grant ID and makes the client discard the
   credential.

Do not exchange a normal support token for an unrestricted ordinary target-user
token. That loses the actor identity and makes reliable audit and revocation
impossible. Kinship's built-in `impersonate()` flow remains intentionally
limited to stateful guards. To end a stateless impersonation, revoke its `jti`
in the application token provider and discard the client credential; there is
no actor session to restore.

If an existing middleware intentionally rejects every locked identity, leave
its implementation unchanged. Put the support experience in a separately
composed route group that uses the new impersonation middleware instead of the
ordinary login-lock middleware. Both groups can continue to share all other
authentication and authorization middleware. Do not clear `locked_at` merely
to let support enter the account.

## Auditing

Kinship dispatches synchronous `UserImpersonationStarted` and
`UserImpersonationStopped` events. Each event exposes the original actor, target,
guard, required reason, start time, and expiry through `ImpersonationState`.
Applications should persist these events in their audit system and include both
identities when auditing actions performed during the session. Stateless token
adapters must record grant issuance and revocation themselves because
`authorizeStateless()` authorizes a grant but cannot know whether token issuance
ultimately succeeded.

The dashboard should also show an unmissable impersonation banner and a stop
control outside the target user's ordinary navigation permissions.

## Security boundaries

- Impersonation never changes account lock, password, MFA, or suspension data.
- Locked targets are allowed only when the application Gate and middleware allow
  them.
- Session impersonation rejects stateless guards; stateless integrations use the
  separate provider-specific middleware and grant flow.
- Expired sessions restore the actor and reject the in-flight request.
- If the original actor no longer exists, Kinship logs out instead of leaving an
  unrestorable target session.
- Sensitive payments, withdrawals, credential changes, and compliance actions
  should have application middleware or policies that deny them during an
  impersonation session.
