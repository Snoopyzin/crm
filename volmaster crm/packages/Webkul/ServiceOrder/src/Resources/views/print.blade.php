@php
    // Header: the issuing shop's own identity (Dados Fiscais of whichever
    // tenant prints this). Footer: always Volmaster Tech -- the
    // system's signature, on every shop's documents.
    $fiscal = \Webkul\Fiscal\Models\FiscalSettings::current();

    $shopName = $fiscal->nome_fantasia ?: $fiscal->razao_social ?: (app()->bound('currentTenant') ? app('currentTenant')->name : config('app.name'));

    $cnpjDigits = preg_replace('/\D/', '', (string) $fiscal->cnpj);

    $cnpj = strlen($cnpjDigits) === 14
        ? preg_replace('/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/', '$1.$2.$3/$4-$5', $cnpjDigits)
        : $fiscal->cnpj;

    $logo = 'data:image/png;base64,'.base64_encode(file_get_contents(base_path('packages/Webkul/ServiceOrder/src/Resources/assets/volmaster-logo.png')));

    $brl = fn ($value) => core()->formatBasePrice((float) $value);
    $dash = fn ($value) => filled($value) ? $value : '-';

    // Perfil Técnico: impressão sem valores, descontos, pagamento nem
    // observações da negociação (só serviços, peças, quantidades e tempos).
    $mostrarValores = \Webkul\ServiceOrder\Services\ValoresFinanceiros::can();

    // Client
    $person = $serviceOrder->person;
    $phones = \Webkul\ServiceOrder\Services\ClienteContatos::linhaTelefones($person?->contact_numbers);
    $emails = collect($person?->emails ?? [])->pluck('value')->filter()->implode(' / ');
    $address = \Webkul\ServiceOrder\Services\ClienteContatos::enderecoDe($person?->id) ?? [];
    $addressLine = \Webkul\ServiceOrder\Services\ClienteContatos::linhaEndereco($address);
    $cityLine = collect([$address['city'] ?? null, $address['state'] ?? null])->filter()->implode('/');
    $cep = $address['postcode'] ?? null;

    // Vehicle
    $vehicle = $serviceOrder->vehicle;
    $tipos = ['carros' => 'Carro', 'motos' => 'Moto', 'caminhoes' => 'Caminhão'];

    // Items split by type, each with its own subtotal
    $servicos = $serviceOrder->items->where('tipo', \Webkul\ServiceOrder\Models\ServiceOrderItem::TIPO_SERVICO);
    $pecas = $serviceOrder->items->where('tipo', \Webkul\ServiceOrder\Models\ServiceOrderItem::TIPO_PECA);
    $discountTotal = round((float) $serviceOrder->sub_total - (float) $serviceOrder->grand_total, 2);

    $responsavel = $serviceOrder->user?->name;
