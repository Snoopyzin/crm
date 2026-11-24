@php
    $locked = $approved;

    $identityFields = [
        ['razao_social', 'Razão Social', true, null, null],
        ['nome_fantasia', 'Nome Fantasia', false, null, null],
        ['cnpj', 'CNPJ', true, '00.000.000/0000-00', null],
        ['inscricao_municipal', 'Inscrição Municipal', true, null, 'Cadastro da empresa na prefeitura -- seu contador informa.'],
        ['inscricao_estadual', 'Inscrição Estadual (IE)', false, null, 'Só precisa pra Nota Fiscal de Produto (venda de peças). Deixe em branco se não tiver.'],
        ['uf', 'UF', true, 'Ex: GO', null],
    ];

    $addressFields = [
        ['cep', 'CEP', true, '00000-000'],
        ['logradouro', 'Rua / Avenida', true, null],
        ['numero', 'Número', true, 'Ex: 880 ou SN'],
        ['complemento', 'Complemento', false, null],
        ['bairro', 'Bairro', true, null],
        ['municipio', 'Cidade', true, 'Ex: Goiânia'],
        ['email', 'E-mail da empresa', true, null],
        ['telefone', 'Telefone', false, null],
    ];

    // Inline colors (translucent, readable in light and dark) -- the admin
    // CSS is prebuilt and doesn't include every Tailwind color variant.
    $green = 'background:rgba(34,197,94,.12);border-color:rgba(34,197,94,.5);color:#16a34a';
    $yellow = 'background:rgba(234,179,8,.12);border-color:rgba(234,179,8,.5);color:#ca8a04';
    $red = 'background:rgba(239,68,68,.12);border-color:rgba(239,68,68,.5);color:#dc2626';
    $blue = 'background:rgba(59,130,246,.12);border-color:rgba(59,130,246,.5);color:#2563eb';

    $statusBanner = match (true) {
        $approved => [$green, 'Emissão de nota fiscal ativa. Os dados da empresa e o certificado ficam travados -- pra alterar, fale com a Volmaster.'],
        $onboarding?->status === 'pending' => [$yellow, 'Dados enviados em '.$onboarding->updated_at->format('d/m/Y H:i').'. Aguardando a Volmaster ativar a emissão -- você ainda pode corrigir e reenviar.'],
        in_array($onboarding?->status, ['rejected', 'failed']) => [$red, 'Não foi possível ativar a emissão: '.($onboarding->error ?: 'pedido recusado').'. Corrija os dados e envie de novo.'],
        default => [$blue, 'Preencha os dados da sua empresa e envie o certificado digital A1. A Volmaster revisa e ativa a emissão de notas fiscais pra você.'],
    };
@endphp

