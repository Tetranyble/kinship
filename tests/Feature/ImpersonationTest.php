<?php

namespace Tetranyble\Kinship\Tests\Feature;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Tetranyble\Kinship\Contracts\StatelessImpersonationTokenBroker;
use Tetranyble\Kinship\Events\UserImpersonationStarted;
use Tetranyble\Kinship\Events\UserImpersonationStopped;
use Tetranyble\Kinship\Exceptions\ImpersonationException;
use Tetranyble\Kinship\Impersonation\ImpersonationManager;
use Tetranyble\Kinship\Impersonation\ImpersonationState;
use Tetranyble\Kinship\Tests\Fixtures\ApiImpersonationMiddleware;
use Tetranyble\Kinship\Tests\Fixtures\EncryptedStatelessTokenBroker;
use Tetranyble\Kinship\Tests\Fixtures\SupportImpersonationMiddleware;
use Tetranyble\Kinship\Tests\Fixtures\User;
use Tetranyble\Kinship\Tests\PackageTestCase;

class ImpersonationTest extends PackageTestCase
{
    use RefreshDatabase;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('kinship.impersonation.enabled', true);
        $app['config']->set('kinship.impersonation.middleware', SupportImpersonationMiddleware::class);
        $app['config']->set('kinship.impersonation.stateless_broker', EncryptedStatelessTokenBroker::class);
        $app['config']->set('kinship.impersonation.stateless_middleware', ApiImpersonationMiddleware::class);
        $app['config']->set('auth.guards.api', [
            'driver' => 'token',
            'provider' => 'users',
            'hash' => false,
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->startSession();

        Gate::define('kinship.impersonate', function (User $actor, User $target): bool {
            return $actor->name === 'Support'
                && $actor->mfa_verified_at !== null
                && $target->locked_at !== null;
        });

        Route::middleware(['web', 'kinship.impersonation.valid'])
            ->get('/impersonation-check', fn (): string => 'OK');

        Route::middleware('kinship.impersonation.stateless')
            ->get('/stateless-impersonation-check', fn (): string => 'OK');

        $this->assertSame(
            SupportImpersonationMiddleware::class,
            app('router')->getMiddleware()['kinship.impersonation.valid'] ?? null,
        );
        $this->assertSame(
            ApiImpersonationMiddleware::class,
            app('router')->getMiddleware()['kinship.impersonation.stateless'] ?? null,
        );
    }

    public function test_authorized_support_user_can_impersonate_a_locked_account_and_restore_their_identity(): void
    {
        Event::fake([UserImpersonationStarted::class, UserImpersonationStopped::class]);
        $support = User::query()->create(['name' => 'Support', 'email' => 'support@example.test', 'mfa_verified_at' => now()]);
        $customer = User::query()->create([
            'name' => 'Customer',
            'email' => 'customer@example.test',
            'locked_at' => now(),
        ]);
        $lockedAt = $customer->locked_at;
        $this->actingAs($support);

        $state = $support->impersonate($customer, 'Ticket SUP-1234');

        $this->assertSame($support->id, $state->actorId);
        $this->assertSame($customer->id, $state->targetId);
        $this->assertSame('web', $state->guard);
        $this->assertSame('Ticket SUP-1234', $state->reason);
        $this->assertTrue(auth()->user()->is($customer));
        $this->assertTrue($customer->isImpersonating());
        $this->assertTrue($customer->impersonator()?->is($support));
        $this->assertEquals($lockedAt, $customer->fresh()->locked_at);
        Event::assertDispatched(UserImpersonationStarted::class);

        $customer->stopImpersonating();

        $this->assertTrue(auth()->user()->is($support));
        $this->assertFalse($support->isImpersonating());
        Event::assertDispatched(UserImpersonationStopped::class);
    }

    public function test_impersonation_middleware_is_not_automatically_applied_to_the_web_group(): void
    {
        $webMiddleware = app('router')->getMiddlewareGroups()['web'] ?? [];

        $this->assertNotContains(SupportImpersonationMiddleware::class, $webMiddleware);
        $this->assertFalse((bool) config('kinship.impersonation.auto_middleware'));
    }

    public function test_stateless_authorization_returns_a_grant_without_switching_identity(): void
    {
        $support = User::query()->create(['name' => 'Support', 'email' => 'support@example.test', 'mfa_verified_at' => now()]);
        $customer = User::query()->create(['name' => 'Customer', 'email' => 'customer@example.test', 'locked_at' => now()]);
        $this->actingAs($support, 'api');

        $grant = app(ImpersonationManager::class)->authorizeStateless(
            actor: $support,
            target: $customer,
            reason: 'Ticket API-1234',
            guard: 'api',
        );

        $this->assertNotSame('', $grant->id);
        $this->assertSame($support->id, $grant->actorId);
        $this->assertSame($customer->id, $grant->targetId);
        $this->assertSame('Ticket API-1234', $grant->reason);
        $this->assertTrue(auth('api')->user()->is($support));
        $this->assertFalse(session()->has('kinship.impersonation'));

        $credential = app(StatelessImpersonationTokenBroker::class)
            ->issueImpersonationToken($customer, $grant);
        $this->actingAs($customer, 'api');

        $this->withHeader('X-Test-Impersonation', $credential->accessToken)
            ->getJson('/stateless-impersonation-check')
            ->assertOk();

        app(StatelessImpersonationTokenBroker::class)->revoke($grant->id);
        $this->withHeader('X-Test-Impersonation', $credential->accessToken)
            ->getJson('/stateless-impersonation-check')
            ->assertUnauthorized();
    }

    public function test_stateless_middleware_rejects_missing_expired_and_mismatched_grants(): void
    {
        $support = User::query()->create(['name' => 'Support', 'email' => 'support@example.test', 'mfa_verified_at' => now()]);
        $customer = User::query()->create(['name' => 'Customer', 'email' => 'customer@example.test', 'locked_at' => now()]);
        $other = User::query()->create(['name' => 'Other', 'email' => 'other@example.test']);
        $this->actingAs($support, 'api');

        $grant = app(ImpersonationManager::class)->authorizeStateless(
            actor: $support,
            target: $customer,
            reason: 'Ticket API-5678',
            guard: 'api',
        );

        $this->actingAs($customer, 'api');
        $this->getJson('/stateless-impersonation-check')->assertUnauthorized();

        $expired = $grant->toArray();
        $expired['expires_at'] = time() - 1;
        $expiredGrant = ImpersonationState::fromArray($expired);
        $this->assertNotNull($expiredGrant);
        $expiredCredential = app(StatelessImpersonationTokenBroker::class)
            ->issueImpersonationToken($customer, $expiredGrant);
        $this->withHeader('X-Test-Impersonation', $expiredCredential->accessToken)
            ->getJson('/stateless-impersonation-check')
            ->assertUnauthorized();

        $credential = app(StatelessImpersonationTokenBroker::class)
            ->issueImpersonationToken($customer, $grant);
        $this->actingAs($other, 'api');
        $this->withHeader('X-Test-Impersonation', $credential->accessToken)
            ->getJson('/stateless-impersonation-check')
            ->assertForbidden();
    }

    public function test_gate_denial_self_impersonation_and_nested_impersonation_fail_closed(): void
    {
        $support = User::query()->create(['name' => 'Support', 'email' => 'support@example.test', 'mfa_verified_at' => now()]);
        $otherSupport = User::query()->create(['name' => 'Other', 'email' => 'other@example.test']);
        $first = User::query()->create(['name' => 'First', 'email' => 'first@example.test', 'locked_at' => now()]);
        $second = User::query()->create(['name' => 'Second', 'email' => 'second@example.test', 'locked_at' => now()]);

        $this->actingAs($otherSupport);
        try {
            $otherSupport->impersonate($first, 'Unauthorized attempt');
            $this->fail('An unauthorized actor was allowed to impersonate.');
        } catch (AuthorizationException) {
            $this->assertTrue(auth()->user()->is($otherSupport));
        }

        $this->actingAs($support);
        try {
            $support->impersonate($support, 'Self impersonation');
            $this->fail('Self impersonation was allowed.');
        } catch (\InvalidArgumentException) {
            $this->assertTrue(auth()->user()->is($support));
        }

        $this->expectException(ImpersonationException::class);
        $support->impersonate($first, 'Ticket SUP-1');
        $first->impersonate($second, 'Nested attempt');
    }

    public function test_expired_impersonation_restores_actor_and_stops_the_request(): void
    {
        $support = User::query()->create(['name' => 'Support', 'email' => 'support@example.test', 'mfa_verified_at' => now()]);
        $customer = User::query()->create(['name' => 'Customer', 'email' => 'customer@example.test', 'locked_at' => now()]);
        $this->actingAs($support);
        $state = $support->impersonate($customer, 'Ticket SUP-2');
        $expired = $state->toArray();
        $expired['expires_at'] = time() - 1;
        session()->put('kinship.impersonation', $expired);

        $this->getJson('/impersonation-check')
            ->assertStatus(419)
            ->assertJson(['status' => false]);

        $this->assertTrue(auth()->user()->is($support));
        $this->assertNull(app(ImpersonationManager::class)->state());
    }

    public function test_extended_middleware_revalidates_application_conditions_on_every_request(): void
    {
        $support = User::query()->create(['name' => 'Support', 'email' => 'support@example.test', 'mfa_verified_at' => now()]);
        $customer = User::query()->create(['name' => 'Customer', 'email' => 'customer@example.test', 'locked_at' => now()]);
        $this->actingAs($support);
        $support->impersonate($customer, 'Ticket SUP-4');

        $support->update(['mfa_verified_at' => null]);

        $this->getJson('/impersonation-check')
            ->assertForbidden()
            ->assertJson(['status' => false]);

        $this->assertTrue(auth()->user()->is($support));
        $this->assertNull(app(ImpersonationManager::class)->state());
    }

    public function test_impersonation_is_opt_in_and_requires_a_reason(): void
    {
        $support = User::query()->create(['name' => 'Support', 'email' => 'support@example.test', 'mfa_verified_at' => now()]);
        $customer = User::query()->create(['name' => 'Customer', 'email' => 'customer@example.test', 'locked_at' => now()]);
        $this->actingAs($support);

        config(['kinship.impersonation.enabled' => false]);
        try {
            $support->impersonate($customer, 'Ticket SUP-3');
            $this->fail('Disabled impersonation was allowed.');
        } catch (ImpersonationException) {
            $this->assertTrue(auth()->user()->is($support));
        }

        config(['kinship.impersonation.enabled' => true]);
        $this->expectException(\InvalidArgumentException::class);
        $support->impersonate($customer, '');
    }

    public function test_disabling_impersonation_while_active_restores_the_actor(): void
    {
        $support = User::query()->create(['name' => 'Support', 'email' => 'support@example.test', 'mfa_verified_at' => now()]);
        $customer = User::query()->create(['name' => 'Customer', 'email' => 'customer@example.test', 'locked_at' => now()]);
        $this->actingAs($support);
        $support->impersonate($customer, 'Ticket SUP-5');

        config(['kinship.impersonation.enabled' => false]);

        $this->getJson('/impersonation-check')
            ->assertForbidden()
            ->assertJson(['status' => false]);

        $this->assertTrue(auth()->user()->is($support));
        $this->assertNull(app(ImpersonationManager::class)->state());
    }

    public function test_corrupt_impersonation_state_fails_closed_and_logs_out(): void
    {
        $support = User::query()->create(['name' => 'Support', 'email' => 'support@example.test', 'mfa_verified_at' => now()]);
        $customer = User::query()->create(['name' => 'Customer', 'email' => 'customer@example.test', 'locked_at' => now()]);
        $this->actingAs($support);
        $support->impersonate($customer, 'Ticket SUP-6');

        session()->put('kinship.impersonation', ['guard' => 'web', 'actor_id' => $support->id]);

        $this->getJson('/impersonation-check')
            ->assertForbidden()
            ->assertJson(['status' => false]);

        $this->assertGuest();
        $this->assertFalse(session()->has('kinship.impersonation'));
    }

    public function test_deleted_actor_cannot_leave_the_target_authenticated(): void
    {
        $support = User::query()->create(['name' => 'Support', 'email' => 'support@example.test', 'mfa_verified_at' => now()]);
        $customer = User::query()->create(['name' => 'Customer', 'email' => 'customer@example.test', 'locked_at' => now()]);
        $this->actingAs($support);
        $support->impersonate($customer, 'Ticket SUP-7');

        $support->delete();

        $this->getJson('/impersonation-check')
            ->assertForbidden()
            ->assertJson(['status' => false]);

        $this->assertGuest();
        $this->assertFalse(session()->has('kinship.impersonation'));
    }
}
