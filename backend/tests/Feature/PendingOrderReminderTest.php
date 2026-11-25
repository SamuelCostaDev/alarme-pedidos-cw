<?php

namespace Tests\Feature;

use App\Jobs\SendOrderAlarm;
use App\Models\Installation;
use App\Models\OpenOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PendingOrderReminderTest extends TestCase
{
    use RefreshDatabase;

    private Installation $installation;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->installation = Installation::create([
            'company_external_id' => '12743',
            'company_name' => 'Samuel Testes',
            'access_token' => 'at',
            'refresh_token' => 'rt',
            'token_expires_at' => now()->addHour(),
        ]);
    }

    private function openPending(): OpenOrder
    {
        return OpenOrder::create([
            'installation_id' => $this->installation->id,
            'order_id' => '12345',
            'kind' => 'pending',
            'status' => 'waiting_confirmation',
            'first_seen_at' => now(),
            'last_alert_at' => now(),
        ]);
    }

    public function test_reminds_every_three_minutes_up_to_five_times(): void
    {
        $order = $this->openPending();

        for ($i = 1; $i <= 5; $i++) {
            $this->travel(3)->minutes();
            $this->artisan('alarme:lembretes')->assertExitCode(0);
            $this->assertSame($i, $order->fresh()->reminders_sent);
        }

        $this->travel(3)->minutes();
        $this->artisan('alarme:lembretes')->assertExitCode(0);
        Queue::assertPushed(SendOrderAlarm::class, 5);
    }

    public function test_resolved_order_does_not_receive_reminders(): void
    {
        $order = $this->openPending();
        $order->update(['resolved_at' => now()]);

        $this->travel(10)->minutes();
        $this->artisan('alarme:lembretes')->assertExitCode(0);

        Queue::assertNothingPushed();
    }

    public function test_order_older_than_six_hours_is_closed(): void
    {
        $order = $this->openPending();
        $order->update(['first_seen_at' => now()->subHours(7)]);

        $this->artisan('alarme:lembretes')->assertExitCode(0);

        $this->assertNotNull($order->fresh()->resolved_at);
        Queue::assertNothingPushed();
    }
}
