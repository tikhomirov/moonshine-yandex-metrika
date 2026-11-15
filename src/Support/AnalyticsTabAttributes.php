<?php

declare(strict_types=1);

namespace Tikhomirov\MoonshineYandexMetrika\Support;

/**
 * Поведение вкладки в тулбаре (период, CSV, зависимость от Яндекс OAuth).
 */
final readonly class AnalyticsTabAttributes
{
    public function __construct(
        public bool $usesPeriod,
        public bool $requiresYandex,
        public ?string $exportCsvUrl,
        public ?string $exportCsvLabel,
    ) {}

    public static function none(): self
    {
        return new self(false, false, null, null);
    }
}