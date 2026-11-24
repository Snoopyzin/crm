<?php

namespace Webkul\Vehicle\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Event;
use Illuminate\View\View;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\Contact\Repositories\PersonRepository;
use Webkul\Vehicle\DataGrids\VehicleDataGrid;
use Webkul\Vehicle\Repositories\VehicleRepository;

class VehicleController extends Controller
{
    /**
     * Create a new controller instance.
     */
    public function __construct(protected VehicleRepository $vehicleRepository, protected PersonRepository $personRepository) {}

    /**
     * Display a listing of the resource.
     */
    public function index(): View|JsonResponse
    {
        if (request()->ajax()) {
            return datagrid(VehicleDataGrid::class)->process();
        }

        return view('vehicle::index');
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(): View
    {
        $persons = $this->personRepository->all();

        return view('vehicle::create', compact('persons'));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(): RedirectResponse
    {
        $this->validate(request(), [
            'person_id' => 'required|integer|exists:persons,id',
            'marca' => 'required|string|max:255',
            'modelo' => 'required|string|max:255',
            'placa' => 'required|string|max:20|unique:vehicles,placa',
            'ano' => 'nullable|integer|digits:4',
            'chassi' => 'nullable|string|max:255',
            'km' => 'nullable|integer|min:0',
            'cor' => 'nullable|string|max:255',
            'frota' => 'nullable|string|max:50',
            'tipo' => 'required|in:carros,motos,caminhoes',
            'combustivel' => 'nullable|in:Flex,Gasolina,Etanol,Diesel,Elétrico,Híbrido,GNV',
            'status' => 'required|string|max:255',
            'image' => 'nullable|image|max:5120',
        ]);

        $data = request()->except('image');

        if (request()->hasFile('image')) {
            $data['photo_path'] = request()->file('image')->store('vehicles');
        }

        Event::dispatch('vehicle.create.before');

        $vehicle = $this->vehicleRepository->create($data);

        Event::dispatch('vehicle.create.after', $vehicle);

        session()->flash('success', trans('vehicle::app.index.create-success'));

        return redirect()->route('admin.vehicles.index');
    }

    /**
     * Display the specified resource.
     */
    public function view(int $id): View
    {
        $vehicle = $this->vehicleRepository->findOrFail($id);

        return view('vehicle::view', compact('vehicle'));
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(int $id): View
    {
        $vehicle = $this->vehicleRepository->findOrFail($id);

        $persons = $this->personRepository->all();

        return view('vehicle::edit', compact('vehicle', 'persons'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(int $id): RedirectResponse
    {
        $this->validate(request(), [
            'person_id' => 'required|integer|exists:persons,id',
            'marca' => 'required|string|max:255',
            'modelo' => 'required|string|max:255',
            'placa' => 'required|string|max:20|unique:vehicles,placa,'.$id,
            'ano' => 'nullable|integer|digits:4',
            'chassi' => 'nullable|string|max:255',
            'km' => 'nullable|integer|min:0',
            'cor' => 'nullable|string|max:255',
            'frota' => 'nullable|string|max:50',
            'tipo' => 'required|in:carros,motos,caminhoes',
            'combustivel' => 'nullable|in:Flex,Gasolina,Etanol,Diesel,Elétrico,Híbrido,GNV',
            'status' => 'required|string|max:255',
            'image' => 'nullable|image|max:5120',
        ]);

        $data = request()->except('image');

        if (request()->hasFile('image')) {
            $data['photo_path'] = request()->file('image')->store('vehicles');
        }

        Event::dispatch('vehicle.update.before', $id);

        $vehicle = $this->vehicleRepository->update($data, $id);

        Event::dispatch('vehicle.update.after', $vehicle);

        session()->flash('success', trans('vehicle::app.index.update-success'));

        return redirect()->route('admin.vehicles.index');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(int $id): JsonResponse
    {
        $this->vehicleRepository->findOrFail($id);

        try {
            Event::dispatch('vehicle.delete.before', $id);

            $this->vehicleRepository->delete($id);

            Event::dispatch('vehicle.delete.after', $id);

            return response()->json([
                'message' => trans('vehicle::app.index.delete-success'),
            ], 200);
        } catch (\Exception $exception) {
            return response()->json([
                'message' => trans('vehicle::app.index.delete-failed'),
            ], 400);
        }
    }
}
