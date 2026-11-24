{{--
    Início de quem ganha comissão: quanto a pessoa ganhou no período, por OS e
    cliente. Não traz o valor da OS, o desconto nem o percentual: só a comissão.
--}}
{!! view_render_event('admin.dashboard.index.minhas_comissoes.before') !!}

<v-dashboard-minhas-comissoes>
    <!-- Shimmer -->
    <div class="light-shimmer-bg dark:shimmer h-[110px] w-full rounded-lg"></div>
</v-dashboard-minhas-comissoes>

{!! view_render_event('admin.dashboard.index.minhas_comissoes.after') !!}

@pushOnce('scripts')
    <script
        type="text/x-template"
        id="v-dashboard-minhas-comissoes-template"
    >
        <template v-if="isLoading">
            <div class="light-shimmer-bg dark:shimmer h-[110px] w-full rounded-lg"></div>
        </template>

        <template v-else>
            <div class="w-full rounded-lg border border-gray-300 bg-white dark:border-gray-800 dark:bg-gray-900">
                <div class="flex items-center justify-between gap-3 p-4 max-sm:flex-wrap">
                    <div>
                        <p class="text-base font-semibold text-gray-600 dark:text-gray-300">
                            Minhas comissões
                        </p>

                        <p class="text-xs text-gray-500 dark:text-gray-400">
                            OS faturadas de @{{ report.statistics.periodo }}
                        </p>
                    </div>

                    <div class="text-right">
                        <p class="text-2xl font-bold text-green-600">
                            @{{ report.statistics.total.formatado }}
                        </p>

                        <p class="text-xs text-gray-500 dark:text-gray-400">
                            @{{ report.statistics.os }} @{{ report.statistics.os === 1 ? 'OS faturada' : 'OS faturadas' }}
                        </p>
                    </div>
                </div>

                <div
                    class="flex flex-col"
                    v-if="report.statistics.linhas.length"
                >
                    <a
                        :href="'{{ route('admin.service_orders.view', ':id') }}'.replace(':id', linha.os)"
                        class="flex items-center justify-between gap-2.5 border-t p-4 transition-all hover:bg-gray-50 dark:border-gray-800 dark:hover:bg-gray-950"
                        v-for="linha in report.statistics.linhas"
                    >
                        <div class="flex flex-col gap-1">
                            <p class="font-medium text-gray-800 dark:text-white">
                                @{{ linha.cliente }}
                            </p>

                            <p class="text-sm text-gray-500 dark:text-gray-400">
                                OS #@{{ linha.os }} &middot; @{{ linha.servico }} &middot; @{{ linha.data }}
                            </p>
                        </div>

                        <p class="whitespace-nowrap text-base font-semibold text-green-600">
                            @{{ linha.comissao_formatada }}
                        </p>
                    </a>
                </div>

                <div
                    class="border-t p-4 text-center text-sm text-gray-400 dark:border-gray-800"
                    v-else
                >
                    Nenhuma comissão neste período. A comissão entra quando a OS é faturada.
                </div>
            </div>
        </template>
    </script>

    <script type="module">
        app.component('v-dashboard-minhas-comissoes', {
            template: '#v-dashboard-minhas-comissoes-template',

            data() {
                return {
                    report: [],

                    isLoading: true,
                }
            },

            mounted() {
                this.getStats({});

                this.$emitter.on('reporting-filter-updated', this.getStats);
            },

            methods: {
                getStats(filters) {
                    this.isLoading = true;

                    var filters = Object.assign({}, filters);

                    filters.type = 'minhas-comissoes';

                    this.$axios.get("{{ route('admin.dashboard.stats') }}", {
                            params: filters
                        })
                        .then(response => {
                            this.report = response.data;

                            this.isLoading = false;
                        })
                        .catch(error => {});
                },
            }
        });
    </script>
@endPushOnce