@endphp
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>OS #{{ $serviceOrder->id }}</title>
    <style>
        @page { margin: 118px 36px 74px 36px; }

        body { font-family: "DejaVu Sans", sans-serif; font-size: 9.5px; color: #263238; }

        /* Repeated on every page (dompdf renders position:fixed per page). */
        .doc-header { position: fixed; top: -96px; left: 0; right: 0; height: 80px; border-bottom: 2px solid #0E90D9; }
        .doc-header table { margin: 0; border: 0; }
        .doc-header td { border: 0; padding: 0; vertical-align: top; }
        .shop-name { font-size: 16px; font-weight: bold; color: #0b3e7a; }
        .shop-line { font-size: 9px; color: #4b5b66; margin-top: 2px; }
        .doc-id { text-align: right; }
        .doc-id .label { font-size: 9px; color: #607d8b; text-transform: uppercase; letter-spacing: .5px; }
        .doc-id .number { font-size: 18px; font-weight: bold; color: #0E90D9; }
        .doc-id .stage { font-size: 9.5px; font-weight: bold; color: #263238; }

        .doc-footer { position: fixed; bottom: -56px; left: 0; right: 0; height: 40px; border-top: 1px solid #cfd8dc; }
        .doc-footer table { margin: 0; border: 0; }
        .doc-footer td { border: 0; padding: 6px 0 0; vertical-align: middle; font-size: 8.5px; color: #78909c; }
        .doc-footer img { height: 24px; }
        .pagenum:before { content: counter(page); }

        .section { margin-top: 8px; border: 1px solid #cfd8dc; border-radius: 3px; }
        .section-title { background: #eef6fc; color: #0b3e7a; font-weight: bold; font-size: 9.5px; padding: 4px 8px; border-bottom: 1px solid #cfd8dc; text-transform: uppercase; letter-spacing: .3px; }
        table.fields { width: 100%; border-collapse: collapse; }
        table.fields td { padding: 4px 8px; vertical-align: top; border: 0; }
        .k { display: block; font-size: 7.5px; color: #78909c; text-transform: uppercase; letter-spacing: .3px; }
        .v { display: block; font-size: 9.5px; color: #1f2a30; }

        table.items { width: 100%; border-collapse: collapse; }
        table.items th { background: #f6f9fb; color: #4b5b66; font-size: 8px; text-transform: uppercase; text-align: left; padding: 4px 6px; border-bottom: 1px solid #cfd8dc; }
        table.items td { padding: 4px 6px; border-bottom: 1px solid #eceff1; }
        table.items .num { text-align: right; white-space: nowrap; }
        table.items tr.subtotal td { border-bottom: 0; font-weight: bold; background: #fafcfd; }

        table.totals { width: 100%; margin-top: 10px; border-collapse: collapse; }
        table.totals td { padding: 3px 8px; border: 0; }
        table.totals .num { text-align: right; }
        table.totals tr.grand td { border-top: 2px solid #0E90D9; font-size: 12px; font-weight: bold; color: #0b3e7a; padding-top: 5px; }

        .signature { margin-top: 10px; page-break-inside: avoid; }
        .signature td { border: 0; padding: 0 8px; vertical-align: bottom; }
        .line { border-bottom: 1px solid #455a64; height: 22px; }
        .line-label { font-size: 8px; color: #607d8b; padding-top: 3px; }
        .origem { border: 1px solid #0E90D9; border-radius: 3px; padding: 5px 8px; margin-right: 14px; background: #f3f9fe; }
        .origem-q { font-weight: bold; color: #0b3e7a; font-size: 9.5px; margin-bottom: 3px; }
        .origem-opt { display: inline-block; margin-right: 9px; font-size: 9.5px; color: #78909c; }
        .origem-opt.on { color: #0b3e7a; font-weight: bold; }
        .notice { margin-top: 8px; text-align: center; font-weight: bold; font-size: 10px; color: #0b3e7a; }
    </style>
</head>
<body>

    <div class="doc-header">
        <table width="100%">
            <tr>
                <td>
                    <div class="shop-name">{{ $shopName }}</div>

                    @if ($fiscal->razao_social && $fiscal->razao_social !== $shopName)
                        <div class="shop-line">{{ $fiscal->razao_social }}</div>
                    @endif

                    @if ($cnpj)
                        <div class="shop-line">
                            <strong>CNPJ:</strong> {{ $cnpj }}
                            @if ($fiscal->inscricao_estadual) &nbsp;&middot;&nbsp; <strong>IE:</strong> {{ $fiscal->inscricao_estadual }} @endif
                            @if ($fiscal->inscricao_municipal) &nbsp;&middot;&nbsp; <strong>IM:</strong> {{ $fiscal->inscricao_municipal }} @endif
                        </div>
                    @endif
                </td>

                <td class="doc-id" width="190">
                    <div class="label">Ordem de Serviço</div>
                    <div class="number">Nº {{ str_pad((string) $serviceOrder->id, 6, '0', STR_PAD_LEFT) }}</div>
                    <div class="stage">{{ $serviceOrder->workshop_status }}</div>
                    <div class="shop-line">Emitido em {{ now()->format('d/m/Y H:i') }}</div>
                </td>
            </tr>
        </table>
    </div>

    <div class="doc-footer">
        <table width="100%">
            <tr>
                <td width="80"><img src="{{ $logo }}" alt="Volmaster Tech"></td>
                <td>Documento gerado pelo sistema <strong>Volmaster Tech</strong> &middot; volmastercrm.com</td>
                <td style="text-align: right;">Página <span class="pagenum"></span></td>
            </tr>
        </table>
    </div>

    {{-- Cliente --}}
    <div class="section">
        <div class="section-title">Dados do cliente</div>
        <table class="fields">
            <tr>
                <td width="50%"><span class="k">Cliente</span><span class="v"><strong>{{ $dash($person?->name) }}</strong>@if ($person?->codigo) <span style="color:#78909c">(cliente nº {{ $person->codigo }})</span>@endif</span></td>
                <td width="25%"><span class="k">CPF / CNPJ</span><span class="v">{{ $dash($person?->cpf_cnpj) }}</span></td>
                <td width="25%"><span class="k">Telefone</span><span class="v">{{ $dash($phones) }}</span></td>
            </tr>
            <tr>
                <td><span class="k">Endereço</span><span class="v">{{ $dash($addressLine) }}</span></td>
                <td><span class="k">Cidade / UF</span><span class="v">{{ $dash($cityLine) }}</span></td>
                <td><span class="k">CEP</span><span class="v">{{ $dash($cep) }}</span></td>
            </tr>
            @if ($emails)
                <tr>
                    <td colspan="3"><span class="k">E-mail</span><span class="v">{{ $emails }}</span></td>
                </tr>
            @endif
        </table>
    </div>

    {{-- Veículo (software-only services have none) --}}
    @if ($vehicle)
    <div class="section">
        <div class="section-title">Dados do veículo</div>
        <table class="fields">
            <tr>
                <td width="16%"><span class="k">Placa</span><span class="v"><strong>{{ $dash($vehicle?->placa) }}</strong></span></td>
                <td width="20%"><span class="k">Marca</span><span class="v">{{ $dash($vehicle?->marca) }}</span></td>
                <td width="24%"><span class="k">Modelo</span><span class="v">{{ $dash($vehicle?->modelo) }}</span></td>
                <td width="12%"><span class="k">Ano</span><span class="v">{{ $dash($vehicle?->ano) }}</span></td>
                <td width="14%"><span class="k">Cor</span><span class="v">{{ $dash($vehicle?->cor) }}</span></td>
                <td width="14%"><span class="k">Tipo</span><span class="v">{{ $tipos[$vehicle?->tipo] ?? '-' }}</span></td>
            </tr>
            <tr>
                <td colspan="2"><span class="k">Chassi</span><span class="v">{{ $dash($vehicle?->chassi) }}</span></td>
                <td><span class="k">KM</span><span class="v">{{ $vehicle?->km ? number_format((int) $vehicle->km, 0, ',', '.') : '-' }}</span></td>
                <td colspan="3"><span class="k">Frota</span><span class="v">{{ $dash($vehicle?->frota) }}</span></td>
            </tr>
            @if ($serviceOrder->combustivel_entrada || $serviceOrder->descricao_entrada || $serviceOrder->itens_entrada)
                <tr>
                    <td colspan="2"><span class="k">Combustível na entrada</span><span class="v">{{ $dash($serviceOrder->combustivel_entrada) }}</span></td>
                    <td colspan="4"><span class="k">Descrição do veículo / itens deixados</span><span class="v">{{ $dash(collect([$serviceOrder->descricao_entrada, $serviceOrder->itens_entrada])->filter()->implode(' | ')) }}</span></td>
                </tr>
            @endif
        </table>
    </div>
    @endif

    {{-- OS --}}
    <div class="section">
        <div class="section-title">Informações da OS</div>
        <table class="fields">
            <tr>
                <td width="25%"><span class="k">Aberta em</span><span class="v">{{ $serviceOrder->created_at?->format('d/m/Y') }}</span></td>
                <td width="25%"><span class="k">Previsão de entrega</span><span class="v">{{ $serviceOrder->previsaoTexto() ?? '-' }}</span></td>
                <td width="25%"><span class="k">Responsável</span><span class="v">{{ $dash($responsavel) }}</span></td>
                <td width="25%"><span class="k">Mecânico</span><span class="v">{{ $dash($serviceOrder->mechanic?->nome) }}</span></td>
            </tr>
            @if ($serviceOrder->subject || $serviceOrder->description)
                <tr>
                    <td colspan="4"><span class="k">Serviço solicitado / defeito relatado</span><span class="v">{{ collect([$serviceOrder->identificacao(), $serviceOrder->description])->filter()->implode(' — ') }}</span></td>
                </tr>
            @endif
            @if ($serviceOrder->diagnostico)
                <tr>
                    <td colspan="4"><span class="k">Laudo técnico / diagnóstico</span><span class="v">{{ $serviceOrder->diagnostico }}</span></td>
                </tr>
            @endif
        </table>
    </div>

    {{-- Serviços / Peças, each with its own subtotal --}}
    @foreach ([['Serviços', $servicos, true], ['Peças / produtos', $pecas, false]] as [$title, $lines, $isService])
        @if ($lines->isNotEmpty())
            <div class="section">
                <div class="section-title">{{ $title }}</div>
                <table class="items">
                    <thead>
                        <tr>
                            <th width="13%">Código</th>
                            <th>Descrição</th>
                            <th class="num" width="9%">{{ $isService ? 'Horas' : 'Qtd.' }}</th>
                            @if ($mostrarValores)
                                <th class="num" width="14%">Valor unit.</th>
                                <th class="num" width="12%">Desconto</th>
                                <th class="num" width="14%">Total</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($lines as $item)
                            <tr>
                                <td>{{ $dash($item->codigo) }}</td>
                                <td>{{ $item->name }}</td>
                                <td class="num">{{ rtrim(rtrim(number_format($item->effective_quantity, 2, ',', ''), '0'), ',') }}{{ $isService ? 'h' : '' }}</td>
                                @if ($mostrarValores)
                                    <td class="num">{{ $brl($item->price) }}</td>
                                    <td class="num">{{ $item->discount_total > 0 ? '- '.$brl($item->discount_total) : '-' }}</td>
                                    <td class="num">{{ $brl($item->line_total) }}</td>
                                @endif
                            </tr>
                        @endforeach
                        @if ($mostrarValores)
                            <tr class="subtotal">
                                <td colspan="5" class="num">Subtotal {{ mb_strtolower($title) }}</td>
                                <td class="num">{{ $brl($lines->sum(fn ($item) => $item->line_total)) }}</td>
                            </tr>
                        @endif
                    </tbody>
                </table>
            </div>
        @endif
    @endforeach

    {{-- Totais + pagamento --}}
    <table width="100%" style="margin-top: 6px; table-layout: fixed; border-collapse: collapse;">
        <tr>
            <td width="60%" style="vertical-align: top; border: 0; padding: 10px 0 0 0;">
                <div class="origem">
                    <div class="origem-q">Por qual meio você chegou à nossa empresa?</div>
                    @foreach (\Webkul\ServiceOrder\Models\ServiceOrder::ORIGENS as $origem)
                        <span class="origem-opt {{ $serviceOrder->origem_cliente === $origem ? 'on' : '' }}">{{ $serviceOrder->origem_cliente === $origem ? '☒' : '☐' }} {{ $origem }}</span>
                    @endforeach
                </div>

                @if ($mostrarValores)
                    <span class="k" style="margin-top: 8px;">Condição de pagamento</span>
                    <span class="v"><strong>{{ $dash($serviceOrder->condicao_pagamento) }}</strong></span>
                    @if ($serviceOrder->negociacao_observacoes)
                        <span class="k" style="margin-top: 6px;">Observações</span>
                        <span class="v">{{ $serviceOrder->negociacao_observacoes }}</span>
                    @endif
                @endif
            </td>
            <td width="40%" style="border: 0; vertical-align: top; padding: 0;">
                @if ($mostrarValores)
                    <table class="totals">
                        <tr><td>Serviços</td><td class="num">{{ $brl($servicos->sum(fn ($item) => $item->line_total)) }}</td></tr>
                        @if ($pecas->isNotEmpty())
                            <tr><td>Peças / produtos</td><td class="num">{{ $brl($pecas->sum(fn ($item) => $item->line_total)) }}</td></tr>
                        @endif
                        <tr class="grand"><td>Total</td><td class="num">{{ $brl($serviceOrder->grand_total) }}</td></tr>
                        @if ($discountTotal > 0)
                            <tr><td colspan="2" class="num" style="font-size: 8px; color: #607d8b;">Já com {{ $brl($discountTotal) }} de desconto (valor cheio {{ $brl($serviceOrder->sub_total) }})</td></tr>
                        @endif
                    </table>
                @endif
            </td>
        </tr>
    </table>

    {{-- Retirada do veículo --}}
    <div class="section signature">
        @if ($vehicle)
            <div class="section-title">Retirada do veículo <span style="float: right; text-transform: none; letter-spacing: 0;">Guarde este comprovante para retirar o seu veículo.</span></div>
        @else
            <div class="section-title">Aceite do serviço <span style="float: right; text-transform: none; letter-spacing: 0;">Declaro estar de acordo com os serviços e valores acima.</span></div>
        @endif
        <table width="100%" style="margin: 4px 0 8px;">
            <tr>
                <td width="38%"><div class="line"></div><div class="line-label">{{ $vehicle ? 'Nome de quem retirou' : 'Nome' }}</div></td>
                <td width="24%"><div class="line"></div><div class="line-label">Documento (RG / CPF)</div></td>
                <td width="38%"><div class="line"></div><div class="line-label">Assinatura</div></td>
            </tr>
            <tr>
                <td colspan="3" style="padding-top: 8px; font-size: 8.5px; color: #607d8b;">{{ $vehicle ? 'Data da retirada' : 'Data' }}: ____/____/________ &nbsp;&nbsp; Hora: ____:____</td>
            </tr>
        </table>
    </div>

</body>
</html>
