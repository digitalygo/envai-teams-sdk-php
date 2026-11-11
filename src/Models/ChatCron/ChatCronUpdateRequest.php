<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Models\ChatCron;

use Digitalygo\Envai\Models\Common\OptionalField;

final readonly class ChatCronUpdateRequest
{
    public OptionalField $targetMode;

    public OptionalField $prompt;

    public OptionalField $intervalMinutes;

    public OptionalField $dailyAt;

    public OptionalField $timezone;

    public OptionalField $startAt;

    public OptionalField $repeatCount;

    public OptionalField $until;

    public function __construct(
        ?OptionalField $targetMode = null,
        ?OptionalField $prompt = null,
        ?OptionalField $intervalMinutes = null,
        ?OptionalField $dailyAt = null,
        ?OptionalField $timezone = null,
        ?OptionalField $startAt = null,
        ?OptionalField $repeatCount = null,
        ?OptionalField $until = null,
    ) {
        $this->targetMode = $targetMode ?? OptionalField::absent();
        $this->prompt = $prompt ?? OptionalField::absent();
        $this->intervalMinutes = $intervalMinutes ?? OptionalField::absent();
        $this->dailyAt = $dailyAt ?? OptionalField::absent();
        $this->timezone = $timezone ?? OptionalField::absent();
        $this->startAt = $startAt ?? OptionalField::absent();
        $this->repeatCount = $repeatCount ?? OptionalField::absent();
        $this->until = $until ?? OptionalField::absent();
    }

    public static function targetMode(ChatCronTargetMode $mode): self
    {
        return new self(targetMode: OptionalField::of($mode));
    }

    public static function prompt(string $prompt): self
    {
        return new self(prompt: OptionalField::of($prompt));
    }

    public static function intervalMinutes(?int $minutes): self
    {
        return new self(intervalMinutes: OptionalField::of($minutes));
    }

    public static function dailyAt(?string $time): self
    {
        return new self(dailyAt: OptionalField::of($time));
    }

    public static function timezone(?string $timezone): self
    {
        return new self(timezone: OptionalField::of($timezone));
    }

    public static function startAt(?string $at): self
    {
        return new self(startAt: OptionalField::of($at));
    }

    public static function repeatCount(?int $count): self
    {
        return new self(repeatCount: OptionalField::of($count));
    }

    public static function until(?string $until): self
    {
        return new self(until: OptionalField::of($until));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $payload = [];

        if ($this->targetMode->isPresent()) {
            $mode = $this->targetMode->value();

            if (! $mode instanceof ChatCronTargetMode) {
                throw new \LogicException('ChatCronUpdateRequest field "target_mode" must hold a ChatCronTargetMode instance.');
            }

            $payload['target_mode'] = $mode->value;
        }

        self::putString($payload, 'prompt', $this->prompt);
        self::putNullableInt($payload, 'interval_minutes', $this->intervalMinutes);
        self::putNullableString($payload, 'daily_at', $this->dailyAt);
        self::putNullableString($payload, 'timezone', $this->timezone);
        self::putNullableString($payload, 'start_at', $this->startAt);
        self::putNullableInt($payload, 'repeat_count', $this->repeatCount);
        self::putNullableString($payload, 'until', $this->until);

        return $payload;
    }

    /** @param array<string, mixed> $payload */
    private static function putString(array &$payload, string $key, OptionalField $field): void
    {
        if ($field->isAbsent()) {
            return;
        }

        $value = $field->value();

        if (! is_string($value)) {
            throw new \LogicException(sprintf('ChatCronUpdateRequest field "%s" must be a string.', $key));
        }

        $payload[$key] = $value;
    }

    /** @param array<string, mixed> $payload */
    private static function putNullableString(array &$payload, string $key, OptionalField $field): void
    {
        if ($field->isAbsent()) {
            return;
        }

        $value = $field->value();

        if ($value !== null && ! is_string($value)) {
            throw new \LogicException(sprintf('ChatCronUpdateRequest field "%s" must be a string or null.', $key));
        }

        $payload[$key] = $value;
    }

    /** @param array<string, mixed> $payload */
    private static function putNullableInt(array &$payload, string $key, OptionalField $field): void
    {
        if ($field->isAbsent()) {
            return;
        }

        $value = $field->value();

        if ($value !== null && ! is_int($value)) {
            throw new \LogicException(sprintf('ChatCronUpdateRequest field "%s" must be an integer or null.', $key));
        }

        $payload[$key] = $value;
    }
}
