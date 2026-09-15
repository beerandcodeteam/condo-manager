# Síndico Conversacional — Project Description

## Overview

**Síndico Conversacional** é um microSaaS de atendimento e operação de condomínio pelo WhatsApp. O morador fala em linguagem natural; um **agente de IA externo (construído no n8n, fora do escopo desta especificação)** entende a mensagem, consulta o que vale para aquele condomínio, age e só escala para o síndico/zelador quando precisa de humano. Promessa: *"Fale com o condomínio como você já fala no grupo — só que a resposta vem da regra certa, o chamado não se perde, e o síndico só entra quando é síndico."*

Este projeto é o **backoffice**: a aplicação Laravel que é a **fonte da verdade** do condomínio e que expõe as **tools do agente como endpoints de API**. Toda "tool" chamada pelo agente é um endpoint HTTP aqui — o modelo descreve, **a API executa e decide**. Se a API recusa (conflito de reserva, antecedência inválida, morador desconhecido), o agente não confirma.

O backoffice tem duas faces: **(1) API para o n8n**, autenticada por token por condomínio, com o morador identificado pelo telefone do WhatsApp; **(2) painel web** para super admin, síndico e zelador acompanharem a **visão geral da operação do agente** e gerenciarem documentos, comunicados, chamados, áreas comuns, reservas, moradores e a fila de escalonamentos. Como toda tool passa por esta API, o backoffice **registra cada tool call** e deriva dela a atividade e as métricas do agente. O backoffice também **avisa o morador de volta** disparando webhooks para o n8n quando algo muda do lado humano. O visual do painel segue o canvas em `.spec/init/design/Sindico Conversacional - Backoffice.dc.html`.

**MVP** cobre as cinco capacidades do agente — consultar regimento/convenção (RAG com citação de artigo), consultar comunicados ativos (listagem por flag, sem RAG), abrir chamado de manutenção (com protocolo, prioridade e fotos), consultar status de chamado e reservar área comum (com conflito e antecedência) — mais verificação de morador, fila de escalonamentos, webhooks de notificação, log de tool calls e visão geral com métricas. **Fora do escopo:** o agente/fluxo n8n em si, integração direta com WhatsApp, portal web do morador, cobrança/assinatura do SaaS.

**Presentes no design, mas fora do MVP:** tela Conversas (mensagens, intenções detectadas, "responder como síndico", "ver/abrir conversa"); síndico em vários condomínios; atribuir zelador e reabrir chamado; aprovação pendente, pedidos recusados, capacidade e duração livre em reservas; "última mensagem" e "devolver à IA" em escalonamentos; importação de planilha e status "WhatsApp verificado" de moradores; toggles de comportamento do agente, indicador "Agente online" e busca global ⌘K; tipo de comunicado e contador de respostas por comunicado; card de Integração para o síndico.

### Key Concepts

