<x-admin::layouts>
    <x-slot:title>
        @lang('whatsapp::app.settings.title')
    </x-slot>

    <x-admin::form :action="route('admin.settings.whatsapp_settings.update')" method="PUT">
        <div class="flex flex-col gap-4">
            <div class="flex items-center justify-between rounded-lg border border-gray-200 bg-white px-4 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300">
                <div class="text-xl font-bold dark:text-white">
                    @lang('whatsapp::app.settings.title')
                </div>

                <div class="flex items-center gap-x-2.5">
                    <button type="submit" class="primary-button">
                        @lang('whatsapp::app.settings.save-btn')
                    </button>
                </div>
            </div>

            @unless ($whatsAppSettings->isConfigured())
                <div class="rounded-lg border border-yellow-300 bg-yellow-50 p-4 text-sm text-yellow-800 dark:border-yellow-800 dark:bg-yellow-900/20 dark:text-yellow-300">
                    {{ trans('whatsapp::app.settings.not-configured') }}
                </div>
            @endunless

            <div class="box-shadow rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
                <p class="mb-1 text-sm font-semibold text-gray-800 dark:text-white">
                    @lang('whatsapp::app.settings.requirements-title')
                </p>
                <p class="mb-4 text-sm text-gray-500 dark:text-gray-400">
                    {{ trans('whatsapp::app.settings.requirements') }}
                </p>

                <div class="grid grid-cols-2 gap-4 max-sm:grid-cols-1">
                    <x-admin::form.control-group>
                        <x-admin::form.control-group.label>
                            @lang('whatsapp::app.settings.access_token')
                        </x-admin::form.control-group.label>

                        <x-admin::form.control-group.control
                            type="password"
                            id="access_token"
                            name="access_token"
                            label="Token de Acesso"
                        />

                        <p class="text-xs text-gray-500 dark:text-gray-400">
                            {{ trans('whatsapp::app.settings.access_token-hint') }}
                            {{ $whatsAppSettings->access_token ? '(já configurado -- deixe em branco para manter)' : '' }}
                        </p>

                        <x-admin::form.control-group.error control-name="access_token" />
                    </x-admin::form.control-group>

                    <x-admin::form.control-group>
                        <x-admin::form.control-group.label>
                            @lang('whatsapp::app.settings.phone_number_id')
                        </x-admin::form.control-group.label>

                        <x-admin::form.control-group.control
                            type="text"
                            id="phone_number_id"
                            name="phone_number_id"
                            label="ID do Número de Telefone"
                            :value="$whatsAppSettings->phone_number_id"
                        />

                        <x-admin::form.control-group.error control-name="phone_number_id" />
                    </x-admin::form.control-group>

                    <x-admin::form.control-group>
                        <x-admin::form.control-group.label>
                            @lang('whatsapp::app.settings.business_account_id')
                        </x-admin::form.control-group.label>

                        <x-admin::form.control-group.control
                            type="text"
                            id="business_account_id"
                            name="business_account_id"
                            label="ID da Conta Comercial"
                            :value="$whatsAppSettings->business_account_id"
                        />

                        <x-admin::form.control-group.error control-name="business_account_id" />
                    </x-admin::form.control-group>

                    <x-admin::form.control-group>
                        <x-admin::form.control-group.label>
                            @lang('whatsapp::app.settings.webhook_verify_token')
                        </x-admin::form.control-group.label>

                        <x-admin::form.control-group.control
                            type="text"
                            id="webhook_verify_token"
                            name="webhook_verify_token"
                            label="Token de Verificação do Webhook"
                            :value="$whatsAppSettings->webhook_verify_token"
                        />

                        <p class="text-xs text-gray-500 dark:text-gray-400">
                            {{ trans('whatsapp::app.settings.webhook_verify_token-hint') }}
                        </p>

                        <x-admin::form.control-group.error control-name="webhook_verify_token" />
                    </x-admin::form.control-group>

                    <x-admin::form.control-group>
                        <x-admin::form.control-group.label>
                            @lang('whatsapp::app.settings.template_orcamento')
                        </x-admin::form.control-group.label>

                        <x-admin::form.control-group.control
                            type="text"
                            id="template_orcamento"
                            name="template_orcamento"
                            label="Template do Orçamento"
                            :value="$whatsAppSettings->template_orcamento"
                        />

                        <x-admin::form.control-group.error control-name="template_orcamento" />
                    </x-admin::form.control-group>

                    <x-admin::form.control-group>
                        <x-admin::form.control-group.label>
                            @lang('whatsapp::app.settings.template_diagnostico')
                        </x-admin::form.control-group.label>

                        <x-admin::form.control-group.control
                            type="text"
                            id="template_diagnostico"
                            name="template_diagnostico"
                            label="Template do Diagnóstico"
                            :value="$whatsAppSettings->template_diagnostico"
                        />

                        <x-admin::form.control-group.error control-name="template_diagnostico" />
                    </x-admin::form.control-group>

                    <x-admin::form.control-group>
                        <x-admin::form.control-group.label>
                            @lang('whatsapp::app.settings.template_pos_venda')
                        </x-admin::form.control-group.label>

                        <x-admin::form.control-group.control
                            type="text"
                            id="template_pos_venda"
                            name="template_pos_venda"
                            label="Template do Pós-Venda"
                            :value="$whatsAppSettings->template_pos_venda"
                        />

                        <x-admin::form.control-group.error control-name="template_pos_venda" />
                    </x-admin::form.control-group>
                </div>

                <div class="mt-4 border-t border-gray-200 pt-4 dark:border-gray-800">
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        @lang('whatsapp::app.settings.webhook-url')
                    </p>
                    <p class="select-all font-mono text-sm dark:text-white">
                        {{ route('whatsapp.webhook.receive') }}
                    </p>
                </div>
            </div>
        </div>
    </x-admin::form>
</x-admin::layouts>
