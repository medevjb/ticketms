<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\Imports\ImportControlRequest;
use App\Http\Requests\Imports\StoreTicketImportRequest;
use App\Services\Imports\TicketImportService;
use HasinHayder\TyroDashboard\Http\Controllers\BaseController;
use HasinHayder\TyroDashboard\Support\DashboardRoute;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ImportController extends BaseController
{
    public function index(Request $request, TicketImportService $imports): View
    {
        $status = in_array($request->query('status'), ['queued', 'running', 'paused', 'completed', 'completed_with_errors', 'failed', 'cancelled', 'archived', 'all'], true)
            ? (string) $request->query('status')
            : 'all';

        return view('admin.imports.index', $this->getViewData([
            'dashboardRoute' => DashboardRoute::class,
            'runs' => $imports->paginate($request->user(), $status)->withQueryString(),
            'status' => $status,
        ]));
    }

    public function show(int $run, Request $request, TicketImportService $imports): View
    {
        $importRun = $imports->find($request->user(), $run);

        return view('admin.imports.show', $this->getViewData([
            'dashboardRoute' => DashboardRoute::class,
            'run' => $importRun,
            'rows' => $importRun->rows()->latest('row_number')->paginate(50),
        ]));
    }

    public function store(StoreTicketImportRequest $request, TicketImportService $imports): RedirectResponse
    {
        $data = $request->validated();
        $run = $imports->upload($request->user(), (string) $data['source'], $request->file('file'));

        return to_route('dashboard.ticket-imports.show', $run)->with('success', 'The CSV import was queued. Progress will appear here as rows are processed.');
    }

    public function control(ImportControlRequest $request, int $run, TicketImportService $imports): RedirectResponse
    {
        $result = $imports->control($request->user(), $run, (string) $request->validated('action'));

        return to_route('dashboard.ticket-imports.show', $result)->with('success', 'The import run was updated.');
    }

    public function errors(Request $request, int $run, TicketImportService $imports): StreamedResponse
    {
        return $imports->errorReport($request->user(), $run);
    }
}