- **Plataforma (tenancy):** uma única aplicação multi-condomínio. Todo dado de domínio pertence a um `Condomínio` e é isolado por ele; nenhum usuário ou token de um condomínio enxerga dados de outro.
- **Condomínio:** tenant. Possui token(s) de API usados pelo n8n, URL e segredo de webhook, blocos, unidades, moradores, documentos, comunicados, áreas comuns. Tem ainda dados **informativos** exibidos em Configurações: nome, cidade, número de WhatsApp do condomínio, zelador de contato (nome + telefone) e horário de silêncio (início/fim). Esses dados não alteram regras da API.
- **Super admin:** usuário da plataforma. Cadastra condomínios, cadastra síndicos e zeladores, vê todos os condomínios pelo **seletor de condomínio** da barra lateral. É o **único** que vê e gerencia a integração: gera/revoga tokens de API e configura o webhook (card "Integração" em Configurações).
- **Síndico:** usuário web vinculado a **exatamente um** condomínio (sem seletor). Gerencia tudo do seu condomínio: moradores, documentos, comunicados, chamados, áreas, reservas, escalonamentos e dados informativos do condomínio. Não vê tokens nem webhook.
- **Zelador:** usuário web vinculado a exatamente um condomínio com acesso operacional restrito: vê e atende **todos** os chamados (muda status, comenta, avisa morador) e escalonamentos do condomínio, sem atribuição. Não gerencia documentos, comunicados, áreas, moradores, reservas nem integração.
- **Bloco/Torre:** agrupamento opcional de unidades dentro do condomínio (ex.: "Bloco B"). Condomínios sem blocos têm unidades diretamente.
- **Unidade:** apartamento/casa (ex.: "101"), opcionalmente dentro de um bloco. Tem um ou mais moradores.
- **Morador:** pessoa vinculada a uma unidade e identificada pelo **telefone WhatsApp (formato E.164, obrigatório, único por condomínio)**. Tem **perfil** `proprietário` ou `inquilino`. Não tem login web; existe só para a API. Cadastrado pelo síndico no backoffice.
- **Verificação de morador:** endpoint que responde se um telefone é morador ativo do condomínio (e de qual unidade). O fluxo de "número desconhecido" (pré-cadastro, recusa, mensagem) é responsabilidade do n8n.
- **Documento normativo:** regimento interno ou convenção do condomínio. Enviado em **PDF**; o sistema extrai o texto e quebra em **artigos**; o síndico **revisa/corrige os artigos antes de publicar**. Só documentos publicados entram na busca.
- **Artigo:** unidade de citação do RAG — pertence a um documento normativo, tem número/identificador (ex.: "Art. 12, §2º"), título opcional, texto e embedding. Toda resposta de regra cita documento + artigo. O painel mostra quantas vezes cada artigo foi retornado ao agente ("citado N×", derivado do log de tool calls).
- **Comunicado:** aviso do condomínio (ex.: "falta d'água dia 20", "obra no hall") com título, texto e flag **ativo/inativo** (`is_active`), controlada manualmente pelo síndico. **Sem datas de validade e sem RAG**: a tool devolve a lista completa de comunicados ativos do condomínio e o agente decide o que é relevante. Inativos ficam só como histórico no painel.
- **RAG:** busca semântica feita **no Laravel**, **apenas sobre artigos**, com embeddings **OpenAI `text-embedding-3-small`** (via Laravel AI SDK) armazenados em **Postgres + pgvector**. Retrieval sempre filtrado por condomínio e por documento publicado. Similaridade mínima padrão **0.5**, **5** resultados por padrão e no máximo **10** por requisição, em config global.
- **Testar pergunta:** simulador na tela de Regimento que chama a mesma busca da API e mostra artigo, trecho, score e latência. Não gera resposta por LLM e não entra no log de tool calls.
- **Chamado:** solicitação de manutenção aberta pelo morador via agente ou pelo síndico/zelador no painel. Tem **protocolo**, descrição, local, **categoria**, **prioridade**, até 5 fotos, status, origem (`whatsapp` | `painel`) e histórico.
- **Categoria de chamado:** cadastrável por condomínio (ativa/inativa); cada condomínio nasce com elétrica, hidráulica, elevador, limpeza, segurança e outros.
- **Prioridade do chamado:** `alta`, `média` ou `baixa`. O agente pode enviar na abertura; síndico/zelador alteram no painel. Chamados abertos com prioridade alta contam como "urgentes" na visão geral.
- **Protocolo:** número **sequencial contínuo por condomínio** (1, 2, 3…, nunca reinicia), único mesmo com aberturas simultâneas; exibido como `#123`. É a chave para "e aquela infiltração?".
- **Status do chamado:** `aberto` → `em_andamento` → `resolvido`, com `cancelado` como saída a partir de `aberto` ou `em_andamento`. `resolvido` e `cancelado` são **finais** (sem reabrir). Toda mudança gera entrada no histórico e webhook para o n8n.
- **Avisar morador:** ação manual no chamado em que síndico/zelador escrevem uma mensagem livre; gera webhook para o n8n entregar ao morador e fica registrada na linha do tempo do chamado.
- **Área comum:** espaço reservável cadastrado pelo síndico (salão de festas, churrasqueira, quadra). Tem **faixas de horário fixas**, ativo/inativo, **antecedência mínima** (padrão 24 h), **antecedência máxima** (padrão 60 dias) e **prazo de cancelamento pelo morador** (padrão 24 h antes do início), todos editáveis.
- **Faixa de horário:** período fixo reservável de uma área (ex.: churrasqueira 10h–16h e 17h–23h). Unidade de conflito da reserva.
- **Reserva:** uso de uma faixa de uma área numa data por um morador/unidade, com origem `whatsapp` ou `painel`. Pela API a **confirmação é sempre automática**: se passa nas regras, nasce `confirmada`; se não passa, a API recusa com motivo e **nada é criado**. Conflito = mesma área + mesma data + mesma faixa já confirmada. Não há limite de reservas por unidade.
- **Reserva manual:** o síndico reserva pelo painel em nome de uma unidade/morador. Valida apenas área ativa e conflito de faixa; **ignora a antecedência**.
- **Escalonamento:** pedido de intervenção humana criado pelo agente com **motivo categorizado** (`pediu_humano`, `tool_recusou`, `sem_regra`), resumo, morador e chamado opcional. Entra numa **fila no backoffice**: `pendente` → **Assumir** (`em_atendimento`, com responsável; o painel mostra "Com você") → responder e `resolvido`. Só o responsável responde; o síndico pode assumir no lugar de outro responsável, o zelador não.
- **Webhook de notificação:** evento HTTP assinado (HMAC com segredo do condomínio), enviado por fila para a URL do n8n do condomínio, quando um humano altera algo que o morador precisa saber ou envia um aviso. **Até 3 tentativas**; se todas falharem, a entrega fica marcada como falha e aparece na lista "Falhas de webhook" em Configurações (super admin e síndico), sem reenvio manual no MVP.
- **Log de tool calls:** registro, feito por middleware, de cada chamada aos endpoints `api/v1` do agente: tool, morador (telefone), resultado (`sucesso`, `vazio` ou `recusa`), código de erro, entidades envolvidas (artigos retornados, chamado, reserva ou escalonamento criado/consultado) e latência. Mantido indefinidamente (sem expurgo). É a fonte do feed "Atividade do agente", das métricas da visão geral, do "citado N×" dos artigos, das chamadas/7d por endpoint e das interações por morador.
- **Visão geral:** tela inicial do painel com métricas derivadas do log e da operação (definições no fluxo 9).

