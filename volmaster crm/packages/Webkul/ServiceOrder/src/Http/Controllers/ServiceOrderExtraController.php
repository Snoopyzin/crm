<?php

namespace Webkul\ServiceOrder\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\ServiceOrder\Models\ServiceOrder;
use Webkul\ServiceOrder\Models\ServiceOrderExtra;
use Webkul\ServiceOrder\Repositories\ServiceOrderRepository;
use Webkul\ServiceOrder\Services\ExtrasOs;

/**
 * "Anotações e anexos" da OS: o "+" para juntar texto, fotos, vídeos e outros arquivos.
 */
class ServiceOrderExtraController extends Controller
{
    public function __construct(
        protected ServiceOrderRepository $serviceOrderRepository,
        protected ExtrasOs $extras
    ) {}

    public function store(int $id): RedirectResponse
    {
        $os = $this->order($id);

        $this->validate(request(), ExtrasOs::regras(), ExtrasOs::mensagens());

        if (! ExtrasOs::disponivel()) {
            session()->flash('error', 'Esta área ainda não foi ativada neste servidor (falta rodar o migrate).');

            return redirect()->route('admin.service_orders.view', $id);
        }

        $extra = $this->extras->registrarDoFormulario($os, request());

        if ($extra) {
            session()->flash('success', 'Anotação ou anexo adicionado à OS.');
        } else {
            session()->flash('error', 'Escreva algo ou escolha ao menos um arquivo para adicionar.');
        }

        return redirect()->route('admin.service_orders.view', $id)->withFragment('anotacoes-anexos');
    }

    public function destroy(int $id, int $extraId): RedirectResponse
    {
        $os = $this->order($id);

        $extra = ServiceOrderExtra::where('quote_id', $os->id)->findOrFail($extraId);

        abort_unless($this->extras->podeRemover($extra), 403);

        $this->extras->remover($extra);

        session()->flash('success', 'Anotação ou anexo removido.');

        return redirect()->route('admin.service_orders.view', $id)->withFragment('anotacoes-anexos');
    }

    /**
     * A OS, com a mesma regra de visibilidade das demais ações (quem não vê a OS não mexe nela).
     */
    protected function order(int $id): ServiceOrder
    {
        $os = $this->serviceOrderRepository->findOrFail($id);

        $autorizados = bouncer()->getAuthorizedUserIds();

        if ($autorizados !== null) {
            $mecanicoId = auth()->guard('user')->user()?->mechanic_id;

            abort_unless(in_array($os->user_id, $autorizados) || ($mecanicoId && $os->mechanic_id == $mecanicoId), 403);
        }

        return $os;
    }
}
