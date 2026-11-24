@php
    $inputClass = 'w-full rounded border border-gray-300 px-2.5 py-2 text-sm text-gray-800 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300';

    // Cadastro em 3 passos (pedido do patrão, 19/11/2026): Cliente > Veículo > Entrada do veículo.
    $passosCadastro = [1 => 'Cliente', 2 => 'Veículo', 3 => 'Entrada do veículo'];
    $dicasCadastro = [
        1 => 'Quem é o cliente: nome, CPF/CNPJ e WhatsApp (só o CPF/CNPJ e o WhatsApp são obrigatórios). O endereço é opcional.',
        2 => 'O caminhão: placa, marca e modelo. Pode deixar em branco para serviço só de software.',
        3 => 'A entrada: o que o motorista relatou, km, combustível, itens deixados e fotos e vídeos.',
    ];

    // Se o formulário voltou com erro, abre o primeiro passo que tem erro.
    $camposPasso2 = ['marca', 'modelo', 'placa', 'ano', 'tipo'];
    $camposPasso3 = ['relato_motorista', 'defeito_relatado', 'descricao_entrada', 'km_entrada', 'combustivel_entrada', 'chassi', 'itens_entrada', 'fotos', 'videos'];
    $dosCampos = fn (string $chave, array $campos): bool => collect($campos)->contains(fn ($campo) => $chave === $campo || str_starts_with($chave, $campo.'.'));
    $chavesDeErro = collect($errors->keys());
    $passoInicialCad = match (true) {
        $chavesDeErro->contains(fn ($chave) => ! $dosCampos($chave, array_merge($camposPasso2, $camposPasso3))) => 1,
        $chavesDeErro->contains(fn ($chave) => $dosCampos($chave, $camposPasso2)) => 2,
        $chavesDeErro->contains(fn ($chave) => $dosCampos($chave, $camposPasso3)) => 3,
        default => 1,
    };
    $parteCad = fn (int $passo): string => 'data-passo-cadastro="'.$passo.'"'.($passo === $passoInicialCad ? '' : ' style="display:none"');
@endphp

