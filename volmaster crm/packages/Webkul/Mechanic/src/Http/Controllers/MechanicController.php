<?php

namespace Webkul\Mechanic\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\Mechanic\DataGrids\MechanicDataGrid;
use Webkul\Mechanic\Repositories\MechanicRepository;

class MechanicController extends Controller
{
    /**
     * Create a new controller instance.
     */
    public function __construct(protected MechanicRepository $mechanicRepository) {}

    /**
     * Display a listing of the resource.
     */
    public function index(): View|JsonResponse
    {
        if (request()->ajax()) {
            return datagrid(MechanicDataGrid::class)->process();
        }

        return view('mechanic::index');
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(): View
    {
        return view('mechanic::create');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(): RedirectResponse
    {
        $this->validate(request(), [
            'nome' => 'required|string|max:255',
            'especialidade' => 'required|in:motor,transmissao,eletrica,diagnostico,suspensao,freios,hidraulica,arrefecimento',
            'years_of_experience' => 'nullable|numeric|min:0|max:80',
            'avaliacao' => 'nullable|numeric|min:0|max:5',
            'status' => 'required|in:Ativo,Lenda',
        ]);

        Event::dispatch('mechanic.create.before');

        $mechanic = $this->mechanicRepository->create(request()->all());

        Event::dispatch('mechanic.create.after', $mechanic);

        session()->flash('success', trans('mechanic::app.index.create-success').($mechanic->codigo ? ' Código do mecânico: Nº '.$mechanic->codigo.'.' : ''));

        return redirect()->route('admin.mechanics.index');
    }

    /**
     * Display the specified resource.
     */
    public function view(int $id): View
    {
        $mechanic = $this->mechanicRepository->findOrFail($id);

        return view('mechanic::view', compact('mechanic'));
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(int $id): View
    {
        $mechanic = $this->mechanicRepository->findOrFail($id);

        return view('mechanic::edit', compact('mechanic'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(int $id): RedirectResponse
    {
        $this->validate(request(), [
            'nome' => 'required|string|max:255',
            'especialidade' => 'required|in:motor,transmissao,eletrica,diagnostico,suspensao,freios,hidraulica,arrefecimento',
            'years_of_experience' => 'nullable|numeric|min:0|max:80',
            'avaliacao' => 'nullable|numeric|min:0|max:5',
            'status' => 'required|in:Ativo,Lenda',
        ]);

        Event::dispatch('mechanic.update.before', $id);

        try {
            $mechanic = $this->mechanicRepository->update(request()->all(), $id);
        } catch (ValidationException $exception) {
            return redirect()->back()->withInput()->withErrors($exception->errors());
        }

        Event::dispatch('mechanic.update.after', $mechanic);

        session()->flash('success', trans('mechanic::app.index.update-success'));

        return redirect()->route('admin.mechanics.index');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(int $id): JsonResponse
    {
        $this->mechanicRepository->findOrFail($id);

        try {
            Event::dispatch('mechanic.delete.before', $id);

            $this->mechanicRepository->delete($id);

            Event::dispatch('mechanic.delete.after', $id);

            return response()->json([
                'message' => trans('mechanic::app.index.delete-success'),
            ], 200);
        } catch (ValidationException $exception) {
            return response()->json([
                'message' => collect($exception->errors())->flatten()->first(),
            ], 400);
        } catch (\Exception $exception) {
            return response()->json([
                'message' => trans('mechanic::app.index.delete-failed'),
            ], 400);
        }
    }
}
