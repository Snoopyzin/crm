<div
    id="admin-sidebar"
    ref="sidebar"
    class="duration-80 fixed top-[60px] z-[10002] h-full w-[235px] border-gray-300 bg-white pt-4 transition-all group-[.sidebar-collapsed]/container:w-[70px] dark:border-gray-800 dark:bg-gray-900 max-lg:hidden ltr:border-r rtl:border-l"
>
    {{-- Visible thin scrollbar so long menus can be scrolled (journal-scroll hides it). --}}
    <style>
        .sidebar-scroll { scrollbar-width: thin; scrollbar-color: #9ca3af transparent; }
        .sidebar-scroll::-webkit-scrollbar { width: 6px; }
        .sidebar-scroll::-webkit-scrollbar-track { background: transparent; }
        .sidebar-scroll::-webkit-scrollbar-thumb { background: #9ca3af; border-radius: 6px; }
    </style>

    <div class="sidebar-scroll h-[calc(100vh-100px)] overflow-y-auto group-[.sidebar-collapsed]/container:overflow-visible">
        <nav class="sidebar-rounded grid w-full gap-2">
            <!-- Navigation Menu -->
            @php
                /**
                 * Menu agrupado por assunto: a lista inicial fica curta e cada grupo
                 * abre e fecha. Item que não estiver listado aqui cai em "Mais" e nada
                 * some do menu; permissão continua valendo (menu()->getItems já filtra).
                 */
                $grupos = [
                    '' => ['tecnico', 'dashboard'],
                    'Clientes' => ['cadastro', 'clientes_busca'],
                    'Ordem de Serviço' => ['ordem_servico', 'diagnostico', 'orcamento', 'negociacao', 'em_execucao', 'veiculo_teste', 'faturamento', 'pos_venda'],
                    'Pátio e agenda' => ['rotina_patio', 'rotina_painel', 'rotina_agenda'],
                    'Financeiro' => ['financeiro_rotina', 'financeiro_dre', 'comissoes'],
                    'Gestão' => ['painel_dono', 'indicadores_mentoria', 'alcadas', 'marketing', 'mentor_mentoria', 'registros_mentoria'],
                    'Equipe' => ['colaboradores', 'bonus_mentoria', 'bonus_meu'],
                    'Estoque e catálogo' => ['estoque', 'servicos_catalogo', 'grupos'],
                    '~fim' => ['settings', 'help'],
                ];

                $todos = collect(menu()->getItems('admin'))->keyBy(fn ($i) => $i->getKey());
                $usados = collect($grupos)->flatten()->all();
                $grupos['Mais'] = $todos->keys()->reject(fn ($k) => in_array($k, $usados))->values()->all();

                // coloca o "Mais" antes de Configurações/Ajuda
                $fim = $grupos['~fim']; unset($grupos['~fim']);
                $grupos['~fim'] = $fim;
            @endphp

            @foreach ($grupos as $rotuloGrupo => $chaves)
                @php
                    $itensGrupo = collect($chaves)->map(fn ($k) => $todos->get($k))->filter();
                    $ehFlat = $rotuloGrupo === '' || $rotuloGrupo === '~fim';
                    $grupoAtivo = $itensGrupo->contains(fn ($i) => $i->isActive());
                    $idGrupo = \Illuminate\Support\Str::slug($rotuloGrupo ?: 'principal');
                @endphp

                @if ($itensGrupo->isNotEmpty())
                    @if ($ehFlat)
                        @foreach ($itensGrupo as $menuItem)
                            @include('admin::components.layouts.sidebar.desktop.item')
                        @endforeach
                    @else
                        <div class="vm-group" data-vm-group="{{ $idGrupo }}" data-vm-open="{{ $grupoAtivo ? 1 : 0 }}">
                            <button
                                type="button"
                                class="vm-group-toggle"
                                data-vm-group-toggle
                                aria-expanded="{{ $grupoAtivo ? 'true' : 'false' }}"
                            >
                                <span>{{ $rotuloGrupo }}</span>
                                <span class="vm-chevron" aria-hidden="true"></span>
                            </button>

                            <div class="vm-group-body {{ $grupoAtivo ? '' : 'hidden' }} grid gap-2">
                                @foreach ($itensGrupo as $menuItem)
                                    @include('admin::components.layouts.sidebar.desktop.item')
                                @endforeach
                            </div>
                        </div>
                    @endif
                @endif
            @endforeach
        </nav>
    </div>

    {!! view_render_event('admin.layout.sidebar.toggle.before') !!}

    <!-- Collapse menu -->
    <v-sidebar-collapse></v-sidebar-collapse>

    {!! view_render_event('admin.layout.sidebar.toggle.after') !!}
</div>

@pushOnce('scripts')
    <script>
        /* Grupos do menu: abre/fecha e lembra o que o usuário deixou aberto. */
        (function () {
            var KEY = 'vm_menu_open';
            function lido() { try { return JSON.parse(localStorage.getItem(KEY) || '{}'); } catch (e) { return {}; } }
            function gravar(o) { try { localStorage.setItem(KEY, JSON.stringify(o)); } catch (e) {} }
            function aplicar(g, aberto) {
                g.querySelector('.vm-group-body').classList.toggle('hidden', !aberto);
                g.querySelector('[data-vm-group-toggle]').setAttribute('aria-expanded', aberto ? 'true' : 'false');
                g.classList.toggle('is-open', aberto);
            }
            document.addEventListener('DOMContentLoaded', function () {
                var salvo = lido();
                document.querySelectorAll('[data-vm-group]').forEach(function (g) {
                    var id = g.getAttribute('data-vm-group');
                    var aberto = g.getAttribute('data-vm-open') === '1' || salvo[id] === 1;
                    aplicar(g, aberto);
                });
            });
            document.addEventListener('click', function (e) {
                var b = e.target.closest('[data-vm-group-toggle]');
                if (!b) return;
                var g = b.closest('[data-vm-group]');
                var aberto = b.getAttribute('aria-expanded') !== 'true';
                aplicar(g, aberto);
                var o = lido(); o[g.getAttribute('data-vm-group')] = aberto ? 1 : 0; gravar(o);
            });
        })();
    </script>

    <script
        type="text/x-template"
        id="v-sidebar-collapse-template"
    >
        <div
            class="fixed bottom-0 w-full max-w-[235px] cursor-pointer border-t border-gray-200 bg-white px-4 transition-all duration-300 hover:bg-gray-100 dark:border-gray-800 dark:bg-gray-900 dark:hover:bg-gray-950 max-lg:hidden"
            :class="{'max-w-[70px]': isCollapsed}"
            :title="isCollapsed
                ? '@lang('admin::app.layouts.sidebar.expand')'
                : '@lang('admin::app.layouts.sidebar.collapse')'"
            @click="toggle"
        >
            <div class="flex items-center gap-2.5 p-1.5">
                <span
                    class="icon-left-arrow text-2xl transition-all"
                    :class="[isCollapsed ? 'ltr:rotate-[180deg] rtl:rotate-[0]' : 'ltr:rotate-[0] rtl:rotate-[180deg]']"
                ></span>
            </div>
        </div>
    </script>

    <script type="module">
        app.component('v-sidebar-collapse', {
            template: '#v-sidebar-collapse-template',

            data() {
                return {
                    isCollapsed: {{ request()->cookie('sidebar_collapsed') ?? 0 }},
                }
            },

            methods: {
                toggle() {
                    this.isCollapsed = parseInt(this.isCollapsedCookie()) ? 0 : 1;

                    var expiryDate = new Date();

                    expiryDate.setMonth(expiryDate.getMonth() + 1);

                    document.cookie = 'sidebar_collapsed=' + this.isCollapsed + '; path=/; expires=' + expiryDate.toGMTString();

                    this.$root.$refs.appLayout.classList.toggle('sidebar-collapsed');

                    this.$root.$refs.appLayout.classList.toggle('sidebar-not-collapsed');
                },

                isCollapsedCookie() {
                    const cookies = document.cookie.split(';');

                    for (const cookie of cookies) {
                        const [name, value] = cookie.trim().split('=');

                        if (name === 'sidebar_collapsed') {
                            return value;
                        }
                    }

                    return 0;
                },
            },
        });
    </script>
@endPushOnce