## Tech Stack

| Layer | Technology |
|---|---|
| Linguagem | PHP 8.5 (composer exige `^8.3`) |
| Backend | Laravel 13.31 |
| Frontend (painel) | Livewire 4.4 + Blaze 1.0 (starter kit blank Livewire), Tailwind CSS 4, Vite 8 (vite-plus 0.3) |
| API para o n8n | Rotas `api/v1` Laravel, JSON; autenticação por token de condomínio (Laravel Sanctum — aprovado, instalar via `install:api`). Sem rate limit no MVP |
| Autenticação web | Sessão Laravel para super admin / síndico / zelador (starter kit blank não traz auth — a adicionar). Só login/logout: sem recuperação de senha e sem 2FA no MVP |
| Banco de dados | **PostgreSQL 18 + pgvector 0.8.2** (imagem `pgvector/pgvector:pg18`); extensão `vector` disponível, habilitar via migration (`CREATE EXTENSION vector`) |
| RAG / embeddings | Laravel AI SDK (`laravel/ai` — aprovado, a instalar) com OpenAI `text-embedding-3-small`; coluna `vector` + busca por similaridade no Postgres |
| Extração de PDF | Biblioteca de parsing de PDF em PHP — aprovada; pacote escolhido na implementação. Sem limite de tamanho de arquivo na aplicação no MVP |
| Arquivos | Laravel Filesystem (fotos de chamados, PDFs de documentos) — disco `local` em dev |
| Filas / jobs | Queue driver `database` (extração de PDF, geração de embeddings dos artigos, envio de webhooks) |
| Design de referência | Canvas `.spec/init/design/Sindico Conversacional - Backoffice.dc.html`: layout com barra lateral, cards, pills, tabelas e drawer. Telas sem design (login, super admin, revisão de artigos, blocos/unidades, categorias, formulários) seguem o mesmo sistema visual |
| Cache / sessão | Driver `database` |
| Testes | Pest 5.2 + pest-plugin-laravel 5 |
| Qualidade | Laravel Pint 1.32, Larastan 3.12 (PHPStan) |
| Ambiente dev | Laravel Sail 1.67 (`compose.yaml`: runtime PHP 8.5 + serviço `pgsql`); comandos rodam via `./vendor/bin/sail` |
| Banco de testes | Postgres database `testing` no mesmo serviço (`phpunit.xml`), necessário para testar pgvector |
| Tooling IA | Laravel Boost 2.9 (MCP) |
| CI | GitHub Actions (`.github/workflows/tests.yml`) |
| Integração externa | n8n (agente de IA + WhatsApp) — consome a API e recebe webhooks |

## Core Workflows

### 1. Onboarding do condomínio (super admin / síndico)

