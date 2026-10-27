<?php

namespace App\Modules\Reporting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Branches\Support\BranchPresenter;
use App\Modules\Reporting\Queries\DailySalesQuery;
use App\Modules\Reporting\Queries\ProductSalesQuery;
use App\Modules\Reporting\Queries\StockReportQuery;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ReportController extends Controller
{
    public function sales(
        Request $request,
        DailySalesQuery $dailySales,
        ProductSalesQuery $productSales,
    ): Response {
        [$from, $to, $branchId] = $this->dateRange($request);
        $productReport = $productSales($from, $to, $branchId);

        return Inertia::render('admin/reports/Sales', [
            'filters' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'branch' => $branchId,
            ],
            'branches' => BranchPresenter::options(),
            'sales' => $dailySales($from, $to, $branchId),
            'products' => $productReport['products'],
            'modifiers' => $productReport['modifiers'],
        ]);
    }

    public function stock(
        Request $request,
        StockReportQuery $stockReport,
    ): Response {
        $data = $request->validate([
            'branch' => ['nullable', 'integer', 'exists:branches,id'],
        ]);

        $branchId = isset($data['branch']) && (int) $data['branch'] > 0
            ? (int) $data['branch']
            : null;
        $report = $stockReport($branchId);

        return Inertia::render('admin/reports/Stock', [
            'filters' => ['branch' => $branchId],
            'branches' => BranchPresenter::options(),
            'balances' => $report['balances'],
            'low_stock' => $report['low_stock'],
            'movements' => $report['movements'],
        ]);
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: ?int}
     */
    private function dateRange(Request $request): array
    {
        $data = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'branch' => ['nullable', 'integer', 'exists:branches,id'],
        ]);

        $today = CarbonImmutable::now('Asia/Jakarta')->toDateString();
        $from = CarbonImmutable::parse($data['from'] ?? $today, 'Asia/Jakarta')->startOfDay();
        $to = CarbonImmutable::parse($data['to'] ?? $today, 'Asia/Jakarta')->endOfDay();

        if ($from->greaterThan($to)) {
            throw ValidationException::withMessages([
                'from' => 'Tanggal awal tidak boleh setelah tanggal akhir.',
            ]);
        }

        $branchId = isset($data['branch']) && (int) $data['branch'] > 0
            ? (int) $data['branch']
            : null;

        return [$from, $to, $branchId];
    }
}
