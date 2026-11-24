<?php

use Illuminate\Support\Facades\Route;
use Webkul\ServiceOrder\Http\Controllers\AlcadaController;
use Webkul\ServiceOrder\Http\Controllers\AlertController;
use Webkul\ServiceOrder\Http\Controllers\BonusController;
use Webkul\ServiceOrder\Http\Controllers\CadastroController;
use Webkul\ServiceOrder\Http\Controllers\ClienteBuscaController;
use Webkul\ServiceOrder\Http\Controllers\ColaboradorController;
use Webkul\ServiceOrder\Http\Controllers\CommissionController;
use Webkul\ServiceOrder\Http\Controllers\CreditoClienteController;
use Webkul\ServiceOrder\Http\Controllers\FichaController;
use Webkul\ServiceOrder\Http\Controllers\FinanceiroController;
use Webkul\ServiceOrder\Http\Controllers\HistoricoImportController;
use Webkul\ServiceOrder\Http\Controllers\ImportController;
use Webkul\ServiceOrder\Http\Controllers\IndicatorController;
use Webkul\ServiceOrder\Http\Controllers\MarketingController;
use Webkul\ServiceOrder\Http\Controllers\MentorController;
use Webkul\ServiceOrder\Http\Controllers\PainelDonoController;
use Webkul\ServiceOrder\Http\Controllers\PerfilController;
use Webkul\ServiceOrder\Http\Controllers\RotinaController;
use Webkul\ServiceOrder\Http\Controllers\ServiceOrderController;
use Webkul\ServiceOrder\Http\Controllers\ServiceOrderExtraController;
use Webkul\ServiceOrder\Http\Controllers\ServiceOrderStageController;
use Webkul\ServiceOrder\Http\Controllers\TecnicoController;
use Webkul\ServiceOrder\Http\Controllers\WorkflowController;
use Webkul\ServiceOrder\Http\Controllers\WorkshopSettingsController;

Route::controller(ServiceOrderController::class)->prefix('service-orders')->group(function () {
    Route::get('', 'index')->name('admin.service_orders.index');

    Route::get('create', 'create')->name('admin.service_orders.create');

    Route::post('', 'store')->name('admin.service_orders.store');

    Route::get('stage/{etapa?}', 'stageIndex')->name('admin.service_orders.stage.index');

    Route::get('vehicles-by-person/{personId}', 'vehiclesByPerson')->name('admin.service_orders.vehicles-by-person');

    Route::get('{id}', 'view')->name('admin.service_orders.view');

    Route::get('{id}/edit', 'edit')->name('admin.service_orders.edit');

    Route::put('{id}', 'update')->name('admin.service_orders.update');

    Route::put('{id}/status', 'updateStatus')->name('admin.service_orders.status.update');

    Route::put('{id}/stage', 'updateStage')->name('admin.service_orders.stage.update');

    Route::delete('{id}', 'destroy')->name('admin.service_orders.destroy');

    Route::post('{id}/items', 'storeItem')->name('admin.service_orders.items.store');

    Route::delete('{id}/items/{itemId}', 'destroyItem')->name('admin.service_orders.items.destroy');

    Route::get('{id}/print', 'print')->name('admin.service_orders.print');
});

Route::controller(CadastroController::class)->prefix('service-orders-cadastro')->group(function () {
    Route::get('', 'create')->name('admin.service_orders.cadastro.create');

    Route::post('', 'store')->name('admin.service_orders.cadastro.store');

    // Endereço de um CEP (preenche rua, bairro, cidade e estado no formulário).
    Route::get('cep/{cep}', 'cep')->name('admin.service_orders.cadastro.cep');
});

// Aba "Buscar cliente": pesquisa a pessoa (nome, CPF/CNPJ, telefone ou placa) ou leva ao Cadastro do cliente.
Route::get('service-orders-clientes', [ClienteBuscaController::class, 'index'])->name('admin.clientes.busca');
Route::get('service-orders-clientes/pesquisar', [ClienteBuscaController::class, 'pesquisar'])->name('admin.clientes.pesquisar');

// Aba "Defeito relatado pelo motorista": salva o relato e o defeito sem abrir a tela de edição.
Route::put('service-orders/{id}/defeito', [ServiceOrderController::class, 'updateDefeito'])->name('admin.service_orders.defeito.update');

// "Anotações e anexos" da OS (o "+"): texto, fotos, vídeos e outros arquivos.
Route::controller(ServiceOrderExtraController::class)->prefix('service-orders/{id}/extras')->group(function () {
    Route::post('', 'store')->name('admin.service_orders.extras.store');
    Route::delete('{extraId}', 'destroy')->name('admin.service_orders.extras.destroy');
});

