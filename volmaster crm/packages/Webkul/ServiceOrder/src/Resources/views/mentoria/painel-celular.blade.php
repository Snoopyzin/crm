<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#0b3e7a">
    <link rel="manifest" href="/dono.webmanifest">
    <link rel="apple-touch-icon" href="/workshop-icone-192.png">
    <title>Painel do Dono</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; background: #eef2f5; color: #1f2a30; padding-bottom: 76px; }
        header { background: #0b3e7a; color: #fff; padding: 14px 16px; position: sticky; top: 0; z-index: 5; }
        header h1 { font-size: 18px; margin: 0; } header small { opacity: .8; }
        main { padding: 12px; max-width: 720px; margin: 0 auto; }
        h2 { font-size: 13px; text-transform: uppercase; letter-spacing: .5px; color: #546e7a; margin: 18px 4px 8px; }
        .card { background: #fff; border-radius: 12px; padding: 14px; margin-bottom: 10px; box-shadow: 0 1px 3px rgba(0,0,0,.12); }
        .grid2 { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
        .big { font-size: 30px; font-weight: 800; line-height: 1.1; }
        .muted { color: #607d8b; font-size: 13px; }
        .verde { background: #dcfce7; color: #166534; } .amarelo { background: #fef3c7; color: #92400e; } .vermelho { background: #fee2e2; color: #991b1b; } .sem_dado, .sem_meta { background: #eceff1; color: #455a64; }
        .chip { display: inline-block; padding: 3px 10px; border-radius: 999px; font-size: 12px; font-weight: 700; }
        .kpi { border-radius: 12px; padding: 14px; } .kpi b { display: block; font-size: 26px; margin-top: 4px; }
        .row { display: flex; justify-content: space-between; gap: 10px; align-items: flex-start; }
        nav { position: fixed; bottom: 0; left: 0; right: 0; background: #fff; display: flex; border-top: 1px solid #cfd8dc; z-index: 10; }
        nav button { flex: 1; border: 0; background: none; padding: 14px 4px; font: inherit; font-size: 13px; font-weight: 700; color: #607d8b; min-height: 56px; }
        nav button.on { color: #0b3e7a; box-shadow: inset 0 3px 0 #0b3e7a; }
        main button { font: inherit; border: 0; border-radius: 10px; padding: 14px; font-weight: 700; color: #fff; background: #0b3e7a; min-height: 48px; }
        main button.ok { background: #16a34a; } main button.no { background: #dc2626; }
        .alerta { border-left: 5px solid #0E90D9; } .alerta.critico { border-left-color: #dc2626; } .alerta.resolvido { opacity: .65; }
        select { font: inherit; padding: 8px; border-radius: 8px; border: 1px solid #cfd8dc; }
        .tend { font-size: 12px; color: #78909c; }
    </style>
</head>
<body>
    <header>
        <div class="row">
            <div><h1>Painel do Dono</h1><small>{{ $usuario }}</small></div>
            <a href="{{ route('admin.dashboard.index') }}" style="color:#fff;font-size:13px;opacity:.85;">Sistema</a>
        </div>
    </header>

    <main id="app"><p class="muted">Carregando…</p></main>

    <nav id="abas">
        <button data-aba="hoje" class="on">Hoje</button>
        <button data-aba="alertas">Alertas</button>
        <button data-aba="alcadas">Alçadas</button>
        <button data-aba="indicadores">Indicadores</button>
        <button data-aba="financeiro">Financeiro</button>
    </nav>

    <script>
        var URL_DADOS = @json(route('admin.mentoria.painel.dados', [], false));
        var URL_ALCADAS = @json(route('admin.mentoria.alcadas.dados', [], false));
        var URL_DECIDIR = @json(route('admin.mentoria.alcadas.decidir', ['id' => '__ID__'], false));
        var CSRF = @json(csrf_token());
        var PERIODO = @json($periodo);
        var app = document.getElementById('app');
        var abaAtual = 'hoje';

        function esc(t) { var d = document.createElement('div'); d.textContent = t == null ? '' : String(t); return d.innerHTML; }

        function cartaoLinha(l) {
            return '<div class="card"><div class="row"><div><b>' + esc(l.nome) + '</b><div class="muted">' + esc(l.codigo) + ' · meta ' + esc(l.meta_fmt) + ' · ' + esc(l.cargo) + '</div></div>'
                + '<span class="chip ' + l.situacao + '">' + esc(l.situacao_rotulo) + '</span></div>'
                + '<div class="big" style="margin-top:6px">' + esc(l.realizado_fmt) + '</div>'
                + '<div class="tend">3 meses: ' + l.tendencia.map(function (t) { return esc(t.fmt); }).join(' › ') + '</div></div>';
        }

        function renderHoje(d) {
            var h = '<div class="grid2">'
                + '<div class="kpi sem_meta"><span class="muted">No pátio</span><b>' + d.no_patio + '</b></div>'
                + '<div class="kpi ' + (d.parados ? 'vermelho' : 'verde') + '"><span>Parados +3 dias</span><b>' + d.parados + '</b></div>'
                + '<div class="kpi sem_meta"><span class="muted">Faturado hoje</span><b>' + esc(d.faturado_hoje_fmt) + '</b></div>'
                + '<div class="kpi ' + (d.barreiras_vencidas.length ? 'amarelo' : 'verde') + '"><span>Barreiras vencidas</span><b>' + d.barreiras_vencidas.length + '</b></div></div>';

            h += '<h2>Caminhões por etapa</h2>';
            d.por_etapa.forEach(function (e) {
                h += '<div class="card row"><span>' + esc(e.etapa) + '</span><b>' + e.quantidade + '</b></div>';
            });

            h += '<h2>Barreiras vencidas</h2>';
            if (!d.barreiras_vencidas.length) { h += '<div class="card muted">Nenhuma barreira vencida.</div>'; }
            d.barreiras_vencidas.forEach(function (b) {
                h += '<div class="card"><b>' + esc(b.barreira) + '</b><div class="muted">OS #' + b.os + ' · ' + esc(b.placa || '-') + ' · ' + esc(b.cliente || '') + '</div>'
                    + '<div class="muted">Venceu em ' + esc(b.venceu_em) + ' · nível ' + b.nivel + '</div></div>';
            });

            return h;
        }

        function renderAlertas(d) {
            if (!d.alertas.length) { return '<div class="card muted">Nenhum alerta crítico ou de nível 3.</div>'; }

            return d.alertas.map(function (a) {
                return '<div class="card alerta ' + (a.critico ? 'critico ' : '') + (a.status === 'resolvido' ? 'resolvido' : '') + '">'
                    + '<div class="row"><b>' + esc(a.titulo) + '</b><span class="chip ' + (a.status === 'resolvido' ? 'verde' : (a.critico ? 'vermelho' : 'amarelo')) + '">'
                    + (a.status === 'resolvido' ? 'Resolvido' : (a.critico ? 'Crítico' : 'Nível ' + a.nivel)) + '</span></div>'
                    + '<div class="muted" style="margin-top:4px">' + esc(a.mensagem) + '</div>'
                    + '<div class="muted">' + esc(a.disparado_em) + (a.resolvido_em ? ' · resolvido ' + esc(a.resolvido_em) : '') + '</div></div>';
            }).join('');
        }

        function renderIndicadores(d) {
            var h = '<div class="grid2">' + d.cartoes.map(function (c) {
                return '<div class="kpi ' + c.situacao + '"><span>' + esc(c.rotulo) + '</span><b>' + esc(c.valor) + '</b></div>';
            }).join('') + '</div>';

            h += '<h2>Os 8 passos do caminhão</h2>';
            d.passos.forEach(function (p) {
                h += '<div class="card"><div class="row"><b>' + esc(p.etapa) + '</b><span class="chip ' + p.situacao + '">' + p.agora + ' agora' + (p.parados ? ' · ' + p.parados + ' parados' : '') + '</span></div>'
                    + p.indicadores.map(function (l) {
                        return '<div class="row" style="margin-top:8px"><span class="muted">' + esc(l.nome) + '</span><span class="chip ' + l.situacao + '">' + esc(l.realizado_fmt) + '</span></div>';
                    }).join('') + '</div>';
            });

            d.blocos.forEach(function (b) {
                h += '<h2>' + esc(b.titulo) + '</h2>' + b.linhas.map(cartaoLinha).join('');
            });

            return h;
        }

        function renderAlcadas(d) {
            if (!d.pedidos.length) { return '<div class="card muted">Nenhum pedido aguardando a sua decisão.</div>'; }

            return d.pedidos.map(function (p) {
                return '<div class="card alerta' + (p.atrasado ? ' critico' : '') + '">'
                    + '<div class="row"><b>' + esc(p.tema) + '</b>' + (p.valor_fmt ? '<span class="chip amarelo">' + esc(p.valor_fmt) + '</span>' : '') + '</div>'
                    + '<div class="muted" style="margin:4px 0">' + esc(p.descricao) + '</div>'
                    + '<div class="muted">Pedido por ' + esc(p.solicitante || 'sistema') + (p.os ? ' · OS #' + p.os : '') + (p.prazo_em ? ' · responder até ' + esc(p.prazo_em) : '') + '</div>'
                    + '<input id="com-' + p.id + '" placeholder="Comentário (obrigatório para recusar)" style="width:100%;margin:10px 0;padding:10px;border:1px solid #cfd8dc;border-radius:8px;font:inherit">'
                    + '<div class="grid2"><button class="ok" onclick="decidir(' + p.id + ', \'aprovar\')">Aprovar</button><button class="no" onclick="decidir(' + p.id + ', \'recusar\')">Recusar</button></div></div>';
            }).join('');
        }

        function renderFinanceiro(d) {
            var h = '<div class="grid2">'
                + '<div class="kpi sem_meta"><span class="muted">Saldo conciliado</span><b>' + esc(d.caixa.saldo_conciliado) + '</b></div>'
                + '<div class="kpi sem_meta"><span class="muted">A receber em 7 dias</span><b>' + esc(d.caixa.a_receber_7_dias) + '</b></div>'
                + '<div class="kpi sem_meta"><span class="muted">A pagar em 7 dias</span><b>' + esc(d.caixa.a_pagar_7_dias) + '</b></div>'
                + '<div class="kpi ' + (d.caixa.vencido_receber === 'R$ 0,00' ? 'verde' : 'amarelo') + '"><span>Vencido a receber</span><b>' + esc(d.caixa.vencido_receber) + '</b></div></div>';

            h += '<h2>Relatório diário do financeiro</h2>';
            if (!d.relatorio) {
                h += '<div class="card muted">Nenhum relatório enviado ainda.</div>';
            } else {
                h += '<div class="card"><div class="row"><b>' + esc(d.relatorio.data) + '</b><span class="chip ' + (d.relatorio.no_horario ? 'verde' : 'vermelho') + '">' + (d.relatorio.no_horario ? 'no horário' : 'fora do horário') + '</span></div>'
                    + (d.relatorio.alertas.length ? d.relatorio.alertas.map(function (a) { return '<div style="margin-top:8px">• ' + esc(a) + '</div>'; }).join('') : '<div class="muted" style="margin-top:6px">Sem alertas e decisões.</div>') + '</div>';
            }

            h += '<h2>DRE do mês</h2>';
            d.dre.forEach(function (l) {
                h += '<div class="card row"><span>' + esc(l.rotulo) + '</span><b>' + esc(l.valor) + '</b></div>';
            });

            h += '<h2>Ponto de equilíbrio</h2><div class="card">';
            if (d.equilibrio.ponto_equilibrio && d.equilibrio.ponto_equilibrio !== '—') {
                h += '<div class="big">' + esc(d.equilibrio.ponto_equilibrio) + '</div><div class="muted">' + (d.equilibrio.dia_atingido ? 'Atingido no dia ' + d.equilibrio.dia_atingido : 'Faltam ' + esc(d.equilibrio.faltam)) + '</div>';
            } else {
                h += '<span class="muted">Sem dados suficientes.</span>';
            }

            return h + '</div>';
        }

        function decidir(id, decisao) {
            var comentario = (document.getElementById('com-' + id) || {}).value || '';

            fetch(URL_DECIDIR.replace('__ID__', id), {
                method: 'POST',
                headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF, 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
                body: JSON.stringify({ decisao: decisao, comentario: comentario })
            }).then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
              .then(function (res) {
                  if (!res.ok) { alert((res.j && (res.j.mensagem || (res.j.errors && Object.values(res.j.errors)[0][0]))) || 'Não foi possível registrar.'); return; }
                  carregar();
              })
              .catch(function () { alert('Sem conexão. Tente de novo.'); });
        }

        function carregar() {
            app.innerHTML = '<p class="muted">Carregando…</p>';

            var url = abaAtual === 'alcadas' ? URL_ALCADAS : URL_DADOS + '?aba=' + abaAtual + '&periodo=' + PERIODO;

            fetch(url, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
                .then(function (r) { if (!r.ok) { throw new Error('http ' + r.status); } return r.json(); })
                .then(function (d) {
                    app.innerHTML = abaAtual === 'alertas' ? renderAlertas(d)
                        : (abaAtual === 'alcadas' ? renderAlcadas(d) : (abaAtual === 'financeiro' ? renderFinanceiro(d) : (abaAtual === 'indicadores' ? renderIndicadores(d) : renderHoje(d))));
                })
                .catch(function () { app.innerHTML = '<div class="card vermelho">Não foi possível carregar. Verifique a internet e tente de novo.</div>'; });
        }

        document.getElementById('abas').addEventListener('click', function (e) {
            var botao = e.target.closest('button');
            if (!botao) { return; }
            abaAtual = botao.dataset.aba;
            [].forEach.call(document.querySelectorAll('nav button'), function (b) { b.classList.toggle('on', b === botao); });
            carregar();
        });

        carregar();
    </script>
</body>
</html>
