@php
    $input = 'rounded-md border border-gray-300 px-2 py-1.5 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white';
    $label = 'text-xs text-gray-500 dark:text-gray-400';
    $card = 'box-shadow rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900';
    $h = 'mb-1 text-base font-semibold text-gray-800 dark:text-white';
    $hint = 'mb-3 text-xs text-gray-500 dark:text-gray-400';
    $horario = (array) ($settings['horario_comercial'] ?? []);
    $temChaves = ! empty($settings['push_vapid_public']);
@endphp

<x-admin::layouts>
    <x-slot:title>
        Indicadores e alertas
    </x-slot>

    <div class="flex flex-col gap-4">
        <div class="flex items-center justify-between rounded-lg border border-gray-200 bg-white px-4 py-3 dark:border-gray-800 dark:bg-gray-900">
            <div class="text-xl font-bold dark:text-white">Indicadores e alertas (piloto)</div>
            <a href="{{ route('admin.workshop.settings.sms_report') }}" class="secondary-button">Relatório de SMS</a>
        </div>

        {{-- Geral --}}
        <form method="POST" action="{{ route('admin.workshop.settings.general') }}" class="{{ $card }} flex flex-col gap-5">
            @csrf
            @method('PUT')

            <div>
                <p class="{{ $h }}">Chave do piloto</p>
                <p class="{{ $hint }}">Desligada, a oficina usa o fluxo de sempre: sem bloqueios de etapa, sem barreiras e sem alertas. Ligada, valem todas as regras abaixo.</p>
                <label class="flex items-center gap-2 text-sm font-semibold dark:text-white">
                    <input type="checkbox" name="piloto_ativo" value="1" @checked($settings['piloto_ativo']) />
                    Piloto de indicadores e alertas ligado nesta oficina
                </label>

                @if (! $settings['piloto_ativo'] && ! empty($pilotoFaltando))
                    <div class="mt-2 rounded-md border border-yellow-300 bg-yellow-50 p-3 text-sm text-yellow-900">
                        <p class="font-semibold">Para ligar o piloto, falta:</p>
                        <ul class="ml-5 mt-1 list-disc">
                            @foreach ($pilotoFaltando as $falta)
                                <li>{{ $falta['texto'] }} &mdash; <a href="{{ $falta['link'] }}" class="font-semibold text-blue-600 hover:underline">preencher</a></li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </div>

            <div>
                <p class="{{ $h }}">Modo de teste das barreiras</p>
                <p class="{{ $hint }}">Para o roteiro de teste não levar dias: divide por 10 todos os prazos das barreiras e dos cronômetros (2 h vira 12 min; 24 h vira 2 h 24 min). Só vale com o piloto ligado e só nesta oficina &mdash; marque apenas na oficina de teste. O horário comercial e o silêncio continuam valendo: para testar fora do expediente, deixe todos os dias do horário comercial desmarcados. Vale para as barreiras abertas daqui para frente.</p>
                <div class="flex flex-col gap-1">
                    <label class="flex items-center gap-2 text-sm font-semibold dark:text-white"><input type="checkbox" name="modo_teste_barreiras" value="1" @checked($settings['modo_teste_barreiras'] ?? false) /> Modo de teste ligado (prazos ÷ 10)</label>
                    <label class="flex items-center gap-2 text-sm dark:text-white"><input type="checkbox" name="modo_teste_tempo_padrao" value="1" @checked($settings['modo_teste_tempo_padrao'] ?? false) /> Dividir também o tempo padrão dos serviços (1 h vira 6 min)</label>
                </div>
            </div>

            <div>
                <p class="{{ $h }}">Horário comercial da oficina</p>
                <p class="{{ $hint }}">Os prazos das barreiras contam só dentro deste horário (alertas críticos não esperam).</p>
                <div class="grid grid-cols-1 gap-2 md:grid-cols-2">
                    @foreach ($dias as $dia => $nome)
                        @php $linha = $horario[(string) $dia] ?? null; @endphp
                        <div class="flex items-center gap-2 text-sm dark:text-white">
                            <label class="flex w-28 items-center gap-2"><input type="checkbox" name="horario[{{ $dia }}][aberto]" value="1" @checked($linha) /> {{ $nome }}</label>
                            <input type="time" name="horario[{{ $dia }}][inicio]" value="{{ $linha[0] ?? '08:00' }}" class="{{ $input }}" />
                            <span>às</span>
                            <input type="time" name="horario[{{ $dia }}][fim]" value="{{ $linha[1] ?? '18:00' }}" class="{{ $input }}" />
                        </div>
                    @endforeach
                </div>
            </div>

            <div>
                <p class="{{ $h }}">Notificações</p>
                <div class="flex flex-wrap items-end gap-4">
                    <label class="flex items-center gap-2 text-sm dark:text-white"><input type="checkbox" name="silencio_ativo" value="1" @checked($settings['silencio_ativo']) /> Horário de silêncio</label>
                    <label class="flex items-center gap-2 text-sm dark:text-white"><input type="checkbox" name="dono_resumo_barreiras" value="1" @checked($settings['dono_resumo_barreiras'] ?? false) /> O dono recebe um resumo das barreiras vencidas a cada 2 horas</label>
                    <div class="flex flex-col gap-1"><span class="{{ $label }}">De</span><input type="time" name="silencio_inicio" value="{{ $settings['silencio_inicio'] }}" class="{{ $input }}" /></div>
                    <div class="flex flex-col gap-1"><span class="{{ $label }}">Até</span><input type="time" name="silencio_fim" value="{{ $settings['silencio_fim'] }}" class="{{ $input }}" /></div>
                    <div class="flex flex-col gap-1"><span class="{{ $label }}">Agrupar alertas em (min)</span><input type="number" min="1" max="60" name="agrupar_alertas_minutos" value="{{ $settings['agrupar_alertas_minutos'] }}" class="w-24 {{ $input }}" /></div>
                </div>
                <p class="{{ $hint }} mt-1">No silêncio só os alertas críticos tocam. Os demais chegam quando o silêncio acaba.</p>

                <div class="flex flex-wrap items-end gap-4">
                    <div class="flex flex-col gap-1"><span class="{{ $label }}">E-mail de contato das notificações (VAPID)</span><input type="text" name="push_vapid_subject" value="{{ $settings['push_vapid_subject'] }}" placeholder="mailto:oficina@exemplo.com.br" class="w-72 {{ $input }}" /></div>
                    <span class="rounded-full px-2 py-1 text-xs font-semibold {{ $temChaves ? 'bg-green-100 text-green-700' : 'bg-yellow-100 text-yellow-800' }}">{{ $temChaves ? 'Chaves de notificação geradas' : 'Chaves de notificação ainda não geradas' }}</span>
                </div>
            </div>

            <div>
                <p class="{{ $h }}">SMS de reserva</p>
                <p class="{{ $hint }}">Só para alertas críticos, de nível 3 e de nível 2 não aberto no celular a tempo. Sem provedor contratado, use "Simulado" (não envia nada, só registra).</p>
                <div class="flex flex-wrap items-end gap-4">
                    <div class="flex flex-col gap-1"><span class="{{ $label }}">Provedor</span>
                        <select name="sms_provedor" class="{{ $input }}">
                            <option value="log" @selected($settings['sms_provedor'] === 'log')>Simulado (não envia)</option>
                            <option value="twilio" @selected($settings['sms_provedor'] === 'twilio')>Twilio</option>
                        </select>
                    </div>
                    <div class="flex flex-col gap-1"><span class="{{ $label }}">Limite mensal (SMS)</span><input type="number" min="0" name="sms_limite_mensal" value="{{ $settings['sms_limite_mensal'] }}" class="w-28 {{ $input }}" /></div>
                    <div class="flex flex-col gap-1"><span class="{{ $label }}">Custo por SMS (R$)</span><input type="number" step="0.0001" min="0" name="sms_custo_unitario" value="{{ $settings['sms_custo_unitario'] }}" class="w-28 {{ $input }}" /></div>
                    <div class="flex flex-col gap-1"><span class="{{ $label }}">Nível 2 sem abrir em (min)</span><input type="number" min="1" name="sms_nao_aberto_minutos" value="{{ $settings['sms_nao_aberto_minutos'] }}" class="w-28 {{ $input }}" /></div>
                    <span class="text-sm dark:text-white">Enviados no mês: <b>{{ $smsMes }}</b> / {{ $settings['sms_limite_mensal'] }}</span>
                </div>
                <div class="mt-2 flex flex-wrap items-end gap-4">
                    <div class="flex flex-col gap-1"><span class="{{ $label }}">Twilio: Account SID</span><input type="text" name="sms_twilio_sid" value="{{ $settings['sms_twilio_sid'] }}" class="w-64 {{ $input }}" autocomplete="off" /></div>
                    <div class="flex flex-col gap-1"><span class="{{ $label }}">Twilio: Auth token {{ $settings['sms_twilio_token'] ? '(já salvo; deixe em branco para manter)' : '' }}</span><input type="password" name="sms_twilio_token" value="" class="w-64 {{ $input }}" autocomplete="new-password" /></div>
                    <div class="flex flex-col gap-1"><span class="{{ $label }}">Twilio: número de origem</span><input type="text" name="sms_twilio_from" value="{{ $settings['sms_twilio_from'] }}" class="w-44 {{ $input }}" placeholder="+1..." /></div>
                </div>
            </div>

            <div>
                <p class="{{ $h }}">Cronômetro do técnico</p>
                <div class="flex flex-wrap items-end gap-4">
                    <div class="flex flex-col gap-1"><span class="{{ $label }}">Margem do tempo padrão (%)</span><input type="number" min="100" max="300" name="tecnico_margem_percentual" value="{{ $settings['tecnico_margem_percentual'] }}" class="w-28 {{ $input }}" /></div>
                    <div class="flex flex-col gap-1"><span class="{{ $label }}">Meta de eficiência (%)</span><input type="number" min="1" max="200" name="tecnico_meta_eficiencia" value="{{ $settings['tecnico_meta_eficiencia'] }}" class="w-28 {{ $input }}" /></div>
                </div>
                <p class="{{ $hint }} mt-1">Cronômetro verde até 100% do padrão, amarelo até a margem, vermelho acima. Os percentuais que disparam alerta ao chefe e ao gerente ficam na barreira "Operação acima do tempo", abaixo.</p>
            </div>

            <div><button type="submit" class="primary-button">Salvar configurações</button></div>
        </form>

        {{-- Barreiras --}}
        <div class="{{ $card }}">
            <div class="flex items-center justify-between">
                <p class="{{ $h }}">Barreiras de prazo</p>
                <form method="POST" action="{{ route('admin.workshop.settings.restore_rules') }}">@csrf<button type="submit" class="text-xs text-blue-600 hover:underline">Restaurar as padrão da mentoria que faltam</button></form>
            </div>
            <p class="{{ $hint }}">Cada nível dispara uma vez. Os tempos de nível 2 e 3 contam a partir do vencimento. O proprietário só recebe o nível 3 e os alertas críticos, mesmo que seja marcado num nível menor.</p>

            <div class="flex flex-col gap-4">
                @foreach ($rules as $rule)
                    <form method="POST" action="{{ route('admin.workshop.settings.rule', $rule->id) }}" class="rounded-md border border-gray-200 p-3 dark:border-gray-800">
                        @csrf
                        @method('PUT')

                        <div class="mb-2 flex items-center justify-between">
                            <p class="text-sm font-semibold dark:text-white">
                                {{ $rule->nome }}
                                @if ($rule->critico) <span class="rounded bg-red-100 px-1.5 py-0.5 text-xs text-red-700">crítico</span> @endif
                                <span class="text-xs font-normal text-gray-500">{{ $rule->evento_inicio }}{{ $rule->evento_fim ? ' → '.$rule->evento_fim : '' }}</span>
                            </p>
                            <label class="flex items-center gap-2 text-sm dark:text-white"><input type="checkbox" name="ativo" value="1" @checked($rule->ativo) /> Ativa</label>
                        </div>

                        <div class="flex flex-wrap items-end gap-3">
                            @if ($rule->modo === 'minutos')
                                <div class="flex flex-col gap-1"><span class="{{ $label }}">Prazo (min)</span><input type="number" min="0" name="prazo_minutos" value="{{ $rule->prazo_minutos }}" class="w-24 {{ $input }}" /></div>
                            @elseif ($rule->modo === 'operacao')
                                <div class="flex flex-col gap-1"><span class="{{ $label }}">Nível 1 acima de (% do padrão)</span><input type="number" min="1" name="nivel1_percentual" value="{{ $rule->nivel1_percentual }}" class="w-24 {{ $input }}" /></div>
                                <div class="flex flex-col gap-1"><span class="{{ $label }}">Nível 2 acima de (% do padrão)</span><input type="number" min="1" name="nivel2_percentual" value="{{ $rule->nivel2_percentual }}" class="w-24 {{ $input }}" /></div>
                            @else
                                <span class="text-xs text-gray-500">Dispara na hora, sem prazo.</span>
                            @endif
                        </div>

                        <div class="mt-3 grid grid-cols-1 gap-3 md:grid-cols-3">
                            @foreach ([1, 2, 3] as $nivel)
                                @php $marcados = $rule->destinos($nivel); @endphp
                                <div class="rounded border border-gray-100 p-2 dark:border-gray-800">
                                    <p class="mb-1 text-xs font-semibold dark:text-white">
                                        Nível {{ $nivel }}
                                        @if ($nivel > 1 && $rule->modo === 'minutos')
                                            <span class="font-normal text-gray-500">· após</span>
                                            <input type="number" min="0" name="nivel{{ $nivel }}_apos_minutos" value="{{ $rule->{'nivel'.$nivel.'_apos_minutos'} }}" class="w-16 {{ $input }}" /> <span class="font-normal text-gray-500">min</span>
                                        @endif
                                    </p>
                                    <div class="flex flex-col gap-0.5">
                                        @foreach ($destinos as $chave => $nome)
                                            <label class="flex items-center gap-2 text-xs dark:text-white"><input type="checkbox" name="destinos{{ $nivel }}[]" value="{{ $chave }}" @checked(in_array($chave, $marcados, true)) /> {{ $nome }}</label>
                                        @endforeach
                                    </div>
                                </div>
                            @endforeach
                        </div>

                        <div class="mt-3"><button type="submit" class="secondary-button">Salvar barreira</button></div>
                    </form>
                @endforeach
            </div>
        </div>

        {{-- Papéis e celulares --}}
        <form method="POST" action="{{ route('admin.workshop.settings.roles') }}" class="{{ $card }}">
            @csrf
            @method('PUT')

            <p class="{{ $h }}">Papéis e celulares</p>
            <p class="{{ $hint }}">Diga qual papel da oficina cada função representa: é assim que os alertas encontram o chefe, o gerente, o proprietário. Para o SMS de reserva, cadastre o celular de cada pessoa e marque o aceite.</p>

            <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
                <div>
                    <p class="mb-2 text-sm font-semibold dark:text-white">Funções</p>
                    @foreach ($roles as $role)
                        <div class="mb-2 border-b border-gray-100 pb-2 text-sm dark:border-gray-800 dark:text-white">
                            <p class="mb-1 font-semibold">{{ $role->name }}</p>
                            {{-- Função que acumula cargos: marque todos os papéis dela. --}}
                            <input type="hidden" name="papel[{{ $role->id }}][]" value="" />
                            <div class="flex flex-wrap gap-x-4 gap-y-1">
                                @foreach ($papeis as $chave => $nome)
                                    <label class="flex items-center gap-1 text-xs">
                                        <input type="checkbox" name="papel[{{ $role->id }}][]" value="{{ $chave }}" @checked(in_array($chave, explode(',', (string) $role->perfil_oficina), true)) /> {{ $nome }}
                                    </label>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>

                <div>
                    <p class="mb-2 text-sm font-semibold dark:text-white">Colaboradores</p>
                    @foreach ($users as $user)
                        <div class="mb-1 flex flex-wrap items-center gap-2 text-sm dark:text-white">
                            <span class="w-40 truncate">{{ $user->name }}</span>
                            <input type="text" name="celular[{{ $user->id }}]" value="{{ $user->celular }}" placeholder="(11) 99999-9999" class="w-40 {{ $input }}" />
                            <label class="flex items-center gap-1 text-xs"><input type="checkbox" name="sms_aceite[{{ $user->id }}]" value="1" @checked($user->sms_aceite_em) /> aceita SMS</label>
                            @if ($user->sms_aceite_em)<span class="text-xs text-gray-500">desde {{ \Illuminate\Support\Carbon::parse($user->sms_aceite_em)->format('d/m/Y') }}</span>@endif
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="mt-3"><button type="submit" class="primary-button">Salvar papéis e celulares</button></div>
        </form>

        {{-- Tipos de queixa --}}
        <form method="POST" action="{{ route('admin.workshop.settings.complaints') }}" class="{{ $card }}">
            @csrf
            @method('PUT')

            <p class="{{ $h }}">Tempo alvo do diagnóstico por tipo de queixa</p>
            <p class="{{ $hint }}">Se o diagnóstico passar do tempo alvo, o técnico informa a causa do atraso. Nos primeiros 30 dias, ajuste pelos tempos reais da oficina.</p>

            @foreach ($queixas as $queixa)
                <div class="mb-1 flex items-center gap-2 text-sm dark:text-white">
                    <input type="text" name="queixa[{{ $queixa->id }}][nome]" value="{{ $queixa->nome }}" class="w-72 {{ $input }}" />
                    <input type="number" min="1" name="queixa[{{ $queixa->id }}][tempo_alvo_min]" value="{{ $queixa->tempo_alvo_min }}" class="w-24 {{ $input }}" /> min
                    <label class="flex items-center gap-1 text-xs"><input type="checkbox" name="queixa[{{ $queixa->id }}][ativo]" value="1" @checked($queixa->ativo) /> ativo</label>
                </div>
            @endforeach

            <div class="mt-2 flex items-center gap-2 text-sm dark:text-white">
                <input type="text" name="nova_nome" placeholder="Novo tipo de queixa" class="w-72 {{ $input }}" />
                <input type="number" min="1" name="nova_tempo" placeholder="min" class="w-24 {{ $input }}" /> min
            </div>

            <div class="mt-3"><button type="submit" class="secondary-button">Salvar tipos de queixa</button></div>
        </form>

        {{-- Chaves de notificação --}}
        <div class="{{ $card }}">
            <p class="{{ $h }}">Chaves das notificações no celular</p>
            <p class="{{ $hint }}">Geradas uma vez por oficina. Gerar de novo desfaz as inscrições: cada pessoa precisa ativar as notificações outra vez.</p>

            <form method="POST" action="{{ route('admin.workshop.settings.vapid') }}" onsubmit="return confirm('{{ $temChaves ? 'As inscrições atuais serão desfeitas. Gerar novas chaves?' : 'Gerar as chaves de notificação?' }}')">
                @csrf
                <button type="submit" class="secondary-button">{{ $temChaves ? 'Gerar novas chaves' : 'Gerar chaves' }}</button>
            </form>
        </div>
    </div>
</x-admin::layouts>
