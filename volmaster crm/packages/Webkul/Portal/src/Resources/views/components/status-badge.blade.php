@props(['status'])

@php
    $variant = match ($status) {
        'Autorizado', 'Concluída', 'Convertida', 'Ativo' => 'badge-success',
        'Cancelada', 'Recusada', 'Desativado' => 'badge-danger',
        'Aguardando Peças', 'Em Análise', 'Convidado' => 'badge-warning',
        default => '',
    };
@endphp

<span class="badge {{ $variant }}">{{ $status }}</span>
