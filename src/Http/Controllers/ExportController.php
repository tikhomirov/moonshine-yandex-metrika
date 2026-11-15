<?php

declare(strict_types=1);

namespace Tikhomirov\MoonshineYandexMetrika\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tikhomirov\MoonshineYandexMetrika\Services\AnalyticsExportService;
use Tikhomirov\MoonshineYandexMetrika\Support\DatePeriod;

final class ExportController extends Controller
{
    public function metrika(Request $request, AnalyticsExportService $export): StreamedResponse
    {
        $period = DatePeriod::fromRequest(
            $request->query('preset'),
            $request->query('date_from'),
            $request->query('date_to'),
        );

        $csv = $export->metrikaCsv($period);
        $filename = 'metrika_'.$period->date1().'_'.$period->date2().'.csv';

        return $this->download($filename, $csv);
    }

    public function webmaster(Request $request, AnalyticsExportService $export): StreamedResponse
    {
        $period = DatePeriod::fromRequest(
            $request->query('preset'),
            $request->query('date_from'),
            $request->query('date_to'),
        );

        $csv = $export->webmasterCsv($period);
        $filename = 'webmaster_'.$period->date1().'_'.$period->date2().'.csv';

        return $this->download($filename, $csv);
    }

    private function download(string $filename, string $csv): StreamedResponse
    {
        return response()->streamDownload(function () use ($csv): void {
            echo $csv;
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
