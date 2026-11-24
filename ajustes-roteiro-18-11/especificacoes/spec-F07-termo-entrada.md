# Especificação — F07-termo-entrada

Frente: **Termo de entrada e dados obrigatórios para sair do Cadastro** (item B2 do PDF; linhas 11, 14 e 43 do roteiro; alimenta CON-02 e CON-13).
Tamanho: **grande** (tabela nova, serviço novo, 3 controladores, fluxo público por link, PDF, tela de configuração, ajustes em gate/testes antigos).
Base: código lido em 18/11/2026. Abreviação usada neste arquivo: `SO/` = `packages/Webkul/ServiceOrder/src/`.

## Resumo (leia primeiro)

- O gate "Cadastro → Ordem de Serviço" **já existe** (`StageGate::cadastroParaOs`) e a lista "falta isto para avançar" **já aparece** na tela da OS (`partials/progress.blade.php`). O que falta é: validar os dígitos do CPF/CNPJ, exigir combustível, itens e **4 fotos** (hoje basta 1), tratar a autorização de rodagem como **resposta sim/não** (hoje precisa ser "sim" para sair do Cadastro), e — o maior buraco — o **aceite do cliente**: hoje é só uma caixinha "Termo assinado pelo cliente" sem nenhuma prova.
- Novo: tabela `service_order_termos` (prova do aceite: data, hora, IP, aparelho, texto aceito congelado, assinatura desenhada), assinatura na tela (canvas) e **link público com token** enviado ao WhatsApp do cliente, texto do termo configurável por oficina, PDF do termo, correção de CPF/CNPJ dentro da OS, "refazer o termo" com justificativa (nada se apaga) e trava do **início do test drive** sem autorização de rodagem (roteiro linha 43; a CNH da linha 42 entra junto porque é o mesmo ponto de código).
- **Nenhuma coluna nova em `quotes`**: `termo_assinado_em` e `autorizacao_rodagem` continuam sendo gravadas pelas duas vias, então CON-02 e CON-13 funcionam sem mexer em `IndicatorCalculator`.
- Piloto desligado = comportamento de hoje: o cartão novo só existe no bloco "Fluxo da OS" (que já é só do piloto), o gate retorna `[]`, o Novo Cadastro mantém a regra antiga de 11/14 dígitos e `startTeste` não trava. Três coisas inofensivas aparecem fora do piloto: a tela Configurações > Termo de entrada, o link para ela na Ficha (só com permissão) e a correção do texto de ajuda do CPF/CNPJ no Novo Cadastro (hoje diz "Opcional agora", embora o campo já seja obrigatório — provável origem do "CPF/CNPJ opcional" do PDF).
- Tolerância a banco antigo: se a tabela `service_order_termos` não existir (migrate-safe ainda não rodou), o gate usa só `termo_assinado_em` e a tela mostra o cartão no modo antigo (caixa "assinado"). Sem erro 500.

---

## O que já existe

### Gate e avanço de etapa
- `SO/Services/StageGate.php:23-26` `active()`; `:33-52` `missing()`; **`:112-158` `cadastroParaOs()`**:
  - `:117-119` CPF/CNPJ: só confere que tem algum dígito (não valida dígitos verificadores).
  - `:121-125` telefone do cliente.
  - `:127-151` bloco `if ($os->vehicle_id)` (software-only = OS sem veículo): placa (`:130`), km (`:134`), fotos `termo_foto` com `exists()` = **1 foto basta** (`:138-142`), `termo_assinado_em` (`:144-146`), `autorizacao_rodagem` **precisa ser verdadeira** (`:148-150`).
  - `:153-155` cor de crédito (frente F06 mexe aqui).
  - `:253-286` `testeParaFaturamento()` (autorização `:261-263`, CNH `:265-271`) e `:330-341` `cnhValida()`.
- `SO/Services/StageAdvanceService.php:34-81` `advance()` (devolve `faltando`; forçar exige justificativa e grava `bloqueio_forcado`).
- `SO/Observers/ServiceOrderObserver.php:60-63` grava `ficha_enviada_chefe` ao sair do Cadastro (base do CON-02); `:93-109` com o piloto ligado a etapa só muda pelo `StageAdvanceService`.
- `SO/Resources/views/partials/progress.blade.php:6-9` calcula `$missing`; `:25-35` bloco amarelo `data-testid="faltando-para-avancar"` com a lista; `:37-64` botão Avançar desabilitado e "Forçar avanço".
- `SO/Http/Controllers/ServiceOrderController.php:75-115` `updateStage`; `:184-232` `view` (passa `$pilot`, `$canSeeValues`).

### Termo / checklist de entrada (feito em 17/11)
- Colunas em `quotes` (migration `SO/Database/Migrations/2026_11_16_100100_add_workflow_columns_to_quotes_table.php:29-34`): `km_entrada`, `combustivel_entrada`, `itens_entrada`, `autorizacao_rodagem` (bool, padrão false), `termo_assinado_em`; `descricao_entrada` na `2026_11_17_130000`. Anexos em `service_order_attachments` (`:65-78`), tipos em `SO/Models/ServiceOrderAttachment.php:14-18` (`termo_foto`, `termo_video`).
- `SO/Models/ServiceOrder.php:105-106` casts de `autorizacao_rodagem`/`termo_assinado_em`; `:131` `COMBUSTIVEIS = ['Reserva','1/4','1/2','3/4','Cheio']`.
- `SO/Services/WorkflowService.php:44-67` **`saveTermo()`**: grava km/combustível/itens, fotos e — se vier `assinado` — carimba `termo_assinado_em` e grava o evento `termo_assinado`. Defeitos: (a) `:50` reescreve `autorizacao_rodagem` para **falso** a cada "Salvar termo" sem a caixa marcada; (b) "assinado" é só um atestado do consultor, sem IP, sem prova.
- `:79-109` `saveChecklistEntrada()` (usado pelo Novo Cadastro); `:394-405` `storeAttachment()` (disco `public`, pasta `service-orders/{id}/{tipo}`).
- `SO/Http/Controllers/WorkflowController.php:30-54` `saveTermo` (km e combustível obrigatórios; fotos `image|max:8192`, até 12); rota `SO/Routes/web.php:98` (`admin.service_orders.workflow.termo`), ACL em `SO/Config/acl.php:45` (chave `service_orders.edit`).
- `SO/Resources/views/partials/workflow.blade.php:42-101` cartão "1. Termo de entrada" (formulário simples + caixa "Termo assinado pelo cliente" `:90-96`); `:20-21` eventos restritos ao técnico; `:496-498` mostra a justificativa do `bloqueio_forcado` na linha do tempo.
- `SO/Http/Controllers/CadastroController.php:104-249` Novo Cadastro: **CPF/CNPJ `required`** mas só confere 11 ou 14 dígitos (`:122-126`); checklist opcional (`:155-168`, `:232-241`); `:53-70` `limiteUpload()` (min de `upload_max_filesize`/`post_max_size`). View `SO/Resources/views/cadastro/create.blade.php:70-72` tem o texto **"Opcional agora, mas necessário mais pra frente…"**, que contradiz o `required` (provável origem do "CPF/CNPJ opcional" do PDF); `:328-421` bloco do checklist (fotos até 20, vídeos até 3).
- `SO/Resources/views/partials/checklist-entrada.blade.php` cartão só de leitura (aparece com ou sem piloto).

### Indicadores e eventos
- `SO/Services/Indicators/IndicatorCalculator.php:83` CON-02 → `fichas()` (`:439-444`: `ficha_devolucoes === 0 && termo_assinado_em`); `:94` CON-13 (`termo_assinado_em && autorizacao_rodagem`, base = OS com evento `chegada_veiculo`). Catálogo: `IndicatorCatalog.php:43,54`.
- `SO/Models/ServiceOrderEvent.php:26-96` `TIPOS` (`:30` `termo_assinado`); `:132-156` `record()` — `usuarioId: 0` grava `usuario_id` nulo (ação sem usuário); tipo desconhecido lança exceção.

### Link público, WhatsApp, PDF, LGPD
- Link público com token: `SO/Routes/public.php:10-14` (`pos-venda/{token}`), `SO/Http/Controllers/PublicFeedbackController.php`, `SO/Models/ServiceOrderFeedback.php:30-35` (UUID); acompanhamento com URL assinada (`public.php:16-18`, `tracking-link.blade.php`) — **URL assinada não serve para o termo**: não expira por padrão, não revoga, não marca uso. Rota pública sem CSRF: `public.php:27-30` (`->withoutMiddleware([VerifyCsrfToken::class])`, funciona porque `ValidateCsrfToken` é subclasse).
- WhatsApp: `packages/Webkul/WhatsApp/src/Services/WhatsAppCloudClient.php:38-75` `sendTemplate($to,$template,$bodyParams,$quoteId)` (só modelo aprovado; só parâmetros de corpo; já grava `whatsapp_message_logs` e devolve mensagem de erro em português), `Support/BrazilianPhone.php:15-52`, modelos nomeados em `whatsapp_settings` (orçamento, diagnóstico, pós-venda — **não há modelo de termo**). Padrão de envio com link: `SO/Http/Controllers/ServiceOrderStageController.php:574-607` `sendPosVenda`; escolha do número: `:613-618` `clientWhatsApp()` (protegido) e igual em `partials/orcamento-send.blade.php:29-33`.
- PDF: `SO/Resources/views/print.blade.php` (cabeçalho da oficina via `FiscalSettings`, fonte DejaVu, logo em data URI) e `SO/Http/Controllers/PainelDonoController.php:37-44` (`Pdf::loadView(...)->setPaper('a4','portrait')`). `barryvdh/laravel-dompdf` e `mpdf` já instalados.
- LGPD: `SO/Http/Middleware/RegistraAcessoSensivel.php:17-19` (`PREFIXOS`) grava quem abriu telas sensíveis em `acessos_sensiveis` (migration `2026_11_17_100800`), lido em `FichaController::acessos():111-117`. Só registra rotas `admin.*` de equipe (user, rota, método, IP, hora).
- Multi-oficina: `packages/Webkul/Tenant/src/Http/Middleware/ResolveTenant.php:156-179` resolve a oficina pelo subdomínio, inclusive nas rotas públicas; disco `public` é por oficina (`:107-123`). Disco privado `local` **não** é por oficina e se perde a cada deploy → a assinatura desenhada vai **no banco**, não em arquivo.
- `bootstrap/app.php` não configura `trustProxies`; `config/app.php:82` tem fuso padrão `Asia/Kolkata` (vem do `.env`).

### Configuração
- `SO/Services/WorkshopSettings.php:16-131` `DEFAULTS` (garantias `:71-72`), `get()/set()`; `SO/Http/Controllers/FichaController.php`; menu de configurações `SO/Config/menu.php:213-225`.

### CPF/CNPJ
- **Não existe nenhum validador de dígitos no projeto.** Fiscal só confere tamanho (`packages/Webkul/Fiscal/src/Http/Controllers/FiscalController.php:219-222,331-334`), Asaas só tira os não-dígitos (`SO/Services/AsaasClient.php:85`). O atributo `cpf_cnpj` de `persons` é EAV opcional, sem validação (`packages/Webkul/Installer/src/Database/Migrations/2026_08_13_100000_add_cpf_cnpj_attribute_in_attributes_table.php:19-31`; `AttributeForm.php:84-93` só aplica a regra do campo `validation`). Não se instala pacote → validador próprio.
- **Armadilha de teste**: `tests/Feature/Workshop/Concerns/BuildsWorkshop.php:143` cria o cliente com `12.345.678/0001-90`, que **é inválido** (o dígito certo seria `...-95`; conferido por cálculo). Com validação de dígitos, todo teste que passa pelo gate do Cadastro quebra se o helper não for corrigido.

### Testes existentes que esta frente afeta
`tests/Feature/Workshop/StageGateTest.php:82-108`, `HttpFlowTest.php:72-106`, `ChecklistCadastroTest.php:108-114`, `Concerns/BuildsWorkshop.php:143`.

### Lacunas (o que realmente falta)
1. Validador de dígitos de CPF/CNPJ (e uso no gate e no Novo Cadastro).
2. Gate exigir combustível, itens do veículo e N fotos (configurável, padrão 4).
3. Autorização de rodagem como resposta sim/não do cliente (hoje: "sim" obrigatório; "não" trava a OS no Cadastro).
4. Aceite do cliente com prova: assinatura na tela e link público; data, hora, IP, aparelho; OS mostra o aceite.
5. Texto do termo configurável (variáveis) + congelar o texto aceito; PDF do termo.
6. Bloqueio de **iniciar** o test drive sem autorização de rodagem (hoje só bloqueia ao avançar para Faturamento — `ServiceOrderStageController::startTeste:440-457` não confere nada).
7. Correção do CPF/CNPJ dentro da OS e "refazer o termo" com justificativa.
8. Registro de acesso LGPD para PDF/assinatura/link.

---

## Requisitos do PDF → como serão atendidos

