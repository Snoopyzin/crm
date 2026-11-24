{!! view_render_event('admin.layouts.header.guides.before') !!}

<v-guides-panel></v-guides-panel>

{!! view_render_event('admin.layouts.header.guides.after') !!}

@pushOnce('scripts')
    <script
        type="text/x-template"
        id="v-guides-panel-template"
    >
        <div>
            <!-- Trigger -->
            <div
                class="flex cursor-pointer items-center rounded-md p-1.5 transition-all hover:bg-gray-100 dark:hover:bg-gray-950"
                @click="open"
            >
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-gray-600 dark:text-gray-300">
                    <circle cx="12" cy="12" r="10"></circle>
                    <polygon points="16.24 7.76 14.12 14.12 7.76 16.24 9.88 9.88 16.24 7.76"></polygon>
                </svg>
            </div>

            <!-- Overlay -->
            <div
                v-if="isOpen"
                class="fixed inset-0 z-[10010] bg-black/40"
                @click="isOpen = false"
            ></div>

            <!-- Drawer -->
            <div
                class="fixed right-0 top-0 z-[10011] flex h-full w-full max-w-[420px] transform flex-col overflow-y-auto bg-white shadow-xl transition-transform duration-300 dark:bg-gray-900"
                :class="[isOpen ? 'translate-x-0' : 'translate-x-full']"
            >
                <div class="flex items-center justify-between border-b border-gray-200 p-4 dark:border-gray-800">
                    <div>
                        <p class="text-lg font-semibold text-gray-800 dark:text-white">
                            Guias e trilhas
                        </p>

                        <p class="text-sm text-gray-500 dark:text-gray-400">
                            Aprenda o sistema passo a passo
                        </p>
                    </div>

                    <span
                        class="icon-cancel cursor-pointer text-2xl text-gray-500 dark:text-gray-300"
                        @click="isOpen = false"
                    ></span>
                </div>

                <!-- Loading -->
                <div class="flex flex-col gap-3 p-4" v-if="isLoading">
                    <div class="light-shimmer-bg dark:shimmer h-[90px] w-full rounded-lg"></div>
                    <div class="light-shimmer-bg dark:shimmer h-[60px] w-full rounded-lg" v-for="n in 4" :key="n"></div>
                </div>

                <!-- Content -->
                <div class="flex flex-col gap-4 p-4" v-else>
                    <!-- Progress summary -->
                    <div class="flex items-center gap-4 rounded-lg border border-gray-200 p-4 dark:border-gray-800">
                        <div class="text-2xl font-bold text-blue-600 dark:text-blue-400">
                            @{{ report.progress_percent }}%
                        </div>

                        <div class="text-sm text-gray-500 dark:text-gray-400">
                            <p>@{{ report.guides_completed }}/@{{ report.guides_total }} trilhas concluídas</p>
                            <p>@{{ report.steps_completed }}/@{{ report.steps_total }} guias concluídos</p>
                        </div>
                    </div>

                    <!-- Guide list -->
                    <div class="flex flex-col gap-2">
                        <div
                            class="rounded-lg border border-gray-200 dark:border-gray-800"
                            v-for="guide in report.guides"
                            :key="guide.id"
                        >
                            <button
                                type="button"
                                class="flex w-full items-center justify-between gap-2 p-3 text-left"
                                @click="toggleGuide(guide.id)"
                            >
                                <div class="flex items-center gap-2.5">
                                    <span
                                        class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-blue-50 text-blue-600 dark:bg-blue-950 dark:text-blue-400"
                                        v-html="icons[guide.icon]"
                                    ></span>

                                    <div>
                                        <p class="text-sm font-semibold text-gray-800 dark:text-white">
                                            @{{ guide.title }}
                                        </p>

                                        <p class="text-xs text-gray-500 dark:text-gray-400">
                                            @{{ guide.description }}
                                        </p>
                                    </div>
                                </div>

                                <div class="flex shrink-0 items-center gap-2">
                                    <span
                                        class="rounded-full px-2 py-0.5 text-xs font-semibold"
                                        :class="[guide.completed_count === guide.total_count ? 'bg-green-100 text-green-700 dark:bg-green-900 dark:text-green-300' : 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300']"
                                    >
                                        @{{ guide.completed_count }}/@{{ guide.total_count }}
                                    </span>

                                    <span
                                        class="icon-down-arrow text-lg text-gray-500 transition-transform dark:text-gray-300"
                                        :class="[expanded === guide.id ? 'rotate-180' : '']"
                                    ></span>
                                </div>
                            </button>

                            <div
                                class="flex flex-col gap-3 border-t border-gray-200 p-3 dark:border-gray-800"
                                v-if="expanded === guide.id"
                            >
                                <div
                                    class="flex gap-2.5"
                                    v-for="step in guide.steps"
                                    :key="step.id"
                                >
                                    <input
                                        type="checkbox"
                                        class="mt-1 h-4 w-4 shrink-0 cursor-pointer"
                                        :checked="step.completed"
                                        :disabled="step.completed"
                                        @change="completeStep(guide, step)"
                                    />

                                    <div>
                                        <p
                                            class="text-sm font-medium"
                                            :class="[step.completed ? 'text-gray-400 line-through dark:text-gray-500' : 'text-gray-800 dark:text-white']"
                                        >
                                            @{{ step.title }}
                                        </p>

                                        <p class="text-sm text-gray-500 dark:text-gray-400" v-html="step.content"></p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </script>

    <script type="module">
        app.component('v-guides-panel', {
            template: '#v-guides-panel-template',

            data() {
                return {
                    isOpen: false,

                    isLoading: true,

                    hasLoaded: false,

                    expanded: null,

                    report: {
                        guides: [],
                        progress_percent: 0,
                        guides_completed: 0,
                        guides_total: 0,
                        steps_completed: 0,
                        steps_total: 0,
                    },

                    icons: {
                        wave: '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 12 20 22 4 22 4 12"></polyline><rect x="2" y="7" width="20" height="5"></rect><line x1="12" y1="22" x2="12" y2="7"></line><path d="M12 7H7.5a2.5 2.5 0 0 1 0-5C11 2 12 7 12 7z"></path><path d="M12 7h4.5a2.5 2.5 0 0 0 0-5C13 2 12 7 12 7z"></path></svg>',
                        wrench: '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"></path></svg>',
                        car: '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="3" width="15" height="13"></rect><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"></polygon><circle cx="5.5" cy="18.5" r="2.5"></circle><circle cx="18.5" cy="18.5" r="2.5"></circle></svg>',
                        box: '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12.89 1.45l8 4A2 2 0 0 1 22 7.24v9.53a2 2 0 0 1-1.11 1.79l-8 4a2 2 0 0 1-1.79 0l-8-4a2 2 0 0 1-1.1-1.8V7.24a2 2 0 0 1 1.11-1.79l8-4a2 2 0 0 1 1.79 0z"></path><polyline points="2.32 6.16 12 11 21.68 6.16"></polyline><line x1="12" y1="22.76" x2="12" y2="11"></line></svg>',
                        monitor: '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="3" width="20" height="14" rx="2" ry="2"></rect><line x1="8" y1="21" x2="16" y2="21"></line><line x1="12" y1="17" x2="12" y2="21"></line></svg>',
                        chart: '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10"></line><line x1="12" y1="20" x2="12" y2="4"></line><line x1="6" y1="20" x2="6" y2="14"></line></svg>',
                    },
                }
            },

            methods: {
                open() {
                    this.isOpen = true;

                    if (! this.hasLoaded) {
                        this.fetchGuides();
                    }
                },

                fetchGuides() {
                    this.isLoading = true;

                    this.$axios.get("{{ route('admin.guides.index') }}")
                        .then(response => {
                            this.report = response.data;

                            this.isLoading = false;

                            this.hasLoaded = true;
                        })
                        .catch(error => {});
                },

                toggleGuide(guideId) {
                    this.expanded = this.expanded === guideId ? null : guideId;
                },

                completeStep(guide, step) {
                    if (step.completed) {
                        return;
                    }

                    step.completed = true;

                    guide.completed_count++;

                    this.report.steps_completed++;

                    this.report.progress_percent = this.report.steps_total
                        ? Math.round((this.report.steps_completed / this.report.steps_total) * 100)
                        : 0;

                    if (guide.completed_count === guide.total_count) {
                        this.report.guides_completed++;
                    }

                    this.$axios.post("{{ route('admin.guides.complete-step', ':stepId') }}".replace(':stepId', step.id))
                        .catch(error => {});
                },
            },
        });
    </script>
@endPushOnce
