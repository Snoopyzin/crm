@php
    /*
     * Link de acompanhamento do cliente (público, assinado). Mostra o caminho do
     * caminhão pelas 9 etapas, as anotações e anexos que a oficina liberou e o
     * histórico. Nada de valores, crédito ou anotações internas.
     */
    $passos = $jornada['passos'];
    $atual = $jornada['atual'];
    $concluida = $jornada['concluida'];
    $total = count($passos);
    $andando = ! $isCancelled && ! $concluida && $atual < 6;
    $veiculo = $serviceOrder->vehicle;
    $marcaModelo = trim(($veiculo->marca ?? '').' '.($veiculo->modelo ?? ''));
    $etapaAtual = $passos[$atual];
    $dataBr = fn ($d) => $d ? $d->format('d/m \à\s H:i') : null;
@endphp
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Acompanhe seu veículo — Volmaster Tech</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#071A33">
    @unless ($isCancelled || $concluida)
        <meta http-equiv="refresh" content="45">
    @endunless
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Chakra+Petch:wght@500;600;700&family=Inter:wght@400;500;600;700&display=swap">
    <style>
        :root { --ink:#F2F6FB; --mut:#8FA6C4; --blue:#0E90D9; --cy:#37d0ff; --ok:#1F9D6B; --card:#10223F; --line:rgba(255,255,255,.08); --row:92px; }
        * { box-sizing: border-box; }
        html { background:#050F20; }
        body { margin:0; color:var(--ink); font-family:Inter,-apple-system,"Segoe UI",sans-serif; min-height:100vh;
            background: radial-gradient(900px 500px at 100% -10%, rgba(14,144,217,.28), transparent 60%), radial-gradient(700px 500px at -20% 40%, rgba(91,75,214,.16), transparent 60%), linear-gradient(180deg,#0B2447 0%,#071A33 45%,#050F20 100%); background-repeat:no-repeat; padding: 20px 16px 56px; }
        .wrap { width:100%; max-width:440px; margin:0 auto; }
        .top { display:flex; align-items:center; justify-content:space-between; padding:2px 4px 18px; }
        .top img { height:38px; display:block; }
        .top span { font-size:11.5px; letter-spacing:.14em; text-transform:uppercase; color:var(--mut); font-weight:600; }
        h1,h2,h3 { font-family:'Chakra Petch',Inter,sans-serif; margin:0; letter-spacing:.01em; }
        .card { background:var(--card); border:1px solid var(--line); border-radius:24px; box-shadow:0 30px 60px -34px rgba(0,0,0,.75), inset 0 1px 0 rgba(255,255,255,.05); }

        .hero { padding:20px 20px 18px; overflow:hidden; }
        .cena { position:relative; margin:-20px -20px 16px; aspect-ratio:16/9; background:#0a1e3b; overflow:hidden; }
        .cena img { width:100%; height:100%; object-fit:cover; display:block; }
        .cena::after { content:""; position:absolute; inset:0; background:linear-gradient(180deg, rgba(5,15,32,0) 55%, rgba(5,15,32,.78)); pointer-events:none; }
        .selo { position:absolute; left:14px; bottom:12px; z-index:2; display:inline-flex; align-items:center; gap:8px; background:rgba(8,20,40,.62); border:1px solid rgba(255,255,255,.18); backdrop-filter:blur(6px); -webkit-backdrop-filter:blur(6px); border-radius:999px; padding:6px 13px 6px 10px; font-size:12.5px; font-weight:600; color:#fff; }
        .selo i { width:9px; height:9px; border-radius:50%; background:#37d0ff; box-shadow:0 0 0 0 rgba(55,208,255,.7); animation:pulso 1.6s infinite; }
        .selo.ok i { background:#5be0a6; animation:none; }
        @keyframes pulso { 70% { box-shadow:0 0 0 8px rgba(55,208,255,0); } 100% { box-shadow:0 0 0 0 rgba(55,208,255,0); } }
        .kicker { font-size:12px; font-weight:700; letter-spacing:.1em; color:#5DB8EE; text-transform:uppercase; }
        .hero h1 { font-size:30px; line-height:1.1; margin:6px 0 6px; }
        .hero p { margin:0; color:#C5D3E6; font-size:15px; line-height:1.45; }
        .veic { display:flex; align-items:center; gap:12px; margin-top:16px; padding-top:16px; border-top:1px solid var(--line); flex-wrap:wrap; }
        .plate { display:inline-flex; flex-direction:column; align-items:center; background:#fff; border:3px solid #0b0f14; border-radius:7px; overflow:hidden; font-family:'Chakra Petch',sans-serif; font-weight:700; line-height:1; flex:none; }
        .plate i { display:block; width:100%; background:#1B4FD6; color:#fff; font-style:normal; font-size:7px; letter-spacing:.2em; text-align:center; padding:2px 0 1px; }
        .plate b { font-size:19px; padding:3px 10px 2px; color:#0b0f14; letter-spacing:.04em; }
        .veic .m { font-size:14px; color:#C5D3E6; font-weight:500; }
        .veic .m small { display:flex; align-items:center; gap:6px; color:var(--mut); font-size:12.5px; margin-top:3px; }
        .sw { width:12px; height:12px; border-radius:50%; display:inline-block; box-shadow:0 0 0 2px rgba(255,255,255,.18); }
        .meta { display:flex; gap:10px; margin-top:14px; }
        .meta div { flex:1; background:rgba(255,255,255,.05); border:1px solid var(--line); border-radius:14px; padding:10px 12px; }
        .meta small { display:block; font-size:10.5px; letter-spacing:.08em; text-transform:uppercase; color:var(--mut); font-weight:700; }
        .meta b { display:block; margin-top:2px; font-size:14px; }

        section { margin-top:22px; }
        section > h2 { font-size:19px; margin:0 4px 12px; }
        section > h2 small { font-family:Inter,sans-serif; font-size:12px; color:var(--mut); font-weight:500; margin-left:8px; letter-spacing:0; }

        /* ---- o caminho do caminhão ---- */
        .caminho { padding:18px 16px 14px; overflow:hidden; }
        .trilha { position:relative; padding-left:0; height:calc(var(--row) * var(--n)); }
        .estrada { position:absolute; left:52px; top:calc(var(--row) / 2); bottom:calc(var(--row) / 2); width:14px; border-radius:8px; background:#1a2f50; box-shadow: inset 0 0 0 1px rgba(255,255,255,.06); }
        .estrada::before { content:""; position:absolute; left:50%; top:0; bottom:0; width:2px; transform:translateX(-50%); background:repeating-linear-gradient(to bottom, rgba(255,255,255,.28) 0 8px, transparent 8px 18px); }
        .feita { position:absolute; left:52px; top:calc(var(--row) / 2); width:14px; border-radius:8px; background:linear-gradient(180deg,#0A6FB0,#37d0ff); box-shadow:0 0 18px rgba(55,208,255,.55); height:0; transition: height 1800ms cubic-bezier(.65,0,.35,1); }
        .feita::before { content:""; position:absolute; left:50%; top:0; bottom:0; width:2px; transform:translateX(-50%); background:repeating-linear-gradient(to bottom, rgba(255,255,255,.85) 0 8px, transparent 8px 18px); background-size:100% 18px; }
        .andando .feita::before { animation: fluxo 520ms linear infinite; }
        @keyframes fluxo { to { background-position: 0 18px; } }
        .parada { position:absolute; left:0; right:0; height:var(--row); display:flex; align-items:center; }
        .ponto { position:absolute; left:46px; width:26px; height:26px; border-radius:50%; background:#0B1B33; border:3px solid #35506f; display:flex; align-items:center; justify-content:center; font-size:13px; color:#fff; z-index:2; }
        .parada.feito .ponto { background:var(--ok); border-color:var(--ok); }
        .parada.atual .ponto { background:var(--blue); border-color:#fff; box-shadow:0 0 0 6px rgba(14,144,217,.28); }
        .info { margin-left:120px; padding-right:4px; }
        .info b { display:block; font-family:'Chakra Petch',sans-serif; font-size:17px; font-weight:600; color:#5d7596; }
        .info small { display:block; margin-top:2px; font-size:12.5px; color:#5d7596; line-height:1.35; }
        .parada.feito .info b { color:#fff; } .parada.feito .info small { color:var(--mut); }
        .parada.atual .info b { color:#fff; font-size:19px; } .parada.atual .info small { color:#C5D3E6; font-size:13px; }
        .info em { font-style:normal; display:inline-block; margin-top:5px; font-size:10.5px; letter-spacing:.1em; text-transform:uppercase; background:rgba(14,144,217,.2); color:#7fd0ff; border-radius:999px; padding:3px 9px; font-weight:700; }
        /* o caminhão (3D) fica sobre a estrada, na etapa atual */
        .caminhao { position:absolute; left:6px; width:106px; z-index:3; top:0; filter:drop-shadow(0 10px 10px rgba(0,0,0,.45)); transition: transform 1800ms cubic-bezier(.65,0,.35,1); will-change:transform; pointer-events:none; }
        .caminhao img { width:100%; display:block; }
        .andando .caminhao img { animation: balanco 300ms ease-in-out infinite alternate; }
        @keyframes balanco { from { transform:translateY(0); } to { transform:translateY(-1.5px); } }

        /* ---- anotações e anexos / histórico ---- */
        .vazio { color:var(--mut); font-size:14px; padding:18px; text-align:center; line-height:1.5; }
        .anexo { padding:16px 16px 14px; margin-bottom:12px; }
        .anexo h3 { font-size:16px; } .anexo .q { font-size:12px; color:var(--mut); margin-top:2px; }
        .anexo p { margin:8px 0 0; font-size:14px; line-height:1.5; color:#D7E2F1; white-space:pre-line; }
        .fotos { display:grid; grid-template-columns:repeat(3,1fr); gap:6px; margin-top:12px; }
        .fotos a { display:block; aspect-ratio:1; border-radius:12px; overflow:hidden; background:#0b1b33; border:1px solid var(--line); }
        .fotos img { width:100%; height:100%; object-fit:cover; display:block; }
        .arq { display:flex; align-items:center; gap:10px; margin-top:8px; padding:10px 12px; border-radius:12px; background:rgba(255,255,255,.05); border:1px solid var(--line); color:#fff; text-decoration:none; font-size:13.5px; font-weight:500; }
        .arq u { text-decoration:none; font-size:18px; }
        .hist { padding:8px 18px; }
        .hist li { list-style:none; position:relative; padding:12px 0 12px 22px; border-bottom:1px solid var(--line); }
        .hist li:last-child { border-bottom:0; }
        .hist li::before { content:""; position:absolute; left:0; top:19px; width:9px; height:9px; border-radius:50%; background:var(--blue); box-shadow:0 0 0 4px rgba(14,144,217,.2); }
        .hist b { display:block; font-size:14.5px; font-weight:600; } .hist small { color:var(--mut); font-size:12px; }
        .hist ul, ul.hist { margin:0; padding:8px 18px; }

        .cancelada { text-align:center; padding:50px 20px; } .cancelada h2 { margin:10px 0 6px; font-size:20px; } .cancelada p { color:#C5D3E6; font-size:14px; margin:0; }
        .rodape { text-align:center; margin-top:26px; font-size:12.5px; color:var(--mut); }

    </style>
</head>
<body>
<div class="wrap">
    <div class="top">
        <img src="{{ asset('img/volmaster-logo-branco.svg') }}" alt="Volmaster Tech">
        <span>Acompanhe seu veículo</span>
    </div>

    @if ($isCancelled)
        <div class="card cancelada">
            <div style="font-size:38px">⏸️</div>
            <h2>Ordem de serviço cancelada</h2>
            <p>Fale com a oficina se isso não fizer sentido pra você.</p>
        </div>
    @else
        <div class="card hero">
            @php
                $chegou = $concluida || $atual >= 6;
                $pasta = 'img/caminhoes/';
            @endphp
            <div class="cena">
                @if ($chegou)
                    <img src="{{ asset($pasta.'chegou-'.$caminhao['slug'].'.jpg') }}" alt="Seu caminhão chegou na oficina">
                    <span class="selo ok"><i></i>Pronto para você</span>
                @else
                    {{-- Imagem animada (WebP): anima em qualquer navegador, sem depender de autoplay, codec ou "reduzir animações". --}}
                    <img id="cena-anim" src="{{ asset($pasta.'rua-'.$caminhao['slug'].'.webp') }}" alt="Seu caminhão andando pela rua até a oficina"
                         onerror="this.onerror=null;this.src='{{ asset($pasta.'rua-'.$caminhao['slug'].'.jpg') }}'">
                    <span class="selo"><i></i>{{ $etapaAtual['titulo'] }}</span>
                @endif
            </div>
            <div class="kicker">OS #{{ $serviceOrder->id }}</div>
            <h1>{{ $concluida ? 'Tudo certo!' : $etapaAtual['titulo'] }}</h1>
            <p>{{ $concluida ? 'Sua ordem de serviço foi concluída. Obrigado pela confiança!' : $etapaAtual['texto'] }}</p>

            <div class="veic">
                <span class="plate"><i>BRASIL</i><b>{{ $veiculo->placa ?? '—' }}</b></span>
                <div class="m">
                    {{ $marcaModelo !== '' ? $marcaModelo : 'Veículo' }}
                    @if (! empty($veiculo->cor))
                        <small><span class="sw" style="background: {{ $caminhao['hex'] }}"></span>{{ ucfirst(mb_strtolower($veiculo->cor)) }}</small>
                    @endif
                </div>
            </div>

            @if ($serviceOrder->tempo_servico || $serviceOrder->data_previsao)
                <div class="meta">
                    @if ($serviceOrder->tempo_servico)
                        <div><small>Tempo estimado</small><b>{{ $serviceOrder->tempo_servico }}</b></div>
                    @endif
                    @if ($serviceOrder->data_previsao)
                        <div><small>Previsão</small><b>{{ $serviceOrder->previsaoTexto() }}</b></div>
                    @endif
                </div>
            @endif
        </div>

        <section>
            <h2>O caminho do seu caminhão</h2>
            <div class="card caminho {{ $andando ? 'andando' : '' }}">
                <div class="trilha" id="trilha" style="--n: {{ $total }}">
                    <div class="estrada"></div>
                    <div class="feita" id="feita"></div>

                    @foreach ($passos as $i => $passo)
                        <div class="parada {{ $passo['estado'] }}" style="top: calc(var(--row) * {{ $i }})">
                            <span class="ponto">{{ $passo['estado'] === 'feito' ? '✓' : '' }}</span>
                            <div class="info">
                                <b>{{ $passo['titulo'] }}</b>
                                @if ($passo['estado'] === 'atual')
                                    <small>{{ $passo['texto'] }}</small>
                                    <em>Agora</em>
                                @elseif ($passo['estado'] === 'feito' && $passo['quando'])
                                    <small>{{ $dataBr($passo['quando']) }}</small>
                                @endif
                            </div>
                        </div>
                    @endforeach

                    <div class="caminhao" id="caminhao" aria-hidden="true">
                        <img src="{{ asset($caminhao['arquivo']) }}" alt="">
                    </div>
                </div>
            </div>
        </section>

        <section>
            <h2>Anotações e anexos</h2>
            @forelse ($anexos as $anexo)
                <div class="card anexo">
                    @if ($anexo['titulo'])<h3>{{ $anexo['titulo'] }}</h3>@endif
                    @if ($anexo['quando'])<div class="q">{{ $dataBr($anexo['quando']) }}</div>@endif
                    @if ($anexo['texto'])<p>{{ $anexo['texto'] }}</p>@endif

                    @php($fotos = collect($anexo['arquivos'])->where('tipo', 'foto'))
                    @if ($fotos->isNotEmpty())
                        <div class="fotos">
                            @foreach ($fotos as $foto)
                                <a href="{{ $foto['url'] }}" target="_blank" rel="noopener"><img src="{{ $foto['url'] }}" alt="{{ $foto['nome'] }}" loading="lazy"></a>
                            @endforeach
                        </div>
                    @endif

                    @foreach (collect($anexo['arquivos'])->where('tipo', '!=', 'foto') as $arq)
                        <a class="arq" href="{{ $arq['url'] }}" target="_blank" rel="noopener"><u>{{ $arq['tipo'] === 'video' ? '▶' : '📎' }}</u>{{ $arq['nome'] }}</a>
                    @endforeach
                </div>
            @empty
                <div class="card vazio">Quando a oficina compartilhar fotos, vídeos e anotações sobre o seu veículo, elas aparecem aqui.</div>
            @endforelse
        </section>

        <section>
            <h2>Histórico</h2>
            @if (count($historico))
                <div class="card">
                    <ul class="hist">
                        @foreach ($historico as $linha)
                            <li><b>{{ $linha['titulo'] }}</b>@if ($linha['quando'])<small>{{ $dataBr($linha['quando']) }}</small>@endif</li>
                        @endforeach
                    </ul>
                </div>
            @else
                <div class="card vazio">O histórico do seu veículo vai aparecendo aqui, passo a passo.</div>
            @endif
        </section>

        <script>
            (function () {
                // O caminhão sai da etapa anterior e anda até a atual; a estrada se acende por onde ele passou.
                var ROW = 92, atual = {{ $atual }}, concluida = {{ $concluida ? 'true' : 'false' }};
                var alvo = concluida ? {{ $total - 1 }} : atual;
                var caminhao = document.getElementById('caminhao'), feita = document.getElementById('feita');
                var reduzir = false;   // o caminhão andando é o ponto da página: sempre anima
                function y(i) { return i * ROW + (ROW - caminhao.offsetHeight) / 2; }
                function pos(i) { caminhao.style.transform = 'translateY(' + y(i) + 'px)'; feita.style.height = (i * ROW) + 'px'; }
                caminhao.style.transition = 'none'; feita.style.transition = 'none';
                pos(reduzir ? alvo : Math.max(0, alvo - 1));
                var img = caminhao.querySelector('img');
                function andar() { requestAnimationFrame(function () { requestAnimationFrame(function () {
                    caminhao.style.transition = ''; feita.style.transition = ''; pos(alvo);
                    @unless ($andando)
                        setTimeout(function () { document.querySelector('.caminho').classList.remove('andando'); }, reduzir ? 0 : 1800);
                    @endunless
                }); }); }
                if (img.complete) { pos(reduzir ? alvo : Math.max(0, alvo - 1)); andar(); } else { img.onload = function () { pos(reduzir ? alvo : Math.max(0, alvo - 1)); andar(); }; }
            })();
        </script>
    @endif

    <p class="rodape">Dúvidas? Fale com a oficina pelo WhatsApp.</p>
</div>
</body>
</html>