| # | Requisito (B2 / roteiro) | Situação hoje | Como será atendido |
|---|---|---|---|
| 1 | CPF/CNPJ **válido (validar dígitos)** | PARCIAL: presença (`StageGate:117-119`); Novo Cadastro exige preencher mas só confere tamanho | `Support/CpfCnpj` (CPF, CNPJ numérico e alfanumérico) + regra `DocumentoValido`; gate usa; Novo Cadastro valida dígitos **só com o piloto ligado**; correção do documento dentro da OS |
| 2 | Placa | EXISTE (`StageGate:130`) | Mantém; mesma mensagem `Placa do veículo` |
| 3 | Km | EXISTE (`:134`; 0 conta como faltando) | Mantém; mensagem `Km de entrada` |
| 4 | Nível de combustível | FALTA no gate (campo existe) | Gate exige valor de `ServiceOrder::COMBUSTIVEIS` |
| 5 | **≥ 4 fotos** | PARCIAL (basta 1) | Conta `termo_foto` (Novo Cadastro + cartão do termo); mínimo = `WorkshopSettings 'termo_min_fotos'` (padrão 4). Vídeos não contam |
| 6 | Itens do veículo | FALTA no gate (campo `itens_entrada` existe, opcional) | Gate exige texto; caixa "Não há itens deixados nem avarias" grava a frase padrão (não obriga a mentir) |
| 7 | Autorização de rodagem **(sim/não)** | PARCIAL (exige "sim"; "não" trava a OS; reescrita para falso a cada salvar) | O cliente responde sim/não **no próprio aceite**; "não" **sai** do Cadastro; o teste não inicia sem "sim"; "sim" posterior via ação registrada com canal/observação |
| 8 | Aceite do cliente no termo | PARCIAL (caixinha sem prova) | Assinatura na tela (canvas) **ou** link público de uso único/idempotente; ambos gravam `termo_assinado_em`, `autorizacao_rodagem`, linha em `service_order_termos` e evento `termo_assinado` |
| 9 | Data, hora e **IP** do aceite | FALTA | `aceito_em`, `aceito_ip`, `aceito_user_agent` (+ nome digitado, quem colheu, código de verificação); `dados_json` do evento repete |
| 10 | OS mostra o aceite (roteiro 14) | FALTA | Cartão do termo mostra "Aceito em dd/mm/aaaa hh:mm:ss, canal, IP, aparelho"; IP/aparelho só para quem tem `service_orders.termo` |
| 11 | Exceção: serviço só de software dispensa fotos, km e combustível | EXISTE (`if ($os->vehicle_id)`; `StageGateTest:102`) | Mantém, centralizado em `TermoEntradaService::exige()`; dispensa também placa, itens, autorização e termo; **CPF/CNPJ válido e telefone continuam exigidos** |
| 12 | Alimenta CON-02 e CON-13 | EXISTE (leem as colunas) | As duas vias gravam as mesmas colunas; teste de integração |
| 13 | Botão de avançar mostra a lista do que falta (roteiro 11) | EXISTE (`progress.blade.php:25-35`) | Só muda o conteúdo da lista (novos itens e textos) + link "Ir para o termo de entrada" |
| 14 | Texto do termo configurável (nome/placa/data…) | FALTA | `WorkshopSettings 'termo_entrada_texto'` (vazio = texto padrão) com variáveis `{placa}` etc.; tela Configurações > Termo de entrada; texto aceito é congelado (snapshot + hash) |
| 15 | Impressão / PDF do termo assinado | FALTA | `GET admin/service-orders/{id}/termo/pdf` (DomPDF, inline) com texto, dados, assinatura e prova do aceite; sem aceite sai como "MINUTA" |
| 16 | LGPD | FALTA | Aviso na página pública; guarda mínima (IP, aparelho truncado); acesso da equipe ao PDF/assinatura/link entra em `acessos_sensiveis`; documento mascarado em eventos; nada é apagado |
| 17 | Link por WhatsApp | PARCIAL (infra de envio existe) | `WhatsAppCloudClient::sendTemplate` com modelo configurável (`termo_whatsapp_template`, padrão `termo_entrada`); fallback "Abrir no WhatsApp" (`wa.me`) e "Copiar link" quando a API não está configurada |
| 18 | Roteiro 43: iniciar teste sem autorização de rodagem bloqueado | FALTA (só trava ao avançar) | `StageGate::missingParaIniciarTeste()` chamado em `startTeste` (roteiro 42, CNH, vai no mesmo método) |
| 19 | Piloto desligado não bloqueia | — | Gate já retorna `[]`; Novo Cadastro mantém a regra antiga; cartão novo só dentro do bloco do piloto |

---

## Mudanças por arquivo

### Desenho do fluxo (para entender os arquivos)

```
Consultor (cartão "1. Termo de entrada" na OS, piloto ligado)
  1. confere/ corrige CPF/CNPJ (valida dígitos)  -> Services/Termo + Support/CpfCnpj
  2. preenche km, combustível, itens e envia >= N fotos (WorkflowController::saveTermo)
  3. colhe o aceite — com os dados completos (senão recusa e diz o que falta):
       A) assinatura na tela:  POST termo/assinar  (canvas + resposta sim/não + nome + "li e concordo")
       B) link:                POST termo/link     (gera token; envia modelo ao WhatsApp ou mostra o link)
                               Cliente: GET/POST /termo/{token} (público, sem login, uso único/idempotente)
  4. aceite grava: service_order_termos (prova) + quotes.termo_assinado_em/autorizacao_rodagem + evento termo_assinado
  5. StageGate::cadastroParaOs deixa de listar o aceite -> botão Avançar libera
Correções: "Refazer o termo" (justificativa) -> aceite antigo vira 'substituido', termo_assinado_em volta a nulo.
Autorização depois: ação "Registrar autorização de rodagem" (canal + observação) -> autorizacao_rodagem=true + evento.
```

Estados de `service_order_termos.status`: `pendente` (link gerado, ainda não aceito), `aceito`, `substituido` (refeito), `revogado` (link trocado por outro ou substituído por assinatura na tela). Linhas **nunca** são apagadas nem editadas depois de `aceito` (exceto virar `substituido`); isso é garantido no model.

### Passos de implementação sugeridos (ordem)
1. `Support/CpfCnpj` + `DocumentoValido` + `CpfCnpjTest`.
2. Migration + model `ServiceOrderTermo`.
3. `TermoTexto` + `TermoEntradaService`.
4. `StageGate` (gate do Cadastro + `missingParaIniciarTeste`), `WorkflowService::saveTermo`, `WorkflowController::saveTermo`, `CadastroController`.
5. Rotas, ACL, menu, `WorkshopSettings`, eventos, `RegistraAcessoSensivel`, textos.
6. Controladores novos (equipe, público, configuração) e views (cartão, público, PDF, configuração).
7. Ajustar testes antigos e escrever os novos; `pint`; rodar `--filter=Termo`, `--filter=CpfCnpj`, `--filter=Workshop`.

### CRIAR

#### 1. `SO/Support/CpfCnpj.php`
Classe estática. Código de referência (já conferido à mão: CPF `529.982.247-25` válido; CNPJ `12.ABC.345/01DE-35` válido pelo algoritmo "valor = ord(caractere) − 48", mesmo módulo 11):

```php
namespace Webkul\ServiceOrder\Support;

class CpfCnpj
{
    /** Só letras e números, em maiúsculas. */
    public static function normalizar(?string $valor): string
    {
        return strtoupper((string) preg_replace('/[^0-9A-Za-z]/', '', (string) $valor));
    }

    /** 'cpf' (11 dígitos), 'cnpj' (12 numéricos ou letras + 2 dígitos) ou null; NÃO confere os dígitos verificadores. */
    public static function tipo(?string $valor): ?string
    {
        $n = static::normalizar($valor);

        return match (true) {
            (bool) preg_match('/^\d{11}$/', $n) => 'cpf',
            (bool) preg_match('/^[0-9A-Z]{12}\d{2}$/', $n) => 'cnpj',
            default => null,
        };
    }

    public static function valido(?string $valor): bool
    {
        $n = static::normalizar($valor);

        return match (static::tipo($n)) {
            'cpf' => static::validarCpf($n),
            'cnpj' => static::validarCnpj($n),
            default => false,
        };
    }

    public static function validarCpf(string $d): bool
    {
        if (! preg_match('/^\d{11}$/', $d) || preg_match('/^(\d)\1{10}$/', $d)) {
            return false;
        }

        for ($t = 9; $t < 11; $t++) {
            $soma = 0;

            for ($i = 0; $i < $t; $i++) {
                $soma += (int) $d[$i] * (($t + 1) - $i);
            }

            if ((int) $d[$t] !== (($soma * 10) % 11) % 10) {
                return false;
            }
        }

        return true;
    }

    public static function validarCnpj(string $c): bool
    {
        if (! preg_match('/^[0-9A-Z]{12}\d{2}$/', $c) || preg_match('/^(.)\1{13}$/', $c)) {
            return false;
        }

        $v = array_map(fn ($ch) => ord($ch) - 48, str_split($c));

        foreach ([[12, [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2]], [13, [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2]]] as [$n, $pesos]) {
            $soma = 0;

            for ($i = 0; $i < $n; $i++) {
                $soma += $v[$i] * $pesos[$i];
            }

            $resto = $soma % 11;

            if ($v[$n] !== ($resto < 2 ? 0 : 11 - $resto)) {
                return false;
            }
        }

        return true;
    }

    /** 000.000.000-00 ou 00.000.000/0000-00; se não tiver a forma de nenhum dos dois, devolve como veio (trim). */
    public static function formatar(?string $valor): string { /* preg_replace conforme o tipo() */ }

    /** ***.982.247-** / **.222.333/****-** (para evento, log e telas sem permissão). */
    public static function mascarar(?string $valor): string { /* CPF: '***.'.substr(3,3).'.'.substr(6,3).'-**'; CNPJ: '**.'.substr(2,3).'.'.substr(5,3).'/****-**'; outro: asteriscos + 2 últimos */ }
}
```
CNPJ alfanumérico: a Receita passou a emitir CNPJ com letras a partir de julho/2026 (IN RFB 2.229/2024). Fica aceito aqui; ver **Riscos** (Fiscal/Asaas ainda removem letras).

#### 2. `SO/Support/DocumentoValido.php`
`implements Illuminate\Contracts\Validation\ValidationRule`; `validate($attribute, $value, Closure $fail)`: se `! CpfCnpj::valido(is_string($value) ? $value : null)` → `$fail('CPF/CNPJ inválido: confira os dígitos.')` (mensagem fixa, não usa `:attribute`).

#### 3. `SO/Support/LimiteUpload.php`
`public static function bytes(): int` — move para cá a lógica de `CadastroController::limiteUpload()` (`:53-70`: menor entre `upload_max_filesize` e `post_max_size`, 0 = desconhecido). `CadastroController::limiteUpload()` passa a só `return LimiteUpload::bytes();`. O cartão do termo usa para avisar o limite (mesma ideia da tela de Cadastro).

#### 4. `SO/Models/ServiceOrderTermo.php`
- `$table = 'service_order_termos'`; `$guarded = []`; `$hidden = ['assinatura_png', 'token_hash']`.
- Constantes: `STATUS_PENDENTE='pendente'`, `STATUS_ACEITO='aceito'`, `STATUS_SUBSTITUIDO='substituido'`, `STATUS_REVOGADO='revogado'`; `CANAL_TELA='assinatura_tela'`, `CANAL_LINK='link_whatsapp'`.
- Casts: `dados_json`=>`array`, `autorizacao_rodagem`=>`boolean`, `link_enviado_em|expira_em|revogado_em|aceito_em|substituido_em`=>`datetime`.
- Imutável (mesmo espírito de `ServiceOrderEvent`):
  ```php
  protected static function booted(): void
  {
      static::deleting(fn () => throw new LogicException('Termos de entrada não podem ser apagados; refaça o termo com justificativa.'));

      static::updating(function (self $termo) {
          if ($termo->getOriginal('status') !== self::STATUS_ACEITO) {
              return;
          }

          $livres = ['status', 'substituido_em', 'substituido_por', 'substituido_motivo', 'updated_at'];

          if (array_diff(array_keys($termo->getDirty()), $livres)) {
              throw new LogicException('O aceite do cliente não pode ser alterado; refaça o termo.');
          }
      });
  }
  ```
- `quote()` → `belongsTo(ServiceOrder::class, 'quote_id')->withoutGlobalScopes()`; `criador()`/`substituidor()` → `User`.
- `scopeLeve($q)`: `select` de todas as colunas **menos** `assinatura_png` e `texto_snapshot` (a tela da OS não precisa carregar a imagem a cada abertura; o PDF carrega completo).

