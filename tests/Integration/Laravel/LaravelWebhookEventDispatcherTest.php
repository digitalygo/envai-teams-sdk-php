<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Integration\Laravel;

use Digitalygo\Envai\Integration\Laravel\Events\EnvaiWebhookReceived;
use Digitalygo\Envai\Integration\Laravel\WebhookDispatcher\LaravelWebhookEventDispatcher;
use Digitalygo\Envai\Webhooks\WebhookPayload;
use Digitalygo\Envai\Webhooks\WebhookReceived;
use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;

final class LaravelWebhookEventDispatcherTest extends IntegrationTestCase
{
    public function test_dispatch_projects_and_delivers_the_laravel_event_to_listeners(): void
    {
        $dispatcher = new Dispatcher(new Container);
        $recorded = [];
        $dispatcher->listen(EnvaiWebhookReceived::class, static function (EnvaiWebhookReceived $event) use (&$recorded): void {
            $recorded[] = $event;
        });
        $coreEvent = new WebhookReceived(
            'evt_123',
            'created',
            WebhookPayload::fromJson('{"items":[{"ok":true}]}'),
        );

        (new LaravelWebhookEventDispatcher($dispatcher, 'staging'))->dispatch($coreEvent);

        $this->assertCount(1, $recorded);
        $this->assertInstanceOf(EnvaiWebhookReceived::class, $recorded[0]);
        $this->assertSame('evt_123', $recorded[0]->eventId);
        $this->assertSame('created', $recorded[0]->eventType);
        $this->assertSame('staging', $recorded[0]->connectionName);
    }
}
