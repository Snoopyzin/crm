<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Termo de entrada do veículo</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <style>
        body { font-family: -apple-system, Segoe UI, Roboto, sans-serif; background: #f3f4f6; color: #1f2937; margin: 0; padding: 16px; }
        .card { background: #fff; border-radius: 12px; padding: 24px; width: 100%; max-width: 560px; margin: 0 auto; box-shadow: 0 1px 3px rgba(0,0,0,.1); box-sizing: border-box; }
        h1 { font-size: 20px; margin: 0 0 4px; }
        h2 { font-size: 14px; margin: 18px 0 6px; text-transform: uppercase; color: #6b7280; letter-spacing: .04em; }
        p.subtitle { color: #6b7280; margin: 0 0 12px; font-size: 14px; }
        dl { display: grid; grid-template-columns: 140px 1fr; gap: 6px 12px; margin: 0; font-size: 14px; }
        dt { color: #6b7280; }
        dd { margin: 0; font-weight: 600; word-break: break-word; }
        .termo { font-size: 14px; line-height: 1.5; background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px; padding: 12px; }
        label { display: block; font-size: 14px; margin: 10px 0 4px; }
        input[type=text] { width: 100%; box-sizing: border-box; border-radius: 8px; border: 1px solid #d1d5db; padding: 10px; font-size: 16px; }
        .opcoes { display: flex; gap: 16px; font-size: 15px; }
        .opcoes label { display: flex; align-items: center; gap: 6px; margin: 0; }
        canvas { width: 100%; height: 160px; border: 1px dashed #9ca3af; border-radius: 8px; background: #fff; touch-action: none; }
        .linha { display: flex; justify-content: space-between; align-items: center; margin-top: 4px; font-size: 12px; color: #6b7280; }
        button { cursor: pointer; font-family: inherit; }
        button.limpar { border: 0; background: none; color: #2563eb; font-size: 13px; }
        button.enviar { width: 100%; margin-top: 16px; padding: 14px; border-radius: 8px; border: none; background: #15803d; color: #fff; font-size: 16px; font-weight: 700; }
        .erro { background: #fef2f2; color: #b91c1c; border-radius: 8px; padding: 10px 12px; font-size: 14px; margin-bottom: 12px; }
        .ok { background: #f0fdf4; color: #166534; border-radius: 8px; padding: 14px; font-size: 16px; font-weight: 600; }
    </style>
</head>
<body>
    <div class="card">
        <h1>Termo de entrada do veículo</h1>
        <p class="subtitle">Confira os dados do seu veículo e aceite o termo para seguirmos com o atendimento.</p>

        <h2>Veículo</h2>
        <dl>
            <dt>Placa</dt><dd>{{ $os->vehicle?->placa ?? '—' }}</dd>
            <dt>Veículo</dt><dd>{{ trim(($os->vehicle?->marca ?? '').' '.($os->vehicle?->modelo ?? '')) ?: '—' }}</dd>
            <dt>Km de entrada</dt><dd>{{ $os->km_entrada !== null ? number_format((int) $os->km_entrada, 0, ',', '.') : '—' }}</dd>
            <dt>Combustível</dt><dd>{{ $os->combustivel_entrada ?: '—' }}</dd>
            <dt>Itens e avarias</dt><dd>{{ $os->itens_entrada ?: '—' }}</dd>
            <dt>Fotos tiradas</dt><dd>{{ $fotos }}</dd>
        </dl>

        @if ($termo->aceito_em)
            <h2>Termo aceito</h2>
            <p class="ok">Obrigado! O termo foi aceito em {{ $termo->aceito_em->format('d/m/Y H:i') }}. Você não precisa fazer mais nada.</p>
        @else
            <h2>Termo</h2>
            <div class="termo">{{ $texto }}</div>

            <form method="POST" action="{{ route('service_orders.termo.aceitar', $token) }}" id="form-termo">
                @csrf

                @if ($errors->any())
                    <div class="erro" style="margin-top: 12px;">{{ $errors->first() }}</div>
                @endif

                <label for="nome">Seu nome</label>
                <input type="text" id="nome" name="nome" value="{{ old('nome') }}" maxlength="150" autocomplete="name" required />

                <label>Você autoriza o teste de rodagem do veículo?</label>
                <div class="opcoes">
                    <label><input type="radio" name="autoriza_rodagem" value="1" @checked(old('autoriza_rodagem') === '1') required /> Sim, autorizo</label>
                    <label><input type="radio" name="autoriza_rodagem" value="0" @checked(old('autoriza_rodagem') === '0') /> Não autorizo</label>
                </div>

                <label>Assine no quadro abaixo (com o dedo ou o mouse)</label>
                <canvas id="assinatura" width="520" height="160"></canvas>
                <div class="linha"><span>A assinatura fica registrada junto com a data, a hora e o aparelho.</span><button type="button" class="limpar" id="limpar">Limpar</button></div>
                <input type="hidden" name="assinatura" id="assinatura-dados" />

                <label style="display:flex; gap:8px; align-items:flex-start; margin-top:14px;">
                    <input type="checkbox" name="li_e_aceito" value="1" required style="margin-top:3px;" />
                    <span>Li e aceito o termo de entrada.</span>
                </label>

                <button type="submit" class="enviar">Aceitar o termo</button>
            </form>

            <script>
                (function () {
                    var tela = document.getElementById('assinatura');
                    var ctx = tela.getContext('2d');
                    var desenhando = false;
                    var desenhou = false;

                    ctx.lineWidth = 2.2;
                    ctx.lineCap = 'round';
                    ctx.strokeStyle = '#111827';

                    function ponto(e) {
                        var r = tela.getBoundingClientRect();
                        var t = e.touches ? e.touches[0] : e;

                        return { x: (t.clientX - r.left) * (tela.width / r.width), y: (t.clientY - r.top) * (tela.height / r.height) };
                    }

                    function comecar(e) { e.preventDefault(); desenhando = true; var p = ponto(e); ctx.beginPath(); ctx.moveTo(p.x, p.y); }
                    function mover(e) { if (!desenhando) { return; } e.preventDefault(); var p = ponto(e); ctx.lineTo(p.x, p.y); ctx.stroke(); desenhou = true; }
                    function terminar() { desenhando = false; }

                    tela.addEventListener('mousedown', comecar); tela.addEventListener('mousemove', mover); window.addEventListener('mouseup', terminar);
                    tela.addEventListener('touchstart', comecar, { passive: false }); tela.addEventListener('touchmove', mover, { passive: false }); tela.addEventListener('touchend', terminar);

                    document.getElementById('limpar').addEventListener('click', function () { ctx.clearRect(0, 0, tela.width, tela.height); desenhou = false; });

                    document.getElementById('form-termo').addEventListener('submit', function () {
                        document.getElementById('assinatura-dados').value = desenhou ? tela.toDataURL('image/png') : '';
                    });
                })();
            </script>
        @endif
    </div>
</body>
</html>