#### 5. `SO/Services/Termo/TermoTexto.php`
Texto e variáveis (sem Blade, sem `eval`; saída sempre escapada na view).
- `public const VARIAVEIS` (chave => descrição para a tela de configuração): `cliente_nome`, `cliente_documento`, `oficina_nome`, `oficina_cnpj`, `os_numero` (6 dígitos, igual à impressão), `veiculo` (marca e modelo), `placa`, `km` (com milhar), `combustivel`, `itens`, `fotos_qtd`, `data` (dd/mm/aaaa da abertura da OS), `hora` (hh:mm), `garantia_servico_dias`, `garantia_pecas_dias` (da Ficha), `autorizacao_rodagem` (vira `AUTORIZA` ou `NÃO AUTORIZA`; na prévia, `(o cliente responderá no aceite)`). **Sem variáveis de dinheiro** (o técnico vê o termo e não pode ver valores).
- Sintaxe `{variavel}`. `render(string $modelo, array $valores): string` com `preg_replace_callback('/\{([a-z_]+)\}/', …)`: variável conhecida vira o valor (`null` → `''`; `itens` vazio → `(não informado)`); desconhecida fica como está; passagem única (valor nunca é reinterpretado); normaliza `\r\n` para `\n`.
- `desconhecidas(string $modelo): array` (nomes não suportados) e `padrao(): string` (texto abaixo), `modelo(): string` = `trim((string) WorkshopSettings::get('termo_entrada_texto')) ?: padrao()`, `exemplo(): array` (dados de exemplo para a prévia).
- **Texto padrão** (sugestão a ser revisada por advogado — ver Decisões):

```
TERMO DE ENTRADA DE VEÍCULO — OS nº {os_numero}

{oficina_nome} (CNPJ {oficina_cnpj}) recebeu em {data}, às {hora}, o veículo {veiculo}, placa {placa}, sob a responsabilidade de {cliente_nome} (CPF/CNPJ {cliente_documento}), para os serviços pedidos na OS acima.

CONDIÇÕES DO VEÍCULO NA ENTRADA
• Quilometragem (hodômetro): {km} km
• Nível de combustível: {combustivel}
• Itens deixados no veículo e avarias: {itens}
• Fotos registradas na entrada: {fotos_qtd}

AUTORIZAÇÃO DE RODAGEM
O cliente {autorizacao_rodagem} a realização de teste de rodagem (circulação em via pública) com o veículo, por profissional habilitado, pelo tempo e na distância necessários ao diagnóstico e à conferência do serviço. A movimentação do veículo dentro das dependências da oficina é sempre autorizada.

CIÊNCIA DO CLIENTE
1. As condições, os itens e as avarias descritos acima, e as fotos tiradas na entrada, correspondem ao estado em que o veículo foi recebido.
2. Nenhum serviço será executado sem a aprovação do orçamento pelo cliente.
3. Garantia: {garantia_servico_dias} dias para os serviços e {garantia_pecas_dias} dias para as peças, nas condições do orçamento aprovado.
4. Veículo pronto e não retirado no prazo combinado pode gerar diária de pátio, conforme a tabela da oficina.
5. A oficina trata o nome, o CPF/CNPJ e o telefone do cliente, e guarda a data, a hora, o endereço IP e o aparelho usados neste aceite, apenas para executar o serviço, emitir os documentos fiscais e comprovar este termo (Lei nº 13.709/2018 - LGPD).

Ao aceitar, o cliente declara que leu e concorda com este termo.
```

#### 6. `SO/Services/Termo/TermoEntradaService.php` (regra de negócio central)
Resolvido pelo container (`app(TermoEntradaService::class)`). **Mensagens de pendência do gate ficam escritas em português dentro de constantes da classe (como as demais mensagens do `StageGate`), nunca via `trans()`** — o locale do usuário não pode mudar o texto (os testes comparam o texto exato).

Constantes (texto exato):
```php
public const PEND_DOC_AUSENTE   = 'CPF/CNPJ do cliente';
public const PEND_DOC_INVALIDO  = 'CPF/CNPJ do cliente inválido: confira os dígitos';
public const PEND_PLACA         = 'Placa do veículo';
public const PEND_KM            = 'Km de entrada';
public const PEND_COMBUSTIVEL   = 'Nível de combustível na entrada';
public const PEND_FOTOS         = 'Fotos do veículo: faltam %d (mínimo %d)';
public const PEND_ITENS         = 'Itens do veículo e avarias (ou marque que não há)';
public const PEND_ACEITE        = 'Aceite do cliente no termo de entrada, com a autorização de rodagem (sim ou não)';
public const SEM_ITENS_TEXTO    = 'Nenhum item deixado no veículo e nenhuma avaria aparente.';
public const CANAIS_AUTORIZACAO = ['WhatsApp', 'Telefone', 'Presencial', 'E-mail'];
```

Assinaturas e regras:
```php
// disponibilidade da tabela (padrão PrevisaoOs::disponivel(): memoizado)
public static function disponivel(): bool                 // static::$disponivel ??= Schema::hasTable('service_order_termos')
public static function definirDisponibilidade(?bool $valor): void   // seam de teste (simular banco não migrado); null = esquecer

// regras vindas da configuração da oficina
public function exige(ServiceOrder $os): bool            // (bool) $os->vehicle_id  -> ÚNICO ponto que decide "serviço só de software"; F13 pode acrescentar chamado externo aqui
public function minimoDeFotos(): int                     // max(1, (int) WorkshopSettings::get('termo_min_fotos', 4))
public function validadeDoLinkMinutos(): int             // clamp((int) WorkshopSettings::get('termo_link_validade_min', 2880), 5, 43200)

// fonte única da lista (cartão e gate leem daqui)
/** @return array<string, array{ok: bool, rotulo: string, detalhe: ?string, pendencia: string}> chaves: documento, placa, km, combustivel, fotos, itens, aceite */
public function checklist(ServiceOrder $os): array
public function pendenciasDoDocumento(ServiceOrder $os): array              // 0 ou 1 mensagem (ausente | inválido)
public function pendenciasDoVeiculo(ServiceOrder $os, bool $comAceite = true): array   // placa, km, combustível, fotos, itens [, aceite] — na ordem do PDF
public function prontoParaAceite(ServiceOrder $os): array                   // documento + veículo sem o aceite (usado antes de colher o aceite)
```
Regras do `checklist()`:
- `documento`: ausente se `CpfCnpj::normalizar($person?->cpf_cnpj) === ''`; inválido se `! CpfCnpj::valido(...)`.
- `placa`: `$os->vehicle?->placa`; `km`: `(int) $os->km_entrada > 0`; `combustivel`: `in_array($os->combustivel_entrada, ServiceOrder::COMBUSTIVEIS, true)`.
- `fotos`: `attachments()->where('tipo', TIPO_TERMO_FOTO)->count()` ≥ `minimoDeFotos()`; pendência `sprintf(PEND_FOTOS, min - tem, min)`; detalhe `"{tem} de {min}"`.
- `itens`: `trim((string) $os->itens_entrada) !== ''`.
- `aceite`: **ok se `$os->termo_assinado_em` não é nulo** (compatível com OS marcadas pela caixa antiga; a prova nova fica em `service_order_termos`). Se a tabela não existe, é a única fonte.
- Se `! exige($os)`: o `checklist()` devolve **só** `documento`.

Fluxos:
```php
public function textoPara(ServiceOrder $os, ?bool $autoriza = null): string          // TermoTexto::render(TermoTexto::modelo(), valoresDoTexto())
public function valoresDoTexto(ServiceOrder $os, ?bool $autoriza = null): array
public function versao(ServiceOrder $os): string      // sha256 do que o cliente enxerga (texto com marcador no lugar da autorização + dados + ids das fotos + documento + nome)
public function atual(ServiceOrder $os): ?ServiceOrderTermo       // status aceito, mais recente
public function link(ServiceOrder $os): ?ServiceOrderTermo        // status pendente, mais recente
public function historico(ServiceOrder $os): Collection          // todas, mais nova primeiro (scopeLeve)

public function assinarNaTela(ServiceOrder $os, array $dados, Request $request): ServiceOrderTermo
// $dados: autorizacao_rodagem ('sim'|'nao'), nome_assinante, assinatura (data URI PNG), versao. Dentro de DB::transaction com lockForUpdate na OS:
//  1. se $os->termo_assinado_em -> ValidationException 'O termo já foi aceito em dd/mm/aaaa hh:mm.'
//  2. prontoParaAceite() não vazio -> ValidationException 'Complete antes de colher o aceite: …'
//  3. $dados['versao'] !== versao($os) -> ValidationException (dados mudaram; recarregar)
//  4. assinaturaValida(): data URI 'data:image/png;base64,', <= 600 KB decodificado, getimagesizefromstring() = image/png até 2000x1000,
//     e (se GD) pelo menos ~150 pixels não transparentes (assinatura em branco é recusada)
//  5. revoga links pendentes da OS (status revogado, revogado_em)
//  6. cria a linha status 'aceito', canal assinatura_tela, texto_snapshot, texto_hash, versao, dados_json, aceito_em=now(), aceito_ip=$request->ip(),
//     aceito_user_agent=Str::limit($request->userAgent(), 250, ''), aceito_nome, assinatura_png, criado_por=auth user
//  7. $os->forceFill(['termo_assinado_em' => now(), 'autorizacao_rodagem' => $autoriza])->save()
//  8. ServiceOrderEvent::record($os, 'termo_assinado', [...]) (usuário logado)

public function gerarLink(ServiceOrder $os): array   // ['termo' => ServiceOrderTermo, 'url' => string]
// exige prontoParaAceite() vazio e termo não aceito; revoga links pendentes; token = Str::random(48) (SÓ alfanumérico);
// grava token_hash = hash('sha256', $token), expira_em = now()->addMinutes(validadeDoLinkMinutos()), criado_por, e o evento termo_link_gerado {termo_id, expira_em};
// url = route('service_orders.termo.show', $token); o token puro NUNCA é gravado (aparece uma vez na tela, via flash).

public function enviarLinkPorWhatsApp(ServiceOrder $os, WhatsAppCloudClient $client): array
// ['enviado' => bool, 'url' => string, 'telefone' => ?string, 'wa_me' => ?string, 'mensagem' => ?string]
//  - número: ClienteContatos::whatsappDe($person->contact_numbers) (novo helper); sem número / inválido (BrazilianPhone::e164 nulo) -> mensagem clara, NÃO gera link
//  - gera o link; chama $client->sendTemplate($numero, WorkshopSettings::get('termo_whatsapp_template'), [nome, oficina, placa, url, validadeTexto], $os->id)
//    parâmetros sem quebra de linha/tabulação (preg_replace('/\s+/', ' ', …)); validadeTexto = '48 horas' ou '30 minutos'
//  - sucesso: link_enviado_em=now(), link_destino=telefone mascarado, evento termo_link_enviado (além do termo_link_gerado de gerarLink)
//  - falha (sem credenciais, modelo não aprovado…): mantém o link gerado, devolve 'mensagem' do cliente WhatsApp e o 'wa_me'
//    (https://wa.me/55DDNNNN?text=…); não grava evento de envio (a equipe copia ou abre no WhatsApp)

public function localizarPorToken(string $token): ?ServiceOrderTermo   // regex /^[A-Za-z0-9]{48}$/ antes de consultar; busca por hash
public function estadoDoLink(ServiceOrderTermo $t): string              // aceito | substituido | revogado | expirado | valido (os de OS cancelada = revogado)

public function aceitarPorLink(string $token, array $dados, Request $request): ServiceOrderTermo
// $dados: autorizacao_rodagem, nome, versao. DB::transaction: lockForUpdate na linha do termo e depois na OS.
//  - já 'aceito' -> devolve a mesma linha SEM gravar nada (idempotente)
//  - estado != valido -> throw TermoIndisponivelException($motivo)
//  - OS já com termo_assinado_em (assinado na tela no meio tempo) -> revoga o link e lança TermoIndisponivelException('revogado')
//  - versao diferente -> ValidationException (mostrar o texto atual)
//  - mesmas gravações da tela (sem assinatura_png, canal link_whatsapp), evento com usuarioId: 0 (NUNCA atribuir ao usuário logado do mesmo navegador)

public function reabrir(ServiceOrder $os, string $justificativa): void
// só com termo aceito e etapa Cadastro/Ordem de Serviço; justificativa >= 10 caracteres;
// linha aceita -> status substituido (+substituido_em/por/motivo); revoga links pendentes; $os->termo_assinado_em = null; evento termo_reaberto {termo_id, justificativa}

public function registrarAutorizacao(ServiceOrder $os, string $canal, ?string $observacao): void
// exige termo aceito e autorizacao_rodagem falsa; canal em CANAIS_AUTORIZACAO; $os->autorizacao_rodagem = true; evento autorizacao_rodagem_registrada {canal, observacao, termo_id}

public function corrigirDocumento(ServiceOrder $os, string $valor): void
// recusa se termo aceito (refazer antes); recusa se ! CpfCnpj::valido; grava via
// app(AttributeValueRepository::class)->save(['entity_type'=>'persons','entity_id'=>$person->id,'cpf_cnpj'=>CpfCnpj::formatar($valor)]);
// evento documento_cliente_corrigido {antes: mascarar(), depois: mascarar()} — nunca o número inteiro no evento

public static function dispositivo(?string $userAgent): string   // "iPhone · Safari", "Android · Chrome"… (regex simples; desconhecido = "aparelho desconhecido")
public function oficina(): array                                  // ['nome' => FiscalSettings::first() nome_fantasia ?: razao_social ?: tenant atual ?: app.name, 'cnpj' => FiscalSettings cnpj ?: WorkshopSettings 'ficha_cnpj']
```
Dados congelados no aceite: `dados_json` = `{placa, veiculo, km, combustivel, itens, fotos:[ids dos anexos], documento_mascarado, cliente, os_numero}`; `texto_snapshot` = texto já renderizado com a resposta da autorização; `texto_hash = hash('sha256', texto_snapshot."\n".json_encode(dados))`. Código de verificação exibido = 10 primeiros caracteres do `texto_hash` em maiúsculas.

