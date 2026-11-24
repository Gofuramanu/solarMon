<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Models\SystemSetting;
use App\Services\EnergyAnalysisService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LogController extends Controller
{
    private const ALLOWED_SORTS = [
        'id',
        'voltage',
        'current',
        'power',
        'temperature',
        'lux',
        'recorded_at',
    ];

    public function __construct(
        protected EnergyAnalysisService $energyAnalysisService
    ) {}

    /**
     * Display paginated, searchable, date-filtered, and sortable sensor logs.
     */
    public function index(Request $request): View
    {
        $filters = $this->extractFilters($request);
        $query = $this->applyFiltersAndSorting($filters);

        $perPage = in_array((int) $request->query('per_page', 25), [15, 25, 50, 100], true)
            ? (int) $request->query('per_page', 25)
            : 25;

        $readings = $query->paginate($perPage)->withQueryString();

        $summaryAnalysis = $this->energyAnalysisService->analyze(
            $filters['selectedDevice'],
            $filters['from'],
            $filters['to'],
            null,
            $filters['search']
        );

        return view('logs.index', [
            'siteName' => SystemSetting::getValue('site_name', 'Solar Panel Monitoring IoT'),
            'devices' => $filters['devices'],
            'selectedDevice' => $filters['selectedDevice'],
            'readings' => $readings,
            'summaryAnalysis' => $summaryAnalysis,
            'search' => $filters['search'],
            'dateFrom' => $filters['dateFromInput'],
            'dateTo' => $filters['dateToInput'],
            'sort' => $filters['sort'],
            'direction' => $filters['direction'],
            'perPage' => $perPage,
        ]);
    }

    /**
     * Stream filtered sensor readings as a UTF-8 CSV file.
     */
    public function exportCsv(Request $request): StreamedResponse
    {
        $filters = $this->extractFilters($request);
        $query = $this->applyFiltersAndSorting($filters);
        $filename = 'solar_sensor_logs_' . now()->format('Ymd_His') . '.csv';

        return response()->streamDownload(function () use ($query): void {
            $handle = fopen('php://output', 'w');
            if ($handle === false) {
                return;
            }

            // UTF-8 BOM for clean rendering in Microsoft Excel on Windows
            fwrite($handle, "\xEF\xBB\xBF");

            fputcsv($handle, [
                'ID',
                'Perangkat',
                'Tegangan (V)',
                'Arus (mA)',
                'Daya (W)',
                'Temperatur (C)',
                'Intensitas Cahaya (Lux)',
                'Waktu Pengukuran',
            ]);

            foreach ($query->cursor() as $row) {
                fputcsv($handle, [
                    $row->id,
                    $row->device?->device_code ?? '-',
                    number_format((float) $row->voltage, 3, '.', ''),
                    number_format((float) $row->current, 3, '.', ''),
                    number_format((float) $row->power, 4, '.', ''),
                    number_format((float) $row->temperature, 2, '.', ''),
                    number_format((float) $row->lux, 2, '.', ''),
                    $row->recorded_at->format('Y-m-d H:i:s'),
                ]);
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * Render filtered sensor readings and energy summary as a downloadable PDF report.
     */
    public function exportPdf(Request $request): Response
    {
        $filters = $this->extractFilters($request);
        $query = $this->applyFiltersAndSorting($filters);

        $totalMatching = (clone $query)->count();
        $maxPdfRows = 500;
        $rows = $query->limit($maxPdfRows)->get();

        $analysis = $this->energyAnalysisService->analyze(
            $filters['selectedDevice'],
            $filters['from'],
            $filters['to'],
            null,
            $filters['search']
        );

        $pdf = Pdf::loadView('logs.pdf', [
            'siteName' => SystemSetting::getValue('site_name', 'Solar Panel Monitoring IoT'),
            'selectedDevice' => $filters['selectedDevice'],
            'dateFrom' => $filters['dateFromInput'],
            'dateTo' => $filters['dateToInput'],
            'search' => $filters['search'],
            'rows' => $rows,
            'totalMatching' => $totalMatching,
            'maxPdfRows' => $maxPdfRows,
            'analysis' => $analysis,
            'generatedAt' => now()->format('d M Y, H:i:s'),
        ])->setPaper('a4', 'landscape');

        return $pdf->download('laporan_monitoring_panel_surya_' . now()->format('Ymd_His') . '.pdf');
    }

    /**
     * Parse and sanitize query filter parameters.
     *
     * @return array<string, mixed>
     */
    protected function extractFilters(Request $request): array
    {
        $devices = Device::query()->orderBy('id')->get();
        $deviceCode = $request->query('device');
        $selectedDevice = ($deviceCode && $deviceCode !== 'all')
            ? $devices->firstWhere('device_code', $deviceCode)
            : null;

        $dateFromInput = $request->query('date_from', '');
        $dateToInput = $request->query('date_to', '');

        $from = is_string($dateFromInput) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFromInput)
            ? Carbon::createFromFormat('Y-m-d', $dateFromInput)->startOfDay()
            : null;
        $to = is_string($dateToInput) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateToInput)
            ? Carbon::createFromFormat('Y-m-d', $dateToInput)->endOfDay()
            : null;

        $sort = $request->query('sort', 'recorded_at');
        if (! in_array($sort, self::ALLOWED_SORTS, true)) {
            $sort = 'recorded_at';
        }

        $direction = strtolower((string) $request->query('direction', 'desc')) === 'asc' ? 'asc' : 'desc';
        $search = is_string($request->query('search')) ? trim($request->query('search')) : '';

        return [
            'devices' => $devices,
            'selectedDevice' => $selectedDevice,
            'from' => $from,
            'to' => $to,
            'dateFromInput' => $from ? $dateFromInput : '',
            'dateToInput' => $to ? $dateToInput : '',
            'search' => $search,
            'sort' => $sort,
            'direction' => $direction,
        ];
    }

    /**
     * Apply filters and sorting to the SensorReading query.
     *
     * @param  array<string, mixed>  $filters
     */
    protected function applyFiltersAndSorting(array $filters): Builder
    {
        $query = $this->energyAnalysisService->buildFilteredQuery(
            $filters['selectedDevice'],
            $filters['from'],
            $filters['to'],
            $filters['search']
        );

        return $query
            ->orderBy($filters['sort'], $filters['direction'])
            ->orderBy('id', $filters['direction']);
    }
}
