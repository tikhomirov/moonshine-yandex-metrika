<?php

declare(strict_types=1);

use Tikhomirov\MoonshineYandexMetrika\Tests\TestCase;

uses(TestCase::class)->in('Unit', 'Feature');

/**
 * Все ссылки на Яндекс OAuth (и наш redirect на него) должны открываться в новой вкладке.
 *
 * @return list<string> ссылки без target="_blank"
 */
function yandexLinksWithoutBlankTarget(string $html): array
{
    preg_match_all('/<a\s[^>]*href="([^"]*(?:oauth\.yandex\.ru|yandex-analytics\/oauth\/redirect)[^"]*)"[^>]*>/', $html, $matches, PREG_SET_ORDER);

    return array_values(array_map(
        static fn (array $m): string => $m[1],
        array_filter($matches, static fn (array $m): bool => ! str_contains($m[0], 'target="_blank"')
            || ! str_contains($m[0], 'rel="noopener noreferrer"')),
    ));
}
