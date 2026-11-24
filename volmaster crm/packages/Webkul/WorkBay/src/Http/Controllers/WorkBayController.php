<?php

namespace Webkul\WorkBay\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\ServiceOrder\Models\ServiceOrder;
use Webkul\WorkBay\DataGrids\WorkBayDataGrid;
use Webkul\WorkBay\Models\WorkBayAssignment;
use Webkul\WorkBay\Repositories\WorkBayRepository;
use Webkul\WorkBay\Services\WorkBayAssignmentService;

class WorkBayController extends Controller
{
    /**
     * Create a new controller instance.
     */
    public function __construct(
        protected WorkBayRepository $workBayRepository,
        protected WorkBayAssignmentService $workBayAssignmentService
    ) {}

    /**
     * Display a listing of the resource.
     */
    public function index(): View|JsonResponse
    {
        if (request()->ajax()) {
            return datagrid(WorkBayDataGrid::class)->process();
        }

        return view('workbay::index');
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(): View
    {
        return view('workbay::create');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(): RedirectResponse
    {
        $this->validate(request(), [
            'name' => 'required|string|max:255',
            'capacity' => 'nullable|integer|min:1',
            'status' => 'required|in:Ativa,Inativa',
        ]);

        Event::dispatch('workbay.create.before');

        $workBay = $this->workBayRepository->create(request()->all());

        Event::dispatch('workbay.create.after', $workBay);

        session()->flash('success', trans('workbay::app.index.create-success'));

        return redirect()->route('admin.work_bays.index');
    }

    /**
     * Display the specified resource.
     */
    public function view(int $id): View
    {
        $workBay = $this->workBayRepository->findOrFail($id);
        $workBay->load(['assignments' => function ($query) {
            $query->whereNotIn('status', ['Concluída', 'Cancelada'])->orderBy('starts_at');
        }, 'assignments.serviceOrder']);

        $serviceOrders = ServiceOrder::orderByDesc('id')->get();

        return view('workbay::view', compact('workBay', 'serviceOrders'));
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(int $id): View
    {
        $workBay = $this->workBayRepository->findOrFail($id);

        return view('workbay::edit', compact('workBay'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(int $id): RedirectResponse
    {
        $this->validate(request(), [
            'name' => 'required|string|max:255',
            'capacity' => 'nullable|integer|min:1',
            'status' => 'required|in:Ativa,Inativa',
        ]);

        Event::dispatch('workbay.update.before', $id);

        $workBay = $this->workBayRepository->update(request()->all(), $id);

        Event::dispatch('workbay.update.after', $workBay);

        session()->flash('success', trans('workbay::app.index.update-success'));

        return redirect()->route('admin.work_bays.index');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(int $id): JsonResponse
    {
        $this->workBayRepository->findOrFail($id);

        try {
            Event::dispatch('workbay.delete.before', $id);

            $this->workBayRepository->delete($id);

            Event::dispatch('workbay.delete.after', $id);

            return response()->json([
                'message' => trans('workbay::app.index.delete-success'),
            ], 200);
        } catch (\Exception $exception) {
            return response()->json([
                'message' => trans('workbay::app.index.delete-failed'),
            ], 400);
        }
    }

    /**
     * Assign a service order to this bay for a given time window.
     */
    public function storeAssignment(int $id): RedirectResponse
    {
        $this->validate(request(), [
            'quote_id' => 'required|integer|exists:quotes,id',
            'starts_at' => 'required|date',
            'ends_at' => 'nullable|date|after:starts_at',
        ]);

        try {
            $this->workBayAssignmentService->assign([
                'work_bay_id' => $id,
                'quote_id' => request('quote_id'),
                'starts_at' => request('starts_at'),
                'ends_at' => request('ends_at'),
            ]);

            session()->flash('success', trans('workbay::app.view.assign-success'));
        } catch (ValidationException $exception) {
            session()->flash('error', collect($exception->errors())->flatten()->first());
        }

        return redirect()->route('admin.work_bays.view', $id);
    }

    /**
     * Cancel an assignment, freeing up the bay.
     */
    public function destroyAssignment(int $id, int $assignmentId): RedirectResponse
    {
        $assignment = WorkBayAssignment::where('work_bay_id', $id)->findOrFail($assignmentId);

        $assignment->update(['status' => WorkBayAssignment::STATUS_CANCELADA]);

        session()->flash('success', trans('workbay::app.view.assignment-cancelled'));

        return redirect()->route('admin.work_bays.view', $id);
    }
}