Route::controller(ServiceOrderStageController::class)->prefix('service-orders/{id}')->group(function () {
    Route::put('diagnostico', 'updateDiagnostico')->name('admin.service_orders.diagnostico.update');
    Route::post('diagnostico/whatsapp', 'whatsappDiagnostico')->name('admin.service_orders.diagnostico.whatsapp');

    Route::put('negociacao', 'updateNegociacao')->name('admin.service_orders.negociacao.update');

    Route::put('execucao/tarefa', 'updateExecucaoTarefa')->name('admin.service_orders.execucao.tarefa.update');

    Route::put('items/{itemId}/alerta', 'updateItemAlerta')->name('admin.service_orders.items.alerta.update');

    Route::post('checklist', 'storeChecklistItem')->name('admin.service_orders.checklist.store');
    Route::put('checklist/{itemId}/toggle', 'toggleChecklistItem')->name('admin.service_orders.checklist.toggle');
    Route::delete('checklist/{itemId}', 'destroyChecklistItem')->name('admin.service_orders.checklist.destroy');

    Route::post('orcamento/mail', 'mailOrcamento')->name('admin.service_orders.orcamento.mail');
    Route::post('orcamento/whatsapp', 'whatsappOrcamento')->name('admin.service_orders.orcamento.whatsapp');

    Route::post('timer/start', 'timerStart')->name('admin.service_orders.timer.start');
    Route::post('timer/pause', 'timerPause')->name('admin.service_orders.timer.pause');
    Route::post('timer/resume', 'timerResume')->name('admin.service_orders.timer.resume');
    Route::post('timer/finish', 'timerFinish')->name('admin.service_orders.timer.finish');

    Route::post('teste/start', 'startTeste')->name('admin.service_orders.teste.start');
    Route::post('teste/finish', 'finishTeste')->name('admin.service_orders.teste.finish');

    Route::post('boletos', 'storeBoleto')->name('admin.service_orders.boletos.store');
    Route::post('boletos/asaas', 'storeBoletoViaAsaas')->name('admin.service_orders.boletos.asaas');
    Route::put('boletos/{boletoId}', 'updateBoletoStatus')->name('admin.service_orders.boletos.update');

    Route::post('pos-venda/send', 'sendPosVenda')->name('admin.service_orders.pos_venda.send');
});

// Piloto de indicadores e alertas: passos do fluxo da OS.
Route::controller(WorkflowController::class)->prefix('service-orders/{id}/workflow')->group(function () {
    Route::post('termo', 'saveTermo')->name('admin.service_orders.workflow.termo');
    Route::post('termo-link', 'termoLink')->name('admin.service_orders.workflow.termo_link');
    Route::post('cor-credito', 'saveCorCredito')->name('admin.service_orders.workflow.cor_credito');
    Route::post('designar-tecnico', 'designate')->name('admin.service_orders.workflow.designate');

    Route::post('laudo', 'saveLaudo')->name('admin.service_orders.workflow.laudo');
    Route::post('diagnostico/iniciar', 'startDiagnostico')->name('admin.service_orders.workflow.diagnostico.start');
    Route::post('diagnostico/finalizar', 'finishDiagnostico')->name('admin.service_orders.workflow.diagnostico.finish');
    Route::post('diagnostico/visto', 'audit')->name('admin.service_orders.workflow.diagnostico.audit');
    Route::post('evidencia', 'addEvidence')->name('admin.service_orders.workflow.evidence');
    Route::post('diagnostico/midia', 'addDiagnosticoMidia')->name('admin.service_orders.workflow.diagnostico.midia');
    Route::post('diagnostico/horarios', 'diagnosticoHorarios')->name('admin.service_orders.workflow.diagnostico.horarios');
    Route::post('relacao-pecas', 'deliverParts')->name('admin.service_orders.workflow.parts');

    Route::post('orcamento-enviado', 'budgetSent')->name('admin.service_orders.workflow.budget_sent');
    Route::post('aprovacao', 'approval')->name('admin.service_orders.workflow.approval');

    Route::post('nf', 'registerNf')->name('admin.service_orders.workflow.nf');
    Route::post('pagamento', 'confirmPayment')->name('admin.service_orders.workflow.payment');
    Route::post('acordo', 'signAgreement')->name('admin.service_orders.workflow.agreement');

    Route::post('acao/{acao}', 'action')->name('admin.service_orders.workflow.action');

    Route::post('operacoes/{itemId}/confirmar-atraso', 'confirmDelay')->name('admin.service_orders.workflow.operation.confirm_delay');
    Route::post('operacoes/{itemId}/{acao}', 'operation')->whereIn('acao', ['iniciar', 'pausar', 'retomar', 'concluir'])->name('admin.service_orders.workflow.operation');
});