`SO/Services/Termo/TermoIndisponivelException.php`: `RuntimeException` com `public string $motivo` (`expirado|revogado|substituido`). Só para o controlador público escolher a página.

#### 7. `SO/Http/Controllers/TermoEntradaController.php` (equipe; estende `Webkul\Admin\Http\Controllers\Controller`)
Replica `order(int $id)` do `WorkflowController:266-282` (visibilidade por `getAuthorizedUserIds()`/mecânico) e o padrão `run()` (flash + redirect para `admin.service_orders.view`, `ValidationException` → `session('error')` com a 1ª mensagem).
- `assinar(int $id)` — valida: `assinatura` required string (começa com `data:image/png;base64,`, max 800000 caracteres), `autorizacao_rodagem` required in `sim,nao`, `nome_assinante` required string 3–160, `confirmo` accepted, `versao` required size 64. Mensagens pt-BR (ver Textos). Chama `assinarNaTela`.
- `link(int $id)` — `acao` in `enviar,gerar`. `enviar` → `enviarLinkPorWhatsApp`; `gerar` → `gerarLink`. Guarda o link/wa.me no flash da sessão (`termo_link`) para o cartão mostrar **uma vez**.
- `pdf(int $id)` — `Pdf::loadView('service_order::termo.pdf', …)->setPaper('a4','portrait')->stream("termo-entrada-os-{$id}.pdf")`; `app()->setLocale('pt_BR')` antes; sem aceite sai como minuta.
- `reabrir(int $id)` — `justificativa` required string 10–500.
- `documento(int $id)` — `cpf_cnpj` required, regra `new DocumentoValido`.
- `autorizacao(int $id)` — `canal` in `CANAIS_AUTORIZACAO`, `observacao` nullable max 255.

#### 8. `SO/Http/Controllers/PublicTermoController.php` (estende `Illuminate\Routing\Controller`, como `PublicFeedbackController`)
- Todas as respostas: `Cache-Control: no-store`, `Referrer-Policy: no-referrer`, `X-Robots-Tag: noindex, nofollow`; `app()->setLocale('pt_BR')`. **Sem sessão**: erro de validação **não** usa `back()->withErrors()`; devolve a própria view com `$errors` e status 422 (WhatsApp/navegador embutido às vezes bloqueia cookie).
- `show(string $token)` (GET, **sem nenhum efeito colateral** — previews de link do WhatsApp e antivírus fazem GET): token inválido → view `public/termo-indisponivel` com **404** e texto genérico (não revela se existiu); `expirado|revogado|substituido` → mesma view, **410**; `aceito` → página de comprovante (data/hora, "pode fechar esta página"); `valido` → página do termo.
- `accept(string $token)` (POST): valida `autorizacao_rodagem` in `sim,nao`, `nome` 3–160, `confirmo` accepted, `versao` size 64; chama `aceitarPorLink`; sucesso → `redirect()->route('service_orders.termo.show', $token)` (PRG, funciona sem sessão); `TermoIndisponivelException` → 410; versão diferente → página do termo atualizada com aviso (422).
- Dados que a página mostra: nome da oficina, texto do termo (renderizado, com `autorizacao_rodagem` = "(sua resposta abaixo)"), tabela "Dados registrados na entrada" (placa, veículo, km, combustível, itens, fotos em miniatura), pergunta de autorização (sim/não), nome completo, "li e concordo", botão, aviso LGPD. **Nunca** crédito, valores, pagamento, nem dados de outras OS/clientes.

#### 9. `SO/Http/Controllers/TermoEntradaConfigController.php` (estende `Webkul\Admin\Http\Controllers\Controller`)
- `edit()`: view `termo.config` com `texto` (salvo ou padrão), `variaveis` (`TermoTexto::VARIAVEIS`), `previa` (texto atual renderizado com `TermoTexto::exemplo()`), `cfg` (`termo_min_fotos`, `termo_link_validade_min`, `termo_whatsapp_template`).
- `update()`: valida `termo_entrada_texto` nullable string max 8000; `restaurar_padrao` boolean; `termo_min_fotos` required integer 1–20; `termo_link_validade_min` required integer 5–43200; `termo_whatsapp_template` required `regex` (array de regras: `['required','string','max:100','regex:/^[a-z0-9_]+$/']`). Recusa texto com variável desconhecida (lista os nomes) e texto **sem** `{autorizacao_rodagem}`. `restaurar_padrao` grava `null` no texto. Salva com `WorkshopSettings::set`. Flash em pt-BR.

#### 10. Views (todas em `SO/Resources/views/`)
- `partials/termo-entrada.blade.php` — cartão do termo (detalhe abaixo). Incluído por `workflow.blade.php`.
- `components/assinatura-canvas.blade.php` — quadro de assinatura + JS (detalhe abaixo).
- `public/termo.blade.php` — página pública (HTML próprio, CSS inline, sem recurso externo: nada de Google Fonts, para o endereço com token não vazar por Referer). Mobile first, botões grandes, `lang="pt-BR"`, `<meta name="robots" content="noindex,nofollow">`, `<meta name="referrer" content="no-referrer">`.
- `public/termo-indisponivel.blade.php` — página única para inválido/expirado/substituído.
- `termo/pdf.blade.php` — documento (DomPDF). Cabeçalho da oficina como `print.blade.php:5-15,49-98,102-139` (nome fantasia/razão social/CNPJ, rodapé "Documento gerado pelo sistema Volmaster Tech"); faixa "MINUTA — o cliente ainda não aceitou este termo" quando não há aceite; texto (`nl2br(e())`); tabela de dados; bloco "Aceite eletrônico" (canal, data/hora `America/Sao_Paulo`, IP, aparelho, nome informado, colhido por, código de verificação) e a imagem da assinatura (`<img src="data:image/png;base64,…">`) quando houver. Fonte `DejaVu Sans`. Sem crédito e sem valores.
- `termo/config.blade.php` — tela de configuração (formulário `PUT`, legenda das variáveis, caixa "Como fica com dados de exemplo").

**Cartão `partials/termo-entrada.blade.php`** (id `termo-entrada`, `data-testid="termo-card"`; só aparece com veículo, como hoje):
1. Cabeçalho "1. Termo de entrada" com selo aceito/pendente e link "Atualizar".
2. Se `! TermoEntradaService::disponivel()`: **modo antigo** — o formulário atual (`workflow.blade.php:47-99`) inteiro, com aviso de que falta a atualização do banco. Fim.
3. Checklist visual vindo de `checklist()` (documento, placa, km, combustível, fotos "2 de 4", itens, aceite) — mesma fonte da lista do gate.
4. Formulário "Dados do veículo na entrada" → `workflow.termo`: km, combustível, itens + caixa "Não há itens deixados nem avarias a registrar", fotos (mantém `capture="environment"`) com aviso do limite do servidor (`LimiteUpload::bytes()`). **Sem** a caixa da autorização e **sem** a caixa "assinado". Depois do aceite: km/combustível/itens ficam só leitura e sobra um formulário só de fotos (a validação do controlador muda junto).
5. Se documento ausente/inválido e termo não aceito e usuário com `service_orders.termo`: campo "CPF/CNPJ do cliente" + "Salvar CPF/CNPJ" → `termo.documento`.
6. Bloco "Aceite do cliente":
   - **Aceito**: faixa verde "Aceito em 18/11/2026 14:32:10 · link enviado ao WhatsApp · Cliente AUTORIZA/NÃO AUTORIZA a rodagem", nome informado, código de verificação; **IP, aparelho e quem colheu só se `bouncer()->hasPermission('service_orders.termo')`**; botão "Imprimir / PDF do termo"; `<details>` "Ver o texto do termo" (só com permissão, mostra `texto_snapshot`); formulários "Refazer o termo" (justificativa) e, se `autorizacao_rodagem` falsa, "Registrar autorização de rodagem" (canal + observação). Ações só em Cadastro/Ordem de Serviço (refazer) e antes de Concluída/Cancelada.
   - **Aceito pelo modo antigo** (`termo_assinado_em` sem linha): "Marcado como assinado em … (registro antigo, sem IP nem assinatura)".
   - **Pendente** (precisa de permissão): se `prontoParaAceite()` não está vazio, mostra "Complete antes de colher o aceite: …" e esconde as duas formas; senão:
     - `<details>` "Colher a assinatura na tela": resposta Autorizo/Não autorizo (radio obrigatório), nome, quadro de assinatura, "Li o termo e concordo", "Confirmar o aceite" → `termo.assinar`; campo oculto `versao`.
     - "Enviar o termo pelo WhatsApp do cliente": mostra o número de destino (padrão de `orcamento-send.blade.php:29-43`), botões "Enviar pelo WhatsApp" (`acao=enviar`) e "Gerar link para copiar" (`acao=gerar`); situação do último link ("enviado em … válido até …" / "expirou em …"); depois de gerar, caixa com o link (somente leitura, "Copiar link" via `navigator.clipboard` com `select()` de reserva) e "Abrir no WhatsApp" (`wa.me`).
7. Histórico do termo (tabela pequena: criado em, canal, situação, substituído/revogado).
8. OS em `Concluída`/`Cancelada`: tudo só leitura.

**Quadro de assinatura (`components/assinatura-canvas.blade.php`)**: `<canvas>` com `touch-action: none`, fundo branco só por CSS (PNG fica transparente), altura 180 px, largura 100%; resolução interna = tamanho CSS × `devicePixelRatio` (máx. 2); Pointer Events (`pointerdown/move/up/cancel` + `setPointerCapture`) com linha suavizada, traço 2,2 px preto; "Limpar"; considera assinatura válida com ≥ 8 pontos e ≥ 120 px de percurso; no `submit` do formulário: se vazio, `preventDefault()` e mostra "Faça a assinatura no quadro antes de confirmar."; senão preenche `input[name=assinatura]` com `toDataURL('image/png')` e desabilita o botão (evita duplo envio). Como fica dentro de `<details>`, dimensiona o canvas ao abrir pela primeira vez. Rotação da tela limpa o quadro. Script via `@pushOnce('scripts')`.

(Recomendado, opcional) Redução de fotos no navegador antes de enviar (canvas → JPEG 0,82, lado maior 1920 px) no input de fotos do cartão: sem isso, 4 fotos de celular moderno costumam passar do `post_max_size` de hospedagem compartilhada.

#### 11. Migration `SO/Database/Migrations/2026_11_18_070000_create_service_order_termos_table.php` — ver seção **Banco**.

#### 12. Testes — ver seção **Testes Pest**: `tests/Feature/Workshop/CpfCnpjTest.php` e `tests/Feature/Workshop/TermoEntradaTest.php`.

---

### ALTERAR

#### `SO/Services/StageGate.php`
- Imports: `Webkul\Mechanic\Models\Mechanic`, `Webkul\ServiceOrder\Services\Termo\TermoEntradaService`; remover `ServiceOrderAttachment` se ficar sem uso (Pint).
- **`cadastroParaOs()` (`:112-158`)**: trocar o miolo, **deixando intacto o último bloco (cor de crédito, `:153-155`) — a frente F06 mexe nele**:
  ```php
  protected function cadastroParaOs(ServiceOrder $os): array
  {
      $termo = app(TermoEntradaService::class);
      $person = $os->person;

      // CPF/CNPJ com dígitos verificadores corretos (vale também para serviço só de software).
      $missing = $termo->pendenciasDoDocumento($os);

      $hasPhone = collect($person?->contact_numbers ?? [])->contains(fn ($n) => ! empty($n['value']));

      if (! $hasPhone) {
          $missing[] = 'Telefone do cliente';
      }

      // Serviço só de software não tem veículo: placa, km, combustível, fotos, itens e termo não se aplicam.
      if ($termo->exige($os)) {
          $missing = array_merge($missing, $termo->pendenciasDoVeiculo($os));
      }

      if (! in_array($os->cor_credito, array_keys(ServiceOrder::CORES_CREDITO), true)) {
          $missing[] = 'Cor de crédito (verde, amarelo ou vermelho)';
      }

      return $missing;
  }
  ```
  (use `array_merge`, não `+`.)
- **Novo método público** (roteiro 43 e 42):
  ```php
  /**
   * O que falta para INICIAR o test drive (piloto ligado): autorização de rodagem dada pelo
   * cliente no termo e condutor com CNH da categoria exigida.
   *
   * @return string[]
   */
  public function missingParaIniciarTeste(ServiceOrder $os, ?int $condutorId): array
  {
      if (! static::active() || ! $os->vehicle_id) {
          return [];
      }

      $missing = [];

      if (! $os->autorizacao_rodagem) {
          $missing[] = 'Autorização de rodagem do cliente no termo de entrada (o cliente não autorizou ou ainda não respondeu)';
      }

      $condutor = $condutorId ? Mechanic::find($condutorId) : null;

      if (! $condutor) {
          $missing[] = 'Condutor do test drive';
      } elseif (! $this->cnhValida($condutor->cnh_categoria, $os)) {
          $missing[] = 'Condutor sem CNH na categoria exigida (cadastre a categoria da CNH do funcionário)';
      }

      return $missing;
  }
  ```
