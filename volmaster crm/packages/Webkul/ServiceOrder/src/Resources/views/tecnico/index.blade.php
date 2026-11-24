<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#0E90D9">
    <link rel="manifest" href="/workshop.webmanifest">
    <link rel="apple-touch-icon" href="/workshop-icone-192.png">
    <title>Minhas tarefas</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; background: #eef2f5; color: #1f2a30; }
        header { background: #0E90D9; color: #fff; padding: 12px 16px; display: flex; justify-content: space-between; align-items: center; position: sticky; top: 0; z-index: 5; }
        header h1 { font-size: 18px; margin: 0; }
        header a { color: #fff; font-size: 13px; opacity: .9; }
        main { padding: 12px; max-width: 720px; margin: 0 auto; }
        h2 { font-size: 14px; text-transform: uppercase; letter-spacing: .5px; color: #546e7a; margin: 18px 4px 8px; }
        .card { background: #fff; border-radius: 10px; padding: 14px; margin-bottom: 12px; box-shadow: 0 1px 3px rgba(0,0,0,.12); }
        .row { display: flex; justify-content: space-between; align-items: flex-start; gap: 8px; }
        .placa { font-size: 20px; font-weight: 800; letter-spacing: 1px; }
        .muted { color: #607d8b; font-size: 13px; }
        .timer { font-size: 34px; font-weight: 800; font-variant-numeric: tabular-nums; margin: 6px 0; }
        .chip { display: inline-block; padding: 3px 10px; border-radius: 999px; font-size: 12px; font-weight: 700; }
        .verde { background: #dcfce7; color: #166534; } .amarelo { background: #fef3c7; color: #92400e; } .vermelho { background: #fee2e2; color: #991b1b; } .cinza { background: #eceff1; color: #455a64; } .azul { background: #dbeafe; color: #1e40af; }
        .bar { height: 10px; border-radius: 5px; background: #eceff1; overflow: hidden; margin: 6px 0; } .bar > div { height: 100%; }
        .bar .verde { background: #22c55e; } .bar .amarelo { background: #f59e0b; } .bar .vermelho { background: #ef4444; }
        button, .btn { font: inherit; border: 0; border-radius: 8px; padding: 12px 16px; font-weight: 700; cursor: pointer; background: #0E90D9; color: #fff; min-height: 46px; }
        button.sec { background: #eceff1; color: #263238; } button.ok { background: #16a34a; } button.warn { background: #f59e0b; color: #1f2a30; }
        button:disabled { opacity: .5; }
        .actions { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 8px; }
        input, select, textarea { font: inherit; width: 100%; padding: 10px; border: 1px solid #cfd8dc; border-radius: 8px; background: #fff; }
        label { display: block; font-size: 12px; color: #607d8b; margin: 8px 0 3px; }
        .grid2 { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
        ul { padding-left: 18px; margin: 6px 0; }
        .ind { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; }
        .ind .card { margin: 0; text-align: center; padding: 10px 6px; } .ind b { font-size: 22px; display: block; }
        .toast { position: fixed; left: 12px; right: 12px; bottom: 16px; padding: 14px; border-radius: 10px; color: #fff; font-weight: 600; z-index: 20; display: none; }
        .toast.err { background: #dc2626; } .toast.good { background: #16a34a; }
        details summary { cursor: pointer; font-weight: 700; padding: 6px 0; }
        img.th { width: 54px; height: 54px; object-fit: cover; border-radius: 6px; }
    </style>
</head>
<body>
    <header>
        <h1>Minhas tarefas</h1>
        <span style="font-size:13px;">{{ $usuario }} &middot; <a href="{{ route('admin.dashboard.index') }}">Sistema</a></span>
    </header>

    <main id="app">
        <p class="muted" id="carregando">Carregando…</p>
    </main>

    <div class="toast" id="toast"></div>

    <script>
        var URLS = {
            tarefas: @json(route('admin.tecnico.tasks', [], false)),
            diag: function (id, acao) { return @json(url(config('app.admin_path').'/tecnico/diagnostico')) + '/' + id + '/' + acao; },
            op: function (id, acao) { return @json(url(config('app.admin_path').'/tecnico/operacao')) + '/' + id + '/' + acao; }
        };
        var CSRF = @json(csrf_token());
        var QUEIXAS = @json($queixaTipos);
        var PECAS = @json($pecas);
        var estado = null;
        var carregadoEm = Date.now();

        function esc(t) { var d = document.createElement('div'); d.textContent = t == null ? '' : String(t); return d.innerHTML; }
        function hm(seg) { seg = Math.max(0, Math.floor(seg)); var h = Math.floor(seg / 3600), m = Math.floor(seg % 3600 / 60), s = seg % 60; return h + ':' + String(m).padStart(2, '0') + ':' + String(s).padStart(2, '0'); }
        function hmCurto(seg) { seg = Math.max(0, Math.floor(seg)); return Math.floor(seg / 3600) + 'h' + String(Math.floor(seg % 3600 / 60)).padStart(2, '0'); }
        function pct(v) { return v == null ? '-' : String(v.toFixed(1)).replace('.', ',') + '%'; }

        function aviso(texto, bom) {
            var t = document.getElementById('toast');
            t.textContent = texto; t.className = 'toast ' + (bom ? 'good' : 'err'); t.style.display = 'block';
            setTimeout(function () { t.style.display = 'none'; }, 4500);
        }

        function enviar(url, dados, depois) {
            var corpo = dados instanceof FormData ? dados : (function () { var f = new FormData(); Object.keys(dados || {}).forEach(function (k) { f.append(k, dados[k]); }); return f; })();
            return fetch(url, { method: 'POST', headers: { 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' }, credentials: 'same-origin', body: corpo })
                .then(function (r) { return r.json().catch(function () { return { ok: false, mensagem: 'Erro ' + r.status }; }); })
                .then(function (j) {
                    if (j.ok) { if (depois) { depois(); } carregar(); } else { aviso(j.mensagem || j.message || 'Não foi possível concluir.', false); }
                })
                .catch(function () { aviso('Sem conexão. Tente de novo.', false); });
        }

        function carregar() {
            return fetch(URLS.tarefas, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (dados) { estado = dados; carregadoEm = Date.now(); desenhar(); })
                .catch(function () {});
        }

        function cor(op, trabalhado) {
            if (!op.padrao_seg) { return 'verde'; }
            var p = trabalhado / op.padrao_seg * 100;
            return p <= 100 ? 'verde' : (p <= estado.config.margem ? 'amarelo' : 'vermelho');
        }

        function desenhar() {
            var app = document.getElementById('app');

            if (!estado.vinculado) {
                app.innerHTML = '<div class="card">Seu usuário ainda não está ligado a um mecânico. Peça ao gerente para vincular em Configurações &gt; Usuários.</div>';
                return;
            }

            var html = '';
            var ind = estado.indicadores;

            html += '<h2>Meus indicadores · ' + esc(ind.mes) + '</h2><div class="ind">'
                + '<div class="card"><span class="muted">Eficiência</span><b class="chip ' + (ind.eficiencia.cor || 'cinza') + '">' + pct(ind.eficiencia.valor) + '</b><span class="muted">meta ' + ind.eficiencia.meta + '%</span></div>'
                + '<div class="card"><span class="muted">Na margem</span><b>' + pct(ind.operacoes_na_margem.valor) + '</b><span class="muted">' + ind.operacoes_na_margem.quantidade + ' operações</span></div>'
                + '<div class="card"><span class="muted">Diag. no tempo</span><b>' + pct(ind.diagnosticos_no_tempo.valor) + '</b><span class="muted">' + ind.diagnosticos_no_tempo.quantidade + ' diagnósticos</span></div>'
                + '</div>';

            html += '<h2>Operações em execução</h2>';
            html += estado.operacoes.length ? estado.operacoes.map(cartaoOperacao).join('') : '<div class="card muted">Nenhuma operação liberada para você agora.</div>';

            html += '<h2>Diagnósticos</h2>';
            html += estado.diagnosticos.length ? estado.diagnosticos.map(cartaoDiagnostico).join('') : '<div class="card muted">Nenhum diagnóstico designado para você.</div>';

            // Mantém abertos os blocos que o técnico estava usando.
            var abertos = Array.prototype.map.call(document.querySelectorAll('details[open]'), function (d) { return d.id; });
            app.innerHTML = html;
            abertos.forEach(function (id) { var d = document.getElementById(id); if (d) { d.open = true; } });
            ligarAcoes();
            tique();
        }

        function cartaoOperacao(op) {
            var rotulo = { nao_iniciada: 'Não iniciada', em_andamento: 'Em andamento', pausada: 'Pausada', concluida: 'Concluída' }[op.situacao];
            var acoes = '';

            if (op.situacao === 'nao_iniciada') {
                acoes = '<button data-op="' + op.id + '" data-acao="iniciar">INICIAR</button>';
            } else if (op.situacao === 'em_andamento') {
                acoes = '<select id="pausa-' + op.id + '">' + estado.config.motivos_pausa.map(function (m) { return '<option>' + esc(m) + '</option>'; }).join('') + '</select>'
                    + '<div class="actions"><button class="warn" data-op="' + op.id + '" data-acao="pausar">PAUSAR</button><button class="ok" data-op="' + op.id + '" data-acao="concluir">CONCLUIR</button></div>';
            } else if (op.situacao === 'pausada') {
                acoes = '<div class="actions"><button data-op="' + op.id + '" data-acao="retomar">RETOMAR</button><button class="ok" data-op="' + op.id + '" data-acao="concluir">CONCLUIR</button></div>';
            }

            return '<div class="card" data-card-op="' + op.id + '"><div class="row"><div><span class="placa">' + esc(op.placa || '-') + '</span><div>' + esc(op.servico) + '</div><div class="muted">OS #' + op.os_id + ' · padrão ' + hmCurto(op.padrao_seg) + '</div></div>'
                + '<span class="chip cinza">' + rotulo + '</span></div>'
                + '<div class="timer" data-timer="' + op.id + '">' + hm(op.trabalhado_seg) + '</div>'
                + '<div class="bar"><div data-bar="' + op.id + '" class="' + cor(op, op.trabalhado_seg) + '" style="width:' + Math.min(100, op.padrao_seg ? op.trabalhado_seg / op.padrao_seg * 100 : 0) + '%"></div></div>'
                + '<div class="muted" data-pct="' + op.id + '">real ' + hmCurto(op.trabalhado_seg) + ' de ' + hmCurto(op.padrao_seg) + ' padrão</div>'
                + (op.situacao === 'concluida' ? '' : '<div class="actions">' + acoes + '</div>')
                + '</div>';
        }

        function cartaoDiagnostico(d) {
            var estadoTxt = d.visto === 'aprovado' ? '<span class="chip verde">Aprovado pelo chefe</span>'
                : d.visto === 'devolvido' ? '<span class="chip vermelho">Devolvido: corrija</span>'
                : d.finalizado ? '<span class="chip amarelo">Aguardando visto</span>'
                : d.iniciado_em ? '<span class="chip azul">Em andamento</span>' : '<span class="chip cinza">Não iniciado</span>';

            var corpo = '';

            if (!d.iniciado_em) {
                corpo += '<label>Tipo de queixa</label><select id="queixa-' + d.id + '"><option value="">-</option>' + QUEIXAS.map(function (q) { return '<option value="' + q.id + '"' + (q.id == d.queixa_tipo_id ? ' selected' : '') + '>' + esc(q.nome) + ' (' + q.tempo_alvo_min + ' min)</option>'; }).join('') + '</select>'
                    + '<div class="actions"><button data-diag="' + d.id + '" data-acao="iniciar">INICIAR DIAGNÓSTICO</button></div>';
            } else {
                if (d.tempo_alvo_min && !d.finalizado) {
                    corpo += '<div class="muted" data-diag-timer="' + d.id + '" data-inicio="' + d.iniciado_em + '" data-alvo="' + d.tempo_alvo_min + '"></div>';
                }

                corpo += '<label>Laudo</label><textarea id="laudo-' + d.id + '" rows="4"' + (d.finalizado ? ' disabled' : '') + '>' + esc(d.laudo || '') + '</textarea>';

                corpo += '<label>Evidências (' + d.evidencias.length + ')</label><ul>' + (d.evidencias.length ? d.evidencias.map(function (e) {
                    return '<li>' + esc(e.descricao) + (e.valor ? ': <b>' + esc(e.valor) + '</b>' : '') + (e.foto ? ' <img class="th" src="' + esc(e.foto) + '" alt="">' : '') + '</li>';
                }).join('') : '<li class="muted">Nenhuma ainda: o laudo não fecha sem evidência.</li>') + '</ul>';

                if (!d.finalizado) {
                    corpo += '<details id="ev-' + d.id + '"><summary>+ Adicionar evidência</summary>'
                        + '<div class="grid2"><div><label>Tipo</label><select id="ev-tipo-' + d.id + '"><option value="codigo">Código de falha</option><option value="medicao">Medição</option><option value="foto">Foto</option></select></div>'
                        + '<div><label>Valor medido</label><input id="ev-valor-' + d.id + '" placeholder="Ex.: 2,1 V"></div></div>'
                        + '<label>Código / descrição</label><input id="ev-desc-' + d.id + '" placeholder="Ex.: P0203">'
                        + '<label>Foto (tire na hora)</label><input type="file" id="ev-foto-' + d.id + '" accept="image/*" capture="environment">'
                        + '<div class="actions"><button class="sec" data-diag="' + d.id + '" data-acao="evidencia">Adicionar</button></div></details>';
                }

                corpo += '<label>Relação de peças ' + (d.relacao_pecas_em ? '(entregue ' + esc(d.relacao_pecas_em) + ')' : '') + '</label><ul>'
                    + (d.pecas.length ? d.pecas.map(function (p) { return '<li>' + p.quantidade + 'x ' + esc(p.nome) + (p.codigo ? ' (' + esc(p.codigo) + ')' : '') + '</li>'; }).join('') : '<li class="muted">Nenhuma peça lançada.</li>') + '</ul>';

                if (!d.relacao_pecas_em) {
                    corpo += '<details id="pc-' + d.id + '"><summary>+ Peças da relação</summary>'
                        + '<label>Peça</label><select id="pc-peca-' + d.id + '"><option value="">-</option>' + PECAS.map(function (p) { return '<option value="' + p.id + '">' + esc((p.codigo ? p.codigo + ' - ' : '') + p.nome) + '</option>'; }).join('') + '</select>'
                        + '<label>Quantidade</label><input type="number" min="1" value="1" id="pc-qtd-' + d.id + '">'
                        + '<div class="actions"><button class="sec" data-diag="' + d.id + '" data-acao="peca">Incluir peça</button></div>'
                        + '<label style="margin-top:12px"><input type="checkbox" id="pc-sem-' + d.id + '" style="width:auto"> Este serviço não precisa de peças</label>'
                        + '<div class="actions"><button class="sec" data-diag="' + d.id + '" data-acao="relacao-pecas">Entregar relação de peças</button></div></details>';
                }

                if (!d.finalizado) {
                    corpo += '<label>Causa do atraso (obrigatória se passou do tempo alvo)</label><select id="causa-' + d.id + '"><option value="">-</option>'
                        + estado.config.causas_atraso_diagnostico.map(function (c) { return '<option>' + esc(c) + '</option>'; }).join('') + '</select>'
                        + '<div class="actions"><button class="sec" data-diag="' + d.id + '" data-acao="laudo">Salvar laudo</button><button class="ok" data-diag="' + d.id + '" data-acao="finalizar">FINALIZAR DIAGNÓSTICO</button></div>';
                }
            }

            return '<div class="card"><div class="row"><div><span class="placa">' + esc(d.placa || '-') + '</span><div>' + esc(d.servico) + '</div><div class="muted">OS #' + d.id + (d.queixa ? ' · ' + esc(d.queixa) : '') + '</div></div>' + estadoTxt + '</div>' + corpo + '</div>';
        }

        function ligarAcoes() {
            document.querySelectorAll('button[data-op]').forEach(function (b) {
                b.addEventListener('click', function () {
                    var id = b.dataset.op, acao = b.dataset.acao, dados = {};

                    if (acao === 'pausar') { dados.motivo = document.getElementById('pausa-' + id).value; }

                    if (acao === 'concluir') {
                        var op = estado.operacoes.filter(function (o) { return o.id == id; })[0];
                        var trabalhado = op.trabalhado_seg + (op.rodando ? (Date.now() - carregadoEm) / 1000 : 0);

                        if (op.padrao_seg && trabalhado / op.padrao_seg * 100 > estado.config.margem) {
                            var motivo = window.prompt('Passou de ' + estado.config.margem + '% do tempo padrão. Motivo do atraso:\n\n' + estado.config.motivos_atraso_operacao.map(function (m, i) { return (i + 1) + ' - ' + m; }).join('\n') + '\n\nDigite o número:');
                            var escolha = estado.config.motivos_atraso_operacao[parseInt(motivo, 10) - 1];
                            if (!escolha) { aviso('Escolha um dos motivos da lista.', false); return; }
                            dados.motivo_atraso = escolha;
                        }
                    }

                    enviar(URLS.op(id, acao), dados);
                });
            });

            document.querySelectorAll('button[data-diag]').forEach(function (b) {
                b.addEventListener('click', function () {
                    var id = b.dataset.diag, acao = b.dataset.acao, v = function (x) { return document.getElementById(x + '-' + id); };

                    if (acao === 'iniciar') { enviar(URLS.diag(id, 'iniciar'), { queixa_tipo_id: v('queixa').value }); }
                    if (acao === 'laudo') { enviar(URLS.diag(id, 'laudo'), { diagnostico: v('laudo').value }); }
                    if (acao === 'finalizar') {
                        // Salva o laudo digitado e finaliza em seguida.
                        enviar(URLS.diag(id, 'laudo'), { diagnostico: v('laudo').value }).then(function () { enviar(URLS.diag(id, 'finalizar'), { causa_atraso: v('causa').value }); });
                    }
                    if (acao === 'evidencia') {
                        var f = new FormData(); f.append('tipo', v('ev-tipo').value); f.append('descricao', v('ev-desc').value); f.append('valor', v('ev-valor').value);
                        if (v('ev-foto').files[0]) { f.append('foto', v('ev-foto').files[0]); }
                        enviar(URLS.diag(id, 'evidencia'), f);
                    }
                    if (acao === 'peca') { enviar(URLS.diag(id, 'peca'), { part_id: v('pc-peca').value, quantidade: v('pc-qtd').value }); }
                    if (acao === 'relacao-pecas') { enviar(URLS.diag(id, 'relacao-pecas'), { sem_pecas: v('pc-sem').checked ? 1 : '' }); }
                });
            });
        }

        // Cronômetros: contam sozinhos na tela, sem pedir ao servidor a cada segundo.
        function tique() {
            if (!estado || !estado.operacoes) { return; }
            var extra = (Date.now() - carregadoEm) / 1000;

            estado.operacoes.forEach(function (op) {
                var t = op.trabalhado_seg + (op.rodando ? extra : 0);
                var timer = document.querySelector('[data-timer="' + op.id + '"]'); if (timer) { timer.textContent = hm(t); }
                var barra = document.querySelector('[data-bar="' + op.id + '"]');
                if (barra) { barra.className = cor(op, t); barra.style.width = Math.min(100, op.padrao_seg ? t / op.padrao_seg * 100 : 0) + '%'; }
                var p = document.querySelector('[data-pct="' + op.id + '"]');
                if (p) { p.textContent = 'real ' + hmCurto(t) + ' de ' + hmCurto(op.padrao_seg) + ' padrão' + (op.padrao_seg ? ' · ' + pct(t / op.padrao_seg * 100) : ''); }
            });

            document.querySelectorAll('[data-diag-timer]').forEach(function (el) {
                var passados = Math.floor(estado.agora + extra - parseInt(el.dataset.inicio, 10));
                var alvo = parseFloat(el.dataset.alvo) * 60;
                el.textContent = 'Diagnóstico: ' + hmCurto(passados) + ' de ' + hmCurto(alvo) + ' alvo' + (passados > alvo ? ' — passou do alvo' : '');
                el.style.color = passados > alvo ? '#991b1b' : '#607d8b';
            });
        }

        carregar();
        setInterval(tique, 1000);
        setInterval(carregar, 30000);

        if ('serviceWorker' in navigator) { navigator.serviceWorker.register('/workshop-sw.js', { scope: '/' }).catch(function () {}); }
    </script>
</body>
</html>
