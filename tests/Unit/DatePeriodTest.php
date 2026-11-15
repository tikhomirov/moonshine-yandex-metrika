<?php

declare(strict_types=1);

use Carbon\Carbon;
use Tikhomirov\MoonshineYandexMetrika\Support\DatePeriod;

test('today preset is a single day', function () {
    $now = Carbon::parse('2026-11-15');
    $period = DatePeriod::fromPreset(DatePeriod::PRESET_TODAY, $now);

    expect($period->date1())->toBe('2026-11-15')
        ->and($period->date2())->toBe('2026-11-15')
        ->and($period->dayCount())->toBe(1);
});

test('week preset is today minus 7 days through today', function () {
    $now = Carbon::parse('2026-11-15');
    $period = DatePeriod::fromPreset(DatePeriod::PRESET_WEEK, $now);

    expect($period->date1())->toBe('2026-11-08')
        ->and($period->date2())->toBe('2026-11-15')
        ->and($period->dayCount())->toBe(8);
});

test('month preset starts at first day of current month', function () {
    $now = Carbon::parse('2026-11-15');
    $period = DatePeriod::fromPreset(DatePeriod::PRESET_MONTH, $now);

    expect($period->date1())->toBe('2026-11-01')
        ->and($period->date2())->toBe('2026-11-15');
});

test('quarter preset starts at first day of current quarter', function () {
    $now = Carbon::parse('2026-11-15');
    $period = DatePeriod::fromPreset(DatePeriod::PRESET_QUARTER, $now);

    expect($period->date1())->toBe('2026-10-01')
        ->and($period->date2())->toBe('2026-11-15');
});

test('half year preset uses july for second half', function () {
    $now = Carbon::parse('2026-11-15');
    $period = DatePeriod::fromPreset(DatePeriod::PRESET_HALF_YEAR, $now);

    expect($period->date1())->toBe('2026-07-01')
        ->and($period->date2())->toBe('2026-11-15');
});

test('year preset starts at january first', function () {
    $now = Carbon::parse('2026-11-15');
    $period = DatePeriod::fromPreset(DatePeriod::PRESET_YEAR, $now);

    expect($period->date1())->toBe('2026-01-01')
        ->and($period->date2())->toBe('2026-11-15');
});

test('custom dates from request override preset', function () {
    $period = DatePeriod::fromRequest('week', '2026-01-01', '2026-01-10');

    expect($period->preset)->toBe(DatePeriod::PRESET_CUSTOM)
        ->and($period->date1())->toBe('2026-01-01')
        ->and($period->date2())->toBe('2026-01-10');
});

test('request without dates defaults to today', function () {
    Carbon::setTestNow('2026-11-15');

    $period = DatePeriod::fromRequest(null, null, null);

    expect($period->preset)->toBe(DatePeriod::PRESET_TODAY)
        ->and($period->date1())->toBe('2026-11-15');

    Carbon::setTestNow();
});