<x-admin::layouts>
    <x-slot:title>
        Nota Fiscal
    </x-slot>

    <x-admin::form
        :action="route('admin.settings.fiscal_settings.update')"
        method="PUT"
        enctype="multipart/form-data"
    >
        <div class="flex flex-col gap-4">
            <div class="flex items-center justify-between rounded-lg border border-gray-200 bg-white px-4 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300">
                <div class="text-xl font-bold dark:text-white">
                    Nota Fiscal
                </div>

                <div class="flex items-center gap-x-2.5">
                    <button type="submit" class="primary-button">
                        {{ $approved ? 'Salvar' : 'Enviar para ativação' }}
                    </button>
                </div>
            </div>

            <div class="rounded-lg border px-4 py-3 text-sm" style="{{ $statusBanner[0] }}">
                {{ $statusBanner[1] }}
            </div>

            {{-- Empresa --}}
            <div class="box-shadow rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
                <p class="mb-4 text-base font-semibold text-gray-800 dark:text-white">Dados da empresa</p>

                <div class="grid grid-cols-2 gap-4 max-sm:grid-cols-1">
                    @foreach ($identityFields as [$name, $label, $required, $placeholder, $hint])
                        @if ($locked)
                            <div>
                                <p class="text-xs text-gray-500 dark:text-gray-400">{{ $label }}</p>
                                <p class="text-sm font-medium text-gray-800 dark:text-white">{{ $fiscalSettings->{$name} ?: '-' }}</p>
                            </div>
                        @else
                            <x-admin::form.control-group>
                                <x-admin::form.control-group.label :class="$required ? 'required' : ''">
                                    {{ $label }}
                                </x-admin::form.control-group.label>

                                <x-admin::form.control-group.control
                                    type="text"
                                    :id="$name"
                                    :name="$name"
                                    :label="$label"
                                    :placeholder="$placeholder"
                                    :value="old($name, $fiscalSettings->{$name})"
                                />

                                @if ($hint)
                                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ $hint }}</p>
                                @endif

                                <x-admin::form.control-group.error :control-name="$name" />
                            </x-admin::form.control-group>
                        @endif
                    @endforeach

                    @if ($locked)
                        <div>
                            <p class="text-xs text-gray-500 dark:text-gray-400">Regime Tributário</p>
                            <p class="text-sm font-medium text-gray-800 dark:text-white">
                                {{ ['1' => 'Simples Nacional', '2' => 'Simples Nacional -- excesso de sublimite', '3' => 'Regime Normal (Lucro Presumido / Real)'][$fiscalSettings->regime_tributario] ?? '-' }}
                            </p>
                        </div>
                    @else
                        <x-admin::form.control-group>
                            <x-admin::form.control-group.label class="required">
                                Regime Tributário
                            </x-admin::form.control-group.label>

                            <x-admin::form.control-group.control
                                type="select"
                                id="regime_tributario"
                                name="regime_tributario"
                                label="Regime Tributário"
                                :value="old('regime_tributario', $fiscalSettings->regime_tributario)"
                            >
                                <option value="">-</option>
                                <option value="1">Simples Nacional</option>
                                <option value="2">Simples Nacional -- excesso de sublimite</option>
                                <option value="3">Regime Normal (Lucro Presumido / Real)</option>
                            </x-admin::form.control-group.control>

                            <x-admin::form.control-group.error control-name="regime_tributario" />
                        </x-admin::form.control-group>
                    @endif
                </div>
            </div>

            {{-- Endereço --}}
            @unless ($locked)
                <div class="box-shadow rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
                    <p class="mb-4 text-base font-semibold text-gray-800 dark:text-white">Endereço e contato (igual ao cartão CNPJ)</p>

                    <div class="grid grid-cols-2 gap-4 max-sm:grid-cols-1">
                        @foreach ($addressFields as [$name, $label, $required, $placeholder])
                            <x-admin::form.control-group>
                                <x-admin::form.control-group.label :class="$required ? 'required' : ''">
                                    {{ $label }}
                                </x-admin::form.control-group.label>

                                <x-admin::form.control-group.control
                                    type="text"
                                    :id="$name"
                                    :name="$name"
                                    :label="$label"
                                    :placeholder="$placeholder"
                                    :value="old($name, $address[$name] ?? '')"
                                />

                                <x-admin::form.control-group.error :control-name="$name" />
                            </x-admin::form.control-group>
                        @endforeach
                    </div>
                </div>

                {{-- Certificado --}}
                <div class="box-shadow rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
                    <p class="mb-1 text-base font-semibold text-gray-800 dark:text-white">Certificado digital A1</p>

                    <p class="mb-4 text-sm text-gray-500 dark:text-gray-400">
                        Arquivo .pfx ou .p12 do certificado da empresa (o contador costuma ter).
                        @if ($onboarding?->certificado)
                            Já recebemos um certificado -- só envie outro se quiser trocar.
                        @endif
                    </p>

                    <div class="grid grid-cols-2 gap-4 max-sm:grid-cols-1">
                        <div>
                            <label for="certificado" class="mb-1.5 block text-xs font-medium text-gray-800 dark:text-white {{ $onboarding?->certificado ? '' : 'required' }}">Arquivo do certificado</label>

                            <input
                                type="file"
                                id="certificado"
                                name="certificado"
                                accept=".pfx,.p12"
                                class="w-full rounded border border-gray-200 px-2.5 py-2 text-sm text-gray-600 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300"
                            >

                            @error('certificado')
                                <p class="mt-1 text-xs italic text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label for="senha_certificado" class="mb-1.5 block text-xs font-medium text-gray-800 dark:text-white">Senha do certificado</label>

                            <input
                                type="password"
                                id="senha_certificado"
                                name="senha_certificado"
                                autocomplete="new-password"
                                class="w-full rounded border border-gray-200 px-2.5 py-2 text-sm text-gray-600 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300"
                            >

                            @error('senha_certificado')
                                <p class="mt-1 text-xs italic text-red-600">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>
                </div>
            @endunless

            {{-- Impostos (sempre editáveis) --}}
            <div class="box-shadow rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
                <p class="mb-1 text-base font-semibold text-gray-800 dark:text-white">Impostos da nota</p>

                <p class="mb-4 text-sm text-gray-500 dark:text-gray-400">
                    Confirme esses valores com o seu contador.
                </p>

                <div class="grid grid-cols-2 gap-4 max-sm:grid-cols-1">
                    <x-admin::form.control-group>
                        <x-admin::form.control-group.label class="required">
                            Código do Município (IBGE)
                        </x-admin::form.control-group.label>

                        <x-admin::form.control-group.control
                            type="text"
                            id="codigo_municipio"
                            name="codigo_municipio"
                            label="Código do Município"
                            placeholder="Ex: 5208707 (Goiânia)"
                            :value="old('codigo_municipio', $fiscalSettings->codigo_municipio)"
                        />

                        <p class="text-xs text-gray-500 dark:text-gray-400">
                            Código de 7 dígitos do IBGE da sua cidade -- pesquise "código IBGE" + o nome da cidade.
                        </p>

                        <x-admin::form.control-group.error control-name="codigo_municipio" />
                    </x-admin::form.control-group>

                    <x-admin::form.control-group>
                        <x-admin::form.control-group.label class="required">
                            Código de Serviço (item da LC 116/2003)
                        </x-admin::form.control-group.label>

                        <x-admin::form.control-group.control
                            type="text"
                            id="item_lista_servico"
                            name="item_lista_servico"
                            label="Código de Serviço"
                            :value="old('item_lista_servico', $fiscalSettings->item_lista_servico)"
                        />

                        <p class="text-xs text-gray-500 dark:text-gray-400">
                            "14.01" é manutenção/conserto de veículos, o mais comum pra oficina.
                        </p>

                        <x-admin::form.control-group.error control-name="item_lista_servico" />
                    </x-admin::form.control-group>

                    <x-admin::form.control-group>
                        <x-admin::form.control-group.label>
                            Alíquota de ISS (%)
                        </x-admin::form.control-group.label>

                        <x-admin::form.control-group.control
                            type="text"
                            id="aliquota_iss"
                            name="aliquota_iss"
                            label="Alíquota de ISS"
                            placeholder="Ex: 5"
                            :value="old('aliquota_iss', $fiscalSettings->aliquota_iss)"
                        />

                        <x-admin::form.control-group.error control-name="aliquota_iss" />
                    </x-admin::form.control-group>

                    <x-admin::form.control-group>
                        <x-admin::form.control-group.label>
                            CFOP Padrão (venda de peças)
                        </x-admin::form.control-group.label>

                        <x-admin::form.control-group.control
                            type="text"
                            id="cfop_padrao"
                            name="cfop_padrao"
                            label="CFOP Padrão"
                            placeholder="5102"
                            :value="old('cfop_padrao', $fiscalSettings->cfop_padrao)"
                        />

                        <x-admin::form.control-group.error control-name="cfop_padrao" />
                    </x-admin::form.control-group>
                </div>

                <div class="mt-4 flex items-center gap-2.5">
                    <x-admin::form.control-group.control
                        type="switch"
                        id="optante_simples_nacional"
                        name="optante_simples_nacional"
                        :checked="$fiscalSettings->optante_simples_nacional ? 'true' : ''"
                        value="1"
                    />

                    <label for="optante_simples_nacional" class="cursor-pointer text-sm font-medium dark:text-white">
                        Optante pelo Simples Nacional
                    </label>
                </div>
            </div>
        </div>
    </x-admin::form>
</x-admin::layouts>
