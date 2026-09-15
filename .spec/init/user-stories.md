# Síndico Conversacional — User Stories

<!-- inputs: project-description.md@sha256:4ed5866b9b9c -->

## Overview

O **Síndico Conversacional** é o backoffice de um microSaaS multi-condomínio de atendimento pelo WhatsApp. Um agente de IA externo (n8n) conversa com o morador e usa este sistema como **fonte da verdade e executor**: as tools do agente são endpoints de API autenticados por token de condomínio, e o morador é identificado pelo telefone. Cada tool call é registrada e alimenta a visão geral do painel. O painel web permite ao super admin, ao síndico e ao zelador acompanhar a operação do agente e gerenciar regimento/convenção, comunicados, chamados, áreas comuns, reservas e a fila de escalonamentos, e dispara webhooks para o n8n avisar o morador quando um humano altera algo. As telas seguem o canvas `.spec/init/design/Sindico Conversacional - Backoffice.dc.html`.

Estas stories cobrem o MVP descrito em `project-description.md`. O fluxo do agente no n8n, a integração direta com WhatsApp, o portal web do morador, a cobrança do SaaS e os itens do design marcados como fora do MVP na descrição (tela Conversas, síndico multi-condomínio, atribuição/reabertura de chamado, aprovação de reserva, importação de planilha, toggles do agente, busca global, entre outros) estão fora do escopo.

**User Types:**
- **Super admin** - operador da plataforma; cadastra condomínios e usuários web; único que vê e gerencia tokens de API e webhooks; troca de condomínio pelo seletor da barra lateral.
- **Síndico** - usuário web de exatamente um condomínio; gerencia moradores, documentos, comunicados, chamados, áreas, reservas, escalonamentos e dados informativos do seu condomínio.
- **Zelador** - usuário web de exatamente um condomínio com acesso operacional; vê e atende todos os chamados e escalonamentos do condomínio, sem atribuição.
- **Agente de IA (n8n)** - cliente de API autenticado com o token de um condomínio; executa as tools em nome do morador.
- **Morador** - pessoa vinculada a uma unidade, identificada pelo telefone WhatsApp; sem login web; beneficiário final das tools e dos webhooks.

---

## 1. Plataforma, acesso e tenancy

### US-1.1: Login no painel por papel
**As a** Super admin, Síndico ou Zelador
**I want to** entrar no painel com e-mail e senha
**So that** eu acesse apenas as funções e o condomínio que meu papel permite

**Acceptance Criteria:**
- [ ] Login por e-mail + senha; credenciais inválidas exibem erro genérico sem revelar se o e-mail existe.
- [ ] Logout encerra a sessão; rotas do painel sem sessão redirecionam para o login.
- [ ] Todo usuário tem exatamente um papel: `super_admin`, `sindico` ou `zelador`.
- [ ] Síndico e zelador pertencem a exatamente um condomínio; super admin não pertence a nenhum.
- [ ] O primeiro super admin é criado por seeder/comando Artisan (não há auto-cadastro público).
- [ ] Não há recuperação de senha nem 2FA no MVP: a tela de login não exibe "Esqueci minha senha".
- [ ] Zelador que acessa rota de documentos, comunicados, áreas, reservas, moradores, categorias, dados do condomínio, tokens ou webhooks recebe `403`.
- [ ] Síndico que acessa rota de tokens, webhooks ou gestão de condomínios/usuários recebe `403`.
- [ ] Após login, síndico e zelador caem na Visão geral do seu condomínio, sem seletor de condomínio.

**Expected Result:** Cada usuário entra no painel e vê somente o menu e as telas do seu papel.

---

### US-1.2: Isolamento entre condomínios
**As a** Síndico
**I want to** ter a garantia de que nenhum dado do meu condomínio aparece para outro condomínio
**So that** a operação de cada condomínio seja privada

**Acceptance Criteria:**
- [ ] Todo registro de domínio (bloco, unidade, morador, documento, artigo, comunicado, categoria, chamado, área, reserva, escalonamento) pertence a um condomínio.
- [ ] Síndico/zelador do condomínio A que acessa, por URL, recurso do condomínio B recebe `404`.
- [ ] Token de API do condomínio A nunca retorna, cria ou altera dados do condomínio B (inclusive busca RAG).
- [ ] Super admin escolhe qualquer condomínio pelo seletor da barra lateral (nome, cidade) e passa a ver o painel daquele condomínio; o seletor tem a ação "+ Novo condomínio".

**Expected Result:** Consultas do painel e da API são sempre escopadas pelo condomínio do usuário ou do token.

---

### US-1.3: Cadastrar condomínio
**As a** Super admin
**I want to** cadastrar, editar e listar condomínios
**So that** novos clientes possam começar a usar a plataforma

**Acceptance Criteria:**
- [ ] Nome obrigatório e único na plataforma; nome vazio ou duplicado retorna erro de validação.
- [ ] Ao criar condomínio, as categorias padrão de chamado são semeadas: elétrica, hidráulica, elevador, limpeza, segurança, outros.
- [ ] Lista de condomínios paginada com busca por nome.
- [ ] Síndico e zelador não têm acesso a esta tela (`403`).

**Expected Result:** Condomínio criado aparece na lista, pronto para receber usuários, token e cadastros.

---

### US-1.4: Cadastrar síndicos e zeladores
**As a** Super admin
**I want to** criar e desativar usuários síndico e zelador vinculados a um condomínio
**So that** a equipe do condomínio acesse o painel

**Acceptance Criteria:**
- [ ] Campos: nome, e-mail (único na plataforma), papel (`sindico` | `zelador`), condomínio, senha inicial.
- [ ] Um condomínio pode ter mais de um síndico e mais de um zelador.
- [ ] Usuário desativado não consegue logar; sessões ativas dele passam a ser rejeitadas.
- [ ] E-mail duplicado retorna erro de validação.

**Expected Result:** Síndico/zelador criado consegue logar e cai direto no seu condomínio.

---

