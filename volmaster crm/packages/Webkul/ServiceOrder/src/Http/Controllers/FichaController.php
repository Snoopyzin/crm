<?php

namespace Webkul\ServiceOrder\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\ServiceOrder\Services\FichaEnquadramento;
use Webkul\ServiceOrder\Services\WorkshopSettings;
use Webkul\ServiceOrder\Support\BrNumber;

/**
 * Ficha de Enquadramento (capítulo 3.1): tudo o que muda de uma oficina para
 * outra. O método da mentoria é o mesmo para todas; as diferenças ficam aqui.
 */
class FichaController extends Controller
{
    public function __construct(protected FichaEnquadramento $ficha) {}

    public function edit(): View
    {
        return view('service_order::ficha.edit', [
            'cfg' => WorkshopSettings::all(),
            'portes' => FichaEnquadramento::PORTES,
            'cargos' => WorkshopSettingsController::PAPEIS,
            'ocupados' => $this->ficha->cargosOcupados(),
            'semResponsavel' => $this->ficha->cargosSemResponsavel(),
            'pendencias' => $this->ficha->pendencias(),
            'faixas' => DB::table('margem_pecas_faixas')->orderByRaw('custo_ate is null, custo_ate')->get(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $dados = $this->validate($request, [
            'ficha_razao_social' => 'nullable|string|max:255',
            'ficha_cnpj' => 'nullable|string|max:20',
            'ficha_cidade' => 'nullable|string|max:120',
            'ficha_porte' => 'required|in:'.implode(',', array_keys(FichaEnquadramento::PORTES)),
            'ficha_boxes' => 'nullable|integer|min:0|max:200',
            'ficha_elevadores' => 'nullable|integer|min:0|max:200',
            'ficha_veiculo_externo' => 'nullable|in:0,1',
            'valor_hora_mao_obra' => 'nullable|string|max:20',
            'diaria_patio_valor' => 'nullable|string|max:20',
            'diaria_patio_horas_pronto' => 'nullable|integer|min:0|max:720',
            'diaria_patio_horas_nao_autorizado' => 'nullable|integer|min:0|max:720',
            'cadastro_validade_dias' => 'nullable|integer|min:1|max:3650',
            'garantia_servico_dias' => 'required|integer|min:0|max:3650',
            'garantia_pecas_dias' => 'required|integer|min:0|max:3650',
            'horario_cafe' => 'nullable|date_format:H:i',
            'horario_lanche' => 'nullable|date_format:H:i',
            'dono_horas_prospeccao' => 'required|numeric|min:0|max:80',
            'gatilho_resultado' => 'nullable|string|max:20',
            'meta_faturamento_mensal' => 'nullable|string|max:20',
            'inicio_mentoria' => 'nullable|date',
            'pct_impostos' => 'nullable|string|max:8',
            'pct_comissoes' => 'nullable|string|max:8',
            'pct_desp_var_pecas' => 'nullable|string|max:8',
            'margem_desejada_pecas' => 'nullable|string|max:8',
            'lucro_desejado' => 'nullable|string|max:20',
            'produtividade_alvo' => 'nullable|numeric|min:1|max:100',
            'horas_mes_tecnico' => 'nullable|numeric|min:1|max:744',
            'encargos_percentual' => 'nullable|string|max:8',
            'relatorio_financeiro_horario' => 'nullable|date_format:H:i',
            'bonus_cheio' => 'nullable|array',
            'bonus_cheio.*' => 'nullable|string|max:8',
        ]);

        $dinheiro = fn (?string $v) => BrNumber::parse($v);

        foreach (['ficha_razao_social', 'ficha_cnpj', 'ficha_cidade', 'ficha_porte', 'horario_cafe', 'horario_lanche', 'inicio_mentoria'] as $campo) {
            WorkshopSettings::set($campo, $dados[$campo] ?? null);
        }

        foreach (['ficha_boxes', 'ficha_elevadores', 'diaria_patio_horas_pronto', 'diaria_patio_horas_nao_autorizado', 'garantia_servico_dias', 'garantia_pecas_dias'] as $campo) {
            WorkshopSettings::set($campo, isset($dados[$campo]) ? (int) $dados[$campo] : null);
        }

        // Validade do cadastro do cliente: sem valor, fica a que já estava (padrão: 90 dias).
        if (isset($dados['cadastro_validade_dias'])) {
            WorkshopSettings::set('cadastro_validade_dias', (int) $dados['cadastro_validade_dias']);
        }

        foreach (['valor_hora_mao_obra', 'diaria_patio_valor', 'gatilho_resultado', 'meta_faturamento_mensal', 'lucro_desejado', 'pct_impostos', 'pct_comissoes', 'pct_desp_var_pecas', 'margem_desejada_pecas', 'encargos_percentual'] as $campo) {
            WorkshopSettings::set($campo, $dinheiro($dados[$campo] ?? null));
        }

        WorkshopSettings::set('produtividade_alvo', (float) ($dados['produtividade_alvo'] ?? 80));
        WorkshopSettings::set('horas_mes_tecnico', (float) ($dados['horas_mes_tecnico'] ?? 176));
        WorkshopSettings::set('relatorio_financeiro_horario', $dados['relatorio_financeiro_horario'] ?? '17:30');

        WorkshopSettings::set('ficha_veiculo_externo', (bool) ($dados['ficha_veiculo_externo'] ?? false));
        WorkshopSettings::set('dono_horas_prospeccao', (float) $dados['dono_horas_prospeccao']);

        $bonus = [];

        foreach ((array) ($dados['bonus_cheio'] ?? []) as $cargo => $percentual) {
            $valor = $dinheiro($percentual);

            if ($valor !== null && array_key_exists($cargo, WorkshopSettingsController::PAPEIS)) {
                $bonus[$cargo] = $valor;
            }
        }

        WorkshopSettings::set('bonus_cheio_percentual', $bonus);

        session()->flash('success', 'Ficha de Enquadramento salva.');

        return redirect()->route('admin.ficha.edit');
    }

    /**
     * Registro de acesso (LGPD): quem abriu ou mexeu em dados financeiros, de pessoal e do painel do dono.
     */
    public function acessos(): View
    {
        return view('service_order::ficha.acessos', [
            'acessos' => DB::table('acessos_sensiveis as a')->leftJoin('users as u', 'u.id', '=', 'a.user_id')
                ->orderByDesc('a.id')->limit(300)->get(['a.rota', 'a.metodo', 'a.ip', 'a.acessado_em', 'u.name as usuario']),
        ]);
    }

    /**
     * Cargo sem responsável: registra a ação (redistribuir/contratar) e o
     * prazo; fica como pendência no painel do gerente até resolver.
     */
    public function pendencia(Request $request): RedirectResponse
    {
        $dados = $this->validate($request, [
            'cargo' => 'required|in:'.implode(',', array_keys(WorkshopSettingsController::PAPEIS)),
            'acao' => 'required|in:redistribuir,contratar',
            'gatilho' => 'nullable|string|max:255',
            'prazo' => 'nullable|date',
        ]);

        DB::table('cargos_pendentes')->insert($dados + ['created_at' => now(), 'updated_at' => now()]);

        session()->flash('success', 'Pendência registrada. O gerente é avisado se passar do prazo.');

        return redirect()->route('admin.ficha.edit');
    }

    public function resolverPendencia(int $id): RedirectResponse
    {
        DB::table('cargos_pendentes')->where('id', $id)->update(['resolvido_em' => today(), 'updated_at' => now()]);

        session()->flash('success', 'Pendência resolvida.');

        return redirect()->route('admin.ficha.edit');
    }

    /**
     * Tabela de margem de peças (faixa de custo, margem mínima e alvo).
     */
    public function margens(Request $request): RedirectResponse
    {
        $this->validate($request, ['faixa' => 'nullable|array']);

        $faixas = collect((array) $request->input('faixa', []))
            ->map(fn ($f) => [
                'custo_ate' => isset($f['custo_ate']) && $f['custo_ate'] !== '' ? (float) str_replace(',', '.', str_replace('.', '', (string) $f['custo_ate'])) : null,
                'margem_minima' => (float) str_replace(',', '.', (string) ($f['margem_minima'] ?? 0)),
                'margem_alvo' => (float) str_replace(',', '.', (string) ($f['margem_alvo'] ?? 0)),
            ])
            ->filter(fn ($f) => $f['margem_minima'] > 0 || $f['margem_alvo'] > 0)
            ->values();

        DB::transaction(function () use ($faixas) {
            DB::table('margem_pecas_faixas')->delete();

            foreach ($faixas as $faixa) {
                DB::table('margem_pecas_faixas')->insert($faixa + ['created_at' => now(), 'updated_at' => now()]);
            }
        });

        session()->flash('success', 'Tabela de margem de peças salva.');

        return redirect()->route('admin.ficha.edit');
    }
}
