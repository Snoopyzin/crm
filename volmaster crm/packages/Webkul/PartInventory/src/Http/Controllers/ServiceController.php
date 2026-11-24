<?php

namespace Webkul\PartInventory\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Event;
use Illuminate\View\View;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\PartInventory\DataGrids\ServiceDataGrid;
use Webkul\PartInventory\Models\PartCategory;
use Webkul\PartInventory\Repositories\ServiceRepository;

class ServiceController extends Controller
{
    /**
     * Create a new controller instance.
     */
    public function __construct(protected ServiceRepository $serviceRepository) {}

    /**
     * Display a listing of the resource.
     */
    public function index(): View|JsonResponse
    {
        if (request()->ajax()) {
            return datagrid(ServiceDataGrid::class)->process();
        }

        return view('partinventory::services.index');
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(): View
    {
        $categories = PartCategory::orderBy('name')->get();

        return view('partinventory::services.create', compact('categories'));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(): RedirectResponse
    {
        $this->validate(request(), [
            'nome' => 'required|string|max:255',
            'descricao' => 'nullable|string',
            'part_category_id' => 'nullable|integer|exists:part_categories,id',
            'new_category_name' => 'nullable|string|max:255',
            'preco_padrao' => 'required|numeric|min:0',
            'tempo_padrao_horas' => 'nullable|numeric|min:0|max:999',
        ]);

        Event::dispatch('service.create.before');

        $data = request()->except('new_category_name');

        // O campo só existe depois da migration do tempo padrão (B5): antes disso não grava.
        if (! \Illuminate\Support\Facades\Schema::hasColumn('services', 'tempo_padrao_horas')) {
            unset($data['tempo_padrao_horas']);
        }

        if ($categoryName = trim((string) request('new_category_name'))) {
            $data['part_category_id'] = PartCategory::firstOrCreate(['name' => $categoryName])->id;
        }

        $service = $this->serviceRepository->create($data);

        Event::dispatch('service.create.after', $service);

        session()->flash('success', trans('partinventory::app.services.index.create-success'));

        return redirect()->route('admin.services.index');
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(int $id): View
    {
        $service = $this->serviceRepository->findOrFail($id);

        $categories = PartCategory::orderBy('name')->get();

        return view('partinventory::services.edit', compact('service', 'categories'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(int $id): RedirectResponse
    {
        $this->validate(request(), [
            'nome' => 'required|string|max:255',
            'descricao' => 'nullable|string',
            'part_category_id' => 'nullable|integer|exists:part_categories,id',
            'new_category_name' => 'nullable|string|max:255',
            'preco_padrao' => 'required|numeric|min:0',
            'tempo_padrao_horas' => 'nullable|numeric|min:0|max:999',
        ]);

        Event::dispatch('service.update.before', $id);

        $data = request()->except('new_category_name');

        // O campo só existe depois da migration do tempo padrão (B5): antes disso não grava.
        if (! \Illuminate\Support\Facades\Schema::hasColumn('services', 'tempo_padrao_horas')) {
            unset($data['tempo_padrao_horas']);
        }

        if ($categoryName = trim((string) request('new_category_name'))) {
            $data['part_category_id'] = PartCategory::firstOrCreate(['name' => $categoryName])->id;
        }

        $service = $this->serviceRepository->update($data, $id);

        Event::dispatch('service.update.after', $service);

        session()->flash('success', trans('partinventory::app.services.index.update-success'));

        return redirect()->route('admin.services.index');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(int $id): JsonResponse
    {
        $this->serviceRepository->findOrFail($id);

        try {
            Event::dispatch('service.delete.before', $id);

            $this->serviceRepository->delete($id);

            Event::dispatch('service.delete.after', $id);

            return response()->json([
                'message' => trans('partinventory::app.services.index.delete-success'),
            ], 200);
        } catch (\Exception $exception) {
            return response()->json([
                'message' => trans('partinventory::app.services.index.delete-failed'),
            ], 400);
        }
    }
}