// Alertas (sino, lista, notificação no celular).
Route::controller(AlertController::class)->prefix('workshop')->group(function () {
    Route::get('alertas', 'index')->name('admin.workshop.alerts.index');
    Route::get('alertas/resumo', 'summary')->name('admin.workshop.alerts.summary');
    Route::post('alertas/lidos', 'markAllRead')->name('admin.workshop.alerts.read_all');
    Route::post('alertas/{alertId}/ciente', 'acknowledge')->name('admin.workshop.alerts.ack');
    Route::post('push/inscrever', 'subscribe')->name('admin.workshop.push.subscribe');
    Route::post('push/cancelar', 'unsubscribe')->name('admin.workshop.push.unsubscribe');
    Route::post('push/testar', 'testPush')->name('admin.workshop.push.test');
});

// Cor de crédito do cliente (B1): quem registra a análise na página do cliente.
Route::post('credito/clientes/{personId}', [CreditoClienteController::class, 'registrar'])->name('admin.credito.registrar');

// Perfis da mentoria: funções dos cargos e migração dos usuários (A4).
Route::controller(PerfilController::class)->prefix('settings/perfis')->group(function () {
    Route::get('', 'index')->name('admin.workshop.perfis.index');
    Route::post('aplicar', 'aplicar')->name('admin.workshop.perfis.aplicar');
    Route::post('migrar', 'migrar')->name('admin.workshop.perfis.migrar');
    Route::post('desfazer', 'desfazer')->name('admin.workshop.perfis.desfazer');
});

// Configuração do piloto (chave geral, horários, barreiras, SMS, papéis).
Route::controller(WorkshopSettingsController::class)->prefix('settings/workshop')->group(function () {
    Route::get('', 'edit')->name('admin.workshop.settings.edit');
    Route::put('geral', 'updateGeneral')->name('admin.workshop.settings.general');
    Route::put('barreiras/{ruleId}', 'updateRule')->name('admin.workshop.settings.rule');
    Route::put('papeis', 'updateRoles')->name('admin.workshop.settings.roles');
    Route::put('queixas', 'updateComplaintTypes')->name('admin.workshop.settings.complaints');
    Route::post('vapid', 'generateVapid')->name('admin.workshop.settings.vapid');
    Route::post('restaurar-barreiras', 'restoreRules')->name('admin.workshop.settings.restore_rules');
    Route::get('sms', 'smsReport')->name('admin.workshop.settings.sms_report');
});