### US-1.5: Gerar e revogar token de API do condomínio
**As a** Super admin
**I want to** gerar e revogar tokens de API de um condomínio
**So that** o n8n daquele condomínio consiga chamar as tools com segurança

**Acceptance Criteria:**
- [ ] Token gerado é exibido **uma única vez** em texto puro; depois só aparecem nome, data de criação e último uso.
- [ ] Token é armazenado com hash (Sanctum), nunca em texto puro.
- [ ] Um condomínio pode ter mais de um token ativo (ex.: rotação).
- [ ] Token revogado passa a receber `401` imediatamente em qualquer endpoint `api/v1`.
- [ ] Gestão feita no card "Integração" em Configurações do condomínio selecionado, visível **apenas** para super admin; o card lista os endpoints do agente com o nome da tool e as chamadas dos últimos 7 dias (US-9.4).

**Expected Result:** O n8n recebe um token funcional e o super admin consegue rotacioná-lo sem downtime.

---

### US-1.6: Configurar webhook do n8n
**As a** Super admin
**I want to** configurar URL e segredo de webhook por condomínio
**So that** o backoffice consiga avisar o morador pelo n8n

**Acceptance Criteria:**
- [ ] Campos: URL (https obrigatória fora do ambiente local) e segredo (gerado pelo sistema, regenerável).
- [ ] O segredo completo é exibido apenas na geração/regeneração.
- [ ] Sem URL configurada, eventos não são enviados e nenhuma ação do painel falha por isso.

**Expected Result:** Condomínio com webhook configurado passa a receber os eventos da US-8.3.

---

## 2. Cadastros do condomínio

### US-2.1: Cadastrar blocos e unidades
**As a** Síndico
**I want to** cadastrar blocos (opcionais) e unidades
**So that** moradores, chamados e reservas fiquem vinculados à unidade certa

**Acceptance Criteria:**
- [ ] Bloco: nome único dentro do condomínio.
- [ ] Unidade: número obrigatório, bloco opcional; número único dentro do mesmo bloco (ou dentro do condomínio quando sem bloco).
- [ ] Bloco com unidades não pode ser excluído (erro explicativo).
- [ ] Unidade com moradores, chamados ou reservas não pode ser excluída.

**Expected Result:** Estrutura bloco → unidade do condomínio cadastrada e navegável no painel.

---

### US-2.2: Cadastrar moradores
**As a** Síndico
**I want to** cadastrar, editar, ativar e inativar moradores com telefone WhatsApp
**So that** o agente identifique quem está falando

**Acceptance Criteria:**
- [ ] Campos: nome, telefone, unidade, perfil `proprietario` | `inquilino` (obrigatórios), ativo (padrão `true`).
- [ ] Telefone é normalizado e validado em formato E.164 (ex.: `+5511999990000`); formato inválido retorna erro.
- [ ] Telefone é único por condomínio; o mesmo telefone pode existir em outro condomínio.
- [ ] Uma unidade pode ter vários moradores.
- [ ] Morador é inativado, não excluído, quando possui chamados, reservas ou escalonamentos.
- [ ] Lista com colunas unidade, nome, telefone, perfil e interações (US-9.4); filtros por bloco (chips "Todos · N" e um por bloco), unidade, nome, telefone e ativo.
- [ ] Telefone ausente é erro de validação (não existe morador "sem WhatsApp").

**Expected Result:** Morador ativo cadastrado é reconhecido pela verificação da US-3.2.

---

### US-2.3: Gerenciar categorias de chamado
**As a** Síndico
**I want to** criar, renomear e inativar categorias de chamado
**So that** os chamados reflitam a realidade do meu condomínio

**Acceptance Criteria:**
- [ ] Categoria: nome e slug únicos por condomínio; ativa/inativa.
- [ ] Categoria em uso não pode ser excluída, apenas inativada.
- [ ] Categoria inativa não é aceita na abertura de chamado (API ou painel), mas continua exibida nos chamados antigos.

**Expected Result:** Síndico controla a lista de categorias que o agente pode usar.

---

### US-2.4: Editar dados informativos do condomínio
**As a** Síndico
**I want to** manter cidade, WhatsApp, zelador de contato e horário de silêncio do condomínio
**So that** a equipe tenha esses dados à mão no painel

**Acceptance Criteria:**
- [ ] Card "Condomínio" em Configurações com nome (somente leitura para síndico), cidade, número de WhatsApp do condomínio (E.164), zelador de contato (nome + telefone E.164), horário de silêncio (início e fim, `HH:MM`) e total de unidades/blocos (calculado, somente leitura).
- [ ] Todos os campos editáveis são opcionais; telefone em formato inválido retorna erro de validação.
- [ ] Horário de silêncio aceita início maior que fim (atravessa a meia-noite, ex.: 22:00–08:00); informar só um dos dois é erro.
- [ ] Esses dados são informativos: nenhum endpoint `api/v1` ou regra de reserva/chamado muda por causa deles.
- [ ] Super admin edita os mesmos campos e também o nome; zelador recebe `403`.

**Expected Result:** Configurações exibe os dados do condomínio atualizados pelo síndico.

---

## 3. API base e verificação de morador

### US-3.1: Autenticar chamadas do n8n
**As a** Agente de IA (n8n)
**I want to** chamar os endpoints `api/v1` com o token Bearer do condomínio
**So that** cada chamada seja executada no condomínio certo

**Acceptance Criteria:**
- [ ] Todos os endpoints `api/v1` exigem `Authorization: Bearer <token>`; ausente ou inválido → `401`.
- [ ] O condomínio da operação é sempre o dono do token; nenhum parâmetro de request troca o condomínio.
- [ ] Respostas de erro são JSON com `code` (string estável) e `message` (pt-BR).
- [ ] Erros de validação retornam `422` com `code: "validation_error"` e os campos inválidos.
- [ ] O uso do token atualiza o "último uso" exibido na US-1.5.
- [ ] Não há rate limit por token no MVP.

**Expected Result:** O n8n tem um contrato de autenticação e de erros previsível em todas as tools.

---

