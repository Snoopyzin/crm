{!! view_render_event('admin.dashboard.index.urgent_remote_services.before') !!}

<v-dashboard-urgent-remote-services>
    <!-- Shimmer -->
    <x-admin::shimmer.dashboard.index.top-persons />
</v-dashboard-urgent-remote-services>

{!! view_render_event('admin.dashboard.index.urgent_remote_services.after') !!}

@pushOnce('scripts')
    <script
        type="text/x-template"
        id="v-dashboard-urgent-remote-services-template"
    >
        <template v-if="isLoading">
            <x-admin::shimmer.dashboard.index.top-persons />
        </template>

        <template v-else>
            <div class="w-full rounded-lg border border-gray-300 bg-white dark:border-gray-800 dark:bg-gray-900">
                <div class="flex items-center justify-between p-4">
                    <p class="text-base font-semibold text-gray-600 dark:text-gray-300">
                        Urgências
                    </p>
                </div>

                <div
                    class="flex flex-col"
                    v-if="report.statistics.length"
                >
                    <a
                        :href="'{{ route('admin.remote_services.view', ':id') }}'.replace(':id', item.id)"
                        class="flex items-center justify-between gap-2.5 border-b p-4 transition-all last:border-b-0 hover:bg-gray-50 dark:border-gray-800 dark:hover:bg-gray-950"
                        v-for="item in report.statistics"
                    >
                        <div class="flex flex-col gap-1">
                            <p class="font-medium text-gray-800 dark:text-white">
                                @{{ (item.person && item.person.name) ? item.person.name : (item.cliente_nome_livre || 'Cliente não informado') }}
                            </p>

                            <p class="text-sm text-gray-500 dark:text-gray-400">
                                @{{ item.tipo_atendimento || 'Tipo não definido' }}
                            </p>
                        </div>

                        <span class="rounded-full bg-blue-100 px-2.5 py-0.5 text-xs font-semibold text-blue-700 dark:bg-blue-900 dark:text-blue-300">
                            ⏱ @{{ formatElapsed(item.iniciado_em) }}
                        </span>
                    </a>
                </div>

                <div
                    class="flex flex-col gap-8 p-4"
                    v-else
                >
                    <div class="grid justify-center justify-items-center gap-3.5 py-2.5">
                        <img
                            src="{{ vite()->asset('images/empty-placeholders/users.svg') }}"
                            class="dark:mix-blend-exclusion dark:invert"
                        >

                        <div class="flex flex-col items-center">
                            <p class="text-base font-semibold text-gray-400">
                                Nenhuma urgência agora
                            </p>

                            <p class="text-gray-400">
                                Não há atendimentos remotos em aberto no momento.
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </template>
    </script>

    <script type="module">
        app.component('v-dashboard-urgent-remote-services', {
            template: '#v-dashboard-urgent-remote-services-template',

            data() {
                return {
                    report: [],

                    isLoading: true,

                    nowTs: Date.now(),

                    tickInterval: null,
                }
            },

            mounted() {
                this.getStats();

                this.tickInterval = setInterval(() => {
                    this.nowTs = Date.now();
                }, 1000);
            },

            beforeUnmount() {
                if (this.tickInterval) {
                    clearInterval(this.tickInterval);
                }
            },

            methods: {
                getStats() {
                    this.isLoading = true;

                    this.$axios.get("{{ route('admin.dashboard.stats') }}", {
                            params: { type: 'urgent-remote-services' }
                        })
                        .then(response => {
                            this.report = response.data;

                            this.isLoading = false;
                        })
                        .catch(error => {});
                },

                formatElapsed(startedAt) {
                    if (! startedAt) {
                        return '00:00:00';
                    }

                    var diff = Math.max(0, this.nowTs - new Date(startedAt).getTime());

                    var totalSeconds = Math.floor(diff / 1000);
                    var hours = Math.floor(totalSeconds / 3600);
                    var minutes = Math.floor((totalSeconds % 3600) / 60);
                    var seconds = totalSeconds % 60;

                    function pad(n) {
                        return String(n).padStart(2, '0');
                    }

                    return pad(hours) + ':' + pad(minutes) + ':' + pad(seconds);
                },
            }
        });
    </script>
@endPushOnce