1. Super admin cadastra o condomínio (nome único). As categorias padrão de chamado são semeadas.
2. Super admin gera um **token de API** para o condomínio (exibido uma única vez) e configura **URL de webhook do n8n + segredo**, no card "Integração" de Configurações (visível só para ele). Tokens podem ser revogados e regenerados. O card também lista os endpoints com o nome da tool e as chamadas dos últimos 7 dias.
3. Super admin cadastra síndico(s) e zelador(es), cada um vinculado a exatamente um condomínio.
4. Síndico completa os dados informativos do condomínio em Configurações: cidade, número de WhatsApp, zelador de contato, horário de silêncio.
5. Síndico cadastra blocos (opcional), unidades e moradores (nome, telefone E.164, unidade, perfil proprietário/inquilino, ativo).
6. Síndico cadastra áreas comuns com faixas de horário e regras (antecedência 24 h / 60 dias e cancelamento 24 h como padrão).
7. Síndico envia documentos normativos (fluxo 3) e cadastra comunicados (fluxo 4).

### 2. Verificação de morador (API)

O n8n chama antes de agir. O tratamento de número desconhecido é do n8n.

```http
GET /api/v1/residents/lookup?phone=+5511999990000
Authorization: Bearer <token-do-condominio>
```

```json
200 OK
{
  "exists": true,
  "resident": { "id": 42, "name": "Ana Souza", "phone": "+5511999990000" },
  "unit": { "id": 7, "number": "101", "block": "B" }
}
```

```json
200 OK
{ "exists": false }
```

- Morador inativo responde `exists: false`.
- Todos os endpoints de ação (chamado, reserva, escalonamento) exigem `phone` de morador ativo; caso contrário `403` com `code: "resident_not_found"`.

### 3. Documento normativo → artigos → busca com citação

**Painel (síndico):**
1. Upload do PDF (tipo: regimento ou convenção).
2. Job extrai o texto e quebra em artigos (número, título, texto). Documento fica `em_revisão`.
3. Síndico revisa a lista de artigos: edita texto/número, junta, divide, remove.
4. Síndico publica → job gera embeddings de cada artigo → documento `publicado`. Uma nova versão publicada do mesmo tipo substitui a anterior na busca.

**API (tool "consultar regimento"):**

```http
POST /api/v1/rules/search
Authorization: Bearer <token-do-condominio>
Content-Type: application/json

{ "query": "posso furar a sacada?", "limit": 5 }
```

```json
200 OK
{
  "results": [
    {
      "document": { "id": 3, "type": "regimento", "title": "Regimento Interno 2024" },
      "article": { "id": 88, "reference": "Art. 23, §1º", "title": "Fachada" },
      "text": "É vedado alterar a fachada, incluindo perfurações em sacadas...",
      "score": 0.87
    }
  ]
}
```

- Busca só em artigos de documentos **publicados** do condomínio do token.
- `limit` padrão 5, máximo 10; resultados abaixo da similaridade mínima (0.5) são descartados.
- Sem resultado acima do limiar → `results: []` (o agente não responde de cabeça; pode escalar). Chamada registrada no log como `vazio`.

**Painel — Testar pergunta:** o síndico digita ou escolhe uma pergunta; o painel executa a mesma busca e mostra artigo, trecho, score e latência. Não entra no log de tool calls.

### 4. Comunicados ativos

**Painel (síndico):** cria, edita, ativa/desativa e exclui comunicados (título, texto, ativo). Abas **Ativos** e **Inativos**; o texto da tela explica que só os ativos chegam ao agente.

**API (tool "consultar comunicados"):**

```http
GET /api/v1/notices
Authorization: Bearer <token-do-condominio>
```

```json
200 OK
{
  "notices": [
    {
      "id": 15,
      "title": "Manutenção da caixa d'água",
      "text": "Quinta 18/09, das 9h às 14h, não haverá abastecimento nos blocos A e B.",
      "updated_at": "2026-09-15T08:00:00-03:00"
    }
  ]
}
```

- Retorna **todos** os comunicados ativos do condomínio do token, mais recentes primeiro. Sem busca semântica, sem parâmetros de filtro.
- Nenhum ativo → `notices: []` (registrado no log como `vazio`).

### 5. Abrir chamado de manutenção

**API (tool "abrir chamado"):**

```http
POST /api/v1/tickets
Authorization: Bearer <token-do-condominio>
Content-Type: multipart/form-data

phone=+5511999990000
description=Elevador do bloco B parou no 3º andar
location=Elevador Bloco B
category=elevador
priority=alta
photos[]=<arquivo>
```

```json
201 Created
{
  "protocol": 123,
  "status": "aberto",
  "priority": "alta",
  "created_at": "2026-09-15T10:12:00-03:00"
}
```