### US-3.2: Verificar se telefone é morador
**As a** Agente de IA (n8n)
**I want to** consultar se um telefone é morador ativo do condomínio
**So that** eu decida no n8n como tratar números desconhecidos

**Acceptance Criteria:**
- [ ] `GET /api/v1/residents/lookup?phone=` aceita telefone E.164; formato inválido → `422`.
- [ ] Morador ativo → `200` com `exists: true`, dados do morador (id, nome, telefone) e da unidade (id, número, bloco ou `null`).
- [ ] Telefone inexistente **ou** morador inativo → `200` com `exists: false` e nenhum outro dado.
- [ ] Telefone cadastrado apenas em outro condomínio → `exists: false`.
- [ ] Endpoints de ação (chamado, reserva, escalonamento) com telefone que não é morador ativo → `403` com `code: "resident_not_found"`.

**Expected Result:** O n8n sabe, antes de agir, se o número pertence a um morador ativo e de qual unidade.

---

## 4. Regimento e convenção (RAG com citação)

### US-4.1: Enviar PDF de regimento ou convenção
**As a** Síndico
**I want to** enviar o PDF do regimento interno ou da convenção
**So that** o sistema transforme o documento em artigos consultáveis

**Acceptance Criteria:**
- [ ] Upload aceita apenas PDF, com tipo obrigatório (`regimento` | `convencao`) e título.
- [ ] A aplicação não impõe limite de tamanho ao PDF no MVP.
- [ ] Após upload, o documento fica `processando` e um job em fila extrai o texto e divide em artigos (referência, título opcional, texto, ordem).
- [ ] Extração concluída → documento `em_revisao`.
- [ ] PDF sem texto extraível (ex.: escaneado) ou erro no parser → documento `falha_extracao` com mensagem visível; síndico pode reenviar.
- [ ] O PDF original fica armazenado e disponível para download no painel.

**Expected Result:** Documento enviado aparece no painel com seus artigos extraídos aguardando revisão.

---

### US-4.2: Revisar artigos antes de publicar
**As a** Síndico
**I want to** revisar e corrigir os artigos extraídos
**So that** as citações do agente sejam fiéis ao documento

**Acceptance Criteria:**
- [ ] Documento `em_revisao` lista artigos na ordem, com referência (ex.: "Art. 23, §1º"), título e texto.
- [ ] Síndico pode editar referência, título e texto; incluir artigo novo; excluir artigo; reordenar.
- [ ] Referência e texto são obrigatórios em cada artigo.
- [ ] Documento sem nenhum artigo não pode ser publicado.
- [ ] Artigos de documento publicado não são editáveis diretamente; mudança exige novo upload (nova versão).

**Expected Result:** Documento em revisão contém a lista final e correta de artigos.

---

### US-4.3: Publicar documento e indexar artigos
**As a** Síndico
**I want to** publicar o documento revisado
**So that** ele passe a ser a regra vigente consultada pelo agente

**Acceptance Criteria:**
- [ ] Publicar dispara job que gera embedding OpenAI `text-embedding-3-small` de cada artigo e grava em coluna `vector` (pgvector).
- [ ] Documento só aparece como `publicado` (e entra na busca) depois que todos os artigos têm embedding; falha no job mantém o documento fora da busca com erro visível e opção de tentar novamente.
- [ ] Ao publicar, o documento publicado anterior do **mesmo tipo** vira `substituido` e sai da busca.
- [ ] Documentos `substituido` continuam listados no painel como histórico.

**Expected Result:** Existe no máximo um regimento e uma convenção publicados por condomínio, ambos indexados.

---

### US-4.4: Buscar regra com citação do artigo
**As a** Agente de IA (n8n)
**I want to** buscar trechos do regimento/convenção por pergunta em linguagem natural
**So that** o morador receba resposta baseada no artigo certo, não inventada

**Acceptance Criteria:**
- [ ] `POST /api/v1/rules/search` com `query` obrigatória (string não vazia) e `limit` opcional (padrão 5, máximo 10; acima → `422`).
- [ ] Busca apenas artigos de documentos `publicado` do condomínio do token.
- [ ] Cada resultado traz: documento (id, tipo, título), artigo (id, referência, título), texto integral do artigo e `score`.
- [ ] Resultados ordenados por `score` decrescente; resultados abaixo da similaridade mínima configurada (padrão `0.5`) são descartados.
- [ ] Sem resultado acima do limiar → `200` com `results: []`.
- [ ] Similaridade mínima e limite padrão vêm de config/env global.
- [ ] Chamada registrada no log de tool calls (US-9.1) com os artigos retornados; `results: []` é registrado como `vazio`.

**Expected Result:** O agente recebe artigos citáveis ou uma lista vazia que o impede de responder de cabeça.

---

### US-4.5: Testar pergunta no painel
**As a** Síndico
**I want to** simular uma pergunta de morador contra o regimento/convenção publicados
**So that** eu confira se o agente vai encontrar o artigo certo

**Acceptance Criteria:**
- [ ] Card "Testar pergunta" na tela de Regimento com campo de pergunta e sugestões clicáveis.
- [ ] Executa exatamente a mesma busca da US-4.4 (mesmo condomínio, mesmo limiar e limite padrão).
- [ ] Exibe a pergunta e, para o melhor resultado, documento + referência do artigo, trecho do texto, `score` e latência em ms; sem resultado acima do limiar, exibe "Nenhum artigo encontrado".
- [ ] Não gera resposta por LLM e **não** grava registro no log de tool calls.
- [ ] Condomínio sem documento publicado exibe aviso e não executa a busca.

**Expected Result:** O síndico vê, antes do morador perguntar, qual artigo o agente receberia.

---

## 5. Comunicados

### US-5.1: Gerenciar comunicados ativos e inativos
**As a** Síndico
**I want to** criar, editar, ativar, desativar e excluir comunicados
**So that** o agente só informe os avisos que eu deixei ativos

