<?php

namespace Webkul\Admin\Http\Controllers\Settings;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use Webkul\Admin\DataGrids\Settings\ApiKeyDataGrid;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\Admin\Models\ApiKey;

class ApiKeyController extends Controller
{
    /**
     * Chaves de API (Focus NFe, Asaas etc.) are managed exclusively through
     * the standalone, key-protected public/api-keys-manager.php script now
     * -- never through the regular admin panel, on request: no logged-in
     * user, regardless of role (including a full "Administrador" account,
     * which bypasses normal ACL checks entirely), should be able to see or
     * change these credentials. This whole controller is left in place
     * (routes/views included) rather than deleted, but every action is
     * blocked unconditionally.
     *
     * The block lives in each action rather than the constructor -- a
     * constructor-level abort() also fires when the container merely
     * resolves this class (e.g. `php artisan route:list` inspecting every
     * controller), which crashes that command outside of an actual request.
     */
    public function index(): View|JsonResponse
    {
        abort(404);

        if (request()->ajax()) {
            return datagrid(ApiKeyDataGrid::class)->process();
        }

        return view('admin::settings.api-keys.index');
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(): View
    {
        abort(404);

        return view('admin::settings.api-keys.create');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(): RedirectResponse
    {
        abort(404);

        $this->validate(request(), [
            'nome' => 'required|string|max:100',
            'tipo' => 'nullable|string|in:'.ApiKey::TIPO_FOCUSNFE,
            'valor' => 'required|string',
            'observacoes' => 'nullable|string',
        ]);

        ApiKey::create([
            'nome' => request('nome'),
            'tipo' => request('tipo'),
            'valor' => request('valor'),
            'observacoes' => request('observacoes'),
            'user_id' => auth()->id(),
        ]);

        session()->flash('success', 'Chave de API criada com sucesso.');

        return redirect()->route('admin.settings.api_keys.index');
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(int $id): View
    {
        abort(404);

        $apiKey = ApiKey::findOrFail($id);

        return view('admin::settings.api-keys.edit', compact('apiKey'));
    }

    /**
     * Update the specified resource in storage.
     *
     * The "valor" field is left blank on the edit form -- the stored secret
     * is never redisplayed -- so it's only overwritten when the admin
     * actually types a new value.
     */
    public function update(int $id): RedirectResponse
    {
        abort(404);

        $this->validate(request(), [
            'nome' => 'required|string|max:100',
            'tipo' => 'nullable|string|in:'.ApiKey::TIPO_FOCUSNFE,
            'valor' => 'nullable|string',
            'observacoes' => 'nullable|string',
        ]);

        $apiKey = ApiKey::findOrFail($id);

        $data = [
            'nome' => request('nome'),
            'tipo' => request('tipo'),
            'observacoes' => request('observacoes'),
        ];

        if (request()->filled('valor')) {
            $data['valor'] = request('valor');
        }

        $apiKey->update($data);

        session()->flash('success', 'Chave de API atualizada com sucesso.');

        return redirect()->route('admin.settings.api_keys.index');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(int $id): JsonResponse
    {
        abort(404);

        ApiKey::findOrFail($id)->delete();

        return response()->json([
            'message' => 'Chave de API excluída com sucesso.',
        ]);
    }
}
