<?php

namespace Webkul\ServiceOrder\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\ServiceOrder\Services\Perfis\PerfilCatalog;
use Webkul\ServiceOrder\Services\Perfis\PerfilService;
use Webkul\User\Models\User;

/**
 * Configurações > Perfis da mentoria: cria as funções dos cargos, mostra a prévia da migração
 * dos usuários e desfaz o último lote. Nada acontece sozinho.
 */
class PerfilController extends Controller
{
    public function __construct(protected PerfilService $perfis) {}

    public function index(): View
    {
        $gerenciadas = $this->perfis->gerenciadas();

        $situacao = collect(PerfilCatalog::todos())->map(function (array $definicao, string $chave) use ($gerenciadas) {
            $funcao = $gerenciadas->first(fn ($role) => (string) $role->getAttribute('perfil_oficina') === $chave);

            return [
                'chave' => $chave,
                'nome' => $definicao['nome'],
                'descricao' => $definicao['descricao'],
                'funcao' => $funcao?->name,
                'usuarios' => $funcao ? User::where('role_id', $funcao->id)->count() : 0,
                'valores' => PerfilCatalog::veValores([$chave]),
            ];
        })->values();

        return view('service_order::settings.perfis', [
            'situacao' => $situacao,
            'plano' => $this->perfis->plano(),
            'perfis' => collect(PerfilCatalog::todos())->map(fn ($d) => $d['nome'])->all(),
            'ativo' => $this->perfis->ativo(),
            'ultimoLote' => $this->perfis->ultimoLote(),
        ]);
    }

    public function aplicar(): RedirectResponse
    {
        $feitos = collect($this->perfis->aplicar());

        $criadas = $feitos->where('acao', 'criada')->count();
        $atualizadas = $feitos->where('acao', 'atualizada')->count();

        session()->flash('success', $criadas || $atualizadas
            ? "Funções da mentoria: {$criadas} criada(s), {$atualizadas} atualizada(s). Nenhuma função antiga foi apagada e nenhum usuário mudou de função."
            : 'As funções da mentoria já estavam certas. Nada mudou.');

        return redirect()->route('admin.workshop.perfis.index');
    }

    public function migrar(): RedirectResponse
    {
        $this->validate(request(), ['usuario' => 'nullable|array', 'usuario.*' => 'nullable|string|max:30']);

        $resultado = $this->perfis->migrar((array) request('usuario', []), auth()->guard('user')->user());

        session()->flash('success', $resultado['movidos']
            ? "{$resultado['movidos']} usuário(s) mudaram de função. Se algo ficou errado, use \"Desfazer o último lote\"."
            : 'Nenhum usuário mudou de função.');

        if ($resultado['ignorados']) {
            session()->flash('warning', implode(' ', $resultado['ignorados']));
        }

        return redirect()->route('admin.workshop.perfis.index');
    }

    public function desfazer(): RedirectResponse
    {
        $this->validate(request(), ['lote' => 'required|string|max:60']);

        $quantidade = $this->perfis->desfazer((string) request('lote'));

        session()->flash('success', $quantidade ? "{$quantidade} usuário(s) voltaram à função anterior." : 'Nada a desfazer neste lote.');

        return redirect()->route('admin.workshop.perfis.index');
    }
}
