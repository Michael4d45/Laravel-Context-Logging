<?php

declare(strict_types=1);

namespace Michael4d45\ContextLogging\Tests;

use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Event;
use Michael4d45\ContextLogging\ContextStore;
use Michael4d45\ContextLogging\Telegram\TelegramBridge;

class TelegramBridgeTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('context-logging.telegram.enabled', true);
        $app['config']->set('context-logging.telegram.drop', true);
    }

    private function linesNotification(array $lines): Notification
    {
        return new class ($lines) extends Notification
        {
            /** @param list<string> $lines */
            public function __construct(protected array $lines) {}

            public function via(object $notifiable): array
            {
                return ['telegram'];
            }
        };
    }

    public function test_normalize_captures_lines_and_chat_id_without_token(): void
    {
        $store = $this->app->make(ContextStore::class);
        $bridge = new TelegramBridge($store);

        $notifiable = new class
        {
            public string $telegram_channel_id = '-100123';
            public string $telegram_token = 'secret-bot-token';

            public function getKey(): string
            {
                return $this->telegram_channel_id;
            }
        };

        $event = new NotificationSending(
            $notifiable,
            $this->linesNotification(['🚨 User has invalid email - User: 42']),
            'telegram'
        );

        $payload = $bridge->normalize($event);

        $this->assertNotNull($payload);
        $this->assertSame('telegram', $payload['source']);
        $this->assertSame('🚨 User has invalid email - User: 42', $payload['message']);
        $this->assertSame('-100123', $payload['chat_id']);
        $this->assertTrue($payload['dropped']);
        $this->assertStringNotContainsString('secret-bot-token', json_encode($payload));
    }

    public function test_normalize_skips_non_telegram_channels(): void
    {
        $store = $this->app->make(ContextStore::class);
        $bridge = new TelegramBridge($store);

        $event = new NotificationSending(
            new \stdClass,
            new class extends Notification
            {
                public function via(object $notifiable): array
                {
                    return ['mail'];
                }
            },
            'mail'
        );

        $this->assertNull($bridge->normalize($event));
    }

    public function test_capture_adds_event_to_context_store(): void
    {
        $store = $this->app->make(ContextStore::class);
        $store->initialize();
        $bridge = new TelegramBridge($store);

        $notifiable = new class
        {
            public string $telegram_channel_id = '-99';
        };

        $bridge->capture(new NotificationSending(
            $notifiable,
            $this->linesNotification(['hello techops']),
            'telegram'
        ));

        $events = $store->getEvents();
        $this->assertCount(1, $events);
        $this->assertSame('hello techops', $events[0]['message']);
        $this->assertSame('telegram', $events[0]['context']['source']);
        $this->assertSame('warning', $events[0]['level']);
    }

    public function test_register_drops_telegram_notification_when_configured(): void
    {
        $store = $this->app->make(ContextStore::class);
        $store->initialize();

        $notifiable = new class
        {
            public string $telegram_channel_id = '-1';
            public string $telegram_token = 'token';
        };

        $result = Event::until(new NotificationSending(
            $notifiable,
            $this->linesNotification(['do not send']),
            'telegram'
        ));

        $this->assertFalse($result);
        $this->assertCount(1, $store->getEvents());
        $this->assertSame('do not send', $store->getEvents()[0]['message']);
    }

    public function test_register_allows_send_when_drop_disabled(): void
    {
        config(['context-logging.telegram.drop' => false]);

        $store = $this->app->make(ContextStore::class);
        $store->initialize();

        $notifiable = new class
        {
            public string $telegram_channel_id = '-1';
        };

        $result = Event::until(new NotificationSending(
            $notifiable,
            $this->linesNotification(['may send']),
            'telegram'
        ));

        $this->assertNotFalse($result);
        $this->assertCount(1, $store->getEvents());
        $this->assertFalse($store->getEvents()[0]['context']['dropped']);
    }
}
