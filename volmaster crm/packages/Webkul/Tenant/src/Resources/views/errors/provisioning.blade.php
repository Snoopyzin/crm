<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Preparando sua conta — Volmaster Tech</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta http-equiv="refresh" content="5">
    <style>
        body { font-family: -apple-system, Segoe UI, Roboto, sans-serif; background: #f3f4f6; color: #1f2937; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; }
        .card { background: #fff; border-radius: 12px; padding: 40px; max-width: 420px; text-align: center; box-shadow: 0 1px 3px rgba(0,0,0,.1); }
        h1 { font-size: 20px; margin: 0 0 12px; }
        p { color: #6b7280; line-height: 1.5; }
        .spinner { width: 36px; height: 36px; border: 4px solid #e5e7eb; border-top-color: #2563eb; border-radius: 50%; margin: 0 auto 20px; animation: spin 0.8s linear infinite; }
        @keyframes spin { to { transform: rotate(360deg); } }
    </style>
</head>
<body>
    <div class="card">
        <div class="spinner"></div>
        <h1>Preparando a conta de {{ $tenant->name }}</h1>
        <p>Isso leva só alguns instantes. Essa página atualiza sozinha — não precisa recarregar.</p>
    </div>
</body>
</html>
