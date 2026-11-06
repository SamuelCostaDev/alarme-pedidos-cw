<?php

namespace Tests\Feature;

use App\Jobs\SendOrderAlarm;
use App\Models\Installation;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cardapioweb.webhook_token' => 'segredo-webhook']);
        Queue::fake();
    }

    private function installation(string $merchant = '3268'): Installation
    {
        return Installation::create([
            'company_external_id' => $merchant,
            'company_name' => 'Frederico Pasta e Grill',
            'access_token' => 'at',
            'refresh_token' => 'rt',
            'token_expires_at' => now()->addHour(),
        ]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'event_id' => '067c677bf1c096ad7db136dc',
            'event_type' => 'ORDER_CREATED',
            'merchant_id' => 3268,
            'order_id' => 237456,
            'order_status' => 'waiting_confirmation',
            'created_at' => '2023-06-22T19:04:20.292-03:00',
        ], $overrides);
    }

    public function test_rejects_missing_or_wrong_token(): void
    {
        $this->postJson('/api/webhooks/cardapioweb', $this->payload())->assertStatus(401);
        $this->postJson('/api/webhooks/cardapioweb', $this->payload(), ['X-Webhook-Token' => 'errado'])
            ->assertStatus(401);
        Queue::assertNothingPushed();
    }

    public function test_new_order_dispatches_alarm(): void
    {
        $inst = $this->installation();

        $this->postJson('/api/webhooks/cardapioweb', $this->payload(), ['X-Webhook-Token' => 'segredo-webhook'])
            ->assertOk();

        Queue::assertPushed(SendOrderAlarm::class, fn ($job) => $job->installationId === $inst->id
            && $job->kind === 'order'
            && $job->orderId === '237456');
        $this->assertSame('alarm_dispatched', WebhookEvent::first()->result);
    }

    public function test_retried_event_is_not_dispatched_twice(): void
    {
        $this->installation();
        $headers = ['X-Webhook-Token' => 'segredo-webhook'];

        $this->postJson('/api/webhooks/cardapioweb', $this->payload(), $headers)->assertOk();
        $this->postJson('/api/webhooks/cardapioweb', $this->payload(), $headers)
            ->assertOk()->assertJson(['duplicate' => true]);

        Queue::assertPushed(SendOrderAlarm::class, 1);
    }

    public function test_closed_table_orders_do_not_ring(): void
    {
        $this->installation();

        $this->postJson('/api/webhooks/cardapioweb', $this->payload(['order_status' => 'closed']),
            ['X-Webhook-Token' => 'segredo-webhook'])->assertOk();

        Queue::assertNothingPushed();
        $this->assertSame('ignored_status', WebhookEvent::first()->result);
    }

    public function test_status_updates_do_not_ring(): void
    {
        $this->installation();

        $this->postJson('/api/webhooks/cardapioweb', $this->payload(['event_type' => 'ORDER_STATUS_UPDATED']),
            ['X-Webhook-Token' => 'segredo-webhook'])->assertOk();

        Queue::assertNothingPushed();
    }

    public function test_unknown_store_is_acknowledged_but_ignored(): void
    {
        $this->postJson('/api/webhooks/cardapioweb', $this->payload(['merchant_id' => 999]),
            ['X-Webhook-Token' => 'segredo-webhook'])->assertOk();

        Queue::assertNothingPushed();
        $this->assertSame('unknown_merchant', WebhookEvent::first()->result);
    }
}
