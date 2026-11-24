<x-admin::layouts>
    <x-slot:title>
        Nova Chave de API
    </x-slot>

    <x-admin::form :action="route('admin.settings.api_keys.store')" method="POST">
        <div class="flex flex-col gap-4">
            <div class="flex items-center justify-between rounded-lg border border-gray-200 bg-white px-4 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300">
                <div class="text-xl font-bold dark:text-white">
                    Nova Chave de API
                </div>

                <div class="flex items-center gap-x-2.5">
                    <button type="submit" class="primary-button">
                        Salvar
                    </button>
                </div>
            </div>

            <div class="box-shadow rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
                <div class="grid grid-cols-1 gap-y-4">
                    <x-admin::form.control-group>
                        <x-admin::form.control-group.label class="required">
                            Nome
                        </x-admin::form.control-group.label>

                        <x-admin::form.control-group.control
                            type="text"
                            id="nome"
                            name="nome"
                            rules="required"
                            label="Nome"
                            placeholder="Ex: Focus NFe, WhatsApp Business API"
                        />

                        <x-admin::form.control-group.error control-name="nome" />
                    </x-admin::form.control-group>

                    <x-admin::form.control-group>
                        <x-admin::form.control-group.label>
                            Tipo
                        </x-admin::form.control-group.label>

                        <x-admin::form.control-group.control
                            type="select"
                            id="tipo"
                            name="tipo"
                            label="Tipo"
                        >
                            <option value="">Outro / genérico</option>
                            <option value="focusnfe">Focus NFe (nota fiscal)</option>
                            <option value="asaas">Asaas (boleto/Pix/cartão)</option>
                        </x-admin::form.control-group.control>

                        <p class="text-xs text-gray-500 dark:text-gray-400">
                            Selecione "Focus NFe" ou "Asaas" se essa for a chave usada pra emitir nota fiscal ou gerar cobrança -- é assim que o sistema encontra a chave certa automaticamente.
                        </p>

                        <x-admin::form.control-group.error control-name="tipo" />
                    </x-admin::form.control-group>

                    <x-admin::form.control-group>
                        <x-admin::form.control-group.label class="required">
                            Valor (chave/token)
                        </x-admin::form.control-group.label>

                        <x-admin::form.control-group.control
                            type="password"
                            id="valor"
                            name="valor"
                            rules="required"
                            label="Valor"
                        />

                        <x-admin::form.control-group.error control-name="valor" />
                    </x-admin::form.control-group>

                    <x-admin::form.control-group>
                        <x-admin::form.control-group.label>
                            Observações
                        </x-admin::form.control-group.label>

                        <x-admin::form.control-group.control
                            type="textarea"
                            id="observacoes"
                            name="observacoes"
                            label="Observações"
                            placeholder="Ex: usada na integração de nota fiscal"
                        />

                        <x-admin::form.control-group.error control-name="observacoes" />
                    </x-admin::form.control-group>
                </div>
            </div>
        </div>
    </x-admin::form>
</x-admin::layouts>
