{!! view_render_event('admin.dashboard.index.service_order_revenue.before') !!}

<v-dashboard-service-order-revenue>
    <!-- Shimmer -->
    <div class="light-shimmer-bg dark:shimmer h-[110px] w-full rounded-lg"></div>
</v-dashboard-service-order-revenue>

{!! view_render_event('admin.dashboard.index.service_order_revenue.after') !!}

@pushOnce('scripts')
    <script
        type="text/x-template"
        id="v-dashboard-service-order-revenue-template"
    >
        <!-- Shimmer -->
        <template v-if="isLoading">
            <div class="light-shimmer-bg dark:shimmer h-[110px] w-full rounded-lg"></div>
        </template>

        <template v-else>
            <div class="box-shadow rounded-lg border border-gray-300 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
                <p class="mb-3 text-base font-semibold text-gray-600 dark:text-gray-300">
                    Faturamento de Ordens de Serviço
                </p>

                <div class="flex gap-2 max-md:flex-wrap">
                    <!-- Concluded Revenue Card -->
                    <div class="flex flex-1 flex-col gap-2 rounded-lg border border-gray-300 px-4 py-5 dark:border-gray-800 max-sm:w-full">
                        <p class="text-xs font-medium text-gray-600 dark:text-gray-300">
                            Concluídas
                        </p>

                        <div class="flex gap-2">
                            <p class="text-xl font-bold text-green-600">
                                @{{ report.statistics.total_concluded_revenue.formatted_total }}
                            </p>

                            <div class="flex items-center gap-0.5">
                                <span
                                    class="text-base !font-semibold text-green-500"
                                    :class="[report.statistics.total_concluded_revenue.progress < 0 ? 'icon-stats-down text-red-500 dark:!text-red-500' : 'icon-stats-up text-green-500 dark:!text-green-500']"
                                ></span>

                                <p
                                    class="text-xs font-semibold text-green-500"
                                    :class="[report.statistics.total_concluded_revenue.progress < 0 ? 'text-red-500' : 'text-green-500']"
                                >
                                    @{{ Math.abs(report.statistics.total_concluded_revenue.progress.toFixed(2)) }}%
                                </p>
                            </div>
                        </div>
                    </div>

                    <!-- Cancelled Revenue Card -->
                    <div class="flex flex-1 flex-col gap-2 rounded-lg border border-gray-300 px-4 py-5 dark:border-gray-800 max-sm:w-full">
                        <p class="text-xs font-medium text-gray-600 dark:text-gray-300">
                            Canceladas
                        </p>

                        <div class="flex gap-2">
                            <p class="text-xl font-bold text-red-500">
                                @{{ report.statistics.total_cancelled_revenue.formatted_total }}
                            </p>

                            <div class="flex items-center gap-0.5">
                                <span class="icon-stats-down text-base !font-semibold text-red-500 dark:!text-red-500"></span>

                                <p class="text-xs font-semibold text-red-500">
                                    @{{ Math.abs(report.statistics.total_cancelled_revenue.progress.toFixed(2)) }}%
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </template>
    </script>

    <script type="module">
        app.component('v-dashboard-service-order-revenue', {
            template: '#v-dashboard-service-order-revenue-template',

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

                    filters.type = 'service-order-revenue-stats';

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
