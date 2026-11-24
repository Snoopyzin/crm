@php
    $campo = 'rounded-md border border-gray-300 px-2 py-1.5 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white';
    $rotulo = 'text-xs text-gray-500 dark:text-gray-400';
    $status = ['agendado' => 'bg-blue-100 text-blue-700', 'chegou' => 'bg-green-100 text-green-700', 'faltou' => 'bg-red-100 text-red-700', 'cancelado' => 'bg-gray-100 text-gray-600'];
@endphp

<x-admin::layouts>
    <x-slot:title>
        Agenda
    </x-slot>

    <div class="flex flex-col gap-4">
        <div class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-gray-200 bg-white px-4 py-3 dark:border-gray-800 dark:bg-gray-900">
            <div>
                <div class="text-xl font-bold dark:text-white">Agenda de {{ $dia->format('d/m/Y') }}</div>
                <p class="text-sm text-gray-500">Capacidade do dia: {{ $capacidade }} atendimentos (20% reservado a encaixes). Ocupação: <b class="{{ $ocupacao > 80 ? 'text-red-600' : '' }}">{{ number_format($ocupacao, 0) }}%</b></p>
            </div>
            <form method="GET"><input type="date" name="dia" value="{{ $dia->toDateString() }}" onchange="this.form.submit()" class="{{ $campo }}" /></form>
        </div>

        <div class="box-shadow rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
            <form method="POST" action="{{ route('admin.rotina.agendar') }}" class="flex flex-wrap items-end gap-3">
                @csrf
                <div class="flex flex-col gap-1"><label class="{{ $rotulo }}">Data e hora</label><input type="datetime-local" name="agendado_para" required value="{{ $dia->copy()->setTime(8, 0)->format('Y-m-d\TH:i') }}" class="{{ $campo }}" /></div>
                <div class="flex flex-col gap-1"><label class="{{ $rotulo }}">Cliente</label><input type="text" name="cliente" required class="w-56 {{ $campo }}" /></div>
                <div class="flex flex-col gap-1"><label class="{{ $rotulo }}">Telefone</label><input type="text" name="telefone" class="w-40 {{ $campo }}" /></div>
                <div class="flex flex-col gap-1"><label class="{{ $rotulo }}">Placa</label><input type="text" name="placa" class="w-28 {{ $campo }}" /></div>
                <div class="flex flex-col gap-1"><label class="{{ $rotulo }}">Queixa</label><input type="text" name="queixa" maxlength="500" class="w-72 {{ $campo }}" /></div>
                <label class="flex items-center gap-1 pb-2 text-xs dark:text-white"><input type="hidden" name="encaixe" value="0" /><input type="checkbox" name="encaixe" value="1" /> Encaixe</label>
                <button type="submit" class="primary-button">Agendar</button>
            </form>
        </div>

        <div class="box-shadow rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
            @forelse ($agendamentos as $a)
                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-100 py-2 text-sm dark:border-gray-800">
                    <span class="dark:text-white"><b>{{ \Carbon\Carbon::parse($a->agendado_para)->format('H:i') }}</b> {{ $a->cliente }} {{ $a->placa ? '· '.$a->placa : '' }} {{ $a->encaixe ? '· encaixe' : '' }}<span class="block text-xs text-gray-500">{{ $a->queixa }}</span></span>
                    <span class="flex items-center gap-2">
                        <span class="rounded px-1.5 py-0.5 text-xs {{ $status[$a->status] }}">{{ $a->status }}</span>
                        @if ($a->status === 'agendado')
                            <form method="POST" action="{{ route('admin.rotina.agenda.status', $a->id) }}" class="flex gap-1">@csrf
                                <button name="status" value="chegou" class="primary-button">Chegou</button>
                                <button name="status" value="faltou" class="secondary-button">Faltou</button>
                                <button name="status" value="cancelado" class="secondary-button">Cancelar</button>
                            </form>
                        @endif
                    </span>
                </div>
            @empty
                <p class="text-sm text-gray-500">Nenhum atendimento agendado neste dia.</p>
            @endforelse
        </div>
    </div>
</x-admin::layouts>
