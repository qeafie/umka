<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MaxAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private const BOT_TOKEN = 'test-max-bot-token';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.max.bot_token' => self::BOT_TOKEN]);
    }

    public function test_login_is_unavailable_until_a_bot_token_is_configured(): void
    {
        config(['services.max.bot_token' => null]);

        $this->postJson('/auth/max', ['initData' => 'launch-data'])
            ->assertServiceUnavailable();

        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_valid_max_launch_data_creates_and_authenticates_a_resident(): void
    {
        $response = $this->postJson('/auth/max', [
            'initData' => $this->signedInitData(),
        ]);

        $response
            ->assertOk()
            ->assertJsonStructure(['csrfToken', 'user' => ['name', 'role']])
            ->assertJsonPath('user.name', 'Анна Иванова')
            ->assertJsonPath('user.role', 'resident');

        $this->assertAuthenticated();
        $this->assertDatabaseHas('users', [
            'max_user_id' => '900100200',
            'name' => 'Анна Иванова',
            'role' => 'resident',
        ]);
    }

    public function test_launch_data_with_an_invalid_signature_is_rejected(): void
    {
        $this->postJson('/auth/max', [
            'initData' => $this->signedInitData('900100201').'tampered',
        ])->assertUnauthorized();

        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_launch_data_older_than_one_day_is_rejected(): void
    {
        $this->postJson('/auth/max', [
            'initData' => $this->signedInitData('900100202', now()->subDay()->subMinute()->timestamp),
        ])->assertUnauthorized();

        $this->assertGuest();
    }

    public function test_duplicate_launch_parameters_are_rejected(): void
    {
        $initData = $this->signedInitData('900100203');

        $this->postJson('/auth/max', [
            'initData' => $initData.'&user='.urlencode(json_encode(['id' => 22])),
        ])->assertUnauthorized();

        $this->assertDatabaseCount('users', 0);
    }

    public function test_repeated_login_updates_the_same_max_account(): void
    {
        $this->postJson('/auth/max', ['initData' => $this->signedInitData('900100204')])->assertOk();
        User::query()->where('max_user_id', '900100204')->update(['role' => 'dispatcher']);
        auth()->logout();
        $this->postJson('/auth/max', ['initData' => $this->signedInitData('900100204')])->assertOk();

        $this->assertSame(1, User::query()->where('max_user_id', '900100204')->count());
        $this->assertDatabaseHas('users', ['max_user_id' => '900100204', 'role' => 'dispatcher']);
    }

    private function signedInitData(string $userId = '900100200', ?int $authDate = null): string
    {
        $parameters = [
            'auth_date' => (string) ($authDate ?? now()->timestamp),
            'user' => json_encode([
                'id' => (int) $userId,
                'first_name' => 'Анна',
                'last_name' => 'Иванова',
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        ];
        ksort($parameters, SORT_STRING);
        $dataCheckString = collect($parameters)
            ->map(fn (string $value, string $key): string => $key.'='.$value)
            ->implode("\n");
        $secretKey = hash_hmac('sha256', self::BOT_TOKEN, 'WebAppData', true);
        $parameters['hash'] = hash_hmac('sha256', $dataCheckString, $secretKey);

        return http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);
    }
}
