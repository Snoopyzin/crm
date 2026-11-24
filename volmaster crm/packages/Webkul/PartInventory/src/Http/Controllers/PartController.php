<?php

namespace Webkul\PartInventory\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\PartInventory\DataGrids\PartDataGrid;
use Webkul\PartInventory\Models\PartCategory;
use Webkul\PartInventory\Models\PartInventory;
use Webkul\PartInventory\Repositories\PartRepository;
use Webkul\PartInventory\Services\PartInventoryService;
use Webkul\Warehouse\Repositories\WarehouseRepository;

class PartController extends Controller
{
    /**
     * Create a new controller instance.
     */
    public function __construct(
        protected PartRepository $partRepository,
        protected WarehouseRepository $warehouseRepository,
        protected PartInventoryService $partInventoryService
    ) {}

    /**
     * Display a listing of the resource.
     */
    public function index(): View|JsonResponse
    {
        if (request()->ajax()) {
            return datagrid(PartDataGrid::class)->process();
        }

        return view('partinventory::index');
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(): View
    {
        $categories = PartCategory::orderBy('name')->get();
        $warehouses = $this->warehouseRepository->all();

        return view('partinventory::create', compact('categories', 'warehouses'));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(): RedirectResponse
    {
        $this->validate(request(), [
            'codigo' => 'required|string|max:255|unique:parts,codigo',
            'nome' => 'required|string|max:255',
            'ncm' => 'nullable|digits:8',
            'cest' => 'nullable|digits:7',
            'part_category_id' => 'nullable|integer|exists:part_categories,id',
            'new_category_name' => 'nullable|string|max:255',
            'valor_unitario' => 'required|numeric|min:0',
            'fornecedor' => 'nullable|string|max:255',
            'quantidade_minima' => 'nullable|integer|min:0',
            'warehouse_id' => 'required|integer|exists:warehouses,id',
            'in_stock' => 'nullable|integer|min:0',
        ]);

        Event::dispatch('part.create.before');

        $data = request()->except(['warehouse_id', 'in_stock', 'new_category_name']);

        if ($categoryName = trim((string) request('new_category_name'))) {
            $data['part_category_id'] = PartCategory::firstOrCreate(['name' => $categoryName])->id;
        }

        $part = $this->partRepository->create($data);

        PartInventory::create([
            'part_id' => $part->id,
            'warehouse_id' => request('warehouse_id'),
            'in_stock' => request('in_stock', 0),
            'allocated' => 0,
        ]);

        Event::dispatch('part.create.after', $part);

        session()->flash('success', trans('partinventory::app.index.create-success'));

        return redirect()->route('admin.parts.index');
    }

    /**
     * Display the specified resource.
     */
    public function view(int $id): View
    {
        $part = $this->partRepository->findOrFail($id);

        $part->load(['category', 'inventories.warehouse', 'inventories.movements' => function ($query) {
            $query->latest()->limit(20);
        }]);

        return view('partinventory::view', compact('part'));
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(int $id): View
    {
        $part = $this->partRepository->findOrFail($id);

        $categories = PartCategory::orderBy('name')->get();

        return view('partinventory::edit', compact('part', 'categories'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(int $id): RedirectResponse
    {
        $this->validate(request(), [
            'codigo' => 'required|string|max:255|unique:parts,codigo,'.$id,
            'nome' => 'required|string|max:255',
            'ncm' => 'nullable|digits:8',
            'cest' => 'nullable|digits:7',
            'part_category_id' => 'nullable|integer|exists:part_categories,id',
            'new_category_name' => 'nullable|string|max:255',
            'valor_unitario' => 'required|numeric|min:0',
            'fornecedor' => 'nullable|string|max:255',
            'quantidade_minima' => 'nullable|integer|min:0',
        ]);

        Event::dispatch('part.update.before', $id);

        $data = request()->except('new_category_name');

        if ($categoryName = trim((string) request('new_category_name'))) {
            $data['part_category_id'] = PartCategory::firstOrCreate(['name' => $categoryName])->id;
        }

        $part = $this->partRepository->update($data, $id);

        Event::dispatch('part.update.after', $part);

        session()->flash('success', trans('partinventory::app.index.update-success'));

        return redirect()->route('admin.parts.index');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(int $id): JsonResponse
    {
        $this->partRepository->findOrFail($id);

        try {
            Event::dispatch('part.delete.before', $id);

            $this->partRepository->delete($id);

            Event::dispatch('part.delete.after', $id);

            return response()->json([
                'message' => trans('partinventory::app.index.delete-success'),
            ], 200);
        } catch (\Exception $exception) {
            return response()->json([
                'message' => trans('partinventory::app.index.delete-failed'),
            ], 400);
        }
    }

    /**
     * Register a stock movement (entrada/ajuste) for a part's inventory row.
     */
    public function storeMovement(int $partId): RedirectResponse
    {
        $this->validate(request(), [
            'part_inventory_id' => 'required|integer|exists:part_inventories,id',
            'type' => 'required|in:entrada,ajuste',
            'quantity' => 'required|integer',
            'reason' => 'nullable|string|max:255',
        ]);

        $partInventory = PartInventory::findOrFail(request('part_inventory_id'));

        try {
            $this->partInventoryService->applyMovement(
                $partInventory,
                request('type'),
                (int) request('quantity'),
                request('reason'),
                null,
                auth()->guard('user')->id()
            );

            session()->flash('success', trans('partinventory::app.view.movement-success'));
        } catch (ValidationException $exception) {
            session()->flash('error', collect($exception->errors())->flatten()->first());
        }

        return redirect()->route('admin.parts.view', $partId);
    }
}
