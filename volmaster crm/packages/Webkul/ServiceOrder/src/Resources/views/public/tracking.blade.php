<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Acompanhe seu veículo — Volmaster Tech</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    @unless ($isCancelled || $currentStep >= count($steps) - 1)
        <meta http-equiv="refresh" content="45">
    @endunless
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700;800&display=swap">
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            background: #f3f4f6;
            color: #1f2937;
            font-family: Roboto, -apple-system, "Segoe UI", sans-serif;
            padding: 32px 16px 60px;
        }
        .card {0
            background: #fff;
            border-radius: 16px;
            width: 100%;
            max-width: 420px;
            margin: 0 auto;
            box-shadow: 0 4px 24px rgba(0,0,0,.06);
            padding: 22px 20px 26px;
            overflow: hidden;
        }

        .brand { display: flex; align-items: center; gap: 8px; margin-bottom: 18px; }
        .brand-dot { width: 9px; height: 9px; border-radius: 999px; background: #0E90D9; }
        .brand-name { font-weight: 700; font-size: 12.5px; color: #5c6b7a; letter-spacing: .03em; }

        .hero { background: #fff; border-radius: 20px; padding: 20px 18px 18px; box-shadow: 0 10px 28px rgba(16,40,60,.08); }
        .stage-kicker { font-size: 11.5px; font-weight: 700; color: #0E90D9; letter-spacing: .04em; text-transform: uppercase; }
        .stage-headline { font-size: 22px; font-weight: 800; margin: 3px 0 2px; letter-spacing: -.01em; }
        .stage-sub { font-size: 13px; color: #6b7280; margin: 0; }

        .progress-track { position: relative; height: 4px; background: #eaeff3; border-radius: 999px; margin: 18px 0 10px; }
        .progress-fill { position: absolute; top: 0; left: 0; height: 100%; background: #0E90D9; border-radius: 999px; transition: width 600ms ease; }

        .dots { display: flex; justify-content: center; gap: 6px; margin: 4px 0 0; }
        .dot { width: 6px; height: 6px; border-radius: 999px; background: #dbe3ea; }
        .dot.done { background: #0E90D9; }
        .dot.current { background: #0E90D9; width: 16px; }

        .vehicle-row { display: flex; justify-content: space-between; align-items: center; background: #fff; border-radius: 14px; padding: 12px 14px; margin-top: 14px; box-shadow: 0 6px 18px rgba(16,40,60,.06); }
        .vehicle-plate { font-family: "Courier New", monospace; font-weight: 700; font-size: 13px; background: #111827; color: #fff; padding: 4px 9px; border-radius: 6px; letter-spacing: .05em; }
        .vehicle-model { font-size: 12px; color: #4b5563; }

        .meta-row { display: flex; gap: 10px; margin-top: 10px; }
        .meta-card { flex: 1; background: #fff; border-radius: 14px; padding: 12px 14px; box-shadow: 0 6px 18px rgba(16,40,60,.06); }
        .meta-card .k { font-size: 10.5px; color: #8a97a6; text-transform: uppercase; letter-spacing: .04em; font-weight: 700; }
        .meta-card .v { font-size: 13.5px; font-weight: 700; margin-top: 2px; }

        .cancelled { text-align: center; padding: 60px 10px; }
        .cancelled-icon { font-size: 38px; }
        .cancelled h2 { margin: 10px 0 4px; font-size: 18px; }
        .cancelled p { font-size: 13px; color: #6b7280; margin: 0; }

        .footer-hint { text-align: center; margin-top: 20px; font-size: 12px; color: #9ca3af; }

        .road-stage {
            position: relative; margin: 14px -18px 0; height: 150px;
            background: #d3e8f6; overflow: hidden;
        }
        .road-stage svg { display: block; width: 100%; height: 100%; }

        /* --- Flat truck scene ---
           Positioning (#truck-g, JS-controlled) and the "bob" wobble (CSS
           animation) live on different elements on purpose: a running CSS
           animation on `transform` replaces the whole property -- including
           an inline-JS-set translate -- on whichever element it's attached
           to, so the two can never share one element. While the service is
           still going ("rolling"), wheels, road and clouds keep moving like a
           looping animation; at the last stage the truck parks at the flag. */
        #truck-g { transition: transform 1800ms cubic-bezier(.65,0,.35,1); }
        .scene.rolling .bob-wrap { animation: bob 260ms ease-in-out infinite alternate; }
        @keyframes bob { from { transform: translateY(0); } to { transform: translateY(-1.2px); } }

        .wheel-spin { transform-origin: center; transform-box: fill-box; }
        .scene.rolling .wheel-spin { animation: spin 420ms linear infinite; }
        @keyframes spin { to { transform: rotate(360deg); } }

        #road-dashes { stroke-dasharray: 14 10; }
        .scene.rolling #road-dashes { animation: dashes 420ms linear infinite; }
        @keyframes dashes { to { stroke-dashoffset: 24; } }

        .cloud { animation: drift linear infinite; }
        .cloud.c1 { animation-duration: 38s; }
        .cloud.c2 { animation-duration: 52s; animation-delay: -20s; }
        .cloud.c3 { animation-duration: 45s; animation-delay: -34s; }
        @keyframes drift { from { transform: translateX(440px); } to { transform: translateX(-120px); } }

        #headlight-glow { opacity: 0; transition: opacity 400ms ease; }
        #truck-g.lit #headlight-glow { opacity: 1; }

        #exhaust circle { transform-box: fill-box; transform-origin: center; animation: puff 900ms ease-out infinite; }
        #exhaust circle:nth-child(2) { animation-delay: 300ms; }
        #exhaust circle:nth-child(3) { animation-delay: 600ms; }
        .scene:not(.rolling) #exhaust { opacity: 0; }
        @keyframes puff {
            0%   { opacity: .5; transform: translate(0,0) scale(.4); }
            100% { opacity: 0;  transform: translate(-12px,-9px) scale(1.4); }
        }

        @media (prefers-reduced-motion: reduce) {
            #truck-g { transition: none; }
            .scene.rolling .bob-wrap, .scene.rolling .wheel-spin, .scene.rolling #road-dashes, .cloud, #exhaust circle { animation: none; }
        }
    </style>
</head>
<body>
    <div class="card">
        <div class="brand">
            <span class="brand-dot"></span>
            <span class="brand-name">VOLMASTER TECH</span>
        </div>

        @if ($isCancelled)
            <div class="cancelled">
                <div class="cancelled-icon">⏸️</div>
                <h2>Ordem de serviço cancelada</h2>
                <p>Fale com a oficina se isso não fizer sentido pra você.</p>
            </div>
        @else
            @php
                $subs = [
                    'Seu veículo chegou na oficina.',
                    'Nosso mecânico está avaliando o problema.',
                    'Aguardando sua aprovação pra seguir.',
                    'Serviço em andamento agora.',
                    'Fazendo o teste de rodagem final.',
                    'Pode vir buscar quando quiser!',
                ];
                $lit = [false, false, false, true, true, true];
                $startIndex = max(0, $currentStep - 1);

                // Caminhão do cliente: cor, marca e tipo vêm do cadastro do veículo.
                $veiculo = $serviceOrder->vehicle;
                $silhueta = \Webkul\ServiceOrder\Services\TruckSvg::silhueta($veiculo->marca ?? null, $veiculo->modelo ?? null, $veiculo->tipo ?? null);
                $caminhaoSvg = \Webkul\ServiceOrder\Services\TruckSvg::render($veiculo->cor ?? null, $veiculo->marca ?? null, $silhueta);
            @endphp

            <div class="hero">
                <div class="stage-kicker">OS #{{ $serviceOrder->id }}</div>
                <div class="stage-headline">{{ $steps[$currentStep] }}</div>
                <p class="stage-sub">{{ $subs[$currentStep] ?? '' }}</p>

                <div class="road-stage">
                    <svg class="scene" id="scene" viewBox="0 0 400 150" preserveAspectRatio="xMidYMax meet" role="img" aria-label="Caminhão indo da oficina até a entrega">
                        {{-- Sky + drifting clouds --}}
                        <rect x="0" y="0" width="400" height="150" fill="#d3e8f6"></rect>

                        <g fill="#f3f8fd">
                            <g class="cloud c1"><g transform="translate(0,22)">
                                <ellipse cx="22" cy="8" rx="22" ry="7"></ellipse><ellipse cx="14" cy="4" rx="10" ry="7"></ellipse><ellipse cx="28" cy="2" rx="11" ry="8"></ellipse>
                            </g></g>
                            <g class="cloud c2"><g transform="translate(0,44)">
                                <ellipse cx="26" cy="8" rx="26" ry="7.5"></ellipse><ellipse cx="17" cy="3" rx="12" ry="8"></ellipse><ellipse cx="34" cy="2" rx="12" ry="9"></ellipse>
                            </g></g>
                            <g class="cloud c3"><g transform="translate(0,14)">
                                <ellipse cx="18" cy="7" rx="18" ry="6"></ellipse><ellipse cx="11" cy="3" rx="8" ry="6"></ellipse><ellipse cx="23" cy="2" rx="9" ry="7"></ellipse>
                            </g></g>
                        </g>

                        {{-- Grass, trees, road --}}
                        <rect x="0" y="104" width="400" height="9" fill="#64a85a"></rect>
                        <g transform="translate(66,104)">
                            <rect x="-1" y="-5" width="2" height="5" fill="#7a5a3a"></rect>
                            <path d="M0,-26 L7,-13 L4,-13 L9,-5 L-9,-5 L-4,-13 L-7,-13 Z" fill="#2f8a3b"></path>
                        </g>
                        <g transform="translate(78,104)">
                            <rect x="-.8" y="-4" width="1.6" height="4" fill="#7a5a3a"></rect>
                            <path d="M0,-18 L5,-9 L3,-9 L6.5,-4 L-6.5,-4 L-3,-9 L-5,-9 Z" fill="#3d9b48"></path>
                        </g>
                        <ellipse cx="352" cy="104" rx="8" ry="4.6" fill="#7cb342"></ellipse>
                        <ellipse cx="361" cy="104" rx="6" ry="3.6" fill="#8bc34a"></ellipse>

                        <rect x="0" y="112" width="400" height="38" fill="#5a5c60"></rect>
                        <line id="road-dashes" x1="-30" y1="131" x2="430" y2="131" stroke="#ffffff" stroke-width="2"></line>

                        {{-- Oficina (start) --}}
                        <g>
                            <rect x="6" y="78" width="44" height="30" fill="#eef1f4"></rect>
                            <path d="M2,79 L28,64 L54,79 Z" fill="#1f7fe0"></path>
                            <rect x="15" y="80.5" width="26" height="5.4" rx="1" fill="#0E90D9"></rect>
                            <text x="28" y="84.6" font-size="4.2" font-weight="800" fill="#ffffff" text-anchor="middle" font-family="Roboto, sans-serif">OFICINA</text>
                            <rect x="13" y="88" width="30" height="20" fill="#9aa6b2"></rect>
                            <g stroke="#8592a0" stroke-width=".8">
                                <line x1="13" y1="92" x2="43" y2="92"></line>
                                <line x1="13" y1="96" x2="43" y2="96"></line>
                                <line x1="13" y1="100" x2="43" y2="100"></line>
                                <line x1="13" y1="104" x2="43" y2="104"></line>
                            </g>
                        </g>

                        {{-- Finish flag --}}
                        <g transform="translate(382,0)">
                            <line x1="0" y1="72" x2="0" y2="108" stroke="#4b5563" stroke-width="1.6"></line>
                            <rect x=".8" y="72" width="13" height="9" fill="#ffffff"></rect>
                            <g fill="#1f2937">
                                <rect x=".8" y="72" width="3.25" height="3"></rect><rect x="7.3" y="72" width="3.25" height="3"></rect>
                                <rect x="4.05" y="75" width="3.25" height="3"></rect><rect x="10.55" y="75" width="3.25" height="3"></rect>
                                <rect x=".8" y="78" width="3.25" height="3"></rect><rect x="7.3" y="78" width="3.25" height="3"></rect>
                            </g>
                        </g>

                        {{-- Caminhão do cliente (vista lateral, vira para a direita). Unidades locais: 0..148 de largura, chão em y=60. --}}
                        <g id="truck-g" transform="translate(8,41)">
                            <g class="bob-wrap">
                                <g transform="scale(1.45)">
                                    {!! $caminhaoSvg !!}
                                </g>
                            </g>
                        </g>
                    </svg>
                </div>

                <div class="dots">
                    @foreach ($steps as $index => $label)
                        <span class="dot {{ $index < $currentStep ? 'done' : '' }} {{ $index === $currentStep ? 'current' : '' }}"></span>
                    @endforeach
                </div>
            </div>

            <div class="vehicle-row">
                <span class="vehicle-plate">{{ $serviceOrder->vehicle->placa ?? '-' }}</span>
                <span class="vehicle-model">{{ $serviceOrder->vehicle->marca ?? '' }} {{ $serviceOrder->vehicle->modelo ?? '' }}</span>
            </div>

            @if ($serviceOrder->tempo_servico || $serviceOrder->data_previsao)
                <div class="meta-row">
                    @if ($serviceOrder->tempo_servico)
                        <div class="meta-card"><div class="k">Tempo estimado</div><div class="v">{{ $serviceOrder->tempo_servico }}</div></div>
                    @endif
                    @if ($serviceOrder->data_previsao)
                        <div class="meta-card"><div class="k">Previsão</div><div class="v">{{ $serviceOrder->previsaoTexto() }}</div></div>
                    @endif
                </div>
            @endif

            <script>
                (function () {
                    // Truck's left edge travels from the oficina to the flag;
                    // it drives from the previous stage to the current one on
                    // load, and keeps "rolling" (wheels, road, smoke) until the
                    // last stage, where it parks.
                    var TRACK_START = 8;
                    var TRACK_END = 168;
                    var TRUCK_Y = 41;
                    var DRIVE_MS = 1800;

                    var lit = @json($lit);
                    var startIndex = {{ $startIndex }};
                    var currentIndex = {{ $currentStep }};
                    var total = {{ count($steps) }};
                    var isLast = currentIndex >= total - 1;

                    var scene = document.getElementById('scene');
                    var truckG = document.getElementById('truck-g');

                    function xFor(index) {
                        return TRACK_START + (total > 1 ? index / (total - 1) : 1) * (TRACK_END - TRACK_START);
                    }

                    function place(index) {
                        truckG.classList.toggle('lit', !!lit[index]);
                        truckG.style.transform = 'translate(' + xFor(index) + 'px, ' + TRUCK_Y + 'px)';
                    }

                    var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

                    truckG.style.transition = 'none';
                    place(reduceMotion ? currentIndex : startIndex);

                    if (!isLast || startIndex !== currentIndex) {
                        scene.classList.add('rolling');
                    }

                    requestAnimationFrame(function () {
                        requestAnimationFrame(function () {
                            truckG.style.transition = '';

                            if (!reduceMotion && startIndex !== currentIndex) {
                                place(currentIndex);
                            }

                            if (isLast) {
                                setTimeout(function () { scene.classList.remove('rolling'); }, reduceMotion ? 0 : DRIVE_MS);
                            }
                        });
                    });
                })();
            </script>
        @endif
    </div>

    <p class="footer-hint">Dúvidas? Fale com a oficina pelo WhatsApp.</p>
</body>
</html>
