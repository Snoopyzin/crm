<x-admin::layouts>
    <x-slot:title>
        Impact
    </x-slot>

    <div class="flex flex-col gap-4">
        <div class="box-shadow rounded-lg border border-gray-200 bg-white p-6 dark:border-gray-800 dark:bg-gray-900">
            <p class="text-xl font-bold text-gray-800 dark:text-white">Impact</p>

            <p id="impact-status" class="mt-2 text-sm text-gray-600 dark:text-gray-300">
                Abrindo o Portal Impact neste computador...
            </p>

            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                Na primeira vez o navegador pergunta se pode abrir o Portal: marque "Sempre permitir" e clique em "Abrir".
            </p>

            <div class="mt-4 flex flex-wrap items-center gap-2.5">
                <a href="volmaster-impact://abrir" id="impact-open" class="primary-button">Abrir o Impact</a>
            </div>

            <div id="impact-help" class="mt-5 hidden rounded-lg border px-4 py-3 text-sm" style="background:rgba(234,179,8,.12);border-color:rgba(234,179,8,.5);color:#a16207">
                <p class="font-semibold">O Portal não abriu?</p>
                <ul class="mt-1 list-disc pl-5 text-xs">
                    <li>Este computador ainda não foi habilitado para o Impact. A habilitação é feita pela equipe da Volmaster Tech na instalação do Portal.</li>
                    <li>Se o navegador mostrou um aviso e você clicou em "Cancelar", clique em "Abrir o Impact" de novo e escolha "Abrir".</li>
                    <li>Continua sem abrir? Fale com o suporte da Volmaster Tech.</li>
                </ul>
            </div>
        </div>
    </div>

    @push('scripts')
        <script>
            // Fire the volmaster-impact:// link (registered on each enabled PC
            // by impact/instalar-atalho-impact.cmd). If the page never loses
            // focus, nothing handled the link -> show the help box.
            (function () {
                var opened = false;

                window.addEventListener('blur', function () { opened = true; });
                document.addEventListener('visibilitychange', function () { if (document.hidden) { opened = true; } });

                function tryOpen() {
                    opened = false;
                    window.location.href = 'volmaster-impact://abrir';

                    setTimeout(function () {
                        var status = document.getElementById('impact-status');
                        var help = document.getElementById('impact-help');

                        if (opened) {
                            status && (status.textContent = 'Portal Impact aberto. Se ele não apareceu na frente, confira a barra de tarefas.');
                        } else {
                            status && (status.textContent = 'Não foi possível confirmar que o Portal abriu.');
                            help && help.classList.remove('hidden');
                        }
                    }, 2500);
                }

                document.addEventListener('click', function (event) {
                    if (event.target.closest && event.target.closest('#impact-open')) {
                        event.preventDefault();
                        tryOpen();
                    }
                });

                window.addEventListener('load', function () { setTimeout(tryOpen, 300); });
            })();
        </script>
    @endpush
</x-admin::layouts>