// Mentoria: Painel do Dono (computador e celular), indicadores, registros e metas.
Route::prefix('mentoria')->group(function () {
    Route::get('painel', [PainelDonoController::class, 'index'])->name('admin.mentoria.painel');
    Route::get('painel-celular', [PainelDonoController::class, 'celular'])->name('admin.mentoria.painel.celular');
    Route::get('painel.json', [PainelDonoController::class, 'dados'])->name('admin.mentoria.painel.dados');
    Route::get('painel.pdf', [PainelDonoController::class, 'pdf'])->name('admin.mentoria.painel.pdf');

    Route::get('indicadores', [IndicatorController::class, 'index'])->name('admin.mentoria.indicadores');
    Route::get('indicadores/{codigo}', [IndicatorController::class, 'show'])->name('admin.mentoria.indicador');

    Route::get('registros', [IndicatorController::class, 'registros'])->name('admin.mentoria.registros');
    Route::post('registros', [IndicatorController::class, 'storeRegistro'])->name('admin.mentoria.registros.store');
    Route::post('registros/{id}/corrigir', [IndicatorController::class, 'correctRegistro'])->name('admin.mentoria.registros.correct');

    Route::get('alcadas', [AlcadaController::class, 'fila'])->name('admin.mentoria.alcadas');
    Route::get('alcadas.json', [AlcadaController::class, 'dados'])->name('admin.mentoria.alcadas.dados');
    Route::post('alcadas/solicitar', [AlcadaController::class, 'solicitar'])->name('admin.mentoria.alcadas.solicitar');
    Route::post('alcadas/{id}/decidir', [AlcadaController::class, 'decidir'])->name('admin.mentoria.alcadas.decidir');
    Route::get('alcadas/matriz', [AlcadaController::class, 'matriz'])->name('admin.mentoria.alcadas.matriz');
    Route::put('alcadas/matriz', [AlcadaController::class, 'updateMatriz'])->name('admin.mentoria.alcadas.matriz.update');

    Route::get('bonus', [BonusController::class, 'index'])->name('admin.mentoria.bonus');
    Route::get('bonus/exportar', [BonusController::class, 'exportar'])->name('admin.mentoria.bonus.exportar');
    Route::get('bonus/meu', [BonusController::class, 'meu'])->name('admin.mentoria.bonus.meu');
    Route::get('bonus/travas', [BonusController::class, 'travasIndex'])->name('admin.mentoria.bonus.travas');
    Route::post('bonus/travas', [BonusController::class, 'travaStore'])->name('admin.mentoria.bonus.travas.store');
    Route::post('bonus/travas/{id}/decidir', [BonusController::class, 'travaDecidir'])->name('admin.mentoria.bonus.travas.decidir');
    Route::post('bonus/calcular', [BonusController::class, 'calcular'])->name('admin.mentoria.bonus.calcular');
    Route::post('bonus/consolidar-painel', [BonusController::class, 'consolidarPainel'])->name('admin.mentoria.bonus.painel');
    Route::post('bonus/aprovar-todos', [BonusController::class, 'aprovarTodos'])->name('admin.mentoria.bonus.aprovar_todos');
    Route::get('bonus/{id}/detalhe', [BonusController::class, 'detalhe'])->name('admin.mentoria.bonus.detalhe');
    Route::post('bonus/{id}/revisar', [BonusController::class, 'revisar'])->name('admin.mentoria.bonus.revisar');
    Route::post('bonus/{id}/aprovar', [BonusController::class, 'aprovar'])->name('admin.mentoria.bonus.aprovar');
    Route::post('bonus/{id}/estornar', [BonusController::class, 'estornar'])->name('admin.mentoria.bonus.estornar');
    Route::post('bonus/{id}/ciencia', [BonusController::class, 'ciencia'])->name('admin.mentoria.bonus.ciencia');

    Route::get('metas', [IndicatorController::class, 'metas'])->name('admin.mentoria.metas');
    Route::put('metas', [IndicatorController::class, 'updateMetas'])->name('admin.mentoria.metas.update');
});

// Financeiro: contas a pagar, caixa, cobrança, relatórios diários, DRE e precificação.
Route::controller(FinanceiroController::class)->prefix('financeiro')->group(function () {
    Route::get('lancamentos', 'lancamentos')->name('admin.financeiro.lancamentos');
    Route::post('lancamentos', 'storeLancamento')->name('admin.financeiro.lancamentos.store');
    Route::post('lancamentos/{id}/classificar', 'classificar')->name('admin.financeiro.classificar');
    Route::post('lancamentos/{id}/pagar', 'pagar')->name('admin.financeiro.pagar');

    Route::get('contas', 'contas')->name('admin.financeiro.contas');
    Route::post('contas/subconta', 'storeSubconta')->name('admin.financeiro.subconta');

    Route::get('caixa', 'caixa')->name('admin.financeiro.caixa');
    Route::post('caixa/fechar', 'fecharCaixa')->name('admin.financeiro.caixa.fechar');
    Route::post('caixa/conciliar', 'conciliar')->name('admin.financeiro.conciliar');
    Route::post('cobranca/contato', 'contatoCobranca')->name('admin.financeiro.cobranca');

    Route::get('relatorios', 'relatorios')->name('admin.financeiro.relatorios');
    Route::post('relatorios/enviar', 'enviarRelatorio')->name('admin.financeiro.relatorios.enviar');
    Route::post('relatorios/gerente', 'salvarRelatorioGerente')->name('admin.financeiro.relatorios.gerente');

    Route::get('dre', 'dre')->name('admin.financeiro.dre');
    Route::post('dre/congelar', 'congelar')->name('admin.financeiro.dre.congelar');
    Route::post('dre/reabrir', 'reabrir')->name('admin.financeiro.dre.reabrir');
    Route::get('analises', 'analises')->name('admin.financeiro.analises');
    Route::get('orcamento', 'orcamento')->name('admin.financeiro.orcamento');
    Route::post('orcamento', 'salvarOrcamento')->name('admin.financeiro.orcamento.salvar');
    Route::post('marco-zero', 'salvarMarcoZero')->name('admin.financeiro.marco_zero');
});