1. Valida morador (fluxo 2), descrição obrigatória, categoria ativa opcional (senão `422 invalid_category`), prioridade opcional (`alta` | `media` | `baixa`, padrão `media`), até 5 fotos de até 10MB (jpg, png, webp, heic).
2. Cria chamado vinculado ao morador e à unidade, com origem `whatsapp`; gera protocolo sequencial contínuo do condomínio (com lock); registra histórico `aberto`.
3. Chamado aparece no painel para síndico e zelador.

**Painel (síndico/zelador):**
- Abas Todos / Abertos / Em andamento / Concluídos (resolvido + cancelado), com colunas protocolo, chamado + origem, unidade, categoria, prioridade, status e abertura.
- Drawer de detalhe com descrição, fotos e linha do tempo.
- Ação principal conforme o status ("Iniciar atendimento", "Marcar concluído"); cancelar exige comentário. Cada mudança gera webhook `ticket.status_changed` (fluxo 8).
- Alterar prioridade.
- **Avisar morador:** mensagem livre obrigatória → webhook `ticket.resident_notified` e entrada na linha do tempo. Só em chamados com morador vinculado.
- **+ Novo chamado:** abertura pelo painel com origem `painel`; unidade e morador opcionais.

### 6. Consultar status do chamado

```http
GET /api/v1/tickets?phone=+5511999990000&status=open
GET /api/v1/tickets/123?phone=+5511999990000
Authorization: Bearer <token-do-condominio>
```

```json
200 OK
{
  "protocol": 123,
  "description": "Infiltração no teto do banheiro",
  "status": "em_andamento",
  "priority": "media",
  "updated_at": "2026-09-14T16:40:00-03:00",
  "history": [
    { "status": "aberto", "at": "2026-09-10T08:00:00-03:00", "comment": null },
    { "status": "em_andamento", "at": "2026-09-14T16:40:00-03:00", "comment": "Técnico agendado para 16/09" }
  ]
}
```

- Listagem sem protocolo retorna até 10 chamados da **unidade** do morador (mais recentes primeiro) para o agente desambiguar "aquela infiltração". `status` aceita `open` (aberto + em_andamento), `closed` (resolvido + cancelado) ou `all` (padrão).
- Chamado de outra unidade → `404 ticket_not_found`.

### 7. Reservar área comum

**Painel (síndico):**
- Cadastra área (nome, descrição, ativa), faixas de horário (início/fim), antecedência mínima e máxima e prazo de cancelamento pelo morador. As regras de cada área aparecem em Configurações e no card "Regras aplicadas pela tool" da tela de Reservas.
- Vê a **agenda semanal** (áreas × dias, com navegação de semana) e o card "Pedidos pelo WhatsApp" com as reservas recentes de origem `whatsapp`.
- **+ Reserva manual:** escolhe área, data, faixa, unidade e morador. Valida só área ativa e conflito de faixa (ignora antecedência). Nasce `confirmada` com origem `painel`.
- Cancela reserva com motivo obrigatório, sem prazo → webhook `reservation.cancelled`.

**API (tools "consultar disponibilidade" e "reservar"):**

```http
GET /api/v1/areas
GET /api/v1/areas/5/availability?date=2026-09-27
Authorization: Bearer <token-do-condominio>
```

```json
200 OK
{
  "area": { "id": 5, "name": "Churrasqueira" },
  "date": "2026-09-27",
  "slots": [
    { "id": 11, "starts": "10:00", "ends": "16:00", "available": false },
    { "id": 12, "starts": "17:00", "ends": "23:00", "available": true }
  ]
}
```

```http
POST /api/v1/reservations
Authorization: Bearer <token-do-condominio>

{ "phone": "+5511999990000", "area_id": 5, "slot_id": 12, "date": "2026-09-27" }
```

```json
201 Created
{ "id": 301, "status": "confirmada", "area": "Churrasqueira", "date": "2026-09-27", "starts": "17:00", "ends": "23:00" }
```

```json
422 Unprocessable Entity
{ "code": "slot_unavailable", "message": "Faixa já reservada nesta data." }
```

Regras (checadas em transação, com trava contra reserva simultânea):
1. Morador ativo (senão `403 resident_not_found`).
2. Área ativa e faixa pertence à área (senão `422 area_unavailable`).
3. Data dentro da antecedência mínima e máxima da área (senão `422 advance_notice_violation`, com os limites na resposta).
4. Nenhuma reserva confirmada na mesma área + data + faixa (senão `422 slot_unavailable`).
5. Passou em tudo → reserva `confirmada` imediatamente, origem `whatsapp`. Recusa não cria registro (fica só no log como `recusa`); o agente **não confirma** ao morador.

