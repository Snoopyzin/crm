<?php

namespace Webkul\RemoteService\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\RemoteService\DataGrids\RemoteServiceTypeDataGrid;
use Webkul\RemoteService\Models\RemoteServiceType;
use Webkul\User\Models\Group;

class RemoteServiceTypeController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(): View|JsonResponse
    {
        if (request()->ajax()) {
            return datagrid(RemoteServiceTypeDataGrid::class)->process();
        }

        return view('remote_service::types.index');
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(): View
    {
        return view('remote_service::types.create', [
            'groups' => Group::orderBy('name')->get(),
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(): RedirectResponse
    {
        $this->validate(request(), [
            'nome' => 'required|string|max:255',
            'group_id' => 'nullable|integer|exists:groups,id',
        ]);

        RemoteServiceType::create(request()->only(['nome', 'group_id']));

        session()->flash('success', 'Tipo de atendimento criado com sucesso.');

        return redirect()->route('admin.remote_services.types.index');
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(int $id): View
    {
        return view('remote_service::types.edit', [
            'remoteServiceType' => RemoteServiceType::findOrFail($id),
            'groups' => Group::orderBy('name')->get(),
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(int $id): RedirectResponse
    { 
        $this->validate(request(), [
            'nome' => 'required|string|max:255',
            'group_id' => 'nullable|integer|exists:groups,id',
        ]);

        RemoteServiceType::findOrFail($id)->update(request()->only(['nome', 'group_id']));

        session()->flash('success', 'Tipo de atendimento atualizado com sucesso.');

        return redirect()->route('admin.remote_services.types.index');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(int $id): JsonResponse
    {
        RemoteServiceType::findOrFail($id)->delete();

        return response()->json([
            'message' => 'Tipo de atendimento excluído com sucesso.',
        ]);
    }
}
