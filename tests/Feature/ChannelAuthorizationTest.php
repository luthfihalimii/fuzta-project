<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ChannelAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    // phpunit.xml pins BROADCAST_CONNECTION=null; NullBroadcaster::auth() skips
    // routes/channels.php closures and accepts every channel, so deny cases
    // would pass for the wrong reason. Use the real reverb (pusher) driver.
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-secret',
            'broadcasting.connections.reverb.app_id' => '1',
        ]);

        // Closures from boot live on a driver instance that this new config
        // replaces, so register them again for the reverb driver.
        require base_path('routes/channels.php');
        Broadcast::connection();
    }

    public static function staffMatrix(): array
    {
        return [
            'kitchen own kitchen' => ['kitchen', 'kitchen', 200],
            'kitchen other kitchen' => ['kitchen', 'kitchen-other', 403],
            'kitchen own pos' => ['kitchen', 'pos', 403],
            'cashier own pos' => ['cashier', 'pos', 200],
            'cashier other pos' => ['cashier', 'pos-other', 403],
            'cashier own kitchen' => ['cashier', 'kitchen', 403],
            'owner other kitchen' => ['owner', 'kitchen-other', 200],
            'owner own pos' => ['owner', 'pos', 200],
        ];
    }

    #[DataProvider('staffMatrix')]
    public function test_staff_channel_access_is_scoped_by_branch_and_role(string $role, string $target, int $status): void
    {
        $branchA = Branch::factory()->create();
        $branchB = Branch::factory()->create();
        $user = User::factory()->{$role}()->for($branchA)->create();

        $isOther = str_ends_with($target, '-other');
        $channel = (string) str($target)->beforeLast('-other').'.'.($isOther ? $branchB : $branchA)->id;

        $response = $this->authorize($user, $channel);

        $response->assertStatus($status);

        if ($status === 200) {
            $response->assertJsonStructure(['auth']);
        }
    }

    public function test_guest_cannot_join_kitchen_channel(): void
    {
        $branch = Branch::factory()->create();

        $this->postJson('/broadcasting/auth', [
            'channel_name' => 'private-kitchen.'.$branch->id,
            'socket_id' => '1.2',
        ])->assertForbidden();
    }

    private function authorize(User $user, string $channel): TestResponse
    {
        return $this->actingAs($user)->postJson('/broadcasting/auth', [
            'channel_name' => 'private-'.$channel,
            'socket_id' => '1.2',
        ]);
    }
}
