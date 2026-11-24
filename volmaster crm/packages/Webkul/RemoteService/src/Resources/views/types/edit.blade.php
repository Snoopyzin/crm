<x-admin::layouts>
    <x-slot:title>
        Editar Tipo de Atendimento
    </x-slot>

    <x-admin::form :action="route('admin.remote_services.types.update', $remoteServiceType->id)" method="PUT">
        <div class="flex flex-col gap-4">
            <div class="flex items-center justify-between rounded-lg border border-gray-200 bg-white px-4 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300">
                <div class="text-xl font-bold dark:text-white">
                    Editar Tipo de Atendimento
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
                            :value="$remoteServiceType->nome"
                        />

                        <x-admin::form.control-group.error control-name="nome" />
                    </x-admin::form.control-group>

                    <x-admin::form.control-group>
                        <x-admin::form.control-group.label>
                            Grupo
                        </x-admin::form.control-group.label>

                        <x-admin::form.control-group.control
                            type="select"
                            id="group_id"
                            name="group_id"
                            label="Grupo"
                            :value="$remoteServiceType->group_id"
                        >
                            <option value="">Sem grupo</option>
                            @foreach ($groups as $group)
                                <option value="{{ $group->id }}" {{ (int) $remoteServiceType->group_id === $group->id ? 'selected' : '' }}>{{ $group->name }}</option>
                            @endforeach
                        </x-admin::form.control-group.control>

                        <x-admin::form.control-group.error control-name="group_id" />
                    </x-admin::form.control-group>
                </div>
            </div>
        </div>
    </x-admin::form>
</x-admin::layouts>
