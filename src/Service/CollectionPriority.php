<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/** Scheduling directives, independent of sync execution and transport selection. */
final class CollectionPriority
{
    public const string CONTEXT_KEY = 'processing_priority';
    public const string SIZE_KEY = 'collectionSize';
    public const string HIGH = 'high';
    public const string NORMAL = 'normal';
    public const string BULK = 'bulk';

    public static function resolve(array $payload): string
    {
        $size = $payload[self::SIZE_KEY] ?? null;
        if ($size !== null && (!is_int($size) || $size < 1)) {
            throw new BadRequestHttpException('collectionSize must be a positive integer.');
        }
        $priority = $payload['priority'] ?? null;
        if ($priority !== null) {
            if (!in_array($priority, [self::HIGH, self::NORMAL, self::BULK], true)) {
                throw new BadRequestHttpException('priority must be high, normal, or bulk.');
            }
            return $priority;
        }
        return match (true) {
            $size === null => self::NORMAL,
            $size <= 100 => self::HIGH,
            $size <= 10000 => self::NORMAL,
            default => self::BULK,
        };
    }

    public static function promote(?string $existing, string $requested): string
    {
        $rank = [self::BULK => 0, self::NORMAL => 1, self::HIGH => 2];
        return $existing !== null && isset($rank[$existing]) && $rank[$existing] > $rank[$requested] ? $existing : $requested;
    }
}
