<x-admin::layouts>
    <x-slot:title>
        Clientes
    </x-slot>

    <div class="flex flex-col gap-4 px-4 py-4 sm:px-8">
        <div class="flex items-center justify-between">
            <p class="text-2xl font-bold text-gray-800 dark:text-white">Clientes cadastrados</p>

            @if (Route::has('tenant.signup.create'))
                <a href="{{ route('tenant.signup.create') }}" target="_blank" class="primary-button">Nova oficina</a>
            @endif
        </div>

        <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-gray-800">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-800">
                <thead class="bg-gray-50 dark:bg-gray-900">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase text-gray-500">Oficina</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase text-gray-500">Subdomínio</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase text-gray-500">Admin</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase text-gray-500">Status</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase text-gray-500">Criado em</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase text-gray-500">Nota fiscal</th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-gray-200 bg-white dark:divide-gray-800 dark:bg-gray-900">
                    @forelse ($tenants as $tenant)
                        @php
                            $statusColors = [
                                'active' => 'bg-green-100 text-green-700 dark:bg-green-900 dark:text-green-300',
                                'pending' => 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300',
                                'provisioning' => 'bg-blue-100 text-blue-700 dark:bg-blue-900 dark:text-blue-300',
                                'failed' => 'bg-red-100 text-red-700 dark:bg-red-900 dark:text-red-300',
                                'suspended' => 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900 dark:text-yellow-300',
                            ];
                        @endphp

                        <tr>
                            <td class="px-4 py-3 text-sm text-gray-800 dark:text-white">{{ $tenant->name }}</td>

                            <td class="px-4 py-3 text-sm">
                                <a
                                    href="https://{{ $tenant->subdomain }}.{{ parse_url((string) config('app.url'), PHP_URL_HOST) }}"
                                    target="_blank"
                                    class="text-brandColor hover:underline"
                                >
                                    {{ $tenant->subdomain }}
                                </a>
                            </td>

                            <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-300">
                                {{ $tenant->admin_name }}<br>
                                <span class="text-xs text-gray-400">{{ $tenant->admin_email }}</span>
                            </td>

                            <td class="px-4 py-3 text-sm">
                                <span class="rounded-full px-2.5 py-0.5 text-xs font-semibold {{ $statusColors[$tenant->status] ?? '' }}">
                                    {{ $tenant->status }}
                                </span>

                                @if ($tenant->status === 'failed' && $tenant->provisioning_error)
                                    <p class="mt-1 max-w-xs text-xs text-red-500">{{ $tenant->provisioning_error }}</p>
                                @endif
                            </td>

                            <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-300">{{ $tenant->created_at }}</td>

                            <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-300">
                                @include('tenant::admin.fiscal-request', ['fiscalRequest' => $fiscalRequests[$tenant->id] ?? null])
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-4 py-6 text-center text-sm text-gray-400">Nenhum cliente cadastrado ainda.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-admin::layouts>
