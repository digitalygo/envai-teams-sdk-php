<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Streaming\Atp;

use Digitalygo\Envai\Streaming\Events\AtpEvent;
use Digitalygo\Envai\Streaming\Events\BillingCreditExhaustedEvent;
use Digitalygo\Envai\Streaming\Events\BillingLimitReachedEvent;
use Digitalygo\Envai\Streaming\Events\ChatCronWakeupEvent;
use Digitalygo\Envai\Streaming\Events\ChatTitleUpdatedEvent;
use Digitalygo\Envai\Streaming\Events\EventEnvelope;
use Digitalygo\Envai\Streaming\Events\ProtocolViolationEvent;

final class AdditionalEventDecoder
{
    /** @param array<string, mixed> $data */
    public static function decode(EventEnvelope $envelope, array $data): AtpEvent
    {
        $type = $envelope->type;
        $violations = [];
        $fields = match ($type) {
            'chat.title.updated' => ['title'],
            'chat.cron.wakeup' => ['cron_id', 'occurrence_id', 'scheduled_at', 'delivered_at', 'prompt', 'target_mode', 'dispatch_mode'],
            default => ['reason', 'scope', 'currency'],
        };
        /** @var array<string, string|null> $values */
        $values = [];
        foreach ($fields as $field) {
            $values[$field] = Payload::string($data, $field, $violations, $type);
        }
        if ($type !== 'chat.title.updated' && $envelope->v !== 3) {
            $violations[] = 'Event requires ATP v3.';
        }
        if ($envelope->agent !== null) {
            $violations[] = 'Event requires null agent.';
        }
        if ($type === 'chat.title.updated' && isset($values['title'])) {
            $title = $values['title'];
            if (mb_strlen($title) > 255 || ! preg_match('/^[^\r\n\x{000B}\x{000C}\x{0085}\x{2028}\x{2029}]*[^\s\x{0085}\x{2028}\x{2029}][^\r\n\x{000B}\x{000C}\x{0085}\x{2028}\x{2029}]*$/u', $title)) {
                $violations[] = 'Invalid chat title.';
            }
        }
        if ($type === 'chat.cron.wakeup') {
            foreach (['cron_id' => 'cron_', 'occurrence_id' => 'occ_'] as $field => $prefix) {
                if (isset($values[$field]) && ! preg_match('/^'.$prefix.'[0-7][0-9a-hjkmnp-tv-zA-HJKMNP-TV-Z]{25}$/D', $values[$field])) {
                    $violations[] = 'Invalid '.$field.'.';
                }
            }
            foreach (['scheduled_at', 'delivered_at'] as $field) {
                if (isset($values[$field]) && ! self::dateTime($values[$field])) {
                    $violations[] = 'Invalid '.$field.'.';
                }
            }
            if (isset($values['prompt']) && (mb_strlen($values['prompt']) > 16000 || ! preg_match('/\S/u', $values['prompt']))) {
                $violations[] = 'Invalid prompt.';
            }
            self::oneOf($values['target_mode'] ?? null, ['same_chat', 'new_chat'], $violations);
            self::oneOf($values['dispatch_mode'] ?? null, ['turn_started', 'turn_injected'], $violations);
        }
        $reset = $amount = $remaining = null;
        if (str_starts_with($type, 'billing.')) {
            self::oneOf($values['reason'] ?? null, $type === 'billing.limit_reached' ? ['limit_reached', 'limit_insufficient', 'limit_reserved'] : ['financial_review_required', 'credit_exhausted', 'credit_reserved', 'credit_insufficient', 'credit_unavailable'], $violations);
            self::oneOf($values['scope'] ?? null, $type === 'billing.limit_reached' ? ['project', 'api_key'] : ['company'], $violations);
            if (isset($values['currency']) && ! preg_match('/^[A-Z]{3}$/D', $values['currency'])) {
                $violations[] = 'Invalid currency.';
            }
            if ($type === 'billing.limit_reached') {
                $reset = Payload::nullableString($data, 'reset_at', $violations, $type);
                if ($reset !== null && ! self::dateTime($reset)) {
                    $violations[] = 'Invalid reset_at.';
                }
                foreach (['amount', 'remaining'] as $field) {
                    if (array_key_exists($field, $data)) {
                        $value = Payload::string($data, $field, $violations, $type);
                        if ($value !== null && ! preg_match('/^(0|[1-9][0-9]*)(\.[0-9]+)?$/D', $value)) {
                            $violations[] = 'Invalid '.$field.'.';
                        }
                        if ($field === 'amount') {
                            $amount = $value;
                        } else {
                            $remaining = $value;
                        }
                    }
                }
                $fields = array_merge($fields, ['reset_at', 'amount', 'remaining']);
            }
        }
        if ($violations !== []) {
            return new ProtocolViolationEvent($envelope, $violations, $data);
        }
        $extra = Payload::extra($data, $fields);
        /** @var array<string, string> $strings */
        $strings = [];
        foreach ($values as $key => $value) {
            assert(is_string($value));
            $strings[$key] = $value;
        }

        return match ($type) {
            'chat.title.updated' => new ChatTitleUpdatedEvent($envelope, $strings['title'], $extra),
            'chat.cron.wakeup' => new ChatCronWakeupEvent($envelope, $strings['cron_id'], $strings['occurrence_id'], $strings['scheduled_at'], $strings['delivered_at'], $strings['prompt'], $strings['target_mode'], $strings['dispatch_mode'], $extra),
            'billing.limit_reached' => new BillingLimitReachedEvent($envelope, $strings['reason'], $strings['scope'], $strings['currency'], $reset, $amount, $remaining, $extra),
            default => new BillingCreditExhaustedEvent($envelope, $strings['reason'], $strings['scope'], $strings['currency'], $extra),
        };
    }

    /**
     * @param  list<string>  $allowed
     * @param  list<string>  $violations
     */
    private static function oneOf(?string $value, array $allowed, array &$violations): void
    {
        if ($value !== null && ! in_array($value, $allowed, true)) {
            $violations[] = 'Invalid enum value.';
        }
    }

    private static function dateTime(string $value): bool
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/iD', $value)) {
            return false;
        }
        try {
            new \DateTimeImmutable($value);
            $errors = \DateTimeImmutable::getLastErrors();

            return $errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0);
        } catch (\Exception) {
            return false;
        }
    }
}
