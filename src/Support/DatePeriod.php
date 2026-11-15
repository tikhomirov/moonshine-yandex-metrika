<?php

declare(strict_types=1);

namespace Tikhomirov\MoonshineYandexMetrika\Support;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * Отчётный период для Метрики и Вебмастера.
 *
 * Пресеты:
 * - today / yesterday — один день;
 * - week — скользящие 8 календарных дат (сегодня − 7 … сегодня), как в примере Метрики;
 * - month / quarter / half_year / year — текущие календарные периоды до сегодня;
 * - custom — произвольный диапазон.
 */
final readonly class DatePeriod
{
    public const string PRESET_TODAY = 'today';

    public const string PRESET_YESTERDAY = 'yesterday';

    public const string PRESET_WEEK = 'week';

    public const string PRESET_MONTH = 'month';

    public const string PRESET_QUARTER = 'quarter';

    public const string PRESET_HALF_YEAR = 'half_year';

    public const string PRESET_YEAR = 'year';

    public const string PRESET_CUSTOM = 'custom';

    /**
     * @var list<string>
     */
    public const array PRESETS = [
        self::PRESET_TODAY,
        self::PRESET_YESTERDAY,
        self::PRESET_WEEK,
        self::PRESET_MONTH,
        self::PRESET_QUARTER,
        self::PRESET_HALF_YEAR,
        self::PRESET_YEAR,
    ];

    public function __construct(
        public CarbonInterface $from,
        public CarbonInterface $to,
        public string $preset = self::PRESET_CUSTOM,
    ) {
        if ($this->from->gt($this->to)) {
            throw new InvalidArgumentException('DatePeriod "from" must be before or equal to "to".');
        }
    }

    public static function today(?CarbonInterface $now = null): self
    {
        $day = ($now ?? Carbon::today())->copy()->startOfDay();

        return new self($day, $day->copy(), self::PRESET_TODAY);
    }

    public static function fromPreset(string $preset, ?CarbonInterface $now = null): self
    {
        $today = ($now ?? Carbon::today())->copy()->startOfDay();

        return match ($preset) {
            self::PRESET_TODAY => self::today($today),
            self::PRESET_YESTERDAY => new self(
                $today->copy()->subDay(),
                $today->copy()->subDay(),
                self::PRESET_YESTERDAY,
            ),
            self::PRESET_WEEK => new self(
                $today->copy()->subDays(7),
                $today->copy(),
                self::PRESET_WEEK,
            ),
            self::PRESET_MONTH => new self(
                $today->copy()->startOfMonth(),
                $today->copy(),
                self::PRESET_MONTH,
            ),
            self::PRESET_QUARTER => new self(
                $today->copy()->firstOfQuarter(),
                $today->copy(),
                self::PRESET_QUARTER,
            ),
            self::PRESET_HALF_YEAR => new self(
                self::startOfHalfYear($today),
                $today->copy(),
                self::PRESET_HALF_YEAR,
            ),
            self::PRESET_YEAR => new self(
                $today->copy()->startOfYear(),
                $today->copy(),
                self::PRESET_YEAR,
            ),
            default => throw new InvalidArgumentException("Unknown period preset [{$preset}]."),
        };
    }

    public static function custom(string|CarbonInterface $from, string|CarbonInterface $to): self
    {
        $fromDate = $from instanceof CarbonInterface
            ? $from->copy()->startOfDay()
            : Carbon::parse($from)->startOfDay();
        $toDate = $to instanceof CarbonInterface
            ? $to->copy()->startOfDay()
            : Carbon::parse($to)->startOfDay();

        return new self($fromDate, $toDate, self::PRESET_CUSTOM);
    }

    /**
     * Разбирает query-параметры страницы дашборда.
     */
    public static function fromRequest(?string $preset, ?string $from, ?string $to, ?CarbonInterface $now = null): self
    {
        $from = $from !== null && $from !== '' ? $from : null;
        $to = $to !== null && $to !== '' ? $to : null;

        if ($from !== null && $to !== null) {
            return self::custom($from, $to);
        }

        $preset = $preset !== null && $preset !== '' ? $preset : self::PRESET_TODAY;

        if (! in_array($preset, self::PRESETS, true)) {
            $preset = self::PRESET_TODAY;
        }

        return self::fromPreset($preset, $now);
    }

    public function date1(): string
    {
        return $this->from->format('Y-m-d');
    }

    public function date2(): string
    {
        return $this->to->format('Y-m-d');
    }

    /**
     * Число календарных дней включительно.
     */
    public function dayCount(): int
    {
        return (int) ($this->from->diffInDays($this->to) + 1);
    }

    /**
     * @return array{preset: string, date_from?: string, date_to?: string}
     */
    public function toQuery(): array
    {
        if ($this->preset !== self::PRESET_CUSTOM) {
            return ['preset' => $this->preset];
        }

        return [
            'preset' => self::PRESET_CUSTOM,
            'date_from' => $this->date1(),
            'date_to' => $this->date2(),
        ];
    }

    private static function startOfHalfYear(CarbonInterface $today): CarbonInterface
    {
        $year = $today->year;

        if ($today->month <= 6) {
            return Carbon::create($year, 1, 1)->startOfDay();
        }

        return Carbon::create($year, 7, 1)->startOfDay();
    }
}
