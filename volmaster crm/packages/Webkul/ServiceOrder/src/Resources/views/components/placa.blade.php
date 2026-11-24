{{--
    Placa do veículo desenhada como placa de verdade: Mercosul (faixa azul, ABC1D23) ou modelo
    antigo (faixa cinza, ABC-1234). Estilo direto no HTML de propósito: não depende de classe
    nova do Tailwind (o CSS compilado do servidor só tem as classes que já existiam).

    Variáveis: $placa (texto da placa) e $escala (opcional; 1 = tamanho normal).
--}}
@php
    $placaBruta = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) ($placa ?? '')) ?? '');
    // Mercosul: a 5ª posição é letra (ABC1D23). Com número (ABC1234) é o modelo antigo.
    $placaMercosul = (bool) preg_match('/^[A-Z]{3}\d[A-Z]\d{2}$/', $placaBruta);
    $placaAntiga = (bool) preg_match('/^[A-Z]{3}\d{4}$/', $placaBruta);
    $placaTexto = $placaAntiga ? substr($placaBruta, 0, 3).'-'.substr($placaBruta, 3) : $placaBruta;
    $placaEscala = (float) ($escala ?? 1);
@endphp

@if ($placaBruta === '')
    <span style="opacity:.65;font-size:{{ round(12 * $placaEscala) }}px">sem placa</span>
@else
    <span
        class="placa-veiculo"
        title="Placa {{ $placaTexto }}"
        style="display:inline-block;min-width:{{ round(88 * $placaEscala) }}px;border:2px solid #111827;border-radius:6px;background:#ffffff;color:#111827;text-align:center;overflow:hidden;line-height:1;vertical-align:middle;box-shadow:0 1px 3px rgba(0,0,0,.3)"
    >
        <span style="display:block;background:{{ $placaMercosul ? '#1d4ed8' : '#6b7280' }};color:#ffffff;font-size:{{ round(7 * $placaEscala) }}px;font-weight:700;letter-spacing:.2em;padding:1px 0">BRASIL</span>
        <span style="display:block;font-family:'Arial Narrow',Arial,Helvetica,sans-serif;font-size:{{ round(18 * $placaEscala) }}px;font-weight:800;letter-spacing:.06em;padding:2px 6px 3px;color:#111827">{{ $placaTexto }}</span>
    </span>
@endif