**Acceptance Criteria:**
- [ ] Campos: título e texto (obrigatórios) e ativo (padrão `true`). Não há datas de validade nem status derivado de datas.
- [ ] Tela com abas **Ativos · N** e **Inativos · N**, cards em duas colunas (pill Ativo/Inativo, título, texto, ação Editar) e o aviso "Só comunicados ativos chegam ao agente".
- [ ] Ativar/desativar é imediato: comunicado desativado deixa de aparecer na US-5.2 na próxima chamada.
- [ ] Excluir remove o comunicado das duas abas e da API.
- [ ] Nenhum embedding é gerado para comunicados.
- [ ] Zelador recebe `403` nesta tela.

**Expected Result:** Síndico controla, por flag, quais avisos o agente enxerga.

---

### US-5.2: Listar comunicados ativos via agente
**As a** Agente de IA (n8n)
**I want to** receber todos os comunicados ativos do condomínio
**So that** eu responda "vai cair a água?" com base nos avisos em vigor

**Acceptance Criteria:**
- [ ] `GET /api/v1/notices` sem parâmetros de filtro retorna todos os comunicados ativos do condomínio do token, ordenados por `updated_at` decrescente.
- [ ] Cada item traz `id`, `title`, `text` e `updated_at`.
- [ ] Comunicados inativos, excluídos ou de outro condomínio nunca aparecem.
- [ ] Nenhum ativo → `200` com `notices: []`, registrado no log (US-9.1) como `vazio`.

**Expected Result:** O agente recebe a lista completa e atual de avisos ativos, sem busca semântica.

---

## 6. Chamados de manutenção

### US-6.1: Abrir chamado via agente
**As a** Agente de IA (n8n)
**I want to** abrir um chamado de manutenção em nome do morador, com fotos
**So that** o problema fique registrado com protocolo e não se perca

**Acceptance Criteria:**
- [ ] `POST /api/v1/tickets` em `multipart/form-data` com `phone` e `description` obrigatórios; `location`, `category` (slug), `priority` e `photos[]` opcionais.
- [ ] `priority` aceita `alta`, `media` ou `baixa` (padrão `media`); outro valor → `422 validation_error`.
- [ ] Telefone que não é morador ativo → `403 resident_not_found`.
- [ ] Categoria inexistente ou inativa → `422` com `code: "invalid_category"`.
- [ ] Até 5 fotos, cada uma até 10MB, tipos jpg, png, webp ou heic; fora disso → `422`.
- [ ] Protocolo **sequencial contínuo por condomínio** (1, 2, 3…, nunca reinicia), único mesmo com aberturas simultâneas; retornado como inteiro e exibido no painel como `#123`.
- [ ] Chamado nasce `aberto`, vinculado ao morador e à unidade, com origem `whatsapp` e entrada inicial no histórico.
- [ ] Resposta `201` com `protocol`, `status`, `priority` e `created_at`.

**Expected Result:** Chamado aparece no painel com fotos e o morador recebe o protocolo pelo agente.

---

### US-6.2: Consultar status do chamado via agente
**As a** Agente de IA (n8n)
**I want to** listar os chamados da unidade do morador e ver o detalhe de um protocolo
**So that** eu responda perguntas como "e aquela infiltração?"

**Acceptance Criteria:**
- [ ] `GET /api/v1/tickets?phone=&status=` lista chamados da **unidade** do morador, mais recentes primeiro, máximo 10 itens; `status` aceita `open` (aberto + em_andamento), `closed` (resolvido + cancelado) ou `all` (padrão).
- [ ] Cada item da lista traz protocolo, descrição, categoria, prioridade, status, `created_at` e `updated_at`.
- [ ] `GET /api/v1/tickets/{protocol}?phone=` retorna detalhe com histórico (status, data, comentário) em ordem cronológica.
- [ ] Protocolo inexistente ou de outra unidade → `404` com `code: "ticket_not_found"`.
- [ ] Telefone que não é morador ativo → `403 resident_not_found`.

**Expected Result:** O agente consegue desambiguar e informar o status atual de qualquer chamado da unidade.

---

### US-6.3: Visualizar chamados no painel
**As a** Síndico ou Zelador
**I want to** listar e abrir os chamados do condomínio
**So that** eu acompanhe e priorize a manutenção

**Acceptance Criteria:**
- [ ] Lista com todos os chamados do condomínio (zelador vê todos, sem atribuição), paginada, ordenada por abertura decrescente.
- [ ] Abas com contagem: **Todos**, **Abertos** (`aberto`), **Em andamento** (`em_andamento`), **Concluídos** (`resolvido` + `cancelado`).
- [ ] Colunas: protocolo `#N`, descrição + origem ("WhatsApp · agente" / "painel"), unidade (ou "Área comum" sem unidade), categoria, prioridade (ponto vermelho alta, laranja média, cinza baixa), pill de status e data de abertura.
- [ ] Filtros adicionais por categoria, prioridade, bloco e busca por protocolo/descrição.
- [ ] Clicar abre drawer lateral com protocolo, status, título, unidade · categoria · origem, descrição, fotos (visualização ampliada) e linha do tempo (mudanças de status e avisos ao morador).

**Expected Result:** Síndico e zelador enxergam a fila de manutenção completa com todas as evidências.

---

### US-6.4: Atualizar status do chamado
**As a** Síndico ou Zelador
**I want to** mudar o status de um chamado com comentário
**So that** o andamento fique registrado e o morador seja avisado

**Acceptance Criteria:**
- [ ] Transições permitidas: `aberto` → `em_andamento` | `resolvido` | `cancelado`; `em_andamento` → `resolvido` | `cancelado`.
- [ ] `resolvido` e `cancelado` são finais; tentativa de transição a partir deles é bloqueada com erro.
- [ ] Comentário é opcional em `em_andamento` e obrigatório em `resolvido` e `cancelado`.
- [ ] Cada mudança grava entrada no histórico (status, comentário, usuário, data).
- [ ] Cada mudança em chamado com morador vinculado dispara o evento `ticket.status_changed` (US-8.3).
- [ ] No drawer, o botão principal segue o status: `aberto` → "Iniciar atendimento" (`em_andamento`), `em_andamento` → "Marcar concluído" (`resolvido`); cancelar é ação secundária. Chamados finais não exibem botão de transição (sem "Reabrir").

