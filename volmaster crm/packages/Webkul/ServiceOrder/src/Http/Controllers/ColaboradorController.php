<?php

namespace Webkul\ServiceOrder\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\Mechanic\Models\Mechanic;
use Webkul\ServiceOrder\Models\Colaborador;
use Webkul\ServiceOrder\Models\MentoriaRegistro;
use Webkul\ServiceOrder\Services\Perfis\PerfilService;
use Webkul\ServiceOrder\Support\BrNumber;
use Webkul\User\Models\User;

/**
 * Cadastro de colaboradores (capítulo 3.2): cargos (com acúmulo), salário
 * base, admissão, vencimentos de férias, exame e EPI, CNH e competências do
 * técnico. Alimenta os indicadores de RH e o motor de bônus.
 */
class ColaboradorController extends Controller
{
    public function index(): View
    {
        return view('service_order::colaboradores.index', [
            'colaboradores' => Colaborador::query()->orderByDesc('ativo')->orderBy('nome')->get(),
            'cargos' => WorkshopSettingsController::PAPEIS,
            'usuarios' => User::where('status', 1)->orderBy('name')->get(['id', 'name']),
            'mecanicos' => Mechanic::orderBy('nome')->get(['id', 'nome']),
            'colaborador' => new Colaborador(['classificacao' => 'oficina']),
        ]);
    }

    public function edit(int $id): View
    {
        return view('service_order::colaboradores.index', [
            'colaboradores' => Colaborador::query()->orderByDesc('ativo')->orderBy('nome')->get(),
            'cargos' => WorkshopSettingsController::PAPEIS,
            'usuarios' => User::where('status', 1)->orderBy('name')->get(['id', 'name']),
            'mecanicos' => Mechanic::orderBy('nome')->get(['id', 'nome']),
            'colaborador' => Colaborador::findOrFail($id),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $colaborador = Colaborador::create($this->dados($request));

        $this->syncRelacionados($colaborador, novo: true);

        session()->flash('success', trim('Colaborador cadastrado. '.app(PerfilService::class)->sincronizarColaborador($colaborador, auth()->guard('user')->user())));

        return redirect()->route('admin.colaboradores.index');
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $colaborador = Colaborador::findOrFail($id);
        $colaborador->update($this->dados($request));

        $this->syncRelacionados($colaborador, novo: false);

        session()->flash('success', trim('Colaborador atualizado. '.app(PerfilService::class)->sincronizarColaborador($colaborador, auth()->guard('user')->user())));

        return redirect()->route('admin.colaboradores.index');
    }

    /**
     * Desligamento: o colaborador sai do quadro (e das contas do bônus e do
     * RH), mas o cadastro fica guardado.
     */
    public function desligar(Request $request, int $id): RedirectResponse
    {
        $colaborador = Colaborador::findOrFail($id);

        $colaborador->update(['ativo' => false, 'desligamento_em' => $request->date('desligamento_em') ?? today()]);

        MentoriaRegistro::record('desligamento', ['user_id' => $colaborador->user_id, 'observacao' => $colaborador->nome], now());

        session()->flash('success', 'Desligamento registrado.');

        return redirect()->route('admin.colaboradores.index');
    }

    /**
     * @return array<string, mixed>
     */
    protected function dados(Request $request): array
    {
        $validos = array_keys(WorkshopSettingsController::PAPEIS);

        $dados = $this->validate($request, [
            'nome' => 'required|string|max:255',
            'cpf' => 'nullable|string|max:14',
            'user_id' => 'nullable|integer|exists:users,id',
            'mechanic_id' => 'nullable|integer|exists:mechanics,id',
            'cargos' => 'nullable|array',
            'cargos.*' => 'in:'.implode(',', $validos),
            'classificacao' => 'required|in:oficina,administracao',
            'salario_base' => 'nullable|string|max:20',
            'admissao' => 'nullable|date',
            'registro_antes_inicio' => 'nullable|in:0,1',
            'ferias_vencimento' => 'nullable|date',
            'exame_periodico_vencimento' => 'nullable|date',
            'epi_vencimento' => 'nullable|date',
            'cnh_categoria' => 'nullable|string|max:3',
            'competencias' => 'nullable|array',
            'competencias.*' => 'nullable|integer|min:0|max:3',
        ]);

        return [
            'nome' => $dados['nome'],
            'cpf' => $dados['cpf'] ?? null,
            'user_id' => $dados['user_id'] ?? null,
            'mechanic_id' => $dados['mechanic_id'] ?? null,
            'cargos' => implode(',', array_values(array_intersect($validos, $dados['cargos'] ?? []))) ?: null,
            'classificacao' => $dados['classificacao'],
            'salario_base' => $this->dinheiro($dados['salario_base'] ?? null),
            'admissao' => $dados['admissao'] ?? null,
            'registro_antes_inicio' => isset($dados['registro_antes_inicio']) ? (bool) $dados['registro_antes_inicio'] : null,
            'ferias_vencimento' => $dados['ferias_vencimento'] ?? null,
            'exame_periodico_vencimento' => $dados['exame_periodico_vencimento'] ?? null,
            'epi_vencimento' => $dados['epi_vencimento'] ?? null,
            'cnh_categoria' => isset($dados['cnh_categoria']) ? strtoupper($dados['cnh_categoria']) : null,
            'competencias' => array_filter($dados['competencias'] ?? [], fn ($nivel) => $nivel !== null && $nivel !== '') ?: null,
        ];
    }

    /**
     * A CNH do técnico é lida no bloqueio do test drive; a admissão entra
     * no histórico dos registros.
     */
    protected function syncRelacionados(Colaborador $colaborador, bool $novo): void
    {
        if ($colaborador->mechanic_id) {
            Mechanic::whereKey($colaborador->mechanic_id)->update(['cnh_categoria' => $colaborador->cnh_categoria]);
        }

        if ($novo && $colaborador->admissao) {
            MentoriaRegistro::record('admissao', [
                'user_id' => $colaborador->user_id,
                'ok' => $colaborador->registro_antes_inicio,
                'observacao' => $colaborador->nome,
            ], $colaborador->admissao->startOfDay());
        }
    }

    protected function dinheiro(?string $bruto): ?float
    {
        return BrNumber::parse($bruto);
    }
}
