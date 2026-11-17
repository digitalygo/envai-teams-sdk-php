<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Models\Reporting;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final class ReportQuery
{
    /** @var list<string> */
    public const USAGE_KEYS = ['from', 'to', 'api_key_id', 'page', 'per_page'];

    /** @var list<string> */
    public const EVENT_KEYS = ['view', 'from', 'to', 'api_key_id', 'chat_id', 'type', 'types', 'workspace_ids', 'api_key_ids', 'page', 'per_page'];

    /** @var list<string> */
    public const ANALYTICS_KEYS = ['view', 'from', 'to', 'api_key_id', 'chat_id'];

    /** @var list<string> */
    public const TYPE_CATALOG_KEYS = ['view', 'from', 'to'];

    private const ULID = '[0-7][0-9a-hjkmnp-tv-zA-HJKMNP-TV-Z]{25}';

    private const MAX_DAYS = 90;

    private const MAX_ITEMS = 50;

    private const MAX_PER_PAGE = 100;

    /**
     * @param  array<string, mixed>  $query
     * @param  list<string>  $allowed
     * @return array<string, mixed>
     */
    public static function prepare(array $query, array $allowed, ?ProjectLogView $view): array
    {
        foreach ($query as $key => $value) {
            if (! in_array($key, $allowed, true)) {
                throw new InvalidArgumentException(sprintf('Unsupported project report filter "%s".', (string) $key));
            }
            if ($value === null) {
                throw new InvalidArgumentException(sprintf('Project report filter "%s" must not be null.', $key));
            }
        }

        if (array_key_exists('view', $query)) {
            if ($view === null || $query['view'] !== $view->value) {
                throw new InvalidArgumentException('Conflicting project log view.');
            }
        } elseif ($view !== null) {
            $query = ['view' => $view->value] + $query;
        }

        self::assertDate($query['from'] ?? null, 'from');
        self::assertDate($query['to'] ?? null, 'to');
        self::assertWindow($query['from'] ?? null, $query['to'] ?? null);
        self::assertId($query['api_key_id'] ?? null, 'api_key_id', 'key');
        self::assertId($query['chat_id'] ?? null, 'chat_id', 'chat');
        self::assertType($query['type'] ?? null);
        self::assertList($query['types'] ?? null, 'types', 80);
        self::assertList($query['workspace_ids'] ?? null, 'workspace_ids', 30);
        self::assertList($query['api_key_ids'] ?? null, 'api_key_ids', 30);
        self::assertPage($query['page'] ?? null);
        self::assertPerPage($query['per_page'] ?? null);

        if (array_key_exists('type', $query) && array_key_exists('types', $query)) {
            throw new InvalidArgumentException('Scalar and array event type filters conflict.');
        }

        if (array_key_exists('api_key_id', $query) && array_key_exists('api_key_ids', $query)) {
            throw new InvalidArgumentException('Scalar and array api key filters conflict.');
        }

        return $query;
    }

    private static function assertDate(mixed $value, string $field): void
    {
        if ($value === null) {
            return;
        }

        self::date($value, $field);
    }

    private static function date(mixed $value, string $field): DateTimeImmutable
    {
        if (! is_string($value)) {
            throw new InvalidArgumentException(sprintf('Project report filter "%s" must be a UTC date string.', $field));
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();

        if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException(sprintf('Project report filter "%s" must use YYYY-MM-DD.', $field));
        }

        return $date;
    }

    private static function assertWindow(mixed $from, mixed $to): void
    {
        if ($from === null || $to === null) {
            return;
        }

        $start = self::date($from, 'from');
        $end = self::date($to, 'to');

        if ($start > $end) {
            throw new InvalidArgumentException('Project report range start must not be after its end.');
        }

        if ($start->diff($end)->days + 1 > self::MAX_DAYS) {
            throw new InvalidArgumentException('Project report range exceeds the maximum window.');
        }
    }

    private static function assertId(mixed $value, string $field, string $prefix): void
    {
        if ($value === null) {
            return;
        }

        $pattern = $prefix === 'chat' ? '[cC][hH][tT]' : '[kK][eE][yY]';

        if (! is_string($value) || preg_match('/^'.$pattern.'_'.self::ULID.'$/D', $value) !== 1) {
            throw new InvalidArgumentException(sprintf('Project report filter "%s" is not a valid id.', $field));
        }
    }

    private static function assertType(mixed $value): void
    {
        if ($value === null) {
            return;
        }

        if (! is_string($value) || mb_strlen($value, 'UTF-8') > 80) {
            throw new InvalidArgumentException('Project report event type filter is invalid.');
        }
    }

    private static function assertList(mixed $value, string $field, int $maxLength): void
    {
        if ($value === null) {
            return;
        }

        if (! is_array($value) || ! array_is_list($value) || count($value) < 1 || count($value) > self::MAX_ITEMS) {
            throw new InvalidArgumentException(sprintf('Project report filter "%s" must be a list of 1 to 50 values.', $field));
        }

        $seen = [];

        foreach ($value as $item) {
            if (! is_string($item) || mb_strlen($item, 'UTF-8') < 1 || mb_strlen($item, 'UTF-8') > $maxLength || array_key_exists($item, $seen)) {
                throw new InvalidArgumentException(sprintf('Project report filter "%s" contains an invalid or duplicate value.', $field));
            }

            $seen[$item] = true;
        }
    }

    private static function assertPage(mixed $value): void
    {
        if ($value === null) {
            return;
        }

        if (! is_int($value) || $value < 1) {
            throw new InvalidArgumentException('page must be positive.');
        }
    }

    private static function assertPerPage(mixed $value): void
    {
        if ($value === null) {
            return;
        }

        if (! is_int($value) || $value < 1 || $value > self::MAX_PER_PAGE) {
            throw new InvalidArgumentException('per_page must be between 1 and 100.');
        }
    }
}