**Expected Result:** Status atualizado aparece no painel, na API (US-6.2) e é notificado ao morador.

---

### US-6.5: Abrir chamado pelo painel
**As a** Síndico ou Zelador
**I want to** abrir um chamado diretamente no painel
**So that** problemas identificados pela equipe também sejam rastreados

**Acceptance Criteria:**
- [ ] Botão "+ Novo chamado" na tela de Chamados.
- [ ] Campos: descrição (obrigatória), local, categoria ativa, prioridade (padrão `media`), unidade e morador opcionais, fotos (mesmos limites da US-6.1).
- [ ] Morador informado deve pertencer à unidade informada.
- [ ] Usa a mesma sequência de protocolo da US-6.1; origem `painel`.
- [ ] Chamado sem morador vinculado não dispara webhooks.

**Expected Result:** Chamado aberto pelo painel entra na mesma fila e ciclo dos chamados do WhatsApp.

---

### US-6.6: Alterar prioridade do chamado
**As a** Síndico ou Zelador
**I want to** mudar a prioridade de um chamado
**So that** a fila reflita a urgência real

**Acceptance Criteria:**
- [ ] Prioridade editável no drawer entre `alta`, `media` e `baixa`, em chamados não finais.
- [ ] Chamado `resolvido` ou `cancelado` não permite alterar prioridade.
- [ ] A alteração não gera entrada de status no histórico nem webhook.
- [ ] Chamados `aberto`/`em_andamento` com prioridade `alta` contam como "urgentes" na Visão geral (US-9.2).

**Expected Result:** Prioridade atualizada aparece na lista, no drawer, na API (US-6.2) e nas métricas.

---

### US-6.7: Avisar morador sobre o chamado
**As a** Síndico ou Zelador
**I want to** enviar uma mensagem livre ao morador a partir do chamado
**So that** eu informe andamentos que não são mudança de status (ex.: visita agendada)

**Acceptance Criteria:**
- [ ] Botão "Avisar morador" no drawer, habilitado só em chamados com morador vinculado.
- [ ] Mensagem obrigatória, 1 a 1000 caracteres; vazia ou maior → erro de validação.
- [ ] Ao enviar: entrada "Aviso ao morador" na linha do tempo com texto, usuário e data, e disparo do evento `ticket.resident_notified` (US-8.3) com protocolo e mensagem.
- [ ] Permitido em qualquer status, inclusive finais.
- [ ] Condomínio sem webhook configurado: aviso fica registrado na linha do tempo e o painel informa que não houve envio.

**Expected Result:** O morador recebe o aviso pelo n8n e o chamado guarda o registro do que foi comunicado.

---

## 7. Áreas comuns e reservas

### US-7.1: Cadastrar área comum com faixas e regras
**As a** Síndico
**I want to** cadastrar áreas comuns com faixas de horário e regras de antecedência
**So that** o agente só ofereça reservas válidas

**Acceptance Criteria:**
- [ ] Campos: nome (único no condomínio), descrição, ativa; antecedência mínima em horas (padrão 24), antecedência máxima em dias (padrão 60), prazo de cancelamento pelo morador em horas antes do início (padrão 24) — todos editáveis.
- [ ] Faixas de horário: início < fim, sem sobreposição entre faixas da mesma área; pelo menos uma faixa para a área ficar ativa.
- [ ] Faixa com reserva futura `confirmada` não pode ser excluída nem ter horário alterado.
- [ ] Área inativa não aparece na API e não aceita reservas; reservas existentes permanecem.

**Expected Result:** Áreas cadastradas com agenda e regras prontas para as tools de reserva.

---

### US-7.2: Consultar áreas e disponibilidade via agente
**As a** Agente de IA (n8n)
**I want to** listar áreas ativas e ver a disponibilidade das faixas numa data
**So that** eu ofereça ao morador apenas opções possíveis

**Acceptance Criteria:**
- [ ] `GET /api/v1/areas` lista áreas ativas com id, nome, descrição, faixas e regras de antecedência.
- [ ] `GET /api/v1/areas/{id}/availability?date=YYYY-MM-DD` retorna cada faixa com `available` booleano.
- [ ] Faixa é `available: false` se já tem reserva `confirmada` na data **ou** se está fora da janela de antecedência (mínima/máxima).
- [ ] Área inexistente, inativa ou de outro condomínio → `404` com `code: "area_not_found"`; data inválida → `422`.

**Expected Result:** O agente mostra ao morador faixas livres e reserváveis naquela data.

---

### US-7.3: Reservar área via agente
**As a** Agente de IA (n8n)
**I want to** reservar uma faixa de uma área para o morador
**So that** a reserva só seja confirmada quando o sistema aceitar

**Acceptance Criteria:**
- [ ] `POST /api/v1/reservations` com `phone`, `area_id`, `slot_id` e `date` obrigatórios.
- [ ] Telefone que não é morador ativo → `403 resident_not_found`.
- [ ] Área inativa/inexistente ou faixa que não pertence à área → `422 area_unavailable`.
- [ ] Início da faixa a menos de `antecedência mínima` horas de agora, ou data além de `antecedência máxima` dias → `422 advance_notice_violation`, com os limites da área na resposta.
- [ ] Já existe reserva `confirmada` na mesma área + data + faixa → `422 slot_unavailable`.
- [ ] Duas requisições simultâneas para a mesma faixa/data resultam em exatamente uma `201` e uma `422 slot_unavailable` (garantia por constraint/lock no banco).
- [ ] Passou em todas as regras → reserva criada como `confirmada`, origem `whatsapp`, vinculada a morador e unidade, resposta `201` com id, status, área, data, início e fim.
- [ ] Qualquer recusa não cria reserva; fica apenas no log de tool calls como `recusa` com o código (US-9.1).

**Expected Result:** Reservas nascem confirmadas apenas quando válidas; recusas trazem código que impede o agente de confirmar.

---