// Rotina: quadro de pátio, painel por cargo, diária de pátio e agenda.
Route::controller(RotinaController::class)->prefix('rotina')->group(function () {
    Route::get('patio', 'patio')->name('admin.rotina.patio');
    Route::get('minha-rotina', 'painel')->name('admin.rotina.painel');
    Route::post('checklist', 'checklist')->name('admin.rotina.checklist');
    Route::post('diaria/{id}', 'diaria')->name('admin.rotina.diaria');
    Route::get('agenda', 'agenda')->name('admin.rotina.agenda');
    Route::post('agenda', 'agendar')->name('admin.rotina.agendar');
    Route::post('agenda/{id}/status', 'statusAgendamento')->name('admin.rotina.agenda.status');
});

// Visão do mentor e importação do histórico das planilhas.
Route::controller(MentorController::class)->prefix('mentor')->group(function () {
    Route::get('', 'index')->name('admin.mentor.index');
    Route::post('replicar-metas', 'replicarMetas')->name('admin.mentor.replicar_metas');
    Route::post('replicar-pesos', 'replicarPesos')->name('admin.mentor.replicar_pesos');
});

Route::controller(HistoricoImportController::class)->prefix('settings/historico')->group(function () {
    Route::get('', 'index')->name('admin.historico.index');
    Route::post('', 'importar')->name('admin.historico.importar');
    Route::get('modelo/{tipo}', 'modelo')->name('admin.historico.modelo');
});

// Cadastros: colaboradores e Ficha de Enquadramento.
Route::controller(ColaboradorController::class)->prefix('colaboradores')->group(function () {
    Route::get('', 'index')->name('admin.colaboradores.index');
    Route::post('', 'store')->name('admin.colaboradores.store');
    Route::get('{id}/editar', 'edit')->name('admin.colaboradores.edit');
    Route::put('{id}', 'update')->name('admin.colaboradores.update');
    Route::post('{id}/desligar', 'desligar')->name('admin.colaboradores.desligar');
});

Route::controller(FichaController::class)->prefix('settings/ficha')->group(function () {
    Route::get('', 'edit')->name('admin.ficha.edit');
    Route::put('', 'update')->name('admin.ficha.update');
    Route::post('pendencias', 'pendencia')->name('admin.ficha.pendencia');
    Route::post('pendencias/{id}/resolver', 'resolverPendencia')->name('admin.ficha.pendencia.resolver');
    Route::post('margens', 'margens')->name('admin.ficha.margens');
    Route::get('acessos', 'acessos')->name('admin.ficha.acessos');
});

// Tela do técnico (celular ou tablet no box): só tarefas próprias, sem valores.
Route::controller(TecnicoController::class)->prefix('tecnico')->group(function () {
    Route::get('', 'index')->name('admin.tecnico.index');
    Route::get('tarefas', 'tasks')->name('admin.tecnico.tasks');
    Route::post('diagnostico/{id}/iniciar', 'startDiagnostico')->name('admin.tecnico.diagnostico.start');
    Route::post('diagnostico/{id}/laudo', 'saveLaudo')->name('admin.tecnico.diagnostico.laudo');
    Route::post('diagnostico/{id}/evidencia', 'addEvidence')->name('admin.tecnico.diagnostico.evidence');
    Route::post('diagnostico/{id}/peca', 'addPart')->name('admin.tecnico.diagnostico.part');
    Route::post('diagnostico/{id}/relacao-pecas', 'deliverParts')->name('admin.tecnico.diagnostico.parts');
    Route::post('diagnostico/{id}/finalizar', 'finishDiagnostico')->name('admin.tecnico.diagnostico.finish');
    Route::post('operacao/{itemId}/{acao}', 'operation')->whereIn('acao', ['iniciar', 'pausar', 'retomar', 'concluir'])->name('admin.tecnico.operation');
});

Route::controller(ImportController::class)->prefix('settings/import')->group(function () {
    Route::get('', 'index')->name('admin.import.index');

    Route::post('upload', 'upload')->name('admin.import.upload');

    Route::get('mapping', 'mapping')->name('admin.import.mapping');

    Route::post('preview', 'preview')->name('admin.import.preview');

    Route::post('run', 'import')->name('admin.import.run');

    Route::post('cancel', 'cancel')->name('admin.import.cancel');

    Route::get('template/{kind}', 'template')->name('admin.import.template');
});

Route::controller(CommissionController::class)->prefix('commissions')->group(function () {
    Route::get('', 'index')->name('admin.commissions.index');

    Route::get('export', 'export')->name('admin.commissions.export');
});

Route::get('marketing', [MarketingController::class, 'index'])->name('admin.marketing.index');
Route::get('marketing/export', [MarketingController::class, 'export'])->name('admin.marketing.export');

// Opens the Portal Impact installed on this PC (volmaster-impact:// link).
Route::view('impact', 'service_order::impact.open')->name('admin.impact.open');
