<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Avalie nosso atendimento — Volmaster Tech</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body { font-family: -apple-system, Segoe UI, Roboto, sans-serif; background: #f3f4f6; color: #1f2937; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; padding: 24px 0; }
        .card { background: #fff; border-radius: 12px; padding: 40px; width: 100%; max-width: 420px; box-shadow: 0 1px 3px rgba(0,0,0,.1); box-sizing: border-box; text-align: center; }
        h1 { font-size: 20px; margin: 0 0 4px; }
        p.subtitle { color: #6b7280; margin: 0 0 24px; font-size: 14px; }
        .choices { display: flex; gap: 12px; justify-content: center; margin-bottom: 16px; }
        button.choice { flex: 1; padding: 16px; border-radius: 10px; border: 2px solid #e5e7eb; background: #fff; font-size: 15px; font-weight: 600; cursor: pointer; }
        button.choice.bom { color: #15803d; }
        button.choice.ruim { color: #b91c1c; }
        button.choice:hover { border-color: currentColor; }
        textarea { width: 100%; box-sizing: border-box; border-radius: 8px; border: 1px solid #e5e7eb; padding: 10px; font-size: 14px; font-family: inherit; margin-bottom: 16px; }
        button.submit { width: 100%; padding: 12px; border-radius: 8px; border: none; background: #2563eb; color: #fff; font-size: 15px; font-weight: 600; cursor: pointer; }
        .thanks { font-size: 17px; font-weight: 600; color: #15803d; }
    </style>
</head>
<body>
    <div class="card">
        @if ($feedback->respondido_em)
            <h1>@lang('service_order::app.feedback.thanks')</h1>
        @else
            <h1>@lang('service_order::app.feedback.title')</h1>
            <p class="subtitle">@lang('service_order::app.feedback.subtitle')</p>

            <form method="POST" action="{{ route('service_orders.feedback.respond', $feedback->token) }}">
                @csrf

                <div class="choices">
                    <button type="submit" name="avaliacao" value="Bom" class="choice bom">
                        @lang('service_order::app.feedback.bom-btn')
                    </button>
                    <button type="submit" name="avaliacao" value="Ruim" class="choice ruim">
                        @lang('service_order::app.feedback.ruim-btn')
                    </button>
                </div>

                <textarea name="comentario" rows="3" placeholder="{{ trans('service_order::app.feedback.comentario-placeholder') }}"></textarea>
            </form>
        @endif
    </div>
</body>
</html>