### US-7.4: Listar reservas do morador via agente
**As a** Agente de IA (n8n)
**I want to** listar as reservas futuras da unidade do morador
**So that** eu responda "o que eu tenho reservado?" e permita cancelar

**Acceptance Criteria:**
- [ ] `GET /api/v1/reservations?phone=` retorna reservas `confirmada` da unidade do morador com data ≥ hoje, ordenadas por data e início.
- [ ] Cada item traz id, área, data, início, fim, morador que reservou e se ainda é cancelável pelo morador (prazo da US-7.5).
- [ ] Telefone que não é morador ativo → `403 resident_not_found`.

**Expected Result:** O agente conhece as reservas futuras da unidade.

---

### US-7.5: Cancelar reserva pelo morador via agente
**As a** Agente de IA (n8n)
**I want to** cancelar uma reserva a pedido do morador
**So that** a faixa seja liberada para outros moradores

**Acceptance Criteria:**
- [ ] `DELETE /api/v1/reservations/{id}?phone=` cancela reserva `confirmada` da unidade do morador.
- [ ] Reserva inexistente, de outra unidade ou já cancelada → `404` com `code: "reservation_not_found"`.
- [ ] Faltando menos que o prazo de cancelamento da área (horas antes do início) → `422` com `code: "cancellation_deadline_passed"` e o prazo na resposta.
- [ ] Cancelamento muda status para `cancelada` (registro mantido, origem `morador`) e a faixa volta a `available: true`.
- [ ] Cancelamento pelo morador não dispara webhook.

**Expected Result:** Reserva cancelada dentro do prazo libera a faixa; fora do prazo a API recusa e o agente não confirma.

---

### US-7.6: Ver agenda de reservas no painel
**As a** Síndico
**I want to** ver as reservas por área e período
**So that** eu acompanhe o uso das áreas comuns

**Acceptance Criteria:**
- [ ] Grade semanal (segunda a domingo): linhas = áreas ativas (nome), colunas = dias; semana atual por padrão, com dia de hoje destacado e botões ‹ › para semana anterior/seguinte.
- [ ] Cada célula lista as reservas `confirmada` do dia naquela área com faixa (ex.: "19h–23h") e sobrenome · unidade; origem `painel` e `whatsapp` diferenciadas por cor.
- [ ] Clicar numa reserva mostra data, faixa, área, unidade, morador, origem e ação de cancelar (US-7.7).
- [ ] Card lateral "Pedidos pelo WhatsApp": últimas 10 reservas de origem `whatsapp` (morador · unidade, área · data · faixa, pill Confirmada/Cancelada).
- [ ] Card lateral "Regras aplicadas pela tool": uma linha por área ativa com faixas, antecedência mínima/máxima e prazo de cancelamento.
- [ ] Zelador recebe `403` nesta tela.

**Expected Result:** Síndico enxerga a ocupação semanal das áreas e as reservas feitas pelo agente.

---

### US-7.7: Cancelar reserva pelo painel
**As a** Síndico
**I want to** cancelar uma reserva com motivo
**So that** eu resolva imprevistos (manutenção, uso indevido) e o morador seja avisado

**Acceptance Criteria:**
- [ ] Apenas reservas `confirmada` com data ≥ hoje podem ser canceladas; motivo obrigatório.
- [ ] Síndico não está sujeito ao prazo de cancelamento da área.
- [ ] Status muda para `cancelada` com origem `sindico`, motivo e usuário registrados.
- [ ] Dispara o evento `reservation.cancelled` (US-8.3) com área, data, faixa e motivo.

**Expected Result:** Reserva cancelada pelo síndico libera a faixa e o morador é notificado pelo n8n.

---

### US-7.8: Criar reserva manual pelo painel
**As a** Síndico
**I want to** reservar uma faixa em nome de uma unidade
**So that** eu registre pedidos feitos fora do WhatsApp e resolva exceções

**Acceptance Criteria:**
- [ ] Botão "+ Reserva manual" na tela de Reservas com campos obrigatórios: área, data, faixa, unidade e morador (ativo e da unidade escolhida).
- [ ] Valida área ativa e faixa pertencente à área; senão erro de validação.
- [ ] **Não** valida antecedência mínima nem máxima (data passada é recusada).
- [ ] Já existe reserva `confirmada` na mesma área + data + faixa → erro "Faixa já reservada nesta data"; garantia pela mesma constraint da US-7.3.
- [ ] Reserva nasce `confirmada` com origem `painel` e usuário criador registrado; não dispara webhook.
- [ ] A reserva aparece na grade da US-7.6 e na listagem do morador (US-7.4), que pode cancelá-la pelas regras da US-7.5.

**Expected Result:** Reserva manual ocupa a faixa como qualquer reserva confirmada.

---

## 8. Escalonamentos e notificações

### US-8.1: Escalar atendimento para humano via agente
**As a** Agente de IA (n8n)
**I want to** criar um escalonamento com motivo e resumo da conversa
**So that** síndico ou zelador assumam o que exige humano

**Acceptance Criteria:**
- [ ] `POST /api/v1/escalations` com `phone`, `reason` e `summary` obrigatórios; `ticket_protocol` opcional.
- [ ] `reason` aceita `pediu_humano`, `tool_recusou` ou `sem_regra`; outro valor → `422 validation_error`.
- [ ] Telefone que não é morador ativo → `403 resident_not_found`.
- [ ] `ticket_protocol` inexistente ou de outra unidade → `422` com `code: "ticket_not_found"`.
- [ ] Escalonamento nasce `pendente`; resposta `201` com id e status.

**Expected Result:** Escalonamento entra na fila do painel com contexto suficiente para o humano agir.

---

### US-8.2: Atender fila de escalonamentos
**As a** Síndico ou Zelador
**I want to** ver os escalonamentos pendentes e respondê-los
**So that** o morador tenha retorno humano quando o agente não resolve