**API (tools "minhas reservas" e "cancelar reserva"):**

```http
GET /api/v1/reservations?phone=+5511999990000
DELETE /api/v1/reservations/301?phone=+5511999990000
Authorization: Bearer <token-do-condominio>
```

- Listagem: reservas `confirmada` futuras da **unidade** do morador, com indicação se ainda são canceláveis.
- Cancelamento: só reserva da unidade (senão `404 reservation_not_found`) e antes do prazo da área (senão `422 cancellation_deadline_passed`). A reserva vira `cancelada` e a faixa é liberada. Não gera webhook.

### 8. Escalonamento para humano e notificação de volta

**API (tool "escalar"):**

```http
POST /api/v1/escalations
Authorization: Bearer <token-do-condominio>

{
  "phone": "+5511999990000",
  "reason": "tool_recusou",
  "summary": "Quer o salão em 20/09 à noite; reservar_area recusou por conflito.",
  "ticket_protocol": null
}
```

```json
201 Created
{ "id": 77, "status": "pendente" }
```

- `reason` obrigatório: `pediu_humano`, `tool_recusou` ou `sem_regra` (senão `422`). `summary` obrigatório. `ticket_protocol` opcional, da unidade do morador.

**Painel (síndico/zelador):**
1. Fila de escalonamentos não resolvidos (mais antigos primeiro). Cada card mostra morador · unidade, pill do motivo, tempo de espera e resumo.
2. **Assumir:** escalonamento passa a `em_atendimento` com o usuário como responsável; o card mostra "Com você" (ou o nome do responsável para os demais).
3. **Responder:** quem assumiu registra a resposta ao morador → `resolvido` + webhook `escalation.answered`.

**Webhooks para o n8n** (job em fila, assinado com HMAC do segredo do condomínio, **até 3 tentativas** com backoff; falha definitiva fica visível no painel):

```json
POST <webhook_url do condomínio>
X-Signature: sha256=<hmac>

{
  "event": "ticket.status_changed",
  "condominium_id": 1,
  "resident_phone": "+5511999990000",
  "data": { "protocol": 123, "status": "resolvido", "comment": "Elevador liberado" },
  "occurred_at": "2026-09-15T15:00:00-03:00"
}
```

Eventos do MVP: `ticket.status_changed`, `ticket.resident_notified` (aviso manual no chamado), `reservation.cancelled` (cancelada pelo síndico), `escalation.answered`.

### 9. Log de tool calls e visão geral

**Registro (API):** um middleware nos endpoints `api/v1` do agente grava, ao fim de cada request:
- tool/endpoint e condomínio do token;
- telefone e morador, quando identificado;
- resultado: `sucesso` (2xx com conteúdo), `vazio` (2xx sem resultados, ex.: busca de regras sem artigo acima do limiar) ou `recusa` (401/403/404/422);
- código de erro, entidades envolvidas (artigos retornados, chamado, reserva ou escalonamento criado/consultado) e latência em ms.

O registro nunca altera nem atrasa a resposta ao n8n.

**Painel — Visão geral (síndico/zelador; super admin no condomínio selecionado):**

| Elemento | Definição |
|---|---|
| Atendimentos hoje | moradores distintos com ≥ 1 tool call hoje (fuso `America/Sao_Paulo`), com variação vs. ontem |
| Resolvidas pelo agente | % dos moradores atendidos hoje sem escalonamento criado hoje, com "N de M sem humano" |
| Chamados abertos | chamados `aberto` + `em_andamento`, com "N urgentes" (prioridade alta) |
| Aguardando síndico | escalonamentos `pendente` + `em_atendimento`, com tempo médio de espera |
| Atividade do agente | últimas tool calls: hora, morador · unidade, descrição da ação e pill (ex.: "Regimento · Art. 14", "Chamado #123", "Reserva confirmada", "Escalado") |
| Aguardando humano | escalonamentos mais antigos não resolvidos, com link para a fila |
| Resolução por tool · 7 dias | por tool (regimento, comunicados, abrir chamado, status de chamado, reservar área): % de chamadas com resultado `sucesso` |

**Outros usos do log:**
- "citado N×" em cada artigo na tela de Regimento.
- Coluna de interações por morador em Moradores.
- Chamadas dos últimos 7 dias por endpoint no card Integração (super admin).
