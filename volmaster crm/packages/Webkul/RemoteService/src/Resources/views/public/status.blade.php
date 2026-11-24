<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Sua posição na fila — Volmaster Tech</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    @if ($remoteService->status !== 'Finalizado')
        <meta http-equiv="refresh" content="30">
    @endif
    <style>
        body { font-family: -apple-system, Segoe UI, Roboto, sans-serif; background: #f3f4f6; color: #1f2937; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; padding: 24px 0; }
        .card { background: #fff; border-radius: 12px; padding: 40px; width: 100%; max-width: 420px; box-shadow: 0 1px 3px rgba(0,0,0,.1); box-sizing: border-box; text-align: center; }
        h1 { font-size: 20px; margin: 0 0 4px; }
        p.subtitle { color: #6b7280; margin: 0 0 24px; font-size: 14px; }
        .position { font-size: 46px; font-weight: 700; color: #2563eb; line-height: 1; }
        .position-label { color: #6b7280; font-size: 14px; margin-top: 8px; }
        .badge { display: inline-block; margin-top: 20px; padding: 6px 14px; border-radius: 999px; font-size: 13px; font-weight: 600; }
        .badge.pendente { background: #ffedd5; color: #c2410c; }
        .badge.aberto { background: #fef9c3; color: #a16207; }
        .badge.finalizado { background: #dcfce7; color: #15803d; }
        p.hint { color: #9ca3af; font-size: 12px; margin-top: 24px; }
        a.refresh { display: inline-block; margin-top: 16px; color: #2563eb; font-size: 13px; text-decoration: none; }
    </style>
</head>
<body>
    <div class="card">
        @if ($remoteService->status === 'Finalizado')
            <h1>Atendimento finalizado! 🎉</h1>
            <p class="subtitle">Sua programação já foi concluída pela nossa equipe.</p>
            <span class="badge finalizado">Finalizado</span>
        @else
            <h1>Você está na fila</h1>
            <p class="subtitle">
                @if (empty($remoteService->tipo_atendimento))
                    Recebemos sua solicitação. Vamos te chamar no WhatsApp pra identificar a programação certa.
                @else
                    {{ $remoteService->tipo_atendimento_label }}
                @endif
            </p>

            <div class="position">{{ $posicao }}</div>
            <p class="position-label">
                {{ $posicao === 0 ? 'pessoa(s) na sua frente — você é o próximo!' : 'pessoa(s) na sua frente' }}
            </p>

            <span class="badge {{ $remoteService->status === 'Pendente' ? 'pendente' : 'aberto' }}">
                {{ $remoteService->status === 'Pendente' ? 'Aguardando confirmação' : 'Confirmado' }}
            </span>

            <p class="hint">Essa página atualiza sozinha. Você também pode salvar este link pra conferir depois.</p>
            <a class="refresh" href="{{ url()->current() }}">Atualizar agora</a>
        @endif
    </div>
</body>
</html>
