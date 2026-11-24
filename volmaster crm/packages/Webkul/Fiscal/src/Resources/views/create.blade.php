<x-admin::layouts>
    <x-slot:title>
        Emitir Nota Fiscal &middot; OS #{{ $serviceOrder->id }}
    </x-slot>

    <x-admin::form :action="route('admin.fiscal_documents.emit-nfe', $serviceOrder->id)" method="POST">
        <div class="flex flex-col gap-4">
            <div class="flex items-center justify-between rounded-lg border border-gray-200 bg-white px-4 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300">
                <div class="flex flex-col gap-2">
                    <x-admin::breadcrumbs name="service_orders.view" :entity="$serviceOrder" />

                    <div class="text-xl font-bold dark:text-white">
                        Emitir Nota Fiscal &middot; OS #{{ $serviceOrder->id }}
                    </div>
                </div>

                <div class="flex items-center gap-x-2.5">
                    <a href="{{ route('admin.service_orders.view', $serviceOrder->id) }}" class="secondary-button">
                        Cancelar
                    </a>

                    <button type="submit" class="primary-button">
                        Confirmar e Emitir
                    </button>
                </div>
            </div>

            <div class="box-shadow rounded-lg border border-yellow-300 bg-yellow-50 p-4 text-sm text-yellow-800 dark:border-yellow-800 dark:bg-yellow-950 dark:text-yellow-200">
                Confira os valores abaixo antes de confirmar -- depois de emitida, a nota fiscal só pode ser cancelada dentro do prazo permitido pela prefeitura, não simplesmente editada.
            </div>

            <div class="box-shadow rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
                <div class="grid grid-cols-2 gap-4 max-sm:grid-cols-1">
                    <div>
                        <p class="text-sm text-gray-500 dark:text-gray-400">Cliente</p>
                        <p class="text-base font-medium dark:text-white">{{ $serviceOrder->person->name ?? '-' }}</p>
                    </div>

                    <div>
                        <p class="text-sm text-gray-500 dark:text-gray-400">CPF/CNPJ</p>
                        <p class="text-base font-medium dark:text-white">{{ $serviceOrder->person->cpf_cnpj ?? '-' }}</p>
                    </div>

                    <div>
                        <p class="text-sm text-gray-500 dark:text-gray-400">Emitente</p>
                        <p class="text-base font-medium dark:text-white">{{ $fiscalSettings->razao_social }} ({{ $fiscalSettings->cnpj }})</p>
                    </div>

                    <div>
                        <p class="text-sm text-gray-500 dark:text-gray-400">Código de Serviço</p>
                        <p class="text-base font-medium dark:text-white">{{ $fiscalSettings->item_lista_servico }}</p>
                    </div>
                </div>
            </div>

            <div class="box-shadow rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
                <div class="grid grid-cols-1 gap-y-4">
                    <x-admin::form.control-group>
                        <x-admin::form.control-group.label class="required">
                            Valor do Serviço
                        </x-admin::form.control-group.label>

                        <x-admin::form.control-group.control
                            type="text"
                            id="valor_servicos"
                            name="valor_servicos"
                            data-money
                            rules="required"
                            label="Valor do Serviço"
                            :value="$valorSugerido"
                        />

                        <p class="text-xs text-gray-500 dark:text-gray-400">
                            Pré-preenchido com o total dos itens de "Serviço" da OS (ou o total geral, se não houver itens marcados como Serviço). Ajuste se precisar.
                        </p>

                        <x-admin::form.control-group.error control-name="valor_servicos" />
                    </x-admin::form.control-group>

                    <x-admin::form.control-group>
                        <x-admin::form.control-group.label class="required">
                            Discriminação do Serviço
                        </x-admin::form.control-group.label>

                        <x-admin::form.control-group.control
                            type="textarea"
                            id="discriminacao"
                            name="discriminacao"
                            rules="required"
                            label="Discriminação do Serviço"
                            :value="$discriminacaoSugerida"
                        />

                        <x-admin::form.control-group.error control-name="discriminacao" />
                    </x-admin::form.control-group>
                </div>
            </div>
        </div>
    </x-admin::form>
</x-admin::layouts>
