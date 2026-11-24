<x-admin::layouts>
    <x-slot:title>
        Novo Atendimento Remoto
    </x-slot>

    <x-admin::form :action="route('admin.remote_services.store')" method="POST">
        <div class="flex flex-col gap-4">
            <div class="flex items-center justify-between rounded-lg border border-gray-200 bg-white px-4 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300">
                <div class="flex flex-col gap-2">
                    <div class="text-xl font-bold dark:text-white">
                        Novo Atendimento Remoto
                    </div>
                </div>

                <div class="flex items-center gap-x-2.5">
                    <button type="submit" class="primary-button">
                        Registrar
                    </button>
                </div>
            </div>

            <div class="box-shadow rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
                <p class="mb-4 text-base font-semibold text-gray-800 dark:text-white">
                    Dados do atendimento
                </p>

                <div class="grid grid-cols-2 gap-x-4 gap-y-4 max-sm:grid-cols-1">
                    <x-admin::form.control-group class="relative">
                        <x-admin::form.control-group.label class="required">
                            Cliente
                        </x-admin::form.control-group.label>

                        <input type="hidden" id="person_id" name="person_id" value="{{ old('person_id') }}">

                        <input
                            type="text"
                            id="cliente_nome_livre"
                            name="cliente_nome_livre"
                            value="{{ old('cliente_nome_livre') }}"
                            autocomplete="off"
                            placeholder="Digite o nome do cliente"
                            class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm text-gray-600 transition-all hover:border-gray-400 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300 dark:hover:border-gray-400"
                        />

                        <div
                            id="cliente-suggestions"
                            class="absolute z-10 mt-1 hidden w-full rounded-md border border-gray-300 bg-white shadow-lg dark:border-gray-800 dark:bg-gray-900"
                        ></div>

                        <p class="mt-1 text-xs text-gray-400">
                            Se o cliente já existir, clique na sugestão. Se não, é criado automaticamente com esse nome ao finalizar.
                        </p>

                        <x-admin::form.control-group.error control-name="person_id" />
                        <x-admin::form.control-group.error control-name="cliente_nome_livre" />
                    </x-admin::form.control-group>

                    <x-admin::form.control-group>
                        <x-admin::form.control-group.label>
                            Nome da oficina (opcional)
                        </x-admin::form.control-group.label>

                        <x-admin::form.control-group.control
                            type="text"
                            id="nome_oficina"
                            name="nome_oficina"
                            :value="old('nome_oficina')"
                            label="Nome da oficina"
                            placeholder="Ex: Oficina do João"
                        />

                        <x-admin::form.control-group.error control-name="nome_oficina" />
                    </x-admin::form.control-group>

                    <x-admin::form.control-group>
                        <x-admin::form.control-group.label>
                            WhatsApp (opcional)
                        </x-admin::form.control-group.label>

                        <x-admin::form.control-group.control
                            type="text"
                            id="whatsapp"
                            name="whatsapp"
                            :value="old('whatsapp')"
                            label="WhatsApp"
                            placeholder="(62) 99999-9999"
                        />

                        <x-admin::form.control-group.error control-name="whatsapp" />
                    </x-admin::form.control-group>

                    <x-admin::form.control-group>
                        <x-admin::form.control-group.label>
                            Veículo já cadastrado (opcional)
                        </x-admin::form.control-group.label>

                        <x-admin::form.control-group.control
                            type="select"
                            id="vehicle_id"
                            name="vehicle_id"
                            :value="old('vehicle_id')"
                            label="Veículo"
                        >
                            <option value="">Sem veículo cadastrado / não se aplica</option>
                        </x-admin::form.control-group.control>

                        <x-admin::form.control-group.error control-name="vehicle_id" />
                    </x-admin::form.control-group>

                    <x-admin::form.control-group>
                        <x-admin::form.control-group.label>
                            Descrição do veículo/máquina (se não estiver cadastrado)
                        </x-admin::form.control-group.label>

                        <x-admin::form.control-group.control
                            type="text"
                            id="veiculo_descricao_livre"
                            name="veiculo_descricao_livre"
                            :value="old('veiculo_descricao_livre')"
                            label="Descrição livre"
                            placeholder="Ex: Scania R450, placa ABC1234"
                        />

                        <x-admin::form.control-group.error control-name="veiculo_descricao_livre" />
                    </x-admin::form.control-group>

                    <div class="col-span-2">
                        @include('remote_service::teamviewer-ocr')
                    </div>

                    <x-admin::form.control-group>
                        <x-admin::form.control-group.label>
                            ID do TeamViewer
                        </x-admin::form.control-group.label>

                        <x-admin::form.control-group.control
                            type="text"
                            id="teamviewer_id"
                            name="teamviewer_id"
                            :value="old('teamviewer_id')"
                            label="ID do TeamViewer"
                            placeholder="Ex: 207 925 577"
                        />

                        <x-admin::form.control-group.error control-name="teamviewer_id" />
                    </x-admin::form.control-group>

                    <x-admin::form.control-group>
                        <x-admin::form.control-group.label>
                            Senha do TeamViewer
                        </x-admin::form.control-group.label>

                        <x-admin::form.control-group.control
                            type="text"
                            id="teamviewer_password"
                            name="teamviewer_password"
                            :value="old('teamviewer_password')"
                            label="Senha do TeamViewer"
                            placeholder="Se mudar depois, é só editar"
                        />

                        <x-admin::form.control-group.error control-name="teamviewer_password" />
                    </x-admin::form.control-group>

                    <x-admin::form.control-group class="col-span-2">
                        <x-admin::form.control-group.label>
                            Tipo de atendimento (pode marcar mais de um)
                        </x-admin::form.control-group.label>

                        <div class="max-h-64 overflow-y-auto rounded-md border border-gray-300 p-3 dark:border-gray-800">
                            @forelse ($tiposPorGrupo as $grupoNome => $tipos)
                                <p class="mb-1 mt-3 text-xs font-semibold uppercase text-gray-400 first:mt-0">
                                    {{ $grupoNome }}
                                </p>

                                <div class="grid grid-cols-3 gap-1 max-sm:grid-cols-1">
                                    @foreach ($tipos as $tipo)
                                        <label class="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-300">
                                            <input type="checkbox" name="tipo_atendimento[]" value="{{ $tipo->nome }}">
                                            {{ $tipo->nome }}
                                        </label>
                                    @endforeach
                                </div>
                            @empty
                                <p class="text-sm text-gray-400">
                                    Nenhum tipo cadastrado ainda.
                                    <a href="{{ route('admin.remote_services.types.create') }}" class="text-brandColor hover:underline">Cadastre o primeiro</a>.
                                </p>
                            @endforelse
                        </div>

                        <x-admin::form.control-group.error control-name="tipo_atendimento" />
                    </x-admin::form.control-group>

                    <x-admin::form.control-group class="col-span-2">
                        <x-admin::form.control-group.label class="required">
                            Descrição do problema
                        </x-admin::form.control-group.label>

                        <x-admin::form.control-group.control
                            type="textarea"
                            id="descricao_problema"
                            name="descricao_problema"
                            rules="required"
                            :value="old('descricao_problema')"
                            label="Descrição do problema"
                        />

                        <x-admin::form.control-group.error control-name="descricao_problema" />
                    </x-admin::form.control-group>
                </div>
            </div>
        </div>
    </x-admin::form>

    @pushOnce('scripts')
        <script>
            function loadVehicles(personId) {
                const vehicleSelect = document.getElementById('vehicle_id');

                vehicleSelect.innerHTML = '<option value="">Sem veículo cadastrado / não se aplica</option>';

                if (! personId) {
                    return;
                }

                fetch('{{ url('admin/remote-services/vehicles-by-person') }}/' + personId)
                    .then((response) => response.json())
                    .then((vehicles) => {
                        vehicles.forEach((vehicle) => {
                            const option = document.createElement('option');
                            option.value = vehicle.id;
                            option.textContent = vehicle.placa + ' - ' + vehicle.marca + ' ' + vehicle.modelo;
                            vehicleSelect.appendChild(option);
                        });
                    });
            }

            (function () {
                const input = document.getElementById('cliente_nome_livre');
                const hiddenPersonId = document.getElementById('person_id');
                const suggestionsBox = document.getElementById('cliente-suggestions');

                let debounceTimer = null;

                function hideSuggestions() {
                    suggestionsBox.classList.add('hidden');
                    suggestionsBox.innerHTML = '';
                }

                function selectPerson(person) {
                    input.value = person.name;
                    hiddenPersonId.value = person.id;
                    hideSuggestions();
                    loadVehicles(person.id);
                }

                input.addEventListener('input', function () {
                    // Any manual edit invalidates a previously picked suggestion.
                    hiddenPersonId.value = '';
                    loadVehicles('');

                    clearTimeout(debounceTimer);

                    const query = input.value.trim();

                    if (query.length < 2) {
                        hideSuggestions();

                        return;
                    }

                    debounceTimer = setTimeout(function () {
                        fetch('{{ route('admin.remote_services.search-persons') }}?q=' + encodeURIComponent(query))
                            .then((response) => response.json())
                            .then((persons) => {
                                if (! persons.length) {
                                    hideSuggestions();

                                    return;
                                }

                                suggestionsBox.innerHTML = '';

                                persons.forEach((person) => {
                                    const item = document.createElement('div');
                                    item.textContent = person.name;
                                    item.className = 'cursor-pointer px-3 py-2 text-sm text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-950';
                                    item.addEventListener('click', () => selectPerson(person));
                                    suggestionsBox.appendChild(item);
                                });

                                suggestionsBox.classList.remove('hidden');
                            });
                    }, 300);
                });

                document.addEventListener('click', function (event) {
                    if (! suggestionsBox.contains(event.target) && event.target !== input) {
                        hideSuggestions();
                    }
                });
            })();

            // Repeat clients keep the same TeamViewer ID across visits --
            // recognize it and auto-fill the rest of the form instead of
            // retyping the same client/oficina every time.
            (function () {
                document.addEventListener('change', function (event) {
                    if (! event.target || event.target.id !== 'teamviewer_id') {
                        return;
                    }

                    var normalized = event.target.value.replace(/\D/g, '');

                    if (normalized.length < 8) {
                        return;
                    }

                    fetch('{{ url('admin/remote-services/lookup-by-teamviewer') }}/' + encodeURIComponent(normalized))
                        .then(function (response) { return response.json(); })
                        .then(function (data) {
                            if (! data.found) {
                                return;
                            }

                            var nomeField = document.getElementById('cliente_nome_livre');
                            var personIdField = document.getElementById('person_id');
                            var oficinaField = document.getElementById('nome_oficina');
                            var whatsappField = document.getElementById('whatsapp');

                            if (nomeField && ! nomeField.value) {
                                nomeField.value = data.person_name || data.cliente_nome_livre || '';
                            }

                            if (personIdField && ! personIdField.value && data.person_id) {
                                personIdField.value = data.person_id;

                                loadVehicles(data.person_id);
                            }

                            if (oficinaField && ! oficinaField.value && data.nome_oficina) {
                                oficinaField.value = data.nome_oficina;
                            }

                            if (whatsappField && ! whatsappField.value && data.whatsapp) {
                                whatsappField.value = data.whatsapp;
                            }

                            var anyTipoChecked = document.querySelector('input[name="tipo_atendimento[]"]:checked');

                            if (! anyTipoChecked && data.tipo_atendimento && data.tipo_atendimento.length) {
                                data.tipo_atendimento.forEach(function (tipo) {
                                    var checkbox = document.querySelector('input[name="tipo_atendimento[]"][value="' + CSS.escape(tipo) + '"]');

                                    if (checkbox) {
                                        checkbox.checked = true;
                                    }
                                });
                            }

                            var label = data.person_name || data.cliente_nome_livre || data.nome_oficina;

                            if (label) {
                                var status = document.getElementById('teamviewer_id_ocr_status');

                                if (status) {
                                    status.textContent = 'Cliente reconhecido: ' + label + ' — dados preenchidos automaticamente.';
                                }
                            }
                        })
                        .catch(function () {});
                });
            })();
        </script>
    @endPushOnce
</x-admin::layouts>