**Acceptance Criteria:**
- [ ] Fila mostra escalonamentos `pendente` e `em_atendimento` do condomínio, mais antigos primeiro, em cards com morador · unidade, pill do motivo ("Pediu humano", "Tool recusou", "Sem regra"), "esperando <tempo>", resumo e chamado vinculado (link).
- [ ] Texto de apoio no topo: "O agente entregou estas conversas porque a regra não cobria, a tool recusou ou o morador pediu um humano."
- [ ] Card `pendente` exibe "Assumir" (US-8.4); card `em_atendimento` exibe "Com você" para o responsável ou "Com <nome>" para os demais.
- [ ] "Responder" só aparece para o responsável de escalonamento `em_atendimento`; resposta obrigatória; ao salvar vira `resolvido` com resposta, usuário e data gravados.
- [ ] Escalonamento `pendente` não pode ser respondido sem antes ser assumido; `resolvido` não pode ser respondido de novo.
- [ ] Filtro para ver também `resolvido`.
- [ ] Menu "Escalonamentos" na barra lateral mostra badge com a quantidade de `pendente`.
- [ ] Resolver dispara o evento `escalation.answered` (US-8.3) com o texto da resposta.

**Expected Result:** Toda escalada tem dono humano, resposta registrada e aviso enviado ao morador.

---

### US-8.3: Enviar webhooks assinados ao n8n
**As a** Morador
**I want to** ser avisado no WhatsApp quando a equipe muda algo que me afeta
**So that** eu não precise perguntar para saber o andamento

**Acceptance Criteria:**
- [ ] Eventos do MVP: `ticket.status_changed` (US-6.4), `ticket.resident_notified` (US-6.7), `reservation.cancelled` (US-7.7), `escalation.answered` (US-8.2).
- [ ] Envio via job em fila: `POST` JSON para a URL do condomínio com `event`, `condominium_id`, `resident_phone`, `data` e `occurred_at`.
- [ ] Header `X-Signature: sha256=<hmac>` = HMAC-SHA256 do corpo bruto com o segredo do condomínio.
- [ ] Resposta não-2xx ou timeout → nova tentativa com backoff, até **3 tentativas** no total; após a 3ª falha a entrega é marcada `falhou` com o último código HTTP/erro e aparece na US-8.5.
- [ ] Falha do webhook nunca desfaz nem bloqueia a ação do painel que o originou.
- [ ] Condomínio sem URL configurada → nenhum job é enfileirado.

**Expected Result:** O n8n recebe eventos autênticos e verificáveis e avisa o morador pelo WhatsApp.

---

### US-8.4: Assumir escalonamento
**As a** Síndico ou Zelador
**I want to** assumir um escalonamento antes de responder
**So that** a equipe saiba quem está cuidando de cada caso

**Acceptance Criteria:**
- [ ] "Assumir" em escalonamento `pendente` muda para `em_atendimento`, com o usuário como responsável e data de início do atendimento.
- [ ] Síndico pode assumir escalonamento `em_atendimento` de outro usuário, trocando o responsável; zelador não (ação oculta e `403` se forçada).
- [ ] Escalonamento `resolvido` não pode ser assumido.
- [ ] Assumir não dispara webhook.
- [ ] Assumir e trocar responsável ficam registrados (usuário e data).

**Expected Result:** Todo escalonamento em atendimento tem um responsável visível na fila.

---

### US-8.5: Ver falhas de webhook
**As a** Síndico ou Super admin
**I want to** ver os avisos ao morador que não chegaram ao n8n
**So that** eu saiba quem não foi notificado e avise por outro meio

**Acceptance Criteria:**
- [ ] Lista "Falhas de webhook" em Configurações com as entregas marcadas `falhou` do condomínio, mais recentes primeiro: evento, morador (telefone), referência (protocolo do chamado, reserva ou escalonamento), tentativas (3), último código HTTP ou erro e data.
- [ ] Entregas bem-sucedidas ou ainda em tentativa não aparecem na lista.
- [ ] Sem reenvio manual no MVP: a lista é somente leitura.
- [ ] Zelador recebe `403`; super admin vê a lista do condomínio selecionado.

**Expected Result:** Toda notificação que falhou após 3 tentativas fica visível para quem gerencia o condomínio.

---

## 9. Log de tool calls e visão geral

### US-9.1: Registrar tool calls do agente
**As a** Síndico
**I want to** que toda chamada do agente à API fique registrada
**So that** o painel mostre o que o agente fez e com que resultado

**Acceptance Criteria:**
- [ ] Middleware em todos os endpoints `api/v1` autenticados grava um registro por request com: condomínio, tool/endpoint, telefone e morador (quando identificado), resultado, código de erro (quando houver), entidades envolvidas e latência em ms.
- [ ] Resultado: `sucesso` (2xx com conteúdo), `vazio` (2xx sem itens — `results: []`, `notices: []` ou listagem vazia) ou `recusa` (401, 403, 404 ou 422, com o `code` da resposta).
- [ ] Entidades envolvidas: artigos retornados (US-4.4), chamado criado/consultado (US-6.1, US-6.2), reserva criada/cancelada (US-7.3, US-7.5) e escalonamento criado (US-8.1).
- [ ] Request sem token válido (401) não é registrado, pois não há condomínio associado.
- [ ] Falha ao gravar o log não altera status nem corpo da resposta ao n8n.
- [ ] "Testar pergunta" (US-4.5) e ações do painel não geram registro.
- [ ] Registros são mantidos indefinidamente (sem expurgo).

**Expected Result:** Cada tool call do agente vira um registro consultável pelo painel.

---

### US-9.2: Ver indicadores da visão geral
**As a** Síndico ou Zelador
**I want to** ver os indicadores do dia na tela inicial
**So that** eu saiba de relance quanto o agente resolveu e o que espera por humano

