<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Algo deu errado — Volmaster Tech</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body { font-family: -apple-system, Segoe UI, Roboto, sans-serif; background: #f3f4f6; color: #1f2937; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; }
        .card { background: #fff; border-radius: 12px; padding: 40px; max-width: 420px; text-align: center; box-shadow: 0 1px 3px rgba(0,0,0,.1); }
        h1 { font-size: 20px; margin: 0 0 12px; }
        p { color: #6b7280; line-height: 1.5; }
    </style>
</head>
<body>
    <div class="card">
        <h1>Não conseguimos preparar a conta de {{ $tenant->name }}</h1>
        <p>Aconteceu um problema durante a criação da sua conta. Nossa equipe já foi avisada — se preferir, entre em contato: <a href="mailto:gabriel@volmastercrm.com">gabriel@volmastercrm.com</a></p>
    </div>
</body>
</html>
