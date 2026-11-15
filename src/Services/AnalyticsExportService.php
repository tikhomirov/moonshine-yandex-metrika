<?php

declare(strict_types=1);

namespace Tikhomirov\MoonshineYandexMetrika\Services;

use Tikhomirov\MoonshineYandexMetrika\Support\DatePeriod;

/**
 * Формирует CSV-выгрузки по Метрике и Вебмастеру.
 */
final class AnalyticsExportService
{
    public function __construct(
        private readonly MetrikaService $metrika,
        private readonly WebmasterService $webmaster,
    ) {}

    public function metrikaCsv(DatePeriod $period, int $maxResults = 50): string
    {
        $metrika = $this->metrika->forPeriod($period);
        $lines = [];
        $lines[] = $this->row(['section', 'metric', 'value', 'extra']);
        $lines[] = $this->row(['period', 'date_from', $period->date1(), $period->preset]);
        $lines[] = $this->row(['period', 'date_to', $period->date2(), '']);
        $lines[] = $this->row(['summary', 'visits', (string) $metrika->totalVisits(), '']);
        $lines[] = $this->row(['summary', 'pageviews', (string) $metrika->totalPageViews(), '']);
        $lines[] = $this->row(['summary', 'unique_visitors', (string) $metrika->uniqueVisitors(), '']);
        $lines[] = $this->row(['summary', 'bounce_rate', (string) $metrika->bounceRate(), '%']);

        foreach ($metrika->visitsByDay() as $row) {
            $lines[] = $this->row([
                'visits_by_day',
                $row['dimension'],
                (string) ($row['metrics'][0] ?? 0),
                '',
            ]);
        }

        foreach ($metrika->topPages(null, $maxResults) as $row) {
            $lines[] = $this->row([
                'top_pages',
                $row['dimension'],
                (string) ($row['metrics'][0] ?? 0),
                '',
            ]);
        }

        foreach ($metrika->trafficSources(null, $maxResults) as $row) {
            $lines[] = $this->row([
                'traffic_sources',
                $row['dimension'],
                (string) ($row['metrics'][0] ?? 0),
                (string) ($row['metrics'][1] ?? 0),
            ]);
        }

        foreach ($metrika->searchPhrases(null, $maxResults) as $row) {
            $lines[] = $this->row([
                'search_phrases',
                $row['dimension'],
                (string) ($row['metrics'][0] ?? 0),
                '',
            ]);
        }

        foreach ($metrika->geoCountries(null, $maxResults) as $row) {
            $lines[] = $this->row([
                'geo',
                $row['dimension'],
                (string) ($row['metrics'][0] ?? 0),
                '',
            ]);
        }

        foreach ($metrika->browsers(null, $maxResults) as $row) {
            $lines[] = $this->row([
                'browsers',
                $row['dimension'],
                (string) ($row['metrics'][0] ?? 0),
                '',
            ]);
        }

        return "\xEF\xBB\xBF".implode("\n", $lines)."\n";
    }

    public function webmasterCsv(DatePeriod $period, int $maxResults = 50): string
    {
        $webmaster = $this->webmaster->forPeriod($period);
        $summary = $webmaster->summary();
        $lines = [];
        $lines[] = $this->row(['section', 'metric', 'value', 'extra']);
        $lines[] = $this->row(['period', 'date_from', $period->date1(), $period->preset]);
        $lines[] = $this->row(['period', 'date_to', $period->date2(), '']);
        $lines[] = $this->row(['host', 'host_id', $webmaster->hostId(), $webmaster->displayHost()]);

        if ($summary !== null) {
            $lines[] = $this->row(['summary', 'searchable_pages_count', (string) $summary['searchable_pages_count'], '']);
            $lines[] = $this->row(['summary', 'excluded_pages_count', (string) $summary['excluded_pages_count'], '']);
            $lines[] = $this->row(['summary', 'problems_total', (string) $summary['problems_total'], '']);
            $lines[] = $this->row(['summary', 'problems_fatal', (string) $summary['site_problems']['FATAL'], '']);
            $lines[] = $this->row(['summary', 'problems_critical', (string) $summary['site_problems']['CRITICAL'], '']);
            $lines[] = $this->row(['summary', 'problems_possible', (string) $summary['site_problems']['POSSIBLE_PROBLEM'], '']);
            $lines[] = $this->row(['summary', 'problems_recommendation', (string) $summary['site_problems']['RECOMMENDATION'], '']);
            $lines[] = $this->row(['summary', 'sqi', (string) ($summary['sqi'] ?? ''), '']);
        }

        $linksTotal = $webmaster->externalLinksTotal();
        $lines[] = $this->row([
            'summary',
            'external_links_total',
            $linksTotal === null ? 'n/a' : (string) $linksTotal,
            '',
        ]);

        foreach ($webmaster->popularQueries($maxResults) as $row) {
            $lines[] = $this->row([
                'popular_queries',
                $row['query'],
                (string) $row['clicks'],
                'shows='.$row['shows']
                    .';pos='.($row['avg_show_position'] ?? '')
                    .';click_pos='.($row['avg_click_position'] ?? ''),
            ]);
        }

        return "\xEF\xBB\xBF".implode("\n", $lines)."\n";
    }

    /**
     * @param  list<string>  $columns
     */
    private function row(array $columns): string
    {
        return implode(',', array_map(function (string $value): string {
            $escaped = str_replace('"', '""', $value);

            return '"'.$escaped.'"';
        }, $columns));
    }
}
