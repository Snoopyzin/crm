@php
    $campo = 'rounded-md border border-gray-300 px-2 py-1.5 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white';
    $rotulo = 'text-xs text-gray-500 dark:text-gray-400';
    $card = 'box-shadow rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900';
    $titulo = 'mb-3 text-base font-semibold text-gray-800 dark:text-white';
    $reais = fn ($v) => $v === null || $v === '' ? '' : number_format((float) $v, 2, ',', '.');
    // B8: o Gerente lê a Ficha, mas só quem tem "Editar a Ficha" (o Proprietário) altera.
    $podeEditar = bouncer()->hasPermission('settings.ficha.editar');
    $semBorda = 'border:0;padding:0;margin:0;min-width:0';
@endphp

<x-admin::layouts>
    <x-slot:title>
        Ficha de Enquadramento
    </x-slot>

    <div class="flex flex-col gap-4">
        <div class="rounded-lg border border-gray-200 bg-white px-4 py-3 dark:border-gray-800 dark:bg-gray-900">
            <div class="text-xl font-bold dark:text-white">Ficha de Enquadramento</div>
            <p class="text-sm text-gray-500">O método da mentoria é o mesmo para todas as oficinas. O que muda de uma para outra fica aqui.</p>
        </div>

        @unless ($podeEditar)
            <div class="rounded-lg border border-yellow-300 bg-yellow-50 px-4 py-3 text-sm text-yellow-900" id="ficha-somente-leitura">
                Você pode consultar a Ficha, mas só o Proprietário altera. Para mudar algum valor, peça a ele.
            </div>
        @endunless

        <form method="POST" action="{{ route('admin.ficha.update') }}" class="flex flex-col gap-4">
            @csrf
            @method('PUT')

            <fieldset @disabled(! $podeEditar) class="flex flex-col gap-4" style="{{ $semBorda }}">

            <div class="{{ $card }}">
                <p class="{{ $titulo }}">Identificação e estrutura</p>
                <div class="flex flex-wrap gap-3">
                    <div class="flex flex-col gap-1"><label class="{{ $rotulo }}">Razão social</label><input type="text" name="ficha_razao_social" value="{{ $cfg['ficha_razao_social'] }}" class="w-72 {{ $campo }}" /></div>
                    <div class="flex flex-col gap-1"><label class="{{ $rotulo }}">CNPJ</label><input type="text" name="ficha_cnpj" value="{{ $cfg['ficha_cnpj'] }}" class="w-44 {{ $campo }}" /></div>
                    <div class="flex flex-col gap-1"><label class="{{ $rotulo }}">Cidade</label><input type="text" name="ficha_cidade" value="{{ $cfg['ficha_cidade'] }}" class="w-48 {{ $campo }}" /></div>
                    <div class="flex flex-col gap-1">
                        <label class="{{ $rotulo }}">Porte</label>
                        <select name="ficha_porte" class="{{ $campo }}">@foreach ($portes as $chave => $nome)<option value="{{ $chave }}" @selected($cfg['ficha_porte'] === $chave)>{{ $nome }}</option>@endforeach</select>
                    </div>
                    <div class="flex flex-col gap-1"><label class="{{ $rotulo }}">Boxes</label><input type="number" min="0" name="ficha_boxes" value="{{ $cfg['ficha_boxes'] }}" class="w-20 {{ $campo }}" /></div>
                    <div class="flex flex-col gap-1"><label class="{{ $rotulo }}">Elevadores</label><input type="number" min="0" name="ficha_elevadores" value="{{ $cfg['ficha_elevadores'] }}" class="w-20 {{ $campo }}" /></div>
                    <div class="flex flex-col gap-1">
                        <label class="{{ $rotulo }}">Veículo de atendimento externo</label>
                        <select name="ficha_veiculo_externo" class="{{ $campo }}"><option value="0" @selected(! $cfg['ficha_veiculo_externo'])>Não</option><option value="1" @selected($cfg['ficha_veiculo_externo'])>Sim</option></select>
                    </div>
                </div>
                <p class="mt-2 text-xs text-gray-500">O horário de funcionamento é ajustado em Configurações &gt; Indicadores e alertas.</p>
            </div>

            <div class="{{ $card }}">
                <p class="{{ $titulo }}">Valores e prazos</p>
                <div class="flex flex-wrap gap-3">
                    <div class="flex flex-col gap-1"><label class="{{ $rotulo }}">Valor da hora de mão de obra (R$)</label><input type="text" data-money name="valor_hora_mao_obra" value="{{ $reais($cfg['valor_hora_mao_obra']) }}" class="w-36 {{ $campo }}" /></div>
                    <div class="flex flex-col gap-1"><label class="{{ $rotulo }}">Diária de pátio (R$/dia)</label><input type="text" data-money name="diaria_patio_valor" value="{{ $reais($cfg['diaria_patio_valor']) }}" class="w-36 {{ $campo }}" /></div>
                    <div class="flex flex-col gap-1"><label class="{{ $rotulo }}">Horas para cobrar após pronto</label><input type="number" min="0" name="diaria_patio_horas_pronto" value="{{ $cfg['diaria_patio_horas_pronto'] }}" class="w-24 {{ $campo }}" /></div>
                    <div class="flex flex-col gap-1"><label class="{{ $rotulo }}">Horas para cobrar com orçamento não autorizado</label><input type="number" min="0" name="diaria_patio_horas_nao_autorizado" value="{{ $cfg['diaria_patio_horas_nao_autorizado'] }}" class="w-24 {{ $campo }}" /></div>
                    <div class="flex flex-col gap-1"><label class="{{ $rotulo }}">Garantia do serviço (dias)</label><input type="number" min="0" name="garantia_servico_dias" value="{{ $cfg['garantia_servico_dias'] }}" class="w-24 {{ $campo }}" /></div>
                    <div class="flex flex-col gap-1"><label class="{{ $rotulo }}">Garantia das peças (dias)</label><input type="number" min="0" name="garantia_pecas_dias" value="{{ $cfg['garantia_pecas_dias'] }}" class="w-24 {{ $campo }}" /></div>
                    <div class="flex flex-col gap-1"><label class="{{ $rotulo }}">Validade do cadastro do cliente (dias)</label><input type="number" min="1" name="cadastro_validade_dias" value="{{ $cfg['cadastro_validade_dias'] ?? 90 }}" class="w-24 {{ $campo }}" title="A última análise de crédito vale por quantos dias. Depois disso o sistema avisa para pedir nova consulta ao Financeiro." /></div>
                    <div class="flex flex-col gap-1"><label class="{{ $rotulo }}">Café</label><input type="time" name="horario_cafe" value="{{ $cfg['horario_cafe'] }}" class="{{ $campo }}" /></div>
                    <div class="flex flex-col gap-1"><label class="{{ $rotulo }}">Lanche</label><input type="time" name="horario_lanche" value="{{ $cfg['horario_lanche'] }}" class="{{ $campo }}" /></div>
                    <div class="flex flex-col gap-1"><label class="{{ $rotulo }}">Horas semanais de prospecção do dono</label><input type="number" step="0.5" min="0" name="dono_horas_prospeccao" value="{{ $cfg['dono_horas_prospeccao'] }}" class="w-24 {{ $campo }}" /></div>
                </div>
            </div>

            <div class="{{ $card }}">
                <p class="{{ $titulo }}">Parâmetros financeiros (DRE e preço mínimo)</p>
                <div class="flex flex-wrap gap-3">
                    <div class="flex flex-col gap-1"><label class="{{ $rotulo }}">Impostos sobre a venda (%)</label><input type="text" name="pct_impostos" value="{{ $cfg['pct_impostos'] }}" class="w-24 {{ $campo }}" /></div>
                    <div class="flex flex-col gap-1"><label class="{{ $rotulo }}">Comissões (%)</label><input type="text" name="pct_comissoes" value="{{ $cfg['pct_comissoes'] }}" class="w-24 {{ $campo }}" /></div>
                    <div class="flex flex-col gap-1"><label class="{{ $rotulo }}">Despesas variáveis de peças (%)</label><input type="text" name="pct_desp_var_pecas" value="{{ $cfg['pct_desp_var_pecas'] }}" class="w-24 {{ $campo }}" /></div>
                    <div class="flex flex-col gap-1"><label class="{{ $rotulo }}">Margem desejada de peças (%)</label><input type="text" name="margem_desejada_pecas" value="{{ $cfg['margem_desejada_pecas'] }}" class="w-24 {{ $campo }}" /></div>
                    <div class="flex flex-col gap-1"><label class="{{ $rotulo }}">Lucro desejado por mês (R$)</label><input type="text" data-money name="lucro_desejado" value="{{ $reais($cfg['lucro_desejado']) }}" class="w-36 {{ $campo }}" /></div>
                    <div class="flex flex-col gap-1"><label class="{{ $rotulo }}">Produtividade alvo (%)</label><input type="number" min="1" max="100" name="produtividade_alvo" value="{{ $cfg['produtividade_alvo'] }}" class="w-24 {{ $campo }}" /></div>
                    <div class="flex flex-col gap-1"><label class="{{ $rotulo }}">Horas do mês por técnico</label><input type="number" min="1" name="horas_mes_tecnico" value="{{ $cfg['horas_mes_tecnico'] }}" class="w-24 {{ $campo }}" /></div>
                    <div class="flex flex-col gap-1"><label class="{{ $rotulo }}">Encargos sobre o salário (%)</label><input type="text" name="encargos_percentual" value="{{ $cfg['encargos_percentual'] }}" class="w-24 {{ $campo }}" /></div>
                    <div class="flex flex-col gap-1"><label class="{{ $rotulo }}">Relatório diário do financeiro até</label><input type="time" name="relatorio_financeiro_horario" value="{{ $cfg['relatorio_financeiro_horario'] }}" class="{{ $campo }}" /></div>
                </div>
            </div>

            <div class="{{ $card }}">
                <p class="{{ $titulo }}">Mentoria e bônus</p>
                <div class="flex flex-wrap gap-3">
                    <div class="flex flex-col gap-1"><label class="{{ $rotulo }}">Data de início da mentoria</label><input type="date" name="inicio_mentoria" value="{{ $cfg['inicio_mentoria'] }}" class="{{ $campo }}" /></div>
                    <div class="flex flex-col gap-1"><label class="{{ $rotulo }}">Meta de faturamento mensal (R$)</label><input type="text" data-money name="meta_faturamento_mensal" value="{{ $reais($cfg['meta_faturamento_mensal']) }}" class="w-40 {{ $campo }}" /></div>
                    <div class="flex flex-col gap-1"><label class="{{ $rotulo }}">Gatilho de resultado da Fase 2 (R$ de faturamento ou resultado mínimo)</label><input type="text" data-money name="gatilho_resultado" value="{{ $reais($cfg['gatilho_resultado']) }}" class="w-40 {{ $campo }}" /></div>
                </div>
                <p class="mb-1 mt-3 text-xs text-gray-500">Valor cheio do bônus por cargo (% do salário base). Referência: técnico, consultor, chefe, peças e financeiro 10% a 20%; gerente 15% a 30%; zeladoria 5% a 10%.</p>
                <div class="flex flex-wrap gap-3">
                    @foreach ($cargos as $chave => $nome)
                        @continue($chave === 'proprietario')
                        <div class="flex flex-col gap-1"><label class="{{ $rotulo }}">{{ $nome }} (%)</label><input type="text" name="bonus_cheio[{{ $chave }}]" value="{{ $cfg['bonus_cheio_percentual'][$chave] ?? '' }}" class="w-24 {{ $campo }}" /></div>
                    @endforeach
                </div>
            </div>

            @if ($podeEditar)
                <div class="flex items-center gap-4">
                    <button type="submit" class="primary-button">Salvar a ficha</button>
                    <a class="text-sm text-blue-600 hover:underline" href="{{ route('admin.ficha.acessos') }}">Registro de acesso aos dados sensíveis (LGPD)</a>
                </div>
            @endif
            </fieldset>
        </form>

        {{-- Cargos ocupados e cargos sem responsável --}}
        <div class="{{ $card }}">
            <p class="{{ $titulo }}">Cargos ocupados e acúmulos</p>

            @foreach ($cargos as $chave => $nome)
                <p class="border-b border-gray-100 py-1.5 text-sm dark:border-gray-800 dark:text-white">
                    <span class="inline-block w-56 font-semibold">{{ $nome }}</span>
                    @if (isset($ocupados[$chave]))
                        {{ implode(', ', $ocupados[$chave]) }}
                    @elseif (in_array($chave, $semResponsavel, true))
                        <span class="rounded bg-yellow-100 px-1.5 py-0.5 text-xs text-yellow-800">sem responsável</span>
                    @else
                        <span class="text-xs text-gray-400">—</span>
                    @endif
                </p>
            @endforeach
            <p class="mt-2 text-xs text-gray-500">Cadastre quem ocupa cada cargo em <a class="text-blue-600 hover:underline" href="{{ route('admin.colaboradores.index') }}">Colaboradores</a>.</p>

            @if ($semResponsavel && $podeEditar)
                <form method="POST" action="{{ route('admin.ficha.pendencia') }}" class="mt-3 flex flex-wrap items-end gap-3 rounded-md bg-yellow-50 p-3 dark:bg-gray-800">
                    @csrf
                    <div class="flex flex-col gap-1">
                        <label class="{{ $rotulo }}">Cargo sem responsável</label>
                        <select name="cargo" class="{{ $campo }}">@foreach ($semResponsavel as $cargo)<option value="{{ $cargo }}">{{ $cargos[$cargo] ?? $cargo }}</option>@endforeach</select>
                    </div>
                    <div class="flex flex-col gap-1"><label class="{{ $rotulo }}">Ação</label><select name="acao" class="{{ $campo }}"><option value="redistribuir">Redistribuir</option><option value="contratar">Contratar</option></select></div>
                    <div class="flex flex-col gap-1"><label class="{{ $rotulo }}">Gatilho</label><input type="text" name="gatilho" maxlength="255" class="w-64 {{ $campo }}" /></div>
                    <div class="flex flex-col gap-1"><label class="{{ $rotulo }}">Prazo</label><input type="date" name="prazo" class="{{ $campo }}" /></div>
                    <button type="submit" class="secondary-button">Registrar pendência</button>
                </form>
            @endif

            @foreach ($pendencias as $pendencia)
                <p class="mt-2 flex flex-wrap items-center gap-2 text-sm dark:text-white">
                    <span class="rounded bg-yellow-100 px-1.5 py-0.5 text-xs text-yellow-800">Pendência</span>
                    {{ $cargos[$pendencia->cargo] ?? $pendencia->cargo }}: {{ $pendencia->acao }}{{ $pendencia->gatilho ? ' · '.$pendencia->gatilho : '' }}{{ $pendencia->prazo ? ' · prazo '.\Carbon\Carbon::parse($pendencia->prazo)->format('d/m/Y') : '' }}
                    @if ($podeEditar)
                        <form method="POST" action="{{ route('admin.ficha.pendencia.resolver', $pendencia->id) }}" class="inline">@csrf<button type="submit" class="text-blue-600 hover:underline">Marcar como resolvida</button></form>
                    @endif
                </p>
            @endforeach
        </div>

        {{-- Tabela de margem de peças --}}
        <form method="POST" action="{{ route('admin.ficha.margens') }}" class="{{ $card }}">
            @csrf
            <fieldset @disabled(! $podeEditar) style="{{ $semBorda }}">
            <p class="{{ $titulo }}">Tabela de margem de peças</p>
            <p class="mb-2 text-xs text-gray-500">Por faixa de custo: margem mínima (abaixo dela o preço vai para a fila de alçadas) e margem alvo. A última faixa fica sem limite de custo.</p>

            @php $linhas = $faixas->values()->all(); while (count($linhas) < 4) { $linhas[] = (object) ['custo_ate' => null, 'margem_minima' => null, 'margem_alvo' => null]; } @endphp
            @foreach ($linhas as $i => $faixa)
                <div class="mb-1 flex flex-wrap items-center gap-2 text-sm dark:text-white">
                    Custo até R$ <input type="text" data-money name="faixa[{{ $i }}][custo_ate]" value="{{ $reais($faixa->custo_ate) }}" class="w-28 {{ $campo }}" />
                    margem mínima <input type="text" name="faixa[{{ $i }}][margem_minima]" value="{{ $faixa->margem_minima }}" class="w-16 {{ $campo }}" />%
                    alvo <input type="text" name="faixa[{{ $i }}][margem_alvo]" value="{{ $faixa->margem_alvo }}" class="w-16 {{ $campo }}" />%
                </div>
            @endforeach

            @if ($podeEditar)
                <div class="mt-3"><button type="submit" class="secondary-button">Salvar tabela de margem</button></div>
            @endif
            </fieldset>
        </form>
    </div>
</x-admin::layouts>