- **Não mudar** `testeParaFaturamento()` nem a frase `Autorização de rodagem do cliente` (`:261-263`): `SO/Services/Bonus/TravaService.php:156` procura `autoriza`/`cnh` nessa lista para gerar a trava `teste_sem_autorizacao`. A frase nova do início do teste também contém "autoriza".

#### `SO/Services/WorkflowService.php::saveTermo()` (`:44-67`)
Assinatura nova: `saveTermo(ServiceOrder $os, array $data, array $photos = []): void`. Comportamento:
- `$bloqueado = TermoEntradaService::disponivel() && $os->termo_assinado_em` → se bloqueado **não** altera km/combustível/itens (só aceita fotos novas).
- Se não bloqueado: `forceFill` de `km_entrada`, `combustivel_entrada` e `itens_entrada`; `itens_entrada` = `TermoEntradaService::SEM_ITENS_TEXTO` quando `sem_itens` vier marcado e o texto estiver vazio; **`autorizacao_rodagem` só é gravada se a chave existir em `$data`** (acaba o bug de zerar a cada salvar; mantém compatibilidade com quem ainda envia o campo).
- Fotos continuam via `storeAttachment` (tipo `termo_foto`).
- Grava o evento `checklist_entrada` com `{km, combustivel, fotos: n, origem: 'termo'}` quando algo mudou (rastro das edições antes do aceite).
- **Caminho antigo `assinado`**: só vale quando `! TermoEntradaService::disponivel()` (banco ainda não migrado) — mantém o código atual de `:57-66`. Com a tabela presente, `assinado` é ignorado (o aceite passa a exigir prova).

#### `SO/Http/Controllers/WorkflowController.php::saveTermo()` (`:30-54`)
- Regras: com termo já aceito (e tabela disponível) só valida `fotos`; senão `km_entrada required|integer|min:0|max:9999999`, `combustivel_entrada required|in:…`, `itens_entrada nullable|string|max:2000`, `sem_itens nullable|boolean`, `fotos nullable|array|max:12`, `fotos.* file|mimes:jpg,jpeg,png,webp,heic,heif|max:10240` (igual ao Cadastro; hoje `image|max:8192` recusa foto grande de celular), mais `autorizacao_rodagem`/`assinado` `nullable|boolean` (legado).
- Repassar `autorizacao_rodagem` **apenas se** `request()->has('autorizacao_rodagem')`; repassar `sem_itens`; `assinado` só se vier.
- Mensagem de sucesso: manter `Termo de entrada salvo.`

#### `SO/Http/Controllers/CadastroController.php`
- `store()` regra do `cpf_cnpj` (`:122-126`): com `StageGate::active()` validar com `CpfCnpj::valido()` (mensagem `CPF/CNPJ inválido: confira os dígitos.`); **com o piloto desligado, manter exatamente a regra antiga** (11 ou 14 dígitos).
  ```php
  'cpf_cnpj' => ['required', 'string', 'max:20', function ($attribute, $value, $fail) {
      if (StageGate::active()) {
          if (! CpfCnpj::valido($value)) {
              $fail('CPF/CNPJ inválido: confira os dígitos.');
          }

          return;
      }

      if (! in_array(strlen(preg_replace('/\D/', '', (string) $value)), [11, 14])) {
          $fail('CPF precisa ter 11 dígitos e CNPJ 14 dígitos.');
      }
  }],
  ```
- `limiteUpload()` delega para `LimiteUpload::bytes()`.
- `create()` passa `'pilot' => StageGate::active()` para a view.

#### `SO/Http/Controllers/ServiceOrderStageController.php::startTeste()` (`:440-457`)
Antes de gravar qualquer coisa:
```php
$serviceOrder = $this->serviceOrderRepository->findOrFail($id);
$faltando = app(StageGate::class)->missingParaIniciarTeste($serviceOrder, request('teste_mechanic_id') ? (int) request('teste_mechanic_id') : null);

if ($faltando) {
    session()->flash('error', 'Não é possível iniciar o teste: '.implode('; ', $faltando).'.');

    return redirect()->route('admin.service_orders.view', $id);
}
```
Piloto desligado: `missingParaIniciarTeste` retorna `[]` e o método segue como hoje.

#### `SO/Models/ServiceOrderEvent.php` (`TIPOS`, perto de `:30`)
Acrescentar (tipo desconhecido lança exceção em `record()`):
```php
'termo_link_gerado' => 'Link do termo de entrada gerado',
'termo_link_enviado' => 'Link do termo de entrada enviado ao WhatsApp do cliente',
'termo_reaberto' => 'Termo de entrada reaberto para novo aceite',
'autorizacao_rodagem_registrada' => 'Autorização de rodagem registrada',
'documento_cliente_corrigido' => 'CPF/CNPJ do cliente corrigido',
```
`termo_assinado` fica com o rótulo atual `Termo de entrada assinado` e passa a valer para as duas vias; `dados_json`: `{termo_id, canal, autorizacao_rodagem, ip, aparelho, versao, km, combustivel, fotos}`.

#### `SO/Services/WorkshopSettings.php` (`DEFAULTS`, logo antes de `'causas_atraso_diagnostico'`)
```php
// Termo de entrada (Cadastro): texto da oficina (vazio = texto padrão), mínimo de fotos do
// veículo, validade do link do WhatsApp (em minutos; 2880 = 48 h) e nome do modelo aprovado na Meta.
'termo_entrada_texto' => null,
'termo_min_fotos' => 4,
'termo_link_validade_min' => 2880,
'termo_whatsapp_template' => 'termo_entrada',
```

#### `SO/Services/ClienteContatos.php`
Novo helper estático (não mexer nos usos existentes em `ServiceOrderStageController:613-618` nem em `orcamento-send.blade.php:31`):
```php
public static function whatsappDe(?array $numeros): ?string
{
    $lista = collect($numeros ?? [])->filter(fn ($n) => ! empty($n['value']));

    return ($lista->first(fn ($n) => stripos($n['label'] ?? '', 'whats') !== false) ?? $lista->first())['value'] ?? null;
}
```

#### `SO/Routes/web.php`, `SO/Routes/public.php`, `SO/Config/acl.php`, `SO/Config/menu.php`, `SO/Http/Middleware/RegistraAcessoSensivel.php`
Ver seção **ACL, menu, rotas e configurações**.

#### Views existentes
- `partials/workflow.blade.php`: substituir o bloco `{{-- Termo de entrada --}} @if ($temVeiculo) … @endif` (`:42-101`) por `@if ($temVeiculo) @include('service_order::partials.termo-entrada', ['serviceOrder' => $serviceOrder]) @endif`. Na linha do tempo (`:496-498`) mostrar também a justificativa de `termo_reaberto`: `@if (in_array($evento->tipo_evento, ['bloqueio_forcado', 'termo_reaberto'], true))`.
- `partials/progress.blade.php`: logo abaixo da lista do bloco amarelo (`:30-34`), quando `$serviceOrder->workshop_status === 'Cadastro'` e há pendência, um link `#termo-entrada` "Ir para o termo de entrada" (o cartão está bem abaixo na página).
- `partials/teste.blade.php` (`:10-21`): se `StageGate::active() && $serviceOrder->vehicle_id && ! $serviceOrder->autorizacao_rodagem`, mostrar faixa vermelha acima do formulário de início: "Início do teste bloqueado: o cliente não autorizou a rodagem no termo de entrada. Registre a autorização no cartão do termo." (o servidor é quem trava; a faixa é só aviso).
- `cadastro/create.blade.php`: `:70-72` trocar o texto por "Obrigatório. Com o piloto ligado conferimos os dígitos do CPF/CNPJ." (quando `$pilot`) ou "Obrigatório. CPF com 11 dígitos ou CNPJ com 14." (piloto desligado); `:330` o "(opcional)" do checklist vira "(opcional agora; com o piloto ligado km, combustível, itens e 4 fotos são exigidos para sair do Cadastro)" quando `$pilot`.
- `ficha/edit.blade.php` (abaixo do título, `:16-17`): linha com link "Texto e regras do termo de entrada" para `admin.termo.config.edit` (`@if (bouncer()->hasPermission('settings.termo'))`).

#### Textos (`SO/Resources/lang/pt_BR/app.php` e `en/app.php`)
Chaves `acl.termo`, `acl.settings_termo` e o bloco `termo` — ver seção **Textos da interface**. `en` com a mesma estrutura de chaves em inglês simples.

#### Testes antigos (nunca apagar sem substituir)
- `tests/Feature/Workshop/Concerns/BuildsWorkshop.php:143`: `'cpf_cnpj' => '12.345.678/0001-95'` (CNPJ válido).
- `StageGateTest.php:82-100`: renomear para "Cadastro exige CPF/CNPJ válido, telefone, placa, km, combustível, 4 fotos, itens, aceite do termo e cor de crédito" e trocar a lista esperada para
  ```php
  [
      'Km de entrada',
      'Nível de combustível na entrada',
      'Fotos do veículo: faltam 4 (mínimo 4)',
      'Itens do veículo e avarias (ou marque que não há)',
      'Aceite do cliente no termo de entrada, com a autorização de rodagem (sim ou não)',
      'Cor de crédito (verde, amarelo ou vermelho)',
  ]
  ```
  e o passo seguinte preenche `km_entrada`, `combustivel_entrada`, `itens_entrada`, `termo_assinado_em`, `autorizacao_rodagem`, `cor_credito` e **4** anexos `termo_foto`. (`:102-108`, software-only: continua `['Cor de crédito…']` com o helper corrigido.)
- `HttpFlowTest.php:72-106`: reescrever. Salvar dados com `workflow.termo` (km, combustível, itens, 4 fotos) **sem** `assinado`; afirmar que `assinado=1` é ignorado (sem `termo_assinado_em` e sem evento `termo_assinado`); `autorizacao_rodagem` não é zerada por um novo "Salvar termo"; o trecho de `cor_credito` (`:97-101`) permanece. O aceite passa a ser testado em `TermoEntradaTest`.
- `ChecklistCadastroTest.php:108-114`: mandar 4 fotos no fixture (`:80-91` hoje manda 3; ajustar as contagens do teste) e asserir que a lista **não** tem `Km de entrada` nem item que comece com `Fotos do veículo`, e **tem** `Aceite do cliente no termo de entrada, com a autorização de rodagem (sim ou não)`; remover a asserção da frase antiga `Termo de entrada: fotos do veículo`.

---

## Banco (migrations)

Uma migration, **aditiva, idempotente, sem coluna nova em `quotes`**:
`packages/Webkul/ServiceOrder/src/Database/Migrations/2026_11_18_070000_create_service_order_termos_table.php` (HHMMSS `070000` = hora 07 reservada para a frente F07; confirmar que ninguém usou o mesmo). Imports: `Illuminate\Database\Migrations\Migration`, `Illuminate\Database\Schema\Blueprint`, `Illuminate\Support\Facades\DB`, `Illuminate\Support\Facades\Schema`; formato `return new class extends Migration` igual às demais.

```php
public function up(): void
{
    if (Schema::hasTable('service_order_termos')) {
        return;
    }

    Schema::create('service_order_termos', function (Blueprint $table) {
        $table->bigIncrements('id');
        $table->unsignedInteger('quote_id');

        // pendente | aceito | substituido | revogado
        $table->string('status', 12)->default('pendente');
        // assinatura_tela | link_whatsapp
        $table->string('canal', 20)->nullable();

        // Link enviado ao cliente: só o hash do token fica guardado.
        $table->char('token_hash', 64)->nullable()->unique();
        $table->string('link_destino', 30)->nullable();      // telefone mascarado
        $table->dateTime('link_enviado_em')->nullable();
        $table->dateTime('expira_em')->nullable();
        $table->dateTime('revogado_em')->nullable();

        // O que o cliente viu e aceitou (congelado).
        $table->char('versao', 64)->nullable();
        $table->char('texto_hash', 64)->nullable();
        $table->mediumText('texto_snapshot')->nullable();
        $table->json('dados_json')->nullable();
        $table->boolean('autorizacao_rodagem')->nullable();

        // Prova do aceite.
        $table->dateTime('aceito_em')->nullable();
        $table->string('aceito_nome', 160)->nullable();
        $table->string('aceito_ip', 45)->nullable();
        $table->string('aceito_user_agent', 255)->nullable();
        $table->mediumText('assinatura_png')->nullable();    // data URI base64 (assinatura na tela)
        $table->unsignedInteger('criado_por')->nullable();   // usuário que gerou o link ou colheu a assinatura

        // Refazer o termo: o aceite antigo fica, marcado como substituído.
        $table->dateTime('substituido_em')->nullable();
        $table->unsignedInteger('substituido_por')->nullable();
        $table->string('substituido_motivo', 500)->nullable();

        $table->timestamps();

        $table->index('quote_id');
        $table->index(['quote_id', 'status']);
    });
}

public function down(): void
{
    // Prova de aceite não se apaga: só remove a tabela se estiver vazia.
    if (Schema::hasTable('service_order_termos') && ! DB::table('service_order_termos')->exists()) {
        Schema::drop('service_order_termos');
    }
}
```
- Sem chave estrangeira para `quotes` de propósito (igual a `service_order_events`): a prova sobrevive mesmo que a OS seja apagada.
- Aplicar em teste: `$env:DB_DATABASE='krayin_test'; php artisan migrate --path=packages/Webkul/ServiceOrder/src/Database/Migrations/2026_11_18_070000_create_service_order_termos_table.php`. **Não** rodar em `krayin_crm` salvo ordem expressa.
- Tolerância à tabela ausente: `TermoEntradaService::disponivel()`. Onde olhar antes de qualquer consulta à tabela: `checklist()` (aceite cai para `termo_assinado_em`), cartão (modo antigo), `WorkflowService::saveTermo` (caminho `assinado` antigo), rotas novas (respondem erro amigável "Atualize o banco de dados…" em vez de 500). O Novo Cadastro e o `StageGate` não dependem da tabela.
- Bancos das oficinas (tenants): a migration precisa chegar a cada banco; o `migrate-safe.php` só migra o principal — caminho definido pela frente F00 (ver Riscos).

