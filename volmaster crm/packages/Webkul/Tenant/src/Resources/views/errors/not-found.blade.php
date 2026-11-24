<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Conta não encontrada — Volmaster Tech</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body { font-family: -apple-system, Segoe UI, Roboto, sans-serif; background: #f3f4f6; color: #1f2937; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; }
        .card { background: #fff; border-radius: 12px; padding: 40px; max-width: 420px; text-align: center; box-shadow: 0 1px 3px rgba(0,0,0,.1); }
        h1 { font-size: 20px; margin: 0 0 12px; }
        p { color: #6b7280; line-height: 1.5; }
        a { display: inline-block; margin-top: 20px; background: #2563eb; color: #fff; text-decoration: none; padding: 10px 20px; border-radius: 8px; font-weight: 600; }
    </style>
</head>
<body>
    <div class="card">
        <h1>Não encontramos essa conta</h1>
        <p>Esse endereço não corresponde a nenhuma oficina cadastrada no Volmaster Tech. Confira se digitou certo, ou crie uma conta nova.</p>
        <a href="https://{{ parse_url((string) config('app.url'), PHP_URL_HOST) }}/signup">Criar conta</a>
    </div>
</body>
</html>
