<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Tests\TestCase;

/**
 * Every row of App\Exceptions\Handler's mapping table, exercised for real
 * through the HTTP stack.
 */
final class ExceptionEnvelopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $throw = fn (\Throwable $e) => Route::middleware('api')->get(
            '/api/v1/_throw',
            fn () => throw $e
        );

        $this->throwRoute = $throw;
    }

    /** @var callable */
    private $throwRoute;

    public function test_validation_exception_is_422_with_errors_nested_under_data(): void
    {
        ($this->throwRoute)(ValidationException::withMessages([
            'username' => ['اسم المستخدم مطلوب'],
            'email' => ['بريد غير صالح', 'مستخدم بالفعل'],
        ]));

        $response = $this->getJson('/api/v1/_throw');

        $response->assertStatus(422);

        // The contract nests errors under data.errors — NOT at the top level,
        // which is where Laravel would put them by default.
        $response->assertJsonPath('data.errors.username.0', 'اسم المستخدم مطلوب');
        $response->assertJsonPath('data.errors.email.1', 'مستخدم بالفعل');

        $payload = $response->json();
        $this->assertArrayNotHasKey('errors', $payload, 'errors must NOT be a top-level key');
        $this->assertFalse($payload['success']);
    }

    public function test_authentication_exception_is_401(): void
    {
        ($this->throwRoute)(new AuthenticationException());

        $this->getJson('/api/v1/_throw')
            ->assertStatus(401)
            ->assertJson(['success' => false, 'message' => 'Unauthenticated']);
    }

    public function test_authorization_exception_is_403(): void
    {
        ($this->throwRoute)(new AuthorizationException());

        $this->getJson('/api/v1/_throw')
            ->assertStatus(403)
            ->assertJson(['success' => false, 'message' => 'Forbidden']);
    }

    public function test_model_not_found_is_404_and_leaks_no_class_name(): void
    {
        $e = new ModelNotFoundException();
        $e->setModel(\App\Models\User::class, ['abc-123']);

        ($this->throwRoute)($e);

        $response = $this->getJson('/api/v1/_throw');

        $response->assertStatus(404)->assertJson(['message' => 'Not found']);
        $this->assertStringNotContainsString('App\\Models\\User', $response->getContent());
        $this->assertStringNotContainsString('abc-123', $response->getContent());
    }

    /**
     * 429 must carry retry_after in the payload AND a real Retry-After header,
     * with an Arabic message.
     */
    public function test_too_many_requests_is_429_with_retry_after_and_arabic_message(): void
    {
        ($this->throwRoute)(new TooManyRequestsHttpException(37, 'throttled'));

        $response = $this->getJson('/api/v1/_throw');

        $response->assertStatus(429);
        $response->assertHeader('Retry-After', '37');
        $response->assertJsonPath('data.retry_after', 37);

        $this->assertStringContainsString('عدد المحاولات كبير جداً', $response->json('message'));
        $this->assertStringContainsString('37', $response->json('message'));

        // Arabic must be literal UTF-8 on the wire.
        $this->assertStringNotContainsString('\\u', $response->getContent());
    }

    public function test_unexpected_exception_is_500_with_no_details_when_debug_is_off(): void
    {
        config()->set('app.debug', false);

        ($this->throwRoute)(new RuntimeException('a very secret internal detail'));

        $response = $this->getJson('/api/v1/_throw');

        $response->assertStatus(500);
        $response->assertJson([
            'success' => false,
            'message' => 'Internal server error',
        ]);

        $body = $response->getContent();
        $this->assertStringNotContainsString('a very secret internal detail', $body);
        $this->assertStringNotContainsString('trace', $body);
        $this->assertStringNotContainsString('RuntimeException', $body);
        $this->assertSame([], $response->json('data'));
    }

    /* ────────────────────── logging behaviour ────────────────────── */

    public function test_401_is_logged_as_a_warning_on_the_security_channel(): void
    {
        Log::shouldReceive('channel')->with('security')->once()->andReturnSelf();
        Log::shouldReceive('warning')
            ->once()
            ->withArgs(fn (string $message, array $ctx) => $message === 'Unauthorized access' && $ctx['status'] === 401);

        \App\Exceptions\Handler::report(new AuthenticationException());
    }

    public function test_403_is_logged_as_a_warning_on_the_security_channel(): void
    {
        Log::shouldReceive('channel')->with('security')->once()->andReturnSelf();
        Log::shouldReceive('warning')
            ->once()
            ->withArgs(fn (string $message, array $ctx) => $message === 'Forbidden' && $ctx['status'] === 403);

        \App\Exceptions\Handler::report(new AuthorizationException());
    }

    public function test_500_is_logged_as_an_error_on_the_app_channel_with_a_trace(): void
    {
        Log::shouldReceive('channel')->with('app')->once()->andReturnSelf();
        Log::shouldReceive('error')
            ->once()
            ->withArgs(fn (string $message, array $ctx) => $message === 'Server error' && isset($ctx['trace']));

        \App\Exceptions\Handler::report(new RuntimeException('boom'));
    }

    public function test_other_4xx_is_logged_at_info_on_the_app_channel(): void
    {
        Log::shouldReceive('channel')->with('app')->once()->andReturnSelf();
        Log::shouldReceive('info')
            ->once()
            ->withArgs(fn (string $message, array $ctx) => $message === 'Client error' && $ctx['status'] === 422);

        \App\Exceptions\Handler::report(ValidationException::withMessages(['a' => ['b']]));
    }

    /** 429 is the limiter's business, not the exception handler's. */
    public function test_429_is_not_logged_by_the_exception_handler(): void
    {
        Log::shouldReceive('channel')->never();

        \App\Exceptions\Handler::report(new TooManyRequestsHttpException(60, 'throttled'));
    }

    /**
     * THE LOG MUST NEVER CONTAIN THE REQUEST BODY — a 422 on a login form
     * would otherwise write the submitted password into storage/logs.
     */
    public function test_log_context_never_contains_the_request_body(): void
    {
        $captured = null;

        Log::shouldReceive('channel')->with('app')->once()->andReturnSelf();
        Log::shouldReceive('info')->once()->withArgs(function (string $m, array $ctx) use (&$captured) {
            $captured = $ctx;

            return true;
        });

        $this->call(
            'POST',
            '/api/v1/health',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['password' => 'SuperSecret123', 'username' => 'victim'])
        );

        \App\Exceptions\Handler::report(ValidationException::withMessages(['a' => ['b']]));

        $serialised = json_encode($captured);
        $this->assertStringNotContainsString('SuperSecret123', (string) $serialised);
        $this->assertStringNotContainsString('password', (string) $serialised);
    }

    /**
     * ┌──────────────────────────────────────────────────────────────────────┐
     * │ REGRESSION — A GUEST WITHOUT `Accept: application/json` MUST STILL   │
     * │ GET 401, NOT 500.                                                    │
     * │                                                                      │
     * │ Laravel's Authenticate middleware only THROWS when                   │
     * │ $request->expectsJson(); otherwise it asks for a redirect target,    │
     * │ and the framework's default is `route('login')` — a route this       │
     * │ HTML-less application does not define. That threw                    │
     * │ RouteNotFoundException and produced a 500 with a full stack trace.   │
     * │                                                                      │
     * │ THIS BUG SURVIVED THE ENTIRE STAGE-1 SUITE because getJson()/        │
     * │ postJson() set the Accept header for you, so every existing test     │
     * │ took the throw branch. It was found by a live `curl` during Stage 2. │
     * │ This test therefore uses ->call() with NO Accept header at all — the │
     * │ only way to reach the other branch.                                  │
     * │                                                                      │
     * │ Fixed by bootstrap/app.php's redirectGuestsTo(fn () => null).        │
     * └──────────────────────────────────────────────────────────────────────┘
     */
    public function test_a_guest_without_an_accept_header_still_gets_401(): void
    {
        // /api/v1/catalogs/grades is deliberately excluded here: decision 10.11
        // made catalog reads public (the unauthenticated signup wizard renders
        // them), so a guest hitting it now gets 200, not 401.
        foreach (['/api/v1/auth/me'] as $path) {
            $response = $this->call('GET', $path);

            $response->assertStatus(401);
            $this->assertSame('Unauthenticated', $response->json('message'));
        }
    }
}