---

## ACL, menu, rotas e configurações

### Rotas da equipe (`SO/Routes/web.php`, depois do grupo `WorkflowController`, `:120`)
```php
// Termo de entrada: aceite na tela, link do WhatsApp, PDF e correções do termo.
Route::controller(TermoEntradaController::class)->prefix('service-orders/{id}/termo')->group(function () {
    Route::post('assinar', 'assinar')->name('admin.service_orders.termo.assinar');
    Route::post('link', 'link')->name('admin.service_orders.termo.link');
    Route::get('pdf', 'pdf')->name('admin.service_orders.termo.pdf');
    Route::post('reabrir', 'reabrir')->name('admin.service_orders.termo.reabrir');
    Route::post('documento', 'documento')->name('admin.service_orders.termo.documento');
    Route::post('autorizacao', 'autorizacao')->name('admin.service_orders.termo.autorizacao');
});

// Texto e regras do termo de entrada.
Route::controller(TermoEntradaConfigController::class)->prefix('settings/termo')->group(function () {
    Route::get('', 'edit')->name('admin.termo.config.edit');
    Route::put('', 'update')->name('admin.termo.config.update');
});
```
(Acrescentar os dois `use` dos controladores no topo do arquivo.)

### Rotas públicas (`SO/Routes/public.php`, depois do bloco do `pos-venda`)
```php
// Termo de entrada: o cliente aceita pelo link enviado ao WhatsApp. Token aleatório de 48 caracteres
// (só o hash fica no banco), expira, uso único/idempotente. O POST dispensa CSRF: o token na URL é a credencial.
Route::controller(PublicTermoController::class)->prefix('termo')->middleware('throttle:30,1')->group(function () {
    Route::get('{token}', 'show')->where('token', '[A-Za-z0-9]{48}')->name('service_orders.termo.show');
    Route::post('{token}', 'accept')->where('token', '[A-Za-z0-9]{48}')->name('service_orders.termo.accept')
        ->withoutMiddleware([VerifyCsrfToken::class]);
});
```
Nomes **sem** o prefixo `admin.` de propósito (o `DonoForaDoFluxo` e o `RegistraAcessoSensivel` só tratam `admin.*`).

### ACL (`SO/Config/acl.php`) — chaves planas novas
```php
[
    // Termo de entrada: colher assinatura na tela, enviar o link ao WhatsApp, imprimir o PDF,
    // refazer o termo, corrigir o CPF/CNPJ e registrar a autorização de rodagem.
    'key' => 'service_orders.termo',
    'name' => 'service_order::app.acl.termo',
    'route' => [
        'admin.service_orders.termo.assinar',
        'admin.service_orders.termo.link',
        'admin.service_orders.termo.pdf',
        'admin.service_orders.termo.reabrir',
        'admin.service_orders.termo.documento',
        'admin.service_orders.termo.autorizacao',
    ],
    'sort' => 8,
], [
    'key' => 'settings.termo',
    'name' => 'service_order::app.acl.settings_termo',
    'route' => ['admin.termo.config.edit', 'admin.termo.config.update'],
    'sort' => 17,
],
```
- `admin.service_orders.workflow.termo` **continua** em `service_orders.edit` (`acl.php:45`).
- Role de teste com `settings.termo` precisa também de `settings` (convenção do projeto).
- O `Bouncer` responde **401** a quem não tem a chave (`Admin/Bouncer.php:34-39`); se a frente F05 trocar por 403, os testes desta frente aceitam 401 **ou** 403.
- Quem recebe `service_orders.termo`: Proprietário, Gerente e Consultor (e Chefe de oficina se a oficina quiser) — entregar à frente F04/F05 para os modelos de função. **Técnico, Peças, Financeiro e Zeladoria não** (colher aceite é ato do atendimento; o técnico não vê IP nem imprime).

### Menu (`SO/Config/menu.php`, depois de `settings.ficha.edit`, `:219-226`)
```php
[
    'key' => 'settings.termo',
    'name' => 'Termo de entrada',
    'info' => 'Texto do termo, mínimo de fotos e link do WhatsApp',
    'route' => 'admin.termo.config.edit',
    'sort' => 11,
    'icon-class' => '',
], [
    'key' => 'settings.termo.edit',
    'name' => 'Termo de entrada',
    'info' => 'Texto do termo, mínimo de fotos e link do WhatsApp',
    'route' => 'admin.termo.config.edit',
    'sort' => 1,
    'icon-class' => 'icon-setting',
],
```
Nenhum item de menu de topo novo (o cartão vive na tela da OS).

### Registro de acesso (LGPD) — `SO/Http/Middleware/RegistraAcessoSensivel.php:17-19`
Acrescentar `'admin.service_orders.termo.'` e `'admin.termo.config.'` em `PREFIXOS`: toda abertura de PDF, colheita de assinatura, geração/envio de link e correção de documento fica em `acessos_sensiveis` (quem, rota, método, IP, hora). O aceite do **cliente** (IP, aparelho) **não** vai para essa tabela: é prova do titular, fica em `service_order_termos` e no evento; a tabela de acesso é "quem da equipe abriu dado sensível".

### Configurações da oficina (`WorkshopSettings`)
`termo_entrada_texto` (null = texto padrão), `termo_min_fotos` (4), `termo_link_validade_min` (2880), `termo_whatsapp_template` (`termo_entrada`).

### Eventos novos
`termo_link_gerado`, `termo_link_enviado`, `termo_reaberto`, `autorizacao_rodagem_registrada`, `documento_cliente_corrigido` (e `termo_assinado` reaproveitado). Nenhuma barreira, nenhum assunto de alçada e nenhum código de indicador novo.

### Modo de teste ÷10
O termo não é barreira de prazo. A validade do link é configurável em **minutos** (mínimo 5) justamente para o roteiro poder testar a expiração em minutos sem esperar 48 h; se a frente F03 expuser um divisor global, `validadeDoLinkMinutos()` pode aplicá-lo, mas não é obrigatório.

---

## Textos da interface (pt-BR)

### Mensagens do gate (fixas no código, não vão para o arquivo de idioma)
Lista do que falta (ordem do PDF): `CPF/CNPJ do cliente` | `CPF/CNPJ do cliente inválido: confira os dígitos` | `Telefone do cliente` | `Placa do veículo` | `Km de entrada` | `Nível de combustível na entrada` | `Fotos do veículo: faltam 2 (mínimo 4)` | `Itens do veículo e avarias (ou marque que não há)` | `Aceite do cliente no termo de entrada, com a autorização de rodagem (sim ou não)` | `Cor de crédito (verde, amarelo ou vermelho)`.
Início do teste: `Autorização de rodagem do cliente no termo de entrada (o cliente não autorizou ou ainda não respondeu)` | `Condutor do test drive` | `Condutor sem CNH na categoria exigida (cadastre a categoria da CNH do funcionário)`; flash: `Não é possível iniciar o teste: …`.

### Arquivo de idioma — `termo` (pt_BR; `en` com as mesmas chaves em inglês)
```php
'acl' => [
    // acrescentar às chaves existentes
    'termo' => 'Termo de entrada (colher assinatura, enviar link, imprimir, refazer)',
    'settings_termo' => 'Configurar o texto e as regras do termo de entrada',
],

'termo' => [
    'passo' => '1. Termo de entrada',
    'badge_aceito' => 'aceito',
    'badge_pendente' => 'pendente',
    'ancora' => 'Ir para o termo de entrada',
    'atualizar' => 'Atualizar',
    'banco_antigo' => 'O banco de dados desta oficina ainda não recebeu a atualização do termo (assinatura na tela e link). Use a caixa abaixo até a atualização ser feita.',

    'checklist' => [
        'titulo' => 'O que o termo precisa para a OS sair do Cadastro',
        'documento' => 'CPF/CNPJ válido',
        'placa' => 'Placa',
        'km' => 'Km de entrada',
        'combustivel' => 'Nível de combustível',
        'fotos' => 'Fotos do veículo',
        'fotos_detalhe' => ':tem de :min',
        'itens' => 'Itens do veículo e avarias',
        'aceite' => 'Aceite do cliente',
    ],

    'dados' => [
        'titulo' => 'Dados do veículo na entrada',
        'km' => 'Km de entrada',
        'combustivel' => 'Nível de combustível',
        'itens' => 'Itens deixados no veículo / avarias',
        'sem_itens' => 'Não há itens deixados nem avarias a registrar',
        'fotos' => 'Fotos do veículo (mínimo :min)',
        'limite' => 'O servidor aceita até :mb MB de fotos por envio.',
        'salvar' => 'Salvar dados do termo',
        'salvar_fotos' => 'Enviar fotos',
        'travado' => 'O termo já foi aceito: km, combustível e itens não podem mais mudar. Para corrigir, refaça o termo.',
    ],

    'documento' => [
        'titulo' => 'CPF/CNPJ do cliente',
        'salvar' => 'Salvar CPF/CNPJ',
        'salvo' => 'CPF/CNPJ do cliente atualizado.',
        'invalido' => 'CPF/CNPJ inválido: confira os dígitos.',
        'travado' => 'O termo já foi aceito com o CPF/CNPJ anterior. Refaça o termo para corrigir.',
    ],

    'aceite' => [
        'titulo' => 'Aceite do cliente',
        'pendente' => 'O cliente ainda não aceitou o termo.',
        'aceito_em' => 'Aceito em :quando',
        'por_tela' => 'assinatura na tela',
        'por_link' => 'link enviado ao WhatsApp',
        'ip' => 'IP :ip',
        'aparelho' => 'Aparelho: :aparelho',
        'colhido_por' => 'Colhido por :nome',
        'nome' => 'Nome informado: :nome',
        'autoriza' => 'O cliente AUTORIZA a rodagem do veículo.',
        'nao_autoriza' => 'O cliente NÃO AUTORIZA a rodagem do veículo.',
        'codigo' => 'Código de verificação: :codigo',
        'legado' => 'Marcado como assinado em :quando (registro antigo, sem IP nem assinatura).',
        'ver_texto' => 'Ver o texto do termo',
        'completar' => 'Complete antes de colher o aceite: :itens.',
    ],

    'tela' => [
        'titulo' => 'Colher a assinatura na tela',
        'ajuda' => 'Entregue o aparelho ao cliente: ele escolhe se autoriza a rodagem, confere o termo e assina com o dedo.',
        'autorizo' => 'Autorizo a rodagem do veículo',
        'nao_autorizo' => 'Não autorizo a rodagem do veículo',
        'nome' => 'Nome de quem assina',
        'confirmo' => 'Li o termo e concordo',
        'assine' => 'Assine aqui',
        'limpar' => 'Limpar',
        'confirmar' => 'Confirmar o aceite',
        'em_branco' => 'Faça a assinatura no quadro antes de confirmar.',
        'sucesso' => 'Aceite do termo registrado.',
    ],

    'link' => [
        'titulo' => 'Enviar o termo pelo WhatsApp do cliente',
        'destino' => 'O link será enviado para :telefone.',
        'enviar' => 'Enviar pelo WhatsApp',
        'gerar' => 'Gerar link para copiar',
        'copiar' => 'Copiar link',
        'copiado' => 'Copiado!',
        'abrir_whatsapp' => 'Abrir no WhatsApp',
        'situacao' => 'Link enviado em :quando e válido até :ate.',
        'expirado' => 'O último link expirou em :quando. Gere um novo.',
        'enviado' => 'Link enviado ao WhatsApp do cliente (:telefone). Vale por :validade e só pode ser usado uma vez.',
        'gerado' => 'Link gerado. Vale por :validade e só pode ser usado uma vez. Ele só aparece agora: copie ou envie.',
        'sem_whatsapp' => 'O cliente não tem um WhatsApp válido cadastrado. Corrija na ficha do cliente no formato +55 (DDD) NÚMERO.',
        'falha_api' => 'Não foi possível enviar pela API do WhatsApp: :motivo Use o link abaixo.',
        'ajuda' => 'Cole o link no WhatsApp da oficina ou abra a conversa já com a mensagem pronta.',
    ],

    'autorizacao' => [
        'titulo' => 'Registrar autorização de rodagem depois do termo',
        'canal' => 'Como o cliente autorizou?',
        'observacao' => 'Observação (opcional)',
        'botao' => 'Registrar autorização',
        'feita' => 'Autorização de rodagem registrada.',
    ],

    'refazer' => [
        'titulo' => 'Refazer o termo',
        'ajuda' => 'O aceite atual fica guardado no histórico e o cliente precisa aceitar de novo.',
        'motivo' => 'Por que o termo precisa ser refeito?',
        'botao' => 'Refazer o termo',
        'feito' => 'Termo reaberto. O aceite anterior ficou guardado no histórico.',
        'etapa' => 'O termo só pode ser refeito até a etapa Ordem de Serviço.',
    ],

    'pdf' => ['botao' => 'Imprimir / PDF do termo', 'minuta' => 'MINUTA — o cliente ainda não aceitou este termo'],

    'historico' => [
        'titulo' => 'Histórico do termo',
        'substituido' => 'substituído em :quando — :motivo',
        'revogado' => 'link trocado por outro',
        'pendente' => 'aguardando o cliente',
        'aceito' => 'aceito',
    ],

    'erro' => [
        'ja_aceito' => 'O termo já foi aceito em :quando.',
        'versao' => 'Os dados do veículo mudaram depois que o termo foi aberto. Confira o texto atualizado e aceite de novo.',
        'assinatura' => 'Assinatura inválida: refaça no quadro.',
        'confirmacao' => 'Marque que o cliente leu e concorda.',
        'nome' => 'Informe o nome completo de quem aceita (mínimo 3 letras).',
        'autorizacao' => 'Responda se o cliente autoriza ou não a rodagem do veículo.',
        'justificativa' => 'Explique o motivo (mínimo 10 caracteres).',
        'sem_tabela' => 'Falta atualizar o banco de dados desta oficina para usar o termo com assinatura e link.',
        'sem_aceite' => 'Esta OS ainda não tem termo aceito.',
    ],

    'publico' => [
        'titulo' => 'Termo de entrada do veículo',
        'saudacao' => 'Olá, :nome. A :oficina precisa do seu aceite para registrar a entrada do veículo.',
        'dados' => 'Dados registrados na entrada',
        'placa' => 'Placa', 'veiculo' => 'Veículo', 'km' => 'Km', 'combustivel' => 'Combustível',
        'itens' => 'Itens e avarias', 'fotos' => 'Fotos',
        'pergunta' => 'Você autoriza a oficina a fazer teste de rodagem com o veículo?',
        'autorizo' => 'Sim, autorizo',
        'nao_autorizo' => 'Não autorizo',
        'nome' => 'Digite seu nome completo para confirmar',
        'concordo' => 'Li o termo acima e concordo.',
        'aceitar' => 'Aceitar o termo',
        'lgpd' => 'Registramos a data, a hora, o endereço IP e o aparelho usados neste aceite, só para comprovar o seu consentimento a este termo (LGPD).',
        'aceito_titulo' => 'Termo aceito',
        'aceito_texto' => 'Recebemos o seu aceite em :quando. Você já pode fechar esta página.',
        'indisponivel_titulo' => 'Link indisponível',
        'invalido' => 'Não encontramos este link. Confira se copiou o endereço inteiro ou peça um novo link à oficina.',
        'expirado' => 'Este link expirou. Peça um novo link à oficina.',
        'substituido' => 'Este termo foi atualizado pela oficina. Peça um novo link.',
        'versao' => 'Os dados do seu veículo foram atualizados pela oficina enquanto você lia. Confira o texto abaixo e aceite novamente.',
    ],

    'config' => [
        'titulo' => 'Termo de entrada do veículo',
        'subtitulo' => 'Texto que o cliente lê e aceita, e as regras do aceite.',
        'texto' => 'Texto do termo',
        'variaveis' => 'Variáveis que você pode usar (escreva entre chaves, por exemplo {placa})',
        'restaurar' => 'Voltar ao texto padrão',
        'min_fotos' => 'Mínimo de fotos do veículo para sair do Cadastro',
        'validade' => 'Validade do link do WhatsApp (minutos; 2880 = 48 horas)',
        'template' => 'Nome do modelo de mensagem aprovado na Meta',
        'template_ajuda' => 'Sem modelo aprovado, o botão de WhatsApp mostra o link para copiar ou abrir pelo seu próprio WhatsApp.',
        'previa' => 'Como fica (com dados de exemplo)',
        'salvo' => 'Termo de entrada salvo.',
        'desconhecidas' => 'Variáveis desconhecidas no texto: :lista.',
        'sem_autorizacao' => 'O texto precisa conter {autorizacao_rodagem}.',
    ],
],
```

