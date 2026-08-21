<?php

namespace Tetranyble\Kinship\Impersonation;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthManager;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tetranyble\Kinship\Contracts\GuardResolver;
use Tetranyble\Kinship\Contracts\ImpersonationAuthorizer;
use Tetranyble\Kinship\Events\UserImpersonationStarted;
use Tetranyble\Kinship\Events\UserImpersonationStopped;
use Tetranyble\Kinship\Exceptions\ImpersonationException;

final class ImpersonationManager
{
    public function __construct(
        private readonly AuthManager $auth,
        private readonly Session $session,
        private readonly GuardResolver $guards,
        private readonly ImpersonationAuthorizer $authorizer,
        private readonly Dispatcher $events,
    ) {}

    public function start(Authenticatable $target, string $reason, ?string $guard = null): ImpersonationState
    {
        return $this->startFor(null, $target, $reason, $guard);
    }

    public function startFor(
        ?Authenticatable $expectedActor,
        Authenticatable $target,
        string $reason,
        ?string $guard = null,
    ): ImpersonationState {
        $this->assertEnabled();

        if ($this->hasStatePayload()) {
            throw new ImpersonationException('Nested user impersonation is not allowed.');
        }

        $guardName = $this->guards->resolve(requestedGuard: $guard);
        $statefulGuard = $this->statefulGuard($guardName);
        $actor = $statefulGuard->user();

        if (! $actor instanceof Authenticatable || ! $actor instanceof Model) {
            throw new ImpersonationException('An authenticated Eloquent actor is required to start impersonation.');
        }

        if ($expectedActor !== null && ! $this->sameUser($actor, $expectedActor)) {
            throw new AuthorizationException('Only the currently authenticated user can start impersonation.');
        }

        $state = $this->authorizeState($actor, $target, $reason, $guardName);

        $this->auth->shouldUse($guardName);
        $statefulGuard->login($target);
        $this->session->regenerate(true);
        $this->session->put($this->sessionKey(), $state->toArray());

        if (method_exists($target, 'clearAssumedRoles')) {
            $target->clearAssumedRoles();
        }

        $this->events->dispatch(new UserImpersonationStarted($actor, $target, $state));

        return $state;
    }

    /**
     * Authorize and describe a stateless impersonation without issuing a token
     * or changing the authenticated identity. The host token provider owns
     * credential issuance, validation, rotation, revocation, and auditing.
     */
    public function authorizeStateless(
        Authenticatable $actor,
        Authenticatable $target,
        string $reason,
        ?string $guard = null,
    ): ImpersonationState {
        $this->assertEnabled();

        $guardName = $this->guards->resolve(requestedGuard: $guard);
        $authenticatedActor = $this->auth->guard($guardName)->user();

        if (! $authenticatedActor instanceof Authenticatable || ! $this->sameUser($authenticatedActor, $actor)) {
            throw new AuthorizationException('Only the currently authenticated user can request stateless impersonation.');
        }

        return $this->authorizeState($actor, $target, $reason, $guardName);
    }

    public function stop(): ?ImpersonationState
    {
        $state = $this->state();
        if ($state === null) {
            return null;
        }

        $guard = $this->statefulGuard($state->guard);
        $actor = $this->loadUser($state->actorType, $state->actorId);
        $target = $this->loadUser($state->targetType, $state->targetId);

        if ($actor === null) {
            $guard->logout();
            $this->session->forget($this->sessionKey());
            $this->session->regenerate(true);

            throw new ImpersonationException('The original impersonator account can no longer be restored.');
        }

        $this->auth->shouldUse($state->guard);
        $guard->login($actor);
        $this->session->regenerate(true);
        $this->session->forget($this->sessionKey());
        $this->events->dispatch(new UserImpersonationStopped($actor, $target, $state));

        return $state;
    }

    public function state(): ?ImpersonationState
    {
        return ImpersonationState::fromArray($this->session->get($this->sessionKey()));
    }

    public function hasStatePayload(): bool
    {
        return $this->session->has($this->sessionKey());
    }

    public function enabled(): bool
    {
        return (bool) config('kinship.impersonation.enabled', false);
    }

    public function active(): bool
    {
        $state = $this->state();

        return $state !== null && ! $state->expired() && $this->currentUserMatchesTarget($state);
    }

    public function expired(): bool
    {
        return $this->state()?->expired() ?? false;
    }

    public function currentUserMatchesTarget(?ImpersonationState $state = null): bool
    {
        $state ??= $this->state();
        if ($state === null) {
            return false;
        }

        $user = $this->auth->guard($state->guard)->user();

        return $user instanceof Authenticatable
            && $user::class === $state->targetType
            && (string) $user->getAuthIdentifier() === (string) $state->targetId;
    }