**Acceptance Criteria:**
- [ ] Visão geral é a tela inicial do painel; super admin vê a do condomínio selecionado.
- [ ] Card **Atendimentos hoje**: moradores distintos com ≥ 1 tool call no dia corrente (fuso `America/Sao_Paulo`), com variação percentual vs. ontem (verde se ≥ 0, vermelho se < 0; "—" se ontem = 0).
- [ ] Card **Resolvidas pelo agente**: % dos atendidos hoje sem escalonamento criado hoje, com "N de M sem humano"; sem atendimentos exibe "—".
- [ ] Card **Chamados abertos**: total `aberto` + `em_andamento`, com "N urgentes" (prioridade `alta`) em vermelho.
- [ ] Card **Aguardando síndico**: total `pendente` + `em_atendimento`, com "tempo médio X min" calculado sobre a espera desde a criação.
- [ ] Todos os números respeitam o condomínio do usuário (US-1.2).

**Expected Result:** Os quatro cards refletem a operação real do dia.

---

### US-9.3: Acompanhar atividade do agente e fila humana
**As a** Síndico ou Zelador
**I want to** ver as últimas ações do agente, quem espera humano e a taxa de resolução por tool
**So that** eu identifique rápido onde o agente está falhando

**Acceptance Criteria:**
- [ ] Card **Atividade do agente**: 6 tool calls mais recentes com hora `HH:MM`, "primeiro nome · unidade", descrição da ação e pill (ex.: "Regimento · Art. 14", "Comunicados", "Chamado #123", "Reserva confirmada", "Escalado", "Recusada"); link "Ver conversas" não existe no MVP.
- [ ] Tool calls sem morador identificado exibem o telefone no lugar do nome.
- [ ] Card **Aguardando humano**: até 3 escalonamentos não resolvidos mais antigos com nome · unidade, motivo e tempo de espera; link "Ver fila" leva à tela de Escalonamentos.
- [ ] Card **Resolução por tool · 7 dias**: uma barra por tool (Regimento/convenção, Comunicados, Abrir chamado, Status de chamado, Reservar área) com % de registros `sucesso` sobre o total da tool nos últimos 7 dias; tool sem chamadas exibe "—".

**Expected Result:** A visão geral mostra atividade recente, fila humana e qualidade por tool.

---

### US-9.4: Ver contadores derivados do log
**As a** Síndico ou Super admin
**I want to** ver quanto cada artigo, morador e endpoint é usado
**So that** eu saiba quais regras e integrações o agente mais usa

**Acceptance Criteria:**
- [ ] Tela de Regimento: cada artigo mostra "citado N×" = quantidade de registros em que o artigo foi retornado pela busca da US-4.4 (histórico total).
- [ ] Tela de Regimento: documento publicado mostra pill "Indexado · N trechos" (artigos com embedding).
- [ ] Tela de Moradores: coluna de interações = total histórico de tool calls do morador.
- [ ] Card Integração (somente super admin, US-1.5): cada endpoint mostra método, rota, nome da tool, descrição e chamadas dos últimos 7 dias.
- [ ] Contadores respeitam o condomínio (US-1.2).

**Expected Result:** Uso real de artigos, moradores e endpoints fica visível no painel.

---

## Appendix: User Story Status

| ID | Story | Priority | Status |
|----|-------|----------|--------|
| US-1.1 | Login no painel por papel | High | Pending |
| US-1.2 | Isolamento entre condomínios | High | Pending |
| US-1.3 | Cadastrar condomínio | High | Pending |
| US-1.4 | Cadastrar síndicos e zeladores | High | Pending |
| US-1.5 | Gerar e revogar token de API do condomínio | High | Pending |
| US-1.6 | Configurar webhook do n8n | High | Pending |
| US-2.1 | Cadastrar blocos e unidades | High | Pending |
| US-2.2 | Cadastrar moradores | High | Pending |
| US-3.1 | Autenticar chamadas do n8n | High | Pending |
| US-3.2 | Verificar se telefone é morador | High | Pending |
| US-4.1 | Enviar PDF de regimento ou convenção | High | Pending |
| US-4.2 | Revisar artigos antes de publicar | High | Pending |
| US-4.3 | Publicar documento e indexar artigos | High | Pending |
| US-4.4 | Buscar regra com citação do artigo | High | Pending |
| US-5.1 | Gerenciar comunicados ativos e inativos | High | Pending |
| US-5.2 | Listar comunicados ativos via agente | High | Pending |
| US-6.1 | Abrir chamado via agente | High | Pending |
| US-6.2 | Consultar status do chamado via agente | High | Pending |
| US-6.3 | Visualizar chamados no painel | High | Pending |
| US-6.4 | Atualizar status do chamado | High | Pending |
| US-7.1 | Cadastrar área comum com faixas e regras | High | Pending |
| US-7.2 | Consultar áreas e disponibilidade via agente | High | Pending |
| US-7.3 | Reservar área via agente | High | Pending |
| US-7.4 | Listar reservas do morador via agente | High | Pending |
| US-7.5 | Cancelar reserva pelo morador via agente | High | Pending |
| US-8.1 | Escalar atendimento para humano via agente | High | Pending |
| US-8.2 | Atender fila de escalonamentos | High | Pending |
| US-8.3 | Enviar webhooks assinados ao n8n | High | Pending |
| US-2.3 | Gerenciar categorias de chamado | Medium | Pending |
| US-6.5 | Abrir chamado pelo painel | Medium | Pending |
| US-7.6 | Ver agenda de reservas no painel | Medium | Pending |
| US-7.7 | Cancelar reserva pelo painel | Medium | Pending |
| US-6.6 | Alterar prioridade do chamado | High | Pending |
| US-8.4 | Assumir escalonamento | High | Pending |
| US-9.1 | Registrar tool calls do agente | High | Pending |
| US-2.4 | Editar dados informativos do condomínio | Medium | Pending |
| US-4.5 | Testar pergunta no painel | Medium | Pending |
| US-6.7 | Avisar morador sobre o chamado | Medium | Pending |
| US-7.8 | Criar reserva manual pelo painel | Medium | Pending |
| US-9.2 | Ver indicadores da visão geral | Medium | Pending |
| US-9.3 | Acompanhar atividade do agente e fila humana | Medium | Pending |
| US-9.4 | Ver contadores derivados do log | Medium | Pending |
| US-8.5 | Ver falhas de webhook | Medium | Pending |
