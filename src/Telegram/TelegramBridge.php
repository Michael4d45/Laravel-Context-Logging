<?php

declare(strict_types=1);

namespace Michael4d45\ContextLogging\Telegram;

use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Facades\Event;
use Michael4d45\ContextLogging\ContextStore;
use Michael4d45\ContextLogging\Support\TraceHelper;
use ReflectionClass;
use ReflectionProperty;

/**
 * Captures Telegram notification sends into the ContextStore.
 *
 * Soft-depends on Laravel notifications. When drop=true (default locally),
 * cancels the send so nothing reaches Telegram — useful for local capture
 * of techops/devops/CS alerts without a BAA or real bot traffic.
 */
final class TelegramBridge
{
    public function __construct(
        private readonly ContextStore $contextStore,
    ) {}

    public function register(): void
    {
        if (! (bool) config('context-logging.telegram.enabled', false)) {
            return;
        }

        if (! class_exists(NotificationSending::class)) {
            return;
        }

        Event::listen(NotificationSending::class, function (NotificationSending $event) {
            if (! $this->isTelegramChannel($event->channel)) {
                return;
            }

            $this->capture($event);

            if ((bool) config('context-logging.telegram.drop', true)) {
                return false;
            }
        });
    }

    public function capture(NotificationSending $event): void
    {
        $payload = $this->normalize($event);
        if ($payload === null) {
            return;
        }

        $message = (string) ($payload['message'] ?? 'Telegram notification');
        $level = (string) ($payload['level'] ?? 'warning');
        unset($payload['level']);

        $this->contextStore->addEvent($level, $message, $payload);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function normalize(NotificationSending $event): ?array
    {
        if (! $this->isTelegramChannel($event->channel)) {
            return null;
        }

        $notification = $event->notification;
        $notifiable = $event->notifiable;

        $text = $this->extractText($notification, $notifiable);
        $chatId = $this->extractChatId($notifiable);
        $dropped = (bool) config('context-logging.telegram.drop', true);

        return array_filter([
            'source' => 'telegram',
            'message' => $text,
            'level' => 'warning',
            'channel' => is_string($event->channel) ? $event->channel : 'telegram',
            'chat_id' => $chatId,
            'notification' => is_object($notification) ? $notification::class : null,
            'notifiable' => $this->describeNotifiable($notifiable),
            'dropped' => $dropped,
            'trace' => TraceHelper::getCollapsedTrace(),
        ], static fn ($v) => $v !== null && $v !== []);
    }

    private function isTelegramChannel(mixed $channel): bool
    {
        return is_string($channel) && strtolower($channel) === 'telegram';
    }

    private function extractText(mixed $notification, mixed $notifiable): string
    {
        if (! is_object($notification)) {
            return 'Telegram notification';
        }

        $lines = $this->readLinesProperty($notification);
        if ($lines !== null) {
            return implode("\n", $lines);
        }

        if (method_exists($notification, 'toTelegram')) {
            try {
                $telegramMessage = $notification->toTelegram($notifiable);
                $fromMessage = $this->textFromTelegramMessage($telegramMessage);
                if ($fromMessage !== null && $fromMessage !== '') {
                    return $fromMessage;
                }
            } catch (\Throwable) {
                // Fall through — missing token/channel on notifiable is common when inspecting.
            }
        }

        return $notification::class;
    }

    /**
     * @return list<string>|null
     */
    private function readLinesProperty(object $notification): ?array
    {
        try {
            $ref = new ReflectionClass($notification);
            if (! $ref->hasProperty('lines')) {
                return null;
            }

            $prop = $ref->getProperty('lines');
            $prop->setAccessible(true);
            $value = $prop->getValue($notification);
            if (! is_array($value)) {
                return null;
            }

            $lines = [];
            foreach ($value as $line) {
                if (is_scalar($line) || $line === null) {
                    $lines[] = (string) $line;
                }
            }

            return $lines !== [] ? $lines : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function textFromTelegramMessage(mixed $telegramMessage): ?string
    {
        if (! is_object($telegramMessage)) {
            return is_string($telegramMessage) ? $telegramMessage : null;
        }

        foreach (['toArray', 'data', 'getPayload'] as $method) {
            if (! method_exists($telegramMessage, $method)) {
                continue;
            }
            try {
                $payload = $telegramMessage->{$method}();
            } catch (\Throwable) {
                continue;
            }
            if (! is_array($payload)) {
                continue;
            }
            if (isset($payload['text']) && is_scalar($payload['text'])) {
                return (string) $payload['text'];
            }
            if (isset($payload['content']) && is_scalar($payload['content'])) {
                return (string) $payload['content'];
            }
        }

        foreach (['content', 'text', 'message'] as $propName) {
            try {
                if (! property_exists($telegramMessage, $propName)
                    && ! (new ReflectionClass($telegramMessage))->hasProperty($propName)) {
                    continue;
                }
                $prop = new ReflectionProperty($telegramMessage, $propName);
                $prop->setAccessible(true);
                $value = $prop->getValue($telegramMessage);
                if (is_scalar($value) && (string) $value !== '') {
                    return (string) $value;
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }

    private function extractChatId(mixed $notifiable): ?string
    {
        if (! is_object($notifiable)) {
            return null;
        }

        foreach (['telegram_channel_id', 'telegram_user_id', 'telegram_chat_id'] as $prop) {
            if (! empty($notifiable->{$prop}) && is_scalar($notifiable->{$prop})) {
                return (string) $notifiable->{$prop};
            }
        }

        if (method_exists($notifiable, 'routeNotificationForTelegram')) {
            try {
                $route = $notifiable->routeNotificationForTelegram();
                if (is_scalar($route) && (string) $route !== '') {
                    return (string) $route;
                }
            } catch (\Throwable) {
                // ignore
            }
        }

        if (method_exists($notifiable, 'getKey')) {
            try {
                $key = $notifiable->getKey();
                if (is_scalar($key) && (string) $key !== '') {
                    return (string) $key;
                }
            } catch (\Throwable) {
                // ignore
            }
        }

        return null;
    }

    private function describeNotifiable(mixed $notifiable): ?string
    {
        if ($notifiable === null) {
            return null;
        }
        if (! is_object($notifiable)) {
            return (string) $notifiable;
        }

        $class = $notifiable::class;
        if (method_exists($notifiable, 'getKey')) {
            try {
                $key = $notifiable->getKey();
                if (is_scalar($key) && (string) $key !== '') {
                    return $class.'#'.$key;
                }
            } catch (\Throwable) {
                // ignore
            }
        }

        return $class;
    }
}