### Mensagem do WhatsApp (modelo `termo_entrada`, categoria Utilidade, pt_BR) — texto para o usuário pedir aprovação na Meta
`Olá, {{1}}! Aqui é a {{2}}. Para concluir a entrada do veículo placa {{3}} na oficina, leia e aceite o termo de entrada neste link: {{4}} — o link vale por {{5}} e só pode ser usado uma vez. Qualquer dúvida, é só responder esta mensagem.`
Ordem dos parâmetros enviados: nome do cliente, nome da oficina, placa, link, validade (por exemplo `48 horas`). (A Meta recusa modelo que começa ou termina com variável; este não começa nem termina.)

### Texto do `wa.me` (quando a API não está configurada)
`Olá, {nome}! Aqui é a {oficina}. Para concluir a entrada do veículo placa {placa}, leia e aceite o termo neste link (vale por {validade}): {url}`

### Datas
Sempre `dd/mm/aaaa hh:mm` (com `:ss` na prova do aceite), convertidas para `America/Sao_Paulo` com `->timezone('America/Sao_Paulo')` na hora de exibir (o fuso padrão de `config/app.php:82` pode não ser o de São Paulo; a conversão é neutra se já for).

---

## Testes Pest a escrever

Convenções: arquivos em `tests/Feature/Workshop/`; funções auxiliares globais com prefixo `termo`/`cpf` (nomes únicos entre arquivos); `Storage::fake('public')`; `$this->enablePilot()`; `makeUser('consultor', ['service_orders','service_orders.view','service_orders.edit','service_orders.termo'])`; `ServiceOrderAttachment::create([... 'tipo'=>'termo_foto'])` para fotos; auxiliar `termoAssinaturaPng(bool $comTraco = true): string` gera o PNG com GD (`imagecreatetruecolor(400, 120)` + `imagesavealpha` + fundo transparente + linha preta de ~250 px com `imagesetthickness(3)` — precisa passar de ~150 pixels não transparentes — + `ob_start()/imagepng()` → `data:image/png;base64,…`; com `$comTraco = false` não desenha nada); auxiliar `termoOsPronta()` cria OS com veículo, km, combustível, itens e 4 fotos (só falta o aceite). IP/aparelho nos testes: `->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])->withHeaders(['User-Agent' => '…iPhone…Safari…'])`.

### `CpfCnpjTest.php`
- **T01** CPF confere os dois dígitos: válidos `529.982.247-25`, `52998224725`, `111.444.777-35`; inválidos `529.982.247-26`, `123.456.789-00`, `111.111.111-11`, `000.000.000-00`, 10 dígitos, vazio, `null`, `abc`.
- **T02** CNPJ confere os dois dígitos, inclusive alfanumérico: válidos `11.222.333/0001-81`, `12.345.678/0001-95`, `12.ABC.345/01DE-35`, `12abc34501de35` (minúsculas); inválidos `12.345.678/0001-90` (o valor que o helper de testes usava), `00.000.000/0000-00`, `11.222.333/0001-82`, `12.ABC.345/01DE-36`.
- **T03** `formatar()` e `mascarar()` (`52998224725` → `529.982.247-25` / `***.982.247-**`; CNPJ `**.222.333/****-**`); nunca devolvem o número inteiro mascarado.
- **T04** regra `DocumentoValido`: `Validator::make(['d' => '123'], ['d' => [new DocumentoValido]])` falha com `CPF/CNPJ inválido: confira os dígitos.`

### `TermoEntradaTest.php`
**Lista do que falta e gate (PDF "Teste:"; roteiro 11)**
- **T05** "sem fotos, sem km ou sem aceite o botão de avançar fica travado e a tela lista o que falta": piloto ligado, OS no Cadastro com veículo; `GET admin.service_orders.view` contém `faltando-para-avancar`, `Km de entrada`, `Nível de combustível na entrada`, `Fotos do veículo: faltam 4 (mínimo 4)`, `Itens do veículo e avarias`, `Aceite do cliente no termo de entrada` e o botão `disabled`; `PUT stage.update` redireciona com `session('error')` contendo `Falta:`; a OS continua no Cadastro.
- **T06** a lista segue a ordem do PDF e cada pendência resolvida some: cliente com CNPJ inválido → primeiro item `CPF/CNPJ do cliente inválido: confira os dígitos`; corrige; completa km, combustível, fotos, itens, aceite e crédito; no fim `avancou` verdadeiro (usar `StageAdvanceService`).
- **T07** o mínimo de fotos vem da configuração: `termo_min_fotos=2` → 2 fotos bastam; 1 foto → `faltam 1 (mínimo 2)`; vídeo (`termo_video`) não conta.
- **T08** itens do veículo: texto livre ou `sem_itens=1` (grava `Nenhum item deixado no veículo e nenhuma avaria aparente.`); vazio sem a caixa continua pendente.
- **T09** serviço só de software (sem `vehicle_id`) dispensa fotos, km, combustível, itens e termo; CPF/CNPJ inválido ainda bloqueia; lista final igual a `['Cor de crédito (verde, amarelo ou vermelho)']` quando o documento é válido.
- **T10** piloto desligado: `advance` do Cadastro com tudo vazio avança; `GET` da OS não mostra o cartão novo (`assertDontSee('Aceite do cliente')`); Novo Cadastro com `123.456.789-00` passa (regra antiga) e **com o piloto ligado** é recusado com erro em `cpf_cnpj`.
- **T11** corrigir o CPF/CNPJ na OS: `termo.documento` inválido → erro; válido → pessoa fica com o número formatado; evento `documento_cliente_corrigido` guarda só dado mascarado (`assertStringNotContainsString` do número inteiro no `dados_json`); depois do aceite a correção é recusada.

**Aceite na tela**
- **T12** assinatura na tela grava o aceite: `termo.assinar` com PNG válido, `autorizacao_rodagem=sim`, nome, `confirmo=1`, `versao` correta → linha `aceito` (canal `assinatura_tela`, `aceito_ip` = `203.0.113.7`, `aceito_user_agent`, `criado_por`, `texto_snapshot` sem `{` sobrando, `texto_hash` de 64 caracteres), `quotes.termo_assinado_em` preenchido, `autorizacao_rodagem` verdadeiro, 1 evento `termo_assinado` com o usuário logado.
- **T13** recusas: assinatura em branco (PNG transparente), PNG inválido/enorme, sem `confirmo`, nome curto, sem resposta de autorização, `versao` antiga (depois de mudar o km), dados incompletos (sem foto) e segundo aceite — nada é gravado.
- **T14** refazer o termo: justificativa curta recusada; válida → linha antiga vira `substituido` (permanece), `termo_assinado_em` nulo, evento `termo_reaberto` com a justificativa, links pendentes revogados; fora das etapas Cadastro/Ordem de Serviço é recusado; a linha `aceito` não aceita `update` direto nem `delete` (`LogicException`).
- **T15** depois do aceite km/combustível/itens ficam travados em `workflow.termo` (valores não mudam) e foto nova ainda entra; `assinado=1` é ignorado; `autorizacao_rodagem` não é zerada por novo salvamento.

**Aceite por link (roteiro 14)**
- **T16** gerar link: token na tela com 48 caracteres, banco guarda só `token_hash` (sha256), nunca o token; `expira_em` = agora + `termo_link_validade_min`; gerar outro link revoga o anterior (status `revogado`).
- **T17** o cliente abre o link **sem login** e aceita: `GET /termo/{token}` 200 mostra texto, placa e dados; `POST` com `autorizacao_rodagem`, `nome`, `confirmo`, `versao` grava `aceito_em`, `aceito_ip`, `aceito_user_agent`, canal `link_whatsapp`; `GET admin.service_orders.view` mostra data, hora e o IP (`assertSee('203.0.113.7')`, `assertSee('Aceito em')`) — **linha 14 do roteiro**.
- **T18** idempotente: segundo `POST` (duplo clique/refresh) não duplica linha nem evento; o `GET` depois do aceite mostra o comprovante.
- **T19** inexistente → 404 genérico; expirado (`Carbon::setTestNow` + validade) → 410 e nada gravado; link revogado por outro novo → 410; OS cancelada → 410; assinado na tela enquanto o link estava pendente → link vira 410.
- **T20** com um usuário da equipe logado no mesmo navegador, o aceite por link grava o evento com `usuario_id` nulo.
- **T21** a página pública não contém crédito, `cor_credito`, `grand_total`/valores, forma de pagamento nem placa/nome de outra OS; resposta tem `X-Robots-Tag: noindex`, `Cache-Control: no-store` e `Referrer-Policy: no-referrer`; sem cookie de sessão o `POST` inválido volta 422 na própria página (sem redirect).
- **T22** dados mudaram depois do link aberto (`versao` antiga) → aceite recusado, página mostra aviso e texto atual.
- **T23** WhatsApp (`Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.x']]], 200)])` + `WhatsAppSettings::current()->update(['access_token' => 'x', 'phone_number_id' => '123'])`): `termo.link` com `acao=enviar` chama a API com o modelo `termo_whatsapp_template`, 5 parâmetros (nome, oficina, placa, link com `/termo/`, `48 horas`), registra `termo_link_enviado` e `whatsapp_message_logs`; **sem credenciais** devolve o link, o `wa.me` e grava `termo_link_gerado` (sem erro 500).
- **T24** cliente sem WhatsApp ou número inválido: mensagem clara e nenhum link criado.

