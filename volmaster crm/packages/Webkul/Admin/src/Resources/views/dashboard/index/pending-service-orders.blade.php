{!! view_render_event('admin.dashboard.index.pending_service_orders.before') !!}

<v-dashboard-pending-service-orders>
    <!-- Shimmer -->
    <x-admin::shimmer.dashboard.index.top-persons />
</v-dashboard-pending-service-orders>

{!! view_render_event('admin.dashboard.index.pending_service_orders.after') !!}

@pushOnce('scripts')
    <script
        type="text/x-template"
        id="v-dashboard-pending-service-orders-template"
    >
        <template v-if="isLoading">
            <x-admin::shimmer.dashboard.index.top-persons />
        </template>

        <template v-else>
            <div class="w-full rounded-lg border border-gray-300 bg-white dark:border-gray-800 dark:bg-gray-900">
                <div class="flex items-center justify-between p-4">
                    <p class="text-base font-semibold text-gray-600 dark:text-gray-300">
                        OS em andamento
                    </p>

                    <div class="flex items-center gap-3 text-xs text-gray-500 dark:text-gray-400">
                        <span class="flex items-center gap-1">
                            <span class="inline-block h-2.5 w-2.5 rounded-full" style="background:#16a34a"></span> No prazo
                        </span>

                        <span class="flex items-center gap-1">
                            <span class="inline-block h-2.5 w-2.5 rounded-full" style="background:#dc2626"></span> Prazo estourado
                        </span>

                        <span class="flex items-center gap-1">
                            <span class="inline-block h-2.5 w-2.5 rounded-full" style="background:#9ca3af"></span> Sem prazo
                        </span>
                    </div>
                </div>

                <div
                    class="flex flex-col"
                    v-if="report.statistics.length"
                >
                    <a
                        :href="'{{ route('admin.service_orders.view', ':id') }}'.replace(':id', item.id)"
                        class="flex items-center justify-between gap-2.5 border-b p-4 transition-all last:border-b-0 hover:bg-gray-50 dark:border-gray-800 dark:hover:bg-gray-950"
                        v-for="item in report.statistics"
                    >
                        <div class="flex flex-col gap-1">
                            <p class="font-medium text-gray-800 dark:text-white">
                                @{{ (item.person && item.person.name) ? item.person.name : 'Cliente não informado' }}
                            </p>

                            <p class="text-sm text-gray-500 dark:text-gray-400">
                                @{{ item.vehicle ? (item.vehicle.modelo + ' - ' + item.vehicle.placa) : 'Sem veículo' }}
                            </p>
                        </div>

                        <div class="flex flex-col items-end gap-1">
                            <span
                                class="rounded-full px-2.5 py-0.5 text-xs font-semibold"
                                :style="prazoStyle(item)"
                                :title="item.prazo ? item.prazo.texto : ''"
                            >
                                @{{ item.workshop_status }}
                            </span>

                            <span class="text-xs text-gray-400">
                                @{{ daysPending(item.created_at) }}
                            </span>
                        </div>
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
                                Nenhuma OS em andamento
                            </p>

                            <p class="text-gray-400">
                                Todas as Ordens de Serviço estão concluídas ou canceladas.
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </template>
    </script>

    <script type="module">
        app.component('v-dashboard-pending-service-orders', {
            template: '#v-dashboard-pending-service-orders-template',

            data() {
                return {
                    report: [],

                    isLoading: true,
                }
            },

            mounted() {
                this.getStats();
            },

            methods: {
                getStats() {
                    this.isLoading = true;

                    this.$axios.get("{{ route('admin.dashboard.stats') }}", {
                            params: { type: 'pending-service-orders' }
                        })
                        .then(response => {
                            this.report = response.data;

                            this.isLoading = false;
                        })
                        .catch(error => {});
                },

                // Verde: dentro do prazo. Vermelho: prazo estourado. Cinza: OS sem prazo definido.
                // Estilo direto (sem classes novas) para não depender de recompilar o CSS.
                prazoStyle(item) {
                    const cores = {
                        no_prazo: { background: '#dcfce7', color: '#166534' },
                        estourado: { background: '#fee2e2', color: '#991b1b' },
                        sem_prazo: { background: '#f3f4f6', color: '#4b5563' },
                    };

                    return cores[item.prazo ? item.prazo.estado : 'sem_prazo'] || cores.sem_prazo;
                },

                daysPending(createdAt) {
                    const days = Math.floor((new Date() - new Date(createdAt)) / (1000 * 60 * 60 * 24));

                    if (days <= 0) {
                        return 'Hoje';
                    }

                    return days === 1 ? 'há 1 dia' : `há ${days} dias`;
                },
            }
        });
    </script>
@endPushOnce