    public function actor(?ImpersonationState $state = null): ?Authenticatable
    {
        $state ??= $this->state();

        return $state === null ? null : $this->loadUser($state->actorType, $state->actorId);
    }

    public function target(?ImpersonationState $state = null): ?Authenticatable
    {
        $state ??= $this->state();

        return $state === null ? null : $this->loadUser($state->targetType, $state->targetId);
    }

    public function abandon(): void
    {
        $this->session->forget($this->sessionKey());
    }

    /**
     * Fail closed when the stored state cannot be trusted enough to restore the actor.
     */
    public function discardInvalidState(): void
    {
        $guardName = $this->guards->resolve();
        $guard = $this->auth->guard($guardName);

        if ($guard instanceof StatefulGuard) {
            $guard->logout();
        }

        $this->session->forget($this->sessionKey());
        $this->session->regenerate(true);
    }

    private function assertEnabled(): void
    {
        if (! $this->enabled()) {
            throw new ImpersonationException('Kinship user impersonation is disabled.');
        }
    }

    private function statefulGuard(string $name): StatefulGuard
    {
        $guard = $this->auth->guard($name);

        if (! $guard instanceof StatefulGuard) {
            throw new ImpersonationException("Kinship impersonation requires a stateful guard; [{$name}] is not stateful.");
        }

        return $guard;
    }

    private function sessionKey(): string
    {
        $key = config('kinship.impersonation.session_key', 'kinship.impersonation');
        if (! is_string($key) || trim($key) === '') {
            throw new ImpersonationException('Kinship impersonation session key must be a non-empty string.');
        }

        return trim($key);
    }

    private function sameUser(Authenticatable $first, Authenticatable $second): bool
    {
        return $first::class === $second::class
            && $first->getAuthIdentifier() !== null
            && (string) $first->getAuthIdentifier() === (string) $second->getAuthIdentifier();
    }

    private function identifier(Authenticatable $user): int|string
    {
        $identifier = $user->getAuthIdentifier();
        if (! is_int($identifier) && ! is_string($identifier)) {
            throw new InvalidArgumentException('Kinship impersonation requires an integer or string user identifier.');
        }

        return $identifier;
    }

    private function authorizeState(
        Authenticatable $actor,
        Authenticatable $target,
        string $reason,
        string $guard,
    ): ImpersonationState {
        if (! $actor instanceof Model || $actor->getAuthIdentifier() === null) {
            throw new InvalidArgumentException('The impersonation actor must be a persisted Eloquent authenticatable model.');
        }

        if (! $target instanceof Model || $target->getAuthIdentifier() === null) {
            throw new InvalidArgumentException('The impersonation target must be a persisted Eloquent authenticatable model.');
        }

        if ($this->sameUser($actor, $target)) {
            throw new InvalidArgumentException('A user cannot impersonate themselves.');
        }

        if ((bool) config('kinship.impersonation.require_same_user_type', true)
            && $actor::class !== $target::class) {
            throw new ImpersonationException('The actor and target must use the same user model type.');
        }

        $reason = trim($reason);
        $maximum = (int) config('kinship.impersonation.max_reason_length', 1000);
        if ((bool) config('kinship.impersonation.require_reason', true) && $reason === '') {
            throw new InvalidArgumentException('An impersonation reason or support reference is required.');
        }

        if ($maximum < 1 || strlen($reason) > $maximum) {
            throw new InvalidArgumentException("The impersonation reason must not exceed {$maximum} bytes.");
        }

        if (! $this->authorizer->authorize($actor, $target, $guard)) {
            throw new AuthorizationException('This user is not authorized to impersonate the target account.');
        }

        $ttl = (int) config('kinship.impersonation.ttl', 1800);
        if ($ttl < 1) {
            throw new ImpersonationException('Kinship impersonation TTL must be at least one second.');
        }

        $now = time();

        return new ImpersonationState(
            id: (string) Str::uuid(),
            actorType: $actor::class,
            actorId: $this->identifier($actor),
            targetType: $target::class,
            targetId: $this->identifier($target),
            guard: $guard,
            reason: $reason,
            startedAt: $now,
            expiresAt: $now + $ttl,
        );
    }

    /** @return (Model&Authenticatable)|null */
    private function loadUser(string $type, int|string $identifier): ?Authenticatable
    {
        if (! class_exists($type)
            || ! is_a($type, Model::class, true)
            || ! is_a($type, Authenticatable::class, true)) {
            return null;
        }

        /** @var Model&Authenticatable $model */
        $model = new $type;
        $user = $model->newQuery()
            ->where($model->getAuthIdentifierName(), $identifier)
            ->first();

        return $user instanceof Authenticatable ? $user : null;
    }
}
