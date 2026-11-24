<x-admin::layouts>
    <x-slot:title>
        Recomendações
    </x-slot>

    <div class="flex flex-col gap-4">
        <div class="flex items-center justify-between rounded-lg border border-gray-200 bg-white px-4 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300">
            <div class="text-xl font-bold dark:text-white">
                Recomendações
            </div>
        </div>

        <div class="box-shadow flex flex-col items-center gap-2 rounded-lg border border-gray-200 bg-white p-12 text-center dark:border-gray-800 dark:bg-gray-900">
            <span class="icon-settings-warehouse text-4xl text-gray-400"></span>

            <p class="text-base font-semibold text-gray-800 dark:text-white">
                Em breve
            </p>

            <p class="max-w-md text-sm text-gray-500 dark:text-gray-400">
                Aqui vai entrar uma rede de oficinas parceiras que a Volmaster recomenda -- uma funcionalidade planejada para o futuro.
            </p>
        </div>
    </div>
</x-admin::layouts>