**Autorização de rodagem (roteiro 43 e 42)**
- **T25** resposta "não autorizo": a OS **sai** do Cadastro (`avancou` verdadeiro) mas `startTeste` é recusado (`teste_iniciado_em` nulo, `session('error')` contém `rodagem`) — **linha 43**.
- **T26** `termo.autorizacao` (canal + observação) libera o início do teste e grava `autorizacao_rodagem_registrada`; o aceite original continua inalterado.
- **T27** iniciar o teste com condutor sem categoria de CNH exigida (B para caminhão) é recusado; com `E` inicia — **linha 42**.
- **T28** piloto desligado: `startTeste` continua sem nenhuma trava.

**Indicadores**
- **T29** CON-13 e CON-02 contam o aceite pelas duas vias: com `chegada_veiculo` + `ficha_enviada_chefe`, `realizado('CON-13')` = 100 com "sim" e 0 com "não"; `CON-02` conta quem tem aceite e nenhuma devolução (padrão do `IndicatorsTest.php:213-233`).

**PDF, permissão e LGPD**
- **T30** `GET termo.pdf` de OS aceita: 200, `content-type` PDF; abertura registrada em `acessos_sensiveis` (rota `admin.service_orders.termo.pdf`, IP).
- **T31** sem aceite sai como minuta (resposta 200 PDF; conferir pelo método da view: `view('service_order::termo.pdf', …)->render()` contém `MINUTA`).
- **T32** IP e aparelho aparecem só para quem tem `service_orders.termo` (usuário só com `service_orders.view` não vê o IP no HTML da OS).
- **T33** sem `service_orders.termo`, as 6 rotas respondem 401 **ou** 403.

**Configuração e snapshot**
- **T34** tela de configuração: salva texto, `termo_min_fotos`, `termo_link_validade_min` e `termo_whatsapp_template`; recusa variável desconhecida (`{foo}`) e texto sem `{autorizacao_rodagem}`; `restaurar_padrao` volta ao texto padrão; exige `settings.termo`.
- **T35** o aceite guarda o texto vigente na hora; trocar o texto depois não altera `texto_snapshot` de aceites antigos; variável com HTML no nome do cliente sai escapada na página e no PDF.

**Tolerância a banco antigo**
- **T36** `TermoEntradaService::definirDisponibilidade(false)`: o gate e a tela da OS respondem 200 sem erro; cartão em modo antigo (caixa "assinado" funciona); `afterEach` volta a `definirDisponibilidade(null)`.

Mapa rápido: **PDF "Teste:"** = T05, T06; **roteiro 11** = T05–T09; **roteiro 14** = T17, T18; **roteiro 42/43** = T25–T27; **CON-02/13** = T29; **piloto desligado** = T10, T28; **software-only** = T09.

Depois: `php artisan test --filter=CpfCnpj`, `--filter=Termo`, `--filter=Workshop`, `php vendor/bin/pint` nos arquivos tocados (PowerShell 5.1, `DB_DATABASE=krayin_test`).

---

## Riscos e interações com outras frentes

- **Testes antigos**: o helper `makePerson` usa CNPJ inválido (`BuildsWorkshop.php:143`). Sem a correção, a validação de dígitos derruba todos os testes que passam pelo gate do Cadastro. O arquivo de helper é compartilhado: aplicar a correção uma única vez.
- **F06 (crédito)** edita o mesmo `StageGate::cadastroParaOs` (bloco da cor de crédito `:153-155`) e talvez `cadastro/create.blade.php`/`CadastroController`. Esta spec só troca o começo da função (documento, telefone, bloco do veículo); combinar para ninguém reescrever a função inteira.
- **F13 (chamado externo)** e **F12 (retorno/garantia)**: ponto único de decisão `TermoEntradaService::exige()`. Hoje retorna `(bool) $os->vehicle_id`. O chamado externo (veículo que não entra na oficina) e a OS de retorno (`WorkflowActions::openReturn`, que cria nova OS no Cadastro com o mesmo veículo) caem no mesmo gate: decidir lá se dispensam ou repetem o termo (ver Decisões).
- **F00 (oficinas)**: a tabela `service_order_termos` tem de existir no banco de **cada** oficina; o `migrate-safe.php` só migra o banco principal. Sem isso, a oficina cai no modo antigo (sem erro, mas sem a prova do aceite). O link público depende do subdomínio da oficina (`ResolveTenant`): gerar o link sempre dentro da requisição da própria oficina (feito por `route()`); nunca por fila/console.
- **F01 (404/datas)**: as páginas públicas têm layout próprio e forçam `pt_BR`; a página de "link indisponível" pode herdar o contato de suporte quando a F01 definir. Datas do termo usam conversão explícita para `America/Sao_Paulo`.
- **F03 (piloto/modo de teste)**: nada a ligar; só a validade do link (minutos) é configurável para facilitar o teste da expiração.
- **F04/F05 (perfis/menu/permissões)**: precisam conceder `service_orders.termo` e `settings.termo` nos modelos de função (Proprietário, Gerente, Consultor; ver ACL) e confirmar que o Técnico não os recebe. `Config/acl.php` e `Config/menu.php` são arquivos compartilhados (inserção no fim do array, sem reordenar).
- **F09 (nota/saída) e Fiscal**: CNPJ alfanumérico (Receita, a partir de jul/2026) passa na validação deste módulo, mas `FiscalController:219-222,331-334`, `AsaasClient:85` e `CadastroController::existingClient:333` removem letras com `\D`. Cliente com CNPJ alfanumérico entraria no Cadastro e falharia na emissão da nota/cobrança. Registrar como dívida da F09/Fiscal.
- **IP real do cliente**: `bootstrap/app.php` não confia em proxy. Na HostGator direta `request()->ip()` é o IP do cliente; atrás de Cloudflare/proxy seria o IP do proxy (prova inútil). Conferir no servidor (Passo a passo) e, se preciso, configurar proxies confiáveis fora desta frente.
- **Fuso**: `config/app.php:82` cai para `Asia/Kolkata` se o `.env` não definir `APP_TIMEZONE`; o termo converte na exibição, mas `now()` gravado segue o fuso da aplicação (frente F01/ambiente deve garantir `America/Sao_Paulo`).
- **WhatsApp**: modelo precisa de aprovação da Meta (dias) e custa por conversa de utilidade; por isso o fallback `wa.me`/copiar. `sendTemplate` só envia parâmetros de corpo (não botão de URL): o link vai como 4º parâmetro de texto. Parâmetros não podem ter quebra de linha (higienizados no serviço). Previews de link do WhatsApp fazem `GET`: o `GET` público **não grava nada**.
- **Uploads**: 4 fotos de celular moderno passam facilmente do `post_max_size` de hospedagem compartilhada; sem o limite alto no PHP o formulário falha sem mensagem (e vira erro 419). Mitigação: aviso de limite no cartão (`LimiteUpload`) e, recomendado, redução de fotos no navegador. Ajuste de PHP no servidor é ação do usuário.
- **Segurança do link**: token de 48 caracteres, só o hash no banco, expiração, revogação ao gerar outro, throttle 30/min, 404 genérico, `noindex/no-store/no-referrer`, sem recurso externo na página, POST sem CSRF **apenas** nesta rota (a credencial é o token). Quem tiver o link pode aceitar em nome do cliente — por isso o link vai só ao WhatsApp cadastrado e expira.
- **Prova do aceite**: assinatura em PNG fica no banco (o disco privado não é por oficina e se perde no deploy). Linhas aceitas são imutáveis no model; o evento `termo_assinado` é append-only. A guarda por prazo legal (LGPD/contrato) é política do dono: o sistema não apaga nada.
- **Concorrência**: `lockForUpdate` na OS e na linha do termo; aceite simultâneo (tela + link) resulta em um único aceito (o outro vira `revogado`/erro amigável).
- **Dono fora do fluxo**: as rotas `admin.service_orders.termo.*` começam com `admin.service_orders.`; se o Proprietário colher o aceite, o `DonoForaDoFluxo` registra a ação (comportamento esperado do dono blindado).
- **Técnico sem valores**: o termo não contém dinheiro (não há variável de valor; diária de pátio aparece sem número). IP, aparelho e texto congelado só para `service_orders.termo`.
- **TravaService**: a frase `Autorização de rodagem do cliente` do fim do teste não pode mudar (`TravaService:156` procura `autoriza`).

---

## Decisões em aberto

1. **Texto jurídico do termo** (cláusulas de ciência, garantia, diária de pátio, LGPD). Recomendação: usar o texto padrão acima como ponto de partida, liberar a edição por oficina e fazer a oficina-piloto validar com o advogado antes de ligar o piloto numa oficina real. Definir também por quanto tempo guardar os aceites (o sistema nunca apaga; sugestão: pelo menos 5 anos).
2. **WhatsApp**: esperar a aprovação do modelo `termo_entrada` na Meta ou começar só com "Abrir no WhatsApp"/"Copiar link"? Recomendação: começar já com `wa.me`/copiar na Oficina Teste e pedir a aprovação do modelo em paralelo (texto sugerido acima).
3. **Cliente que responde "não autorizo a rodagem"**: pode sair do Cadastro? Recomendação: **sim** (o PDF diz "sim/não"; o bloqueio vale na hora de iniciar o teste, roteiro linha 43, e a autorização pode ser registrada depois).
4. **"Itens do veículo"**: texto livre + caixa "não há itens nem avarias" (recomendado agora) ou lista fixa por oficina (estepe, macaco, triângulo, extintor…)? Recomendação: texto livre agora; lista fixa só se a oficina pedir.
5. **CNPJ alfanumérico**: aceitar já na validação (recomendado, é a regra da Receita desde jul/2026) mesmo com Fiscal/Asaas ainda não preparados?
6. **OS de retorno/garantia e chamado externo**: exigem novo termo completo (4 fotos etc.)? Recomendação: retorno/garantia **sim**, mesmas regras (o caminhão volta à oficina); chamado externo, decidir com a F13 (o veículo não entra: sugestão de dispensar fotos/km e manter CPF/CNPJ e autorização do chamado).

---

## Passo a passo para o usuário

1. **Atualizar o servidor** com o zip desta rodada e abrir `public/migrate-safe.php?key=…` uma vez (cria a tabela `service_order_termos` no banco principal). Apagar o arquivo depois. Para cada oficina (e para a "Oficina Teste Mentoria") a tabela precisa existir no banco dela — seguir o procedimento que a frente de oficinas (F00) definir. Enquanto não existir, o cartão do termo funciona no modo antigo.
2. **Configurações > Termo de entrada**: ler o texto padrão, ajustar com o advogado, conferir "Mínimo de fotos" (4), "Validade do link" (2880 min = 48 h; na Oficina Teste pode usar 10 min para testar a expiração) e o nome do modelo do WhatsApp (`termo_entrada`).
3. **Configurações > Funções**: marcar "Termo de entrada (colher assinatura, enviar link, imprimir, refazer)" para Proprietário, Gerente e Consultor; marcar "Configurar o texto e as regras do termo de entrada" para o Proprietário. Técnico não recebe.
4. **WhatsApp (opcional agora)**: na Meta Business, criar o modelo `termo_entrada` (categoria Utilidade, português do Brasil) com o texto sugerido; depois de aprovado, confirmar o nome em Configurações > Termo de entrada. Sem isso, usar os botões "Copiar link" e "Abrir no WhatsApp".
5. **Servidor (cPanel > MultiPHP INI Editor)**: subir `post_max_size` e `upload_max_filesize` para pelo menos 40 MB, para as 4+ fotos de celular.
6. **Testar na Oficina Teste** (roteiro): (a) cadastrar um cliente com um CPF de dígito errado e ver que não avança; (b) tentar avançar sem fotos/km/aceite e conferir a lista (linha 11); (c) enviar o link ao WhatsApp de alguém da equipe, abrir no celular **em 4G**, aceitar e conferir na OS a data, a hora e o IP (linha 14) — o IP deve ser o do celular, não o do servidor; se aparecer o IP do servidor, avisar o desenvolvimento; (d) responder "não autorizo" e ver que o teste de rodagem não inicia (linha 43); (e) baixar o PDF do termo assinado.
7. **Equipe**: avisar que o IP e o aparelho do cliente são guardados só como prova do aceite (LGPD) e que o termo aceito não pode ser editado — para corrigir, "Refazer o termo" com justificativa.