<x-admin::layouts>
    <x-slot:title>
        @lang('service_order::app.cadastro.title')
    </x-slot>

    <x-admin::form :action="route('admin.service_orders.cadastro.store')" method="POST" enctype="multipart/form-data" id="cadastro-form">
        <div class="flex flex-col gap-4">
            <div class="flex items-center justify-between rounded-lg border border-gray-200 bg-white px-4 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300">
                <div class="text-xl font-bold dark:text-white">
                    @lang('service_order::app.cadastro.title')
                </div>

                <div class="flex items-center gap-x-2.5">
                    <button type="submit" class="primary-button">
                        @lang('service_order::app.cadastro.save-btn')
                    </button>
                </div>
            </div>

            {{-- Passos do cadastro: Cliente > Veículo > Entrada do veículo. --}}
            <div>
                <nav id="passos-cadastro" data-atual="{{ $passoInicialCad }}" aria-label="Passos do cadastro" style="display:flex;flex-wrap:wrap;gap:6px">
                    @foreach ($passosCadastro as $numero => $nomePasso)
                        <button
                            type="button"
                            data-ir-passo="{{ $numero }}"
                            aria-selected="{{ $numero === $passoInicialCad ? 'true' : 'false' }}"
                            style="display:inline-flex;align-items:center;padding:6px 16px;border-radius:9999px;font-size:13px;font-weight:600;cursor:pointer;{{ $numero === $passoInicialCad ? 'background:#2563eb;border:1px solid #2563eb;color:#ffffff' : 'background:rgba(148,163,184,.16);border:1px solid rgba(148,163,184,.55);color:inherit' }}"
                        >{{ $numero }}. {{ $nomePasso }}</button>
                    @endforeach
                </nav>

                <p id="passo-cadastro-dica" style="margin:8px 2px 0;font-size:13px;opacity:.85" data-dicas='@json($dicasCadastro)'>{{ $dicasCadastro[$passoInicialCad] }}</p>
                <p id="passo-cadastro-aviso" role="alert" style="display:none;margin:8px 2px 0;font-size:13px;font-weight:600;color:#b91c1c"></p>
            </div>

            <div {!! $parteCad(1) !!} class="box-shadow rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
                <p class="mb-4 text-base font-semibold text-gray-800 dark:text-white">
                    @lang('service_order::app.cadastro.cliente-section')
                </p>

                {{-- Código sequencial do cliente (1, 2, 3...) e o tamanho da carteira. --}}
                @if ($carteira)
                    <p class="mb-4 rounded border border-blue-200 bg-blue-50 px-3 py-2 text-xs text-blue-900 dark:border-blue-900 dark:bg-blue-950 dark:text-blue-200">
                        Cada cliente recebe um <strong>código</strong> automático, na ordem de cadastro (1, 2, 3...).
                        Este será o cliente <strong>Nº {{ $carteira['ultimo'] + 1 }}</strong>.
                        Carteira hoje: <strong>{{ number_format($carteira['total'], 0, ',', '.') }}</strong>
                        {{ $carteira['total'] === 1 ? 'cliente' : 'clientes' }}.
                    </p>
                @endif

                <div class="grid grid-cols-3 gap-x-4 max-sm:grid-cols-1">
                    <x-admin::form.control-group>
                        <x-admin::form.control-group.label>
                            @lang('service_order::app.cadastro.name')
                        </x-admin::form.control-group.label>

                        <x-admin::form.control-group.control
                            type="text"
                            id="name"
                            name="name"
                            :value="old('name', request('name'))"
                            :label="trans('service_order::app.cadastro.name')"
                        />

                        <x-admin::form.control-group.error control-name="name" />
                    </x-admin::form.control-group>

                    <x-admin::form.control-group>
                        <x-admin::form.control-group.label class="required">
                            @lang('service_order::app.cadastro.cpf_cnpj')
                        </x-admin::form.control-group.label>

                        <x-admin::form.control-group.control
                            type="text"
                            id="cpf_cnpj"
                            name="cpf_cnpj"
                            placeholder="000.000.000-00 ou 00.000.000/0000-00"
                            :value="old('cpf_cnpj', request('cpf_cnpj'))"
                            :label="trans('service_order::app.cadastro.cpf_cnpj')"
                        />

                        <p class="text-xs text-gray-500 dark:text-gray-400">
                            Opcional agora, mas necessário mais pra frente pra emitir nota fiscal desse cliente.
                        </p>

                        <x-admin::form.control-group.error control-name="cpf_cnpj" />
                    </x-admin::form.control-group>

                    <x-admin::form.control-group>
                        <x-admin::form.control-group.label>
                            @lang('service_order::app.cadastro.email')
                        </x-admin::form.control-group.label>

                        <x-admin::form.control-group.control
                            type="email"
                            id="email"
                            name="email"
                            :value="old('email')"
                            :label="trans('service_order::app.cadastro.email')"
                        />

                        <x-admin::form.control-group.error control-name="email" />
                    </x-admin::form.control-group>

                    <div class="mb-2.5">
                        <label class="mb-1.5 block text-xs font-medium text-gray-800 dark:text-white">Por qual meio o cliente chegou à empresa?</label>
                        @include('service_order::components.origem-select', ['value' => old('origem_cliente', $origemPadrao ?? null), 'required' => false])
                        @error('origem_cliente')
                            <p class="mt-1 text-xs italic text-red-600">{{ $message }}</p>
                        @enderror
                    </div>
                </div>
            </div>

            <div {!! $parteCad(1) !!} class="box-shadow rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
                <p class="mb-1 text-base font-semibold text-gray-800 dark:text-white">
                    @lang('service_order::app.cadastro.contatos-section')
                </p>

                <p class="mb-4 text-xs text-gray-500 dark:text-gray-400">
                    Só o WhatsApp é obrigatório. Em cada telefone dá para informar o nome da pessoa responsável por aquele contato.
                </p>

                <div class="grid grid-cols-[minmax(0,1fr)_minmax(0,1fr)] gap-x-4 gap-y-3 max-sm:grid-cols-1">
                    @foreach ([
                        ['whatsapp', 'whatsapp_responsavel', trans('service_order::app.cadastro.whatsapp'), true, '+55 (62) 99999-9999'],
                        ['telefone_fixo', 'telefone_fixo_responsavel', trans('service_order::app.cadastro.telefone_fixo'), false, '+55 (62) 3333-4444'],
                        ['telefone_2', 'telefone_2_responsavel', trans('service_order::app.cadastro.telefone_2'), false, '+55 (62) 99999-9999'],
                    ] as [$campo, $campoResponsavel, $rotulo, $obrigatorio, $exemplo])
                        <div>
                            <label for="{{ $campo }}" class="mb-1.5 block text-xs font-medium text-gray-800 dark:text-white">
                                {{ $rotulo }} @if ($obrigatorio)<span class="text-red-600">*</span>@endif
                            </label>
                            <input
                                type="text"
                                id="{{ $campo }}"
                                name="{{ $campo }}"
                                data-phone
                                placeholder="{{ $exemplo }}"
                                value="{{ old($campo, $campo === 'whatsapp' ? request('whatsapp') : null) }}"
                                class="{{ $inputClass }}"
                            >
                            @error($campo)
                                <p class="mt-1 text-xs italic text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label for="{{ $campoResponsavel }}" class="mb-1.5 block text-xs font-medium text-gray-800 dark:text-white">
                                @lang('service_order::app.cadastro.responsavel_contato')
                            </label>
                            <input
                                type="text"
                                id="{{ $campoResponsavel }}"
                                name="{{ $campoResponsavel }}"
                                maxlength="120"
                                placeholder="Nome de quem atende (opcional)"
                                value="{{ old($campoResponsavel) }}"
                                class="{{ $inputClass }}"
                            >
                            @error($campoResponsavel)
                                <p class="mt-1 text-xs italic text-red-600">{{ $message }}</p>
                            @enderror
                        </div>
                    @endforeach
                </div>
            </div>

            <div {!! $parteCad(1) !!} class="box-shadow rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
                <p class="mb-1 text-base font-semibold text-gray-800 dark:text-white">
                    @lang('service_order::app.cadastro.endereco-section') <span class="text-xs font-normal text-gray-500 dark:text-gray-400">(opcional)</span>
                </p>

                <p class="mb-4 text-xs text-gray-500 dark:text-gray-400">
                    Digite o CEP: rua, bairro, cidade e estado aparecem sozinhos. Depois é só informar o número e o complemento.
                </p>

                <div
                    id="cadastro-endereco"
                    data-cep-url="{{ route('admin.service_orders.cadastro.cep', ['cep' => 'CEP_AQUI']) }}"
                    class="grid grid-cols-3 gap-x-4 gap-y-3 max-sm:grid-cols-1"
                >
                    <div>
                        <label for="cep" class="mb-1.5 block text-xs font-medium text-gray-800 dark:text-white">@lang('service_order::app.cadastro.cep')</label>
                        <input type="text" id="cep" name="cep" inputmode="numeric" maxlength="9" autocomplete="postal-code" placeholder="00000-000" value="{{ old('cep') }}" class="{{ $inputClass }}">
                        <p id="cep-status" class="mt-1 min-h-4 text-xs text-gray-500 dark:text-gray-400"></p>
                        @error('cep')
                            <p class="mt-1 text-xs italic text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="col-span-2 max-sm:col-span-1">
                        <label for="logradouro" class="mb-1.5 block text-xs font-medium text-gray-800 dark:text-white">@lang('service_order::app.cadastro.logradouro')</label>
                        <input type="text" id="logradouro" name="logradouro" maxlength="255" value="{{ old('logradouro') }}" class="{{ $inputClass }}">
                        @error('logradouro')
                            <p class="mt-1 text-xs italic text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="numero" class="mb-1.5 block text-xs font-medium text-gray-800 dark:text-white">@lang('service_order::app.cadastro.numero')</label>
                        <input type="text" id="numero" name="numero" maxlength="20" value="{{ old('numero') }}" class="{{ $inputClass }}">
                        @error('numero')
                            <p class="mt-1 text-xs italic text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="complemento" class="mb-1.5 block text-xs font-medium text-gray-800 dark:text-white">@lang('service_order::app.cadastro.complemento')</label>
                        <input type="text" id="complemento" name="complemento" maxlength="120" placeholder="Sala, galpão, quadra, lote..." value="{{ old('complemento') }}" class="{{ $inputClass }}">
                        @error('complemento')
                            <p class="mt-1 text-xs italic text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="bairro" class="mb-1.5 block text-xs font-medium text-gray-800 dark:text-white">@lang('service_order::app.cadastro.bairro')</label>
                        <input type="text" id="bairro" name="bairro" maxlength="120" value="{{ old('bairro') }}" class="{{ $inputClass }}">
                        @error('bairro')
                            <p class="mt-1 text-xs italic text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="col-span-2 max-sm:col-span-1">
                        <label for="cidade" class="mb-1.5 block text-xs font-medium text-gray-800 dark:text-white">@lang('service_order::app.cadastro.cidade')</label>
                        <input type="text" id="cidade" name="cidade" maxlength="120" value="{{ old('cidade') }}" class="{{ $inputClass }}">
                        @error('cidade')
                            <p class="mt-1 text-xs italic text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="estado" class="mb-1.5 block text-xs font-medium text-gray-800 dark:text-white">@lang('service_order::app.cadastro.estado')</label>
                        <select id="estado" name="estado" class="{{ $inputClass }}">
                            <option value="">Selecione...</option>
                            @foreach (\Webkul\ServiceOrder\Services\ClienteContatos::UFS as $uf)
                                <option value="{{ $uf }}" @selected(old('estado') === $uf)>{{ $uf }}</option>
                            @endforeach
                        </select>
                        @error('estado')
                            <p class="mt-1 text-xs italic text-red-600">{{ $message }}</p>
                        @enderror
                    </div>
                </div>
            </div>

            {{-- Fim do passo 1 --}}
            <div {!! $parteCad(1) !!}>
                <div style="display:flex;justify-content:flex-end">
                    <button type="button" data-ir-passo="2" class="primary-button">Próximo: Veículo &rarr;</button>
                </div>
            </div>

            <div {!! $parteCad(2) !!} class="box-shadow rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
                <p class="mb-4 text-base font-semibold text-gray-800 dark:text-white">
                    @lang('service_order::app.cadastro.veiculo-section') <span class="text-xs font-normal text-gray-500 dark:text-gray-400">(opcional -- deixe em branco para serviço só de software)</span>
                </p>

                <div class="grid grid-cols-3 gap-x-4 max-sm:grid-cols-1">
                    <x-admin::form.control-group>
                        <x-admin::form.control-group.label>
                            @lang('service_order::app.cadastro.marca')
                        </x-admin::form.control-group.label>

                        <x-admin::form.control-group.control
                            type="text"
                            id="marca"
                            name="marca"
                            :value="old('marca')"
                            :label="trans('service_order::app.cadastro.marca')"
                        />

                        <x-admin::form.control-group.error control-name="marca" />
                    </x-admin::form.control-group>

                    <x-admin::form.control-group>
                        <x-admin::form.control-group.label>
                            @lang('service_order::app.cadastro.modelo')
                        </x-admin::form.control-group.label>

                        <x-admin::form.control-group.control
                            type="text"
                            id="modelo"
                            name="modelo"
                            :value="old('modelo')"
                            :label="trans('service_order::app.cadastro.modelo')"
                        />

                        <x-admin::form.control-group.error control-name="modelo" />
                    </x-admin::form.control-group>

                    <x-admin::form.control-group>
                        <x-admin::form.control-group.label>
                            @lang('service_order::app.cadastro.placa')
                        </x-admin::form.control-group.label>

                        <x-admin::form.control-group.control
                            type="text"
                            id="placa"
                            name="placa"
                            :value="old('placa', request('placa'))"
                            :label="trans('service_order::app.cadastro.placa')"
                        />

                        <x-admin::form.control-group.error control-name="placa" />
                    </x-admin::form.control-group>

                    <x-admin::form.control-group>
                        <x-admin::form.control-group.label>
                            @lang('service_order::app.cadastro.ano')
                        </x-admin::form.control-group.label>

                        <x-admin::form.control-group.control
                            type="text"
                            id="ano"
                            name="ano"
                            maxlength="4"
                            :value="old('ano')"
                            :label="trans('service_order::app.cadastro.ano')"
                        />

                        <x-admin::form.control-group.error control-name="ano" />
                    </x-admin::form.control-group>

                    <x-admin::form.control-group>
                        <x-admin::form.control-group.label>
                            @lang('service_order::app.cadastro.tipo')
                        </x-admin::form.control-group.label>

                        <x-admin::form.control-group.control
                            type="select"
                            id="tipo"
                            name="tipo"
                            :value="old('tipo')"
                            :label="trans('service_order::app.cadastro.tipo')"
                        >
                            <option value="carros">Carros</option>
                            <option value="motos">Motos</option>
                            <option value="caminhoes">Caminhões</option>
                        </x-admin::form.control-group.control>

                        <x-admin::form.control-group.error control-name="tipo" />
                    </x-admin::form.control-group>
                </div>
            </div>

            {{-- Fim do passo 2 --}}
            <div {!! $parteCad(2) !!}>
                <div style="display:flex;justify-content:space-between;gap:8px">
                    <button type="button" data-ir-passo="1" class="secondary-button">&larr; Voltar</button>
                    <button type="button" data-ir-passo="3" class="primary-button">Próximo: Entrada do veículo &rarr;</button>
                </div>
            </div>

            <div {!! $parteCad(3) !!} class="box-shadow rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900" id="cadastro-checklist" data-limite="{{ $limiteUpload }}">
                <p class="mb-1 text-base font-semibold text-gray-800 dark:text-white">
                    @lang('service_order::app.cadastro.checklist-section') <span class="text-xs font-normal text-gray-500 dark:text-gray-400">(opcional)</span>
                </p>

                <p class="mb-4 text-xs text-gray-500 dark:text-gray-400">
                    Estado do veículo na chegada à oficina. Km, combustível, itens e fotos já vão para o termo de entrada da OS.
                    O chassi só é gravado quando a placa do veículo é informada.
                    @if ($limiteUpload)
                        O servidor aceita até <strong>{{ number_format($limiteUpload / 1048576, 0, ',', '.') }} MB</strong> de fotos e vídeos por cadastro.
                    @endif
                </p>

                <div class="grid grid-cols-3 gap-x-4 gap-y-3 max-sm:grid-cols-1">
                    <div>
                        <label for="km_entrada" class="mb-1.5 block text-xs font-medium text-gray-800 dark:text-white">@lang('service_order::app.cadastro.km_entrada')</label>
                        <input type="number" min="0" step="1" inputmode="numeric" id="km_entrada" name="km_entrada" value="{{ old('km_entrada') }}" placeholder="Ex.: 482350" class="{{ $inputClass }}">
                        @error('km_entrada')
                            <p class="mt-1 text-xs italic text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="combustivel_entrada" class="mb-1.5 block text-xs font-medium text-gray-800 dark:text-white">@lang('service_order::app.cadastro.combustivel_entrada')</label>
                        <select id="combustivel_entrada" name="combustivel_entrada" class="{{ $inputClass }}">
                            <option value="">Selecione...</option>
                            @foreach (\Webkul\ServiceOrder\Models\ServiceOrder::COMBUSTIVEIS as $nivel)
                                <option value="{{ $nivel }}" @selected(old('combustivel_entrada') === $nivel)>{{ $nivel }}</option>
                            @endforeach
                        </select>
                        @error('combustivel_entrada')
                            <p class="mt-1 text-xs italic text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="chassi" class="mb-1.5 block text-xs font-medium text-gray-800 dark:text-white">@lang('service_order::app.cadastro.chassi')</label>
                        <input type="text" id="chassi" name="chassi" maxlength="30" autocomplete="off" style="text-transform:uppercase" placeholder="Número do chassi" value="{{ old('chassi') }}" class="{{ $inputClass }}">
                        @error('chassi')
                            <p class="mt-1 text-xs italic text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="col-span-3 max-sm:col-span-1">
                        <label for="relato_motorista" class="mb-1.5 block text-xs font-medium text-gray-800 dark:text-white">@lang('service_order::app.cadastro.relato_motorista')</label>
                        <textarea id="relato_motorista" name="relato_motorista" rows="3" maxlength="2000" placeholder="O que o motorista contou: quando começou, em que situação acontece, o que ele sentiu..." class="{{ $inputClass }}">{{ old('relato_motorista') }}</textarea>
                        @error('relato_motorista')
                            <p class="mt-1 text-xs italic text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="col-span-3 max-sm:col-span-1">
                        <label for="defeito_relatado" class="mb-1.5 block text-xs font-medium text-gray-800 dark:text-white">@lang('service_order::app.cadastro.defeito_relatado')</label>
                        <textarea id="defeito_relatado" name="defeito_relatado" rows="2" maxlength="2000" placeholder="O defeito em poucas palavras. Ex.: engasgando em subida, luz da injeção acesa..." class="{{ $inputClass }}">{{ old('defeito_relatado') }}</textarea>
                        @error('defeito_relatado')
                            <p class="mt-1 text-xs italic text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="col-span-3 max-sm:col-span-1">
                        <label for="descricao_entrada" class="mb-1.5 block text-xs font-medium text-gray-800 dark:text-white">@lang('service_order::app.cadastro.descricao_entrada')</label>
                        <textarea id="descricao_entrada" name="descricao_entrada" rows="3" maxlength="2000" placeholder="Cor, estado geral, avarias visíveis, o que o cliente relatou..." class="{{ $inputClass }}">{{ old('descricao_entrada') }}</textarea>
                        @error('descricao_entrada')
                            <p class="mt-1 text-xs italic text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="col-span-3 max-sm:col-span-1">
                        <label for="itens_entrada" class="mb-1.5 block text-xs font-medium text-gray-800 dark:text-white">@lang('service_order::app.cadastro.itens_entrada')</label>
                        <textarea id="itens_entrada" name="itens_entrada" rows="2" maxlength="2000" placeholder="Estepe, macaco, triângulo, extintor, documentos, ferramentas, carga..." class="{{ $inputClass }}">{{ old('itens_entrada') }}</textarea>
                        @error('itens_entrada')
                            <p class="mt-1 text-xs italic text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="col-span-3 max-sm:col-span-1 grid grid-cols-2 gap-x-4 max-sm:grid-cols-1">
                        <div>
                            <label for="fotos" class="mb-1.5 block text-xs font-medium text-gray-800 dark:text-white">@lang('service_order::app.cadastro.fotos')</label>
                            <input type="file" id="fotos" name="fotos[]" accept="image/*" multiple class="w-full text-sm text-gray-700 dark:text-gray-300">
                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Até 20 fotos, 10 MB cada (JPG, PNG, WEBP ou HEIC).</p>
                            <p class="mt-1 text-xs text-gray-600 dark:text-gray-300" data-resumo="fotos"></p>
                            @error('fotos')
                                <p class="mt-1 text-xs italic text-red-600">{{ $message }}</p>
                            @enderror
                            @foreach ($errors->get('fotos.*') as $mensagens)
                                @foreach ($mensagens as $mensagem)
                                    <p class="mt-1 text-xs italic text-red-600">{{ $mensagem }}</p>
                                @endforeach
                            @endforeach
                        </div>

                        <div>
                            <label for="videos" class="mb-1.5 block text-xs font-medium text-gray-800 dark:text-white">@lang('service_order::app.cadastro.videos')</label>
                            <input type="file" id="videos" name="videos[]" accept="video/*" multiple class="w-full text-sm text-gray-700 dark:text-gray-300">
                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Até 3 vídeos, 100 MB cada (MP4, MOV, WEBM, 3GP).</p>
                            <p class="mt-1 text-xs text-gray-600 dark:text-gray-300" data-resumo="videos"></p>
                            @error('videos')
                                <p class="mt-1 text-xs italic text-red-600">{{ $message }}</p>
                            @enderror
                            @foreach ($errors->get('videos.*') as $mensagens)
                                @foreach ($mensagens as $mensagem)
                                    <p class="mt-1 text-xs italic text-red-600">{{ $mensagem }}</p>
                                @endforeach
                            @endforeach
                        </div>
                    </div>
                </div>

                <p id="checklist-aviso" class="mt-3 hidden rounded border px-3 py-2 text-xs" style="background:rgba(220,38,38,.08);border-color:rgba(220,38,38,.4);color:#b91c1c"></p>
            </div>

            {{-- Fim do passo 3 --}}
            <div {!! $parteCad(3) !!}>
                <div style="display:flex;justify-content:space-between;gap:8px">
                    <button type="button" data-ir-passo="2" class="secondary-button">&larr; Voltar</button>
                    <button type="submit" class="primary-button">@lang('service_order::app.cadastro.save-btn')</button>
                </div>
            </div>
        </div>
    </x-admin::form>

    {{-- Passos do cadastro. O painel do sistema (Vue) refaz a página depois de carregar: o clique é tratado no document. --}}
    @push('scripts')
        <script>
            (function () {
                const mostrar = (passo) => {
                    const nav = document.getElementById('passos-cadastro');

                    if (! nav) {
                        return;
                    }

                    nav.dataset.atual = String(passo);

                    document.querySelectorAll('[data-passo-cadastro]').forEach((parte) => {
                        parte.style.display = parte.dataset.passoCadastro === String(passo) ? '' : 'none';
                    });

                    nav.querySelectorAll('[data-ir-passo]').forEach((botao) => {
                        const ativo = botao.dataset.irPasso === String(passo);

                        botao.setAttribute('aria-selected', ativo ? 'true' : 'false');
                        botao.style.background = ativo ? '#2563eb' : 'rgba(148,163,184,.16)';
                        botao.style.borderColor = ativo ? '#2563eb' : 'rgba(148,163,184,.55)';
                        botao.style.color = ativo ? '#ffffff' : 'inherit';
                    });

                    const dica = document.getElementById('passo-cadastro-dica');

                    if (dica) {
                        dica.textContent = JSON.parse(dica.dataset.dicas || '{}')[passo] || '';
                    }

                    const aviso = document.getElementById('passo-cadastro-aviso');

                    if (aviso) {
                        aviso.style.display = 'none';
                    }

                    nav.scrollIntoView({ block: 'start' });
                };

                // O que é obrigatório no passo 1 (CPF/CNPJ e WhatsApp) é conferido antes de seguir.
                const faltaNoPasso1 = () => {
                    const campos = [['cpf_cnpj', 'o CPF ou CNPJ'], ['whatsapp', 'o WhatsApp']];

                    return campos.filter(([id]) => { const campo = document.getElementById(id); return ! campo || campo.value.trim() === ''; });
                };

                document.addEventListener('click', (evento) => {
                    const botao = evento.target && evento.target.closest ? evento.target.closest('[data-ir-passo]') : null;

                    if (! botao) {
                        return;
                    }

                    const destino = parseInt(botao.dataset.irPasso, 10);
                    const nav = document.getElementById('passos-cadastro');
                    const atual = nav ? parseInt(nav.dataset.atual || '1', 10) : 1;

                    if (destino > atual && atual === 1) {
                        const faltam = faltaNoPasso1();

                        if (faltam.length) {
                            const aviso = document.getElementById('passo-cadastro-aviso');

                            aviso.textContent = 'Antes de seguir, informe ' + faltam.map(([, nome]) => nome).join(' e ') + '.';
                            aviso.style.display = 'block';
                            document.getElementById(faltam[0][0]).focus();

                            return;
                        }
                    }

                    mostrar(destino);
                });
            })();
        </script>
    @endpush

    {{-- Checklist: resumo dos arquivos escolhidos e conferência do limite do servidor antes de enviar. --}}
    @pushOnce('scripts')
        <script>
            (function () {
                function mb(bytes) {
                    return (bytes / 1048576).toFixed(1).replace('.', ',') + ' MB';
                }

                function inputs() {
                    return document.querySelectorAll('#cadastro-checklist input[type=file]');
                }

                function atualizar() {
                    var caixa = document.getElementById('cadastro-checklist');
                    var aviso = document.getElementById('checklist-aviso');

                    if (! caixa || ! aviso) {
                        return true;
                    }

                    var limite = parseInt(caixa.dataset.limite, 10) || 0;
                    var total = 0;

                    inputs().forEach(function (input) {
                        var soma = 0;

                        Array.prototype.forEach.call(input.files, function (arquivo) {
                            soma += arquivo.size;
                        });

                        total += soma;

                        var resumo = caixa.querySelector('[data-resumo="' + input.id + '"]');

                        if (resumo) {
                            resumo.textContent = input.files.length
                                ? input.files.length + (input.files.length === 1 ? ' arquivo' : ' arquivos') + ' (' + mb(soma) + ')'
                                : '';
                        }
                    });

                    if (limite && total > limite) {
                        aviso.textContent = 'Os arquivos escolhidos somam ' + mb(total) + ' e o servidor aceita até ' + mb(limite)
                            + ' por cadastro. Tire alguns arquivos para poder salvar.';
                        aviso.classList.remove('hidden');

                        return false;
                    }

                    aviso.classList.add('hidden');

                    return true;
                }

                document.addEventListener('change', function (event) {
                    if (event.target && event.target.type === 'file' && event.target.closest('#cadastro-checklist')) {
                        atualizar();
                    }
                });

                document.addEventListener('submit', function (event) {
                    if (! event.target || event.target.id !== 'cadastro-form') {
                        return;
                    }

                    if (! atualizar()) {
                        event.preventDefault();
                        event.stopImmediatePropagation();

                        document.getElementById('checklist-aviso').scrollIntoView({ behavior: 'smooth', block: 'center' });
                    }
                }, true);
            })();
        </script>
    @endPushOnce

    {{-- CEP: máscara 00000-000 e preenchimento automático de rua, bairro, cidade e estado. --}}
    @pushOnce('scripts')
        <script>
            (function () {
                var timer = null;
                var ultimo = '';

                function campo(id) {
                    return document.getElementById(id);
                }

                function mensagem(texto, erro) {
                    var status = campo('cep-status');

                    if (! status) {
                        return;
                    }

                    status.textContent = texto || '';
                    status.style.color = erro ? '#dc2626' : '';
                }

                function preencher(dados) {
                    ['logradouro', 'bairro', 'cidade'].forEach(function (id) {
                        if (campo(id)) {
                            campo(id).value = dados[id] || '';
                        }
                    });

                    if (campo('estado')) {
                        campo('estado').value = dados.estado || '';
                    }
                }

                function buscar(digitos) {
                    var caixa = campo('cadastro-endereco');

                    if (! caixa || digitos === ultimo) {
                        return;
                    }

                    ultimo = digitos;
                    mensagem('Buscando endereço...', false);

                    fetch(caixa.dataset.cepUrl.replace('CEP_AQUI', digitos), {
                        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                        credentials: 'same-origin',
                    })
                        .then(function (resposta) { return resposta.json(); })
                        .then(function (dados) {
                            // O CEP pode ter mudado enquanto a consulta rodava.
                            if (digitos !== ultimo) {
                                return;
                            }

                            if (! dados.encontrado) {
                                mensagem(dados.mensagem || 'CEP não encontrado.', true);

                                return;
                            }

                            preencher(dados);
                            mensagem('Endereço encontrado. Informe o número e o complemento.', false);

                            var numero = campo('numero');

                            if (numero) {
                                numero.focus();
                            }
                        })
                        .catch(function () {
                            ultimo = '';
                            mensagem('Não foi possível consultar o CEP agora. Preencha o endereço à mão.', true);
                        });
                }

                document.addEventListener('input', function (event) {
                    if (! event.target || event.target.id !== 'cep') {
                        return;
                    }

                    var digitos = event.target.value.replace(/\D/g, '').slice(0, 8);

                    event.target.value = digitos.length > 5 ? digitos.slice(0, 5) + '-' + digitos.slice(5) : digitos;

                    clearTimeout(timer);

                    if (digitos.length === 8) {
                        timer = setTimeout(function () { buscar(digitos); }, 250);
                    } else {
                        ultimo = '';
                        mensagem('', false);
                    }
                });
            })();
        </script>
    @endPushOnce
</x-admin::layouts>
