<?php

namespace Webkul\ServiceOrder\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\View\View;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\ServiceOrder\Services\ClienteBusca;

/**
 * Aba "Buscar cliente": pesquisa a pessoa por nome, código, CPF/CNPJ, telefone ou placa
 * e, se ela não existir, leva para o Cadastro do cliente já com o que foi digitado.
 * Só lê; quem grava continua sendo o Cadastro (que também reaproveita o cliente
 * que já existe pelo CPF/CNPJ ou WhatsApp). O mesmo serviço alimenta o campo de
 * pesquisa de cliente da abertura da OS.
 */
class ClienteBuscaController extends Controller
{
    public function __construct(protected ClienteBusca $busca) {}

    public function index(): View
    {
        $termo = trim((string) request('q'));

        return view('service_order::clientes.busca', [
            'termo' => $termo,
            'resultados' => ClienteBusca::termoValido($termo) ? $this->busca->buscar($termo) : null,
            'cadastro' => $this->busca->paraOCadastro($termo),
        ]);
    }

    /**
     * Pesquisa para o campo de cliente da abertura da OS (JSON).
     */
    public function pesquisar(): JsonResponse
    {
        $termo = trim((string) request('q'));

        return response()->json([
            'resultados' => ClienteBusca::termoValido($termo) ? $this->busca->buscar($termo)->values() : [],
            'cadastro' => route('admin.service_orders.cadastro.create', $this->busca->paraOCadastro($termo)),
        ]);
    }
}
