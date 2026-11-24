<?php

namespace Webkul\PartInventory\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Event;
use Illuminate\View\View;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\PartInventory\DataGrids\PartCategoryDataGrid;
use Webkul\PartInventory\Repositories\PartCategoryRepository;

/**
 * Grupos -- a classificação compartilhada entre Peças e Serviços (a mesma
 * tabela part_categories, só apresentada com esse nome pro usuário).
 */
class PartCategoryController extends Controller
{
    /**
     * Create a new controller instance.
     */
    public function __construct(protected PartCategoryRepository $partCategoryRepository) {}

    /**
     * Display a listing of the resource.
     */
    public function index(): View|JsonResponse
    {
        if (request()->ajax()) {
            return datagrid(PartCategoryDataGrid::class)->process();
        }

        return view('partinventory::categories.index');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(): RedirectResponse
    {
        $this->validate(request(), [
            'name' => 'required|string|max:255|unique:part_categories,name',
        ]);

        Event::dispatch('part_category.create.before');

        $category = $this->partCategoryRepository->create(request()->only('name'));

        Event::dispatch('part_category.create.after', $category);

        session()->flash('success', trans('partinventory::app.categories.index.create-success'));

        return redirect()->route('admin.part-categories.index');
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(int $id): RedirectResponse
    {
        $this->validate(request(), [
            'name' => 'required|string|max:255|unique:part_categories,name,'.$id,
        ]);

        Event::dispatch('part_category.update.before', $id);

        $category = $this->partCategoryRepository->update(request()->only('name'), $id);

        Event::dispatch('part_category.update.after', $category);

        session()->flash('success', trans('partinventory::app.categories.index.update-success'));

        return redirect()->route('admin.part-categories.index');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(int $id): JsonResponse
    {
        $this->partCategoryRepository->findOrFail($id);

        try {
            Event::dispatch('part_category.delete.before', $id);

            $this->partCategoryRepository->delete($id);

            Event::dispatch('part_category.delete.after', $id);

            return response()->json([
                'message' => trans('partinventory::app.categories.index.delete-success'),
            ], 200);
        } catch (\Exception $exception) {
            return response()->json([
                'message' => trans('partinventory::app.categories.index.delete-failed'),
            ], 400);
        }
    }
}
