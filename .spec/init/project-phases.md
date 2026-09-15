# Síndico Conversacional — Project Phases

<!-- inputs: project-description.md@sha256:4ed5866b9b9c user-stories.md@sha256:269a63afa9a9 database-schema.md@sha256:eee32e68ff03 -->

## Overview

O plano constrói o backoffice **fundação primeiro**: banco completo com lookups (Phase 1), models com todos os relacionamentos, tenancy e factories (Phase 2) e o design system do canvas com o layout do painel (Phase 3). Em seguida vêm **autenticação, papéis e tenancy** (Phase 4) e os **cadastros** que as tools do agente precisam (Phase 5). A **Phase 6** entrega a base de integração com o n8n (autenticação por token, contrato de erros, log de tool calls e webhooks), que todas as áreas seguintes usam. As áreas de negócio seguem a ordem de dependência técnica: **Comunicados** (tool mais simples), **Chamados**, **Reservas**, **Escalonamentos** (referenciam chamados), **Regimento/RAG** (dependências externas: PDF e embeddings) e, por último, a **Visão geral**, que lê dados de todas as áreas.

São **12 fases**. Todas as stories (High e Medium) estão no primeiro release: o **corte do MVP é a Phase 12**. Cada fase é entregue a um agente por número (`Phase 8`, `Phase 8.3`) e carrega suas próprias notas, porque o agente recebe apenas o conteúdo da sua fase.

**Conventions:**
- `[ ]` pending · `[x]` done in the codebase.
- Phases and sub-phases are numbered (`Phase 1`, `Phase 5.3`) for reference by AI agents.
- Business-logic tasks list the **feature tests** to generate; frontend-only tasks list validatable **acceptance criteria** and a **Design ref**.
- Todos os comandos rodam via Sail (`./vendor/bin/sail artisan|composer|npm|pest`). Lógica de domínio em `app/Services/<Área>`, utilitários em `app/Support`, exceções da API em `app/Exceptions/Api`.
- Design: `.spec/init/design/Sindico Conversacional - Backoffice.dc.html`, com seções marcadas por comentários (`<!-- Sidebar -->`, `<!-- DASHBOARD -->`, `<!-- CHAMADOS -->`, `<!-- RESERVAS -->`, `<!-- COMUNICADOS -->`, `<!-- REGIMENTO -->`, `<!-- ESCALONAMENTOS -->`, `<!-- MORADORES -->`, `<!-- CONFIG -->`). A seção `<!-- CONVERSAS -->` está fora do MVP.

---

## Phase 1: Fundação — ambiente e banco de dados

**Goal:** banco completo do schema com lookups semeadas e ambiente de dev/CI em Postgres + pgvector. · **Depends on:** none · **Covers:** todas as tabelas de `database-schema.md`, Tech Stack

**Notas para o agente:**
- Rodar tudo via `./vendor/bin/sail`. Ao final: `./vendor/bin/sail bin pint --dirty --format agent` e `./vendor/bin/sail artisan test --compact`.
- Fonte de verdade das colunas, índices e seeds: `.spec/init/database-schema.md`. Não criar colunas enum; todo categórico é FK para lookup.
- FKs sem `cascade` (exclusão bloqueada pela aplicação), exceto onde indicado.

### Phase 1.1: Ambiente e configuração

- [x] **Task:** Ambiente Sail com PHP 8.5 e PostgreSQL 18 + pgvector
  - **Acceptance criteria:**
    - `compose.yaml` com serviço `laravel.test` (runtime 8.5) e `pgsql` na imagem `pgvector/pgvector:pg18`.
    - `.env` com `DB_CONNECTION=pgsql`, `DB_HOST=pgsql`; extensão `vector` 0.8.2 disponível no servidor.
    - Database `testing` criado pelo script do Sail e usado pelo `phpunit.xml`.
  - **Traces:** Tech Stack (Ambiente dev, Banco de dados, Banco de testes)

- [ ] **Task:** Alinhar `.env.example`, locale pt-BR e config do domínio
  - **Acceptance criteria:**
    - `.env.example` com os mesmos valores de banco do Sail (`pgsql`, `sail`, `password`, `laravel`) e `OPENAI_API_KEY=` vazio.
    - `APP_LOCALE=pt_BR`, `APP_FALLBACK_LOCALE=pt_BR`, `APP_FAKER_LOCALE=pt_BR`; `lang/pt_BR` publicado (`artisan lang:publish`) com `validation.php`, `auth.php` e `pagination.php` traduzidos.
    - `config/condo.php` com: `timezone` = `America/Sao_Paulo`; `rag.min_similarity` = 0.5 (env `RAG_MIN_SIMILARITY`), `rag.default_limit` = 5, `rag.max_limit` = 10, `rag.embedding_dimensions` = 1536; `tickets.max_photos` = 5, `tickets.max_photo_kb` = 10240, `tickets.photo_mimes` = jpg,jpeg,png,webp,heic, `tickets.notice_max_length` = 1000; `webhooks.tries` = 3, `webhooks.backoff` = [10, 60], `webhooks.timeout` = 10; `dashboard.activity_limit` = 6, `dashboard.waiting_limit` = 3; `reservations.whatsapp_card_limit` = 10.
    - `app.timezone` permanece `UTC` (timestamps gravados em UTC; "hoje" e exibição usam `condo.timezone`).
  - **Traces:** Tech Stack; US-3.1; US-4.4; US-6.1; US-6.7; US-8.3; US-9.2; US-9.3; US-7.6

- [ ] **Task:** Instalar Sanctum e preparar `routes/api.php`
  - **Acceptance criteria:**
    - `./vendor/bin/sail artisan install:api` executado: `laravel/sanctum` instalado, `routes/api.php` registrado em `bootstrap/app.php`, migration de `personal_access_tokens` criada.
    - Rota padrão `/user` removida; `routes/api.php` contém apenas o grupo `prefix('v1')->name('api.v1.')` vazio.
  - **Traces:** personal_access_tokens; US-3.1; Tech Stack (API para o n8n)

- [ ] **Task:** CI com PostgreSQL + pgvector
  - **Acceptance criteria:**
    - `.github/workflows/tests.yml` sobe serviço `pgvector/pgvector:pg18` com healthcheck `pg_isready` e banco `testing`.
    - Job exporta `DB_CONNECTION=pgsql`, `DB_HOST=localhost`, `DB_PORT=5432`, `DB_DATABASE=testing`, `DB_USERNAME`/`DB_PASSWORD` do serviço.
    - `composer setup` não depende de SQLite; `composer ci:check` passa com a suíte atual.
  - **Traces:** Tech Stack (CI, Banco de testes)

### Phase 1.2: Migrations

- [ ] **Task:** Migration das 16 lookup tables
  - **Acceptance criteria:**
    - Cria `roles`, `resident_profiles`, `ticket_priorities`, `reservation_origins`, `escalation_reasons`, `tool_call_results`, `agent_tools`, `document_types`, `document_statuses`, `ticket_statuses`, `ticket_origins`, `reservation_statuses`, `reservation_cancellation_origins`, `escalation_statuses`, `webhook_events`, `webhook_delivery_statuses`.
    - Todas com `id`, `name`, `slug` único e timestamps; `ticket_statuses.is_final` boolean default false; `agent_tools` com `http_method` varchar(10), `route` e `description` not null.
    - Roda antes de qualquer tabela de domínio.
  - **Traces:** roles, resident_profiles, ticket_priorities, reservation_origins, escalation_reasons, tool_call_results, agent_tools, document_types, document_statuses, ticket_statuses, ticket_origins, reservation_statuses, reservation_cancellation_origins, escalation_statuses, webhook_events, webhook_delivery_statuses

- [ ] **Task:** Migrations de `condominiums` e extensão de `users`
  - **Acceptance criteria:**
    - `condominiums`: `name` único, `city`, `whatsapp_number` varchar(20), `caretaker_name`, `caretaker_phone` varchar(20), `quiet_hours_start`/`quiet_hours_end` time, `webhook_url`, `webhook_secret` text (todos nullable), `last_ticket_protocol` integer default 0, timestamps.
    - Nova migration altera `users`: `role_id` FK `roles` not null, `condominium_id` FK `condominiums` nullable, `is_active` boolean default true, índice `(condominium_id, role_id)`.
  - **Traces:** condominiums, users; US-1.3; US-1.4; US-2.4

- [ ] **Task:** Migrations de estrutura: `blocks`, `units`, `residents`
  - **Acceptance criteria:**
    - `blocks`: unique `(condominium_id, name)`.
    - `units`: `block_id` nullable; índices únicos **parciais** via `DB::statement`: `(block_id, number) WHERE block_id IS NOT NULL` e `(condominium_id, number) WHERE block_id IS NULL`.
    - `residents`: `unit_id`, `resident_profile_id` not null, `name`, `phone` varchar(20) not null, `is_active` default true, unique `(condominium_id, phone)`, índice `unit_id`.
  - **Traces:** blocks, units, residents; US-2.1; US-2.2

- [ ] **Task:** Migrations de conhecimento: `rule_documents`, `rule_articles`, `notices`
  - **Acceptance criteria:**
    - Primeira instrução: `Schema::ensureVectorExtensionExists()`.
    - `rule_documents`: `document_type_id`, `document_status_id`, `title`, `file_path`, `processing_error` text null, `uploaded_by_user_id` not null, `published_by_user_id` null, `published_at` null; índice `(condominium_id, document_type_id, document_status_id)`.
    - `rule_articles`: `rule_document_id`, `reference`, `title` null, `body` text, `position` integer, `$table->vector('embedding', dimensions: 1536)->nullable()->index()` (HNSW), `embedded_at` null; índice `(rule_document_id, position)`.
    - `notices`: `title`, `body` text, `is_active` default true, `created_by_user_id`, timestamps, `softDeletes`; índice `(condominium_id, is_active, updated_at)`; **sem** coluna de embedding ou datas de validade.
  - **Traces:** rule_documents, rule_articles, notices; US-4.1; US-4.3; US-5.1

- [ ] **Task:** Migrations de integração: `webhook_deliveries` e `agent_tool_calls`
  - **Acceptance criteria:**
    - `webhook_deliveries`: `webhook_event_id`, `webhook_delivery_status_id`, `subject_type`/`subject_id` not null (morph, indexado), `resident_phone` varchar(20) null, `url`, `payload` jsonb, `attempts` integer default 0, `last_response_code` integer null, `last_error` text null, `delivered_at` null, `failed_at` null; índice `(condominium_id, webhook_delivery_status_id, created_at)`.
    - `agent_tool_calls`: `agent_tool_id`, `personal_access_token_id` FK **`nullOnDelete`** (revogar token apaga a linha do Sanctum), `resident_id` null, `phone` varchar(20) null, `tool_call_result_id`, `http_status` smallint, `error_code` null, `entities` jsonb null, `latency_ms` integer, timestamps.
    - Índices de `agent_tool_calls`: `(condominium_id, created_at)`, `(condominium_id, agent_tool_id, created_at)`, `(resident_id, created_at)` e GIN `jsonb_path_ops` em `entities` via `DB::statement`.
    - Roda depois de `personal_access_tokens` e `residents` e antes das tabelas de chamados.
  - **Traces:** webhook_deliveries, agent_tool_calls; US-8.3; US-8.5; US-9.1

- [ ] **Task:** Migrations de chamados: `ticket_categories`, `tickets`, `ticket_photos`, `ticket_status_changes`, `ticket_resident_notices`
  - **Acceptance criteria:**
    - `ticket_categories`: `name`, `slug`, `is_active`; unique `(condominium_id, slug)` e `(condominium_id, name)`.
    - `tickets`: `protocol_number` integer, `ticket_status_id`, `ticket_priority_id`, `ticket_origin_id` not null; `ticket_category_id`, `unit_id`, `resident_id`, `opened_by_user_id` null; `description` text, `location` null; unique `(condominium_id, protocol_number)`; índices `(condominium_id, ticket_status_id, ticket_priority_id)` e `(unit_id, created_at)`.
    - `ticket_photos`: `ticket_id`, `file_path`, `mime_type`, `size_bytes`.
    - `ticket_status_changes`: `from_ticket_status_id` null, `to_ticket_status_id`, `comment` null, `user_id` null; índice `(ticket_id, created_at)`.
    - `ticket_resident_notices`: `ticket_id`, `user_id`, `message` text, `webhook_delivery_id` null; índice `(ticket_id, created_at)`.
  - **Traces:** ticket_categories, tickets, ticket_photos, ticket_status_changes, ticket_resident_notices; US-6.1; US-6.4; US-6.7

- [ ] **Task:** Migrations de áreas e reservas: `common_areas`, `common_area_slots`, `reservations`
  - **Acceptance criteria:**
    - `common_areas`: `name` (unique por condomínio), `description` null, `is_active`, `min_advance_hours` default 24, `max_advance_days` default 60, `cancellation_deadline_hours` default 24.
    - `common_area_slots`: `common_area_id`, `starts_at`/`ends_at` time, `softDeletes`; índice `(common_area_id, starts_at)`.
    - `reservations`: `common_area_id`, `common_area_slot_id`, `unit_id`, `resident_id`, `reservation_status_id`, `reservation_origin_id` not null; `created_by_user_id` null; `date`; `starts_at`/`ends_at` time (snapshot); `cancelled_at`, `reservation_cancellation_origin_id`, `cancellation_reason`, `cancelled_by_user_id` null.
    - Índice único **parcial** `(common_area_slot_id, date) WHERE cancelled_at IS NULL`; índices `(unit_id, date)` e `(common_area_id, date)`.
  - **Traces:** common_areas, common_area_slots, reservations; US-7.1; US-7.3; US-7.8

- [ ] **Task:** Migrations de escalonamentos: `escalations` e `escalation_assignments`
  - **Acceptance criteria:**
    - `escalations`: `resident_id`, `unit_id`, `escalation_status_id`, `escalation_reason_id` not null; `ticket_id` null; `summary` text; `assigned_user_id`, `assigned_at`, `response`, `responded_by_user_id`, `resolved_at` null; índice `(condominium_id, escalation_status_id, created_at)`.
    - `escalation_assignments`: `escalation_id`, `user_id`, `previous_user_id` null; índice `(escalation_id, created_at)`.
    - `./vendor/bin/sail artisan migrate:fresh` roda do zero sem erro e `migrate:rollback` desfaz todas.
  - **Traces:** escalations, escalation_assignments; US-8.1; US-8.4

### Phase 1.3: Seeders de lookup

- [ ] **Task:** `LookupSeeder` com todos os valores do schema
  - **Acceptance criteria:**
    - Upsert por `slug` (idempotente) com os valores de `database-schema.md` § Lookup Table Seeds, nomes em pt-BR.
    - `ticket_statuses`: `aberto` e `em_andamento` com `is_final=false`; `resolvido` e `cancelado` com `is_final=true`.
    - `agent_tools` com 12 linhas (slug → nome exibido): `residents_lookup` → verificar_morador, `rules_search` → consultar_regimento, `notices_list` → consultar_comunicados, `tickets_create` → abrir_chamado, `tickets_list` → listar_chamados, `tickets_show` → consultar_chamado, `areas_list` → listar_areas, `areas_availability` → consultar_disponibilidade, `reservations_create` → reservar_area, `reservations_list` → listar_reservas, `reservations_cancel` → cancelar_reserva, `escalations_create` → escalar_humano; cada uma com método, rota e descrição pt-BR.
    - `DatabaseSeeder` chama `LookupSeeder` sempre.
  - **Feature tests:**
    - `tests/Feature/Database/LookupSeederTest.php` → cada lookup tem exatamente os slugs do schema (roles 3, resident_profiles 2, ticket_priorities 3, reservation_origins 2, escalation_reasons 3, tool_call_results 3, agent_tools 12, document_types 2, document_statuses 7, ticket_statuses 4, ticket_origins 2, reservation_statuses 2, reservation_cancellation_origins 2, escalation_statuses 3, webhook_events 4, webhook_delivery_statuses 3).
    - → rodar o seeder duas vezes não duplica linhas.
    - → `is_final` correto para os 4 status de chamado.
  - **Traces:** roles, resident_profiles, ticket_priorities, reservation_origins, escalation_reasons, tool_call_results, agent_tools, document_types, document_statuses, ticket_statuses, ticket_origins, reservation_statuses, reservation_cancellation_origins, escalation_statuses, webhook_events, webhook_delivery_statuses

---

## Phase 2: Fundação — models, relacionamentos e factories

**Goal:** todos os models com relacionamentos completos, tenancy por condomínio e factories consistentes. · **Depends on:** Phase 1 · **Covers:** todas as tabelas; US-1.2; US-2.2

**Notas para o agente:**
- Rodar via `./vendor/bin/sail`; criar arquivos com `artisan make:model|factory|class --no-interaction`. Ativar skills `laravel-best-practices` e `testing-best-practices`.
- Relacionamentos são entregues **completos** nesta fase; fases seguintes não adicionam relações.
- Utilitários em `app/Support`. Testes rodam no Postgres `testing` (pgvector).

### Phase 2.1: Lookups e utilitários

- [ ] **Task:** Models das 16 lookups
  - **Acceptance criteria:**
    - Models `Role`, `ResidentProfile`, `TicketPriority`, `ReservationOrigin`, `EscalationReason`, `ToolCallResult`, `AgentTool`, `DocumentType`, `DocumentStatus`, `TicketStatus`, `TicketOrigin`, `ReservationStatus`, `ReservationCancellationOrigin`, `EscalationStatus`, `WebhookEvent`, `WebhookDeliveryStatus`.
    - Trait `App\Support\Lookups\HasSlug` com `static idFor(string $slug): int` (cache em memória por request) e `scopeSlug()`; constantes de slug em cada model (ex.: `TicketStatus::ABERTO = 'aberto'`).
    - Relações inversas `hasMany` para as tabelas que as referenciam (ex.: `TicketStatus::tickets()`, `AgentTool::toolCalls()`); cast `is_final` boolean.
  - **Feature tests:**
    - `tests/Feature/Models/LookupModelTest.php` → `TicketStatus::idFor('aberto')` retorna o id semeado; slug inexistente lança `ModelNotFoundException`.
  - **Traces:** roles, resident_profiles, ticket_priorities, reservation_origins, escalation_reasons, tool_call_results, agent_tools, document_types, document_statuses, ticket_statuses, ticket_origins, reservation_statuses, reservation_cancellation_origins, escalation_statuses, webhook_events, webhook_delivery_statuses

- [ ] **Task:** Normalização e validação de telefone E.164
  - **Acceptance criteria:**
    - `App\Support\PhoneNumber::normalize(string): ?string` remove espaços, pontos, hífens e parênteses; resultado válido casa `^\+[1-9]\d{7,14}$`, senão `null`.
    - `PhoneNumber::format(string)` exibe números `+55` como `+55 41 99812-3344`; outros países ficam como estão.
    - Regra de validação `App\Rules\E164Phone` usa `normalize` e falha com mensagem pt-BR "Informe o telefone no formato internacional, ex.: +5511999990000.".
  - **Feature tests:**
    - `tests/Feature/Support/PhoneNumberTest.php` → dataset válido (`+55 (11) 99999-0000` → `+5511999990000`, `+1 415 555 2671`) e inválido (`11999990000` sem `+`, `+0123`, texto); `format` de número BR.
  - **Traces:** US-2.2; US-2.4; US-3.2

- [ ] **Task:** Tenancy — contexto do condomínio e trait `BelongsToCondominium`
  - **Acceptance criteria:**
    - `App\Support\Tenancy\CurrentCondominium` (singleton `scoped`) com `set(Condominium)`, `get()`, `id()`, `clear()`.
    - Trait `BelongsToCondominium`: global scope filtra por `condominium_id` quando há condomínio atual; `creating` preenche `condominium_id` se vazio; relação `condominium()`; escape via `withoutGlobalScope`.
    - Aplicada a: Block, Unit, Resident, RuleDocument, RuleArticle, Notice, TicketCategory, Ticket, TicketPhoto, TicketStatusChange, TicketResidentNotice, CommonArea, CommonAreaSlot, Reservation, Escalation, EscalationAssignment, WebhookDelivery, AgentToolCall. Lookups e User não são escopados.
    - Sem condomínio atual (console, seeders, jobs sem contexto) nenhuma filtragem é aplicada.
  - **Feature tests:**
    - `tests/Feature/Tenancy/CondominiumScopeTest.php` → com condomínio A atual, `Resident`, `Ticket` e `Notice` retornam só linhas de A.
    - → criar model sem `condominium_id` preenche A.
    - → sem condomínio atual, todas as linhas são visíveis.
    - → route model binding de registro de B com A atual resulta em 404.
  - **Traces:** US-1.2; condominiums

### Phase 2.2: Models de domínio

- [ ] **Task:** Models `Condominium` e `User`
  - **Acceptance criteria:**
    - `Condominium`: `HasApiTokens` (Sanctum; tokens pertencem ao condomínio), cast `webhook_secret` → `encrypted`, `hasWebhook(): bool`; `hasMany` users, blocks, units, residents, ticketCategories, ruleDocuments, notices, tickets, commonAreas, reservations, escalations, webhookDeliveries, agentToolCalls.
    - `User`: `belongsTo` role e condominium; casts `is_active` boolean e `password` hashed; `isSuperAdmin()`, `isSindico()`, `isZelador()`; `hasMany` assignedEscalations (`assigned_user_id`), escalationAssignments, ticketStatusChanges, ticketResidentNotices, uploadedRuleDocuments, createdNotices, openedTickets, createdReservations.
  - **Feature tests:**
    - `tests/Feature/Models/CondominiumTest.php` → `webhook_secret` fica cifrado no banco (valor bruto ≠ texto); `createToken()` gera token com `tokenable` = condomínio; `hasWebhook()` falso sem URL.
  - **Traces:** condominiums, users, personal_access_tokens; US-1.5; US-1.6

- [ ] **Task:** Models de estrutura: `Block`, `Unit`, `Resident`
  - **Acceptance criteria:**
    - `Block` hasMany units. `Unit` belongsTo block (nullable); hasMany residents, tickets, reservations, escalations; accessor `label` = número + nome do bloco (bloco "A", unidade "102" → "102A"; sem bloco → "102").
    - `Resident` belongsTo unit e residentProfile; hasMany tickets, reservations, escalations, agentToolCalls; scope `active`; mutator de `phone` usa `PhoneNumber::normalize`; accessor `first_name`.
  - **Feature tests:**
    - `tests/Feature/Models/ResidentTest.php` → telefone formatado é salvo em E.164; `Unit::label` com e sem bloco; scope `active` exclui inativos.
  - **Traces:** blocks, units, residents; US-2.1; US-2.2

- [ ] **Task:** Models de conhecimento: `RuleDocument`, `RuleArticle`, `Notice`
  - **Acceptance criteria:**
    - `RuleDocument` belongsTo documentType, documentStatus, uploadedBy, publishedBy; hasMany articles ordenados por `position`; scope `published`.
    - `RuleArticle` belongsTo ruleDocument; casts `embedding` → `array`, `embedded_at` datetime.
    - `Notice` usa `SoftDeletes`; cast `is_active`; scope `active`; belongsTo createdBy.
  - **Feature tests:**
    - `tests/Feature/Models/KnowledgeModelsTest.php` → `Notice::active()` exclui inativos e excluídos; `RuleDocument::published()` só retorna status `publicado`.
  - **Traces:** rule_documents, rule_articles, notices; US-4.3; US-5.1

- [ ] **Task:** Models de chamados: `TicketCategory`, `Ticket`, `TicketPhoto`, `TicketStatusChange`, `TicketResidentNotice`
  - **Acceptance criteria:**
    - `Ticket` belongsTo status, priority, origin, category, unit, resident, openedBy; hasMany photos, statusChanges, residentNotices; morphMany webhookDeliveries (`subject`); accessor `protocol_label` = `#N`; scopes `open` (aberto + em_andamento) e `closed` (resolvido + cancelado); `isFinal()`.
    - `TicketCategory` hasMany tickets, scope `active`. `TicketPhoto` belongsTo ticket. `TicketStatusChange` belongsTo ticket, fromStatus, toStatus, user. `TicketResidentNotice` belongsTo ticket, user, webhookDelivery.
  - **Feature tests:**
    - `tests/Feature/Models/TicketModelTest.php` → scopes `open`/`closed`; `isFinal()` por status; `protocol_label`.
  - **Traces:** ticket_categories, tickets, ticket_photos, ticket_status_changes, ticket_resident_notices; US-6.3

- [ ] **Task:** Models de áreas e reservas: `CommonArea`, `CommonAreaSlot`, `Reservation`
  - **Acceptance criteria:**
    - `CommonArea` hasMany slots (ordenados por `starts_at`) e reservations; scope `active`; casts inteiros das regras.
    - `CommonAreaSlot` usa `SoftDeletes`; belongsTo area; hasMany reservations.
    - `Reservation` belongsTo area, slot (`withTrashed`), unit, resident, status, origin, cancellationOrigin, createdBy, cancelledBy; morphMany webhookDeliveries; casts `date`, `cancelled_at`; scopes `active` (`cancelled_at IS NULL`) e `upcoming`; `startsAtInCondoTimezone(): CarbonImmutable` (data + `starts_at` em `America/Sao_Paulo`).
  - **Feature tests:**
    - `tests/Feature/Models/ReservationModelTest.php` → `startsAtInCondoTimezone` combina data e hora no fuso do condomínio; slot excluído continua acessível pela reserva.
  - **Traces:** common_areas, common_area_slots, reservations; US-7.1; US-7.3

- [ ] **Task:** Models de escalonamentos, webhooks e log: `Escalation`, `EscalationAssignment`, `WebhookDelivery`, `AgentToolCall`
  - **Acceptance criteria:**
    - `Escalation` belongsTo resident, unit, ticket, status, reason, assignedUser, respondedBy; hasMany assignments; morphMany webhookDeliveries; scope `unresolved`.
    - `EscalationAssignment` belongsTo escalation, user, previousUser.
    - `WebhookDelivery` belongsTo event e status; morphTo `subject`; cast `payload` array.
    - `AgentToolCall` belongsTo agentTool, result (`ToolCallResult`), resident, personalAccessToken; cast `entities` array; scope `forArticle(int $id)` com `whereJsonContains('entities->article_ids', $id)`.
  - **Feature tests:**
    - `tests/Feature/Models/AgentToolCallTest.php` → `forArticle` conta só registros cujo `entities.article_ids` contém o id.
  - **Traces:** escalations, escalation_assignments, webhook_deliveries, agent_tool_calls; US-8.4; US-9.4

### Phase 2.3: Factories, constraints e dados de demonstração

- [ ] **Task:** Factories de todos os models de domínio
  - **Acceptance criteria:**
    - Factories para Condominium, User (states `superAdmin`, `sindico`, `zelador`, `inactive`), Block, Unit (`withBlock`), Resident (`inactive`, `owner`, `tenant`), RuleDocument (um state por status), RuleArticle (`withEmbedding` com 1536 floats), Notice (`inactive`), TicketCategory, Ticket (`aberto`, `emAndamento`, `resolvido`, `cancelado`, `fromPanel`, `highPriority`), TicketPhoto, TicketStatusChange, TicketResidentNotice, CommonArea, CommonAreaSlot, Reservation (`cancelled`, `manual`), Escalation (`pendente`, `emAtendimento`, `resolvido`), EscalationAssignment, WebhookDelivery (`enviado`, `falhou`), AgentToolCall (`sucesso`, `vazio`, `recusa`).
    - Registros filhos compartilham o mesmo condomínio (unidade, morador e chamado do mesmo condomínio; morador pertence à unidade do chamado/reserva).
    - Faker `pt_BR`; telefones em E.164.
  - **Feature tests:**
    - `tests/Feature/Database/FactoryConsistencyTest.php` → `Ticket::factory()->create()` tem `resident.unit_id == unit_id` e `condominium_id` igual em todos os relacionados; o mesmo para `Reservation` e `Escalation`.
  - **Traces:** condominiums, users, blocks, units, residents, rule_documents, rule_articles, notices, ticket_categories, tickets, ticket_photos, ticket_status_changes, ticket_resident_notices, common_areas, common_area_slots, reservations, escalations, escalation_assignments, webhook_deliveries, agent_tool_calls

- [ ] **Task:** Testes das constraints do banco
  - **Acceptance criteria:**
    - Suíte cobre todas as garantias que o schema delega ao banco e passa no Postgres `testing`.
  - **Feature tests:**
    - `tests/Feature/Database/ConstraintsTest.php` → segunda reserva ativa na mesma faixa + data viola o índice parcial; após preencher `cancelled_at` na primeira, nova reserva é aceita.
    - → unidade com mesmo número no mesmo bloco falha; em outro bloco é aceita; duas unidades sem bloco com mesmo número no mesmo condomínio falham.
    - → `protocol_number` repetido no mesmo condomínio falha; em outro condomínio é aceito.
    - → telefone de morador repetido no mesmo condomínio falha; em outro condomínio é aceito.
    - → excluir o `personal_access_token` deixa `agent_tool_calls.personal_access_token_id` nulo.
  - **Traces:** reservations, units, tickets, residents, agent_tool_calls; US-2.1; US-2.2; US-6.1; US-7.3

- [ ] **Task:** `DemoSeeder` com os dados do design
  - **Acceptance criteria:**
    - Executado por `DatabaseSeeder` apenas quando `app()->environment('local')`.
    - Cria super admin `admin@example.com`; condomínios "Residencial Aurora" (Curitiba), "Edifício Solar das Palmeiras" (São Paulo) e "Villa Serena" (Florianópolis), cada um com as 6 categorias padrão.
    - Residencial Aurora: blocos A e B; os 10 moradores do design com unidade e perfil (Helena Barros 101A … Fernando Lima 1504B); síndica Renata Moura; zelador José Carvalho; áreas Salão de festas, Churrasqueira, Quadra e Espaço gourmet com faixas; comunicados ativos e inativos do design; os chamados do design; os 3 escalonamentos do design; reservas da semana corrente; tool calls de hoje, de ontem e dos últimos 7 dias para a visão geral.
    - Senha de todos os usuários: `password`.
  - **Feature tests:**
    - `tests/Feature/Database/DemoSeederTest.php` → roda sem erro e cria Residencial Aurora com 10 moradores, 4 áreas e 3 escalonamentos.
  - **Design ref:** `.spec/init/design/Sindico Conversacional - Backoffice.dc.html` (bloco `<script>`: condos, residentBase, ticketBase, areas, noticeBase, escBase)
  - **Traces:** design (dados de exemplo); US-9.2; condominiums; residents

---

## Phase 3: Fundação — design system e layout do painel

**Goal:** tokens e componentes do canvas em Livewire 4 + Tailwind 4 e o shell do painel. · **Depends on:** Phase 1 · **Covers:** design system do canvas; layout (sidebar, header)

**Notas para o agente:**
- Ativar skills `tailwindcss-development` e `livewire-development` (formato de componentes do Livewire 4). Sem biblioteca de UI: componentes Blade anônimos em `resources/views/components/ui/`.
- Referência visual: `.spec/init/design/Sindico Conversacional - Backoffice.dc.html` (estilos inline e `<helmet>`). Fidelidade de cores, raios, espaçamentos e tipografia.
- Fora do MVP, não construir: item "Conversas", busca ⌘K, pill "Agente online".
- Frontend puro nesta fase: validação visual; se `npm run build` for necessário para ver mudanças, rodar via Sail.

### Phase 3.1: Tokens

- [ ] **Task:** Tokens do design no Tailwind 4
  - **Acceptance criteria:**
    - `resources/css/app.css` `@theme` troca Instrument Sans pela pilha do design: `-apple-system, BlinkMacSystemFont, "SF Pro Text", "Helvetica Neue", Helvetica, sans-serif`; base 13px, line-height 1.45, antialiased.
    - Cores: fundo `#f5f5f7`, sidebar `#ebebef`, texto `#1d1d1f`, secundário `#6e6e73`, terciário `#86868b`, corpo `#3a3a3f`, accent `#5a5bd9` (hover `#4547b8`), superfície suave `#f7f7fa`/`#fafafc`, linha `rgba(0,0,0,.06)`.
    - Paletas de tag: ia `#eeeef8`/`#4547b8`, ok `#e8f8ee`/`#1f7a3e`, warn `#fff4e0`/`#a05a00`, esc `#fdecec`/`#b3261e`, grey `#f0f0f4`/`#3a3a3f`; pontos `#e5484d`, `#f5a623`, `#2fb35b`, `#30a4c9`, `#8e8e93`.
    - Raios: card 14px, controles 8–10px, drawer 18px, pill 99px.
    - `welcome.blade.php` removida; `/` redireciona (login ou visão geral, conforme sessão, a partir da Phase 4).
  - **Design ref:** `.spec/init/design/Sindico Conversacional - Backoffice.dc.html` (`<helmet>` e estilos inline)
  - **Traces:** design (tokens); Tech Stack (Frontend)

### Phase 3.2: Componentes

- [ ] **Task:** Componentes base: button, pill, dot, avatar, count badge
  - **Acceptance criteria:**
    - `x-ui.button`: variantes `primary` (accent, texto branco, 600), `secondary` (branco, borda `rgba(0,0,0,.1)`), `ghost` (sem fundo, `#6e6e73`); tamanhos `sm` (12px, padding 7px 14px) e `md` (13px, padding 9–10px); estado de loading com `wire:loading`; aceita `href` ou `type`.
    - `x-ui.pill`: variantes ia/ok/warn/esc/grey, 11px, 600, padding 3px 9px, radius 99px.
    - `x-ui.dot`: círculo 7–8px com cor por prop.
    - `x-ui.avatar`: 2 iniciais, cor determinística a partir de `[#5a5bd9, #30a4c9, #2fb35b, #f5a623, #e5484d, #8e8e93]`, tamanhos 24/28/30/34px.
    - `x-ui.count-badge`: pill accent 11px usado no menu.
  - **Design ref:** `.spec/init/design/Sindico Conversacional - Backoffice.dc.html` (`<!-- Sidebar -->`, `<!-- CHAMADOS -->`, `<!-- MORADORES -->`)
  - **Traces:** design (componentes)

- [ ] **Task:** Componentes de conteúdo: card, KPI card, tabs, tabela, empty state
  - **Acceptance criteria:**
    - `x-ui.card`: branco, radius 14px, borda `rgba(0,0,0,.06)`, sombra `0 1px 2px rgba(0,0,0,.03)`, slot de header (título 14px 600 + ação à direita).
    - `x-ui.kpi-card`: label 12px `#6e6e73`, valor 30px 600 letter-spacing -.02em, linha de delta com cor por prop.
    - `x-ui.tabs`: abas sublinhadas com contador em `#86868b`; ativa com texto `#1d1d1f` e borda inferior 2px `#1d1d1f`; slot à direita para botão.
    - `x-ui.table`: header uppercase 11px 600 `#86868b` letter-spacing .04em; colunas por `grid-template-columns` recebidas via prop; linhas padding 12px 18px, hover `#f7f7fa`, linha clicável opcional.
    - `x-ui.empty-state`: ícone neutro, título e texto secundário.
  - **Design ref:** `.spec/init/design/Sindico Conversacional - Backoffice.dc.html` (`<!-- DASHBOARD -->`, `<!-- CHAMADOS -->`, `<!-- COMUNICADOS -->`)
  - **Traces:** design (componentes)

- [ ] **Task:** Overlays: drawer e modal
  - **Acceptance criteria:**
    - `x-ui.drawer`: fixo à direita com inset 12px, largura 460px, radius 18px, sombra `0 20px 60px rgba(0,0,0,.2)`, overlay `rgba(0,0,0,.18)`; slots header/body (scroll)/footer; fecha por clique no overlay, botão × (28px, `#f0f0f4`) e Esc; estado controlável por `wire:model`/Alpine.
    - `x-ui.modal`: centralizado, mesmos tokens de card, largura sm/md/lg, título, corpo e rodapé com botões; fecha por Esc e overlay.
  - **Design ref:** `.spec/init/design/Sindico Conversacional - Backoffice.dc.html` (`<!-- CHAMADOS -->` drawer); modal segue o design system (sem mockup)
  - **Traces:** design (componentes)

- [ ] **Task:** Controles de formulário
  - **Acceptance criteria:**
    - `x-ui.input`, `x-ui.textarea`, `x-ui.select`, `x-ui.time`: padding 8px 12px, borda `rgba(0,0,0,.1)`, radius 9px, fundo `#fafafc`, foco com anel accent.
    - `x-ui.field`: variante em grade (label 150px + campo, estilo Configurações) e empilhada; mensagem de erro pt-BR em `#b3261e` abaixo do campo a partir de `$errors`.
    - `x-ui.toggle`: 40×24px, fundo `#5a5bd9` ligado / `#d1d1d6` desligado, knob branco 20px com transição.
    - `x-ui.file-drop`: botão tracejado (`1px dashed rgba(0,0,0,.15)`, texto accent) com lista de arquivos e prévia de imagens.
    - Todos funcionam com `wire:model`.
  - **Design ref:** `.spec/init/design/Sindico Conversacional - Backoffice.dc.html` (`<!-- CONFIG -->` campos e toggles, `<!-- REGIMENTO -->` "+ Enviar PDF")
  - **Traces:** design (componentes)

### Phase 3.3: Layout

- [ ] **Task:** Layout do painel: sidebar, header e área de conteúdo
  - **Acceptance criteria:**
    - `layouts.app`: flex 100vh (mín. 720px); sidebar 236px fundo `#ebebef`, padding 14px 10px.
    - Topo da sidebar: card do condomínio (iniciais em quadrado accent 28px, nome truncado, "N unidades"); seta ▾ e dropdown (nome + cidade, "+ Novo condomínio") renderizados só quando a prop `canSwitch` é verdadeira.
    - Grupos de navegação: Operação (Visão geral `#5a5bd9`, Escalonamentos `#e5484d` com `count-badge`, Chamados `#f5a623`, Reservas `#30a4c9`), Base de conhecimento (Comunicados, Regimento — `#8e8e93`), Cadastro (Moradores, Configurações — `#8e8e93`) e Plataforma (Condomínios, Usuários — `#8e8e93`); cada item recebe visibilidade e badge por props; ativo com fundo `rgba(0,0,0,.07)`.
    - Rodapé: avatar 30px, nome e "Papel · Condomínio", com menu contendo "Sair".
    - Header 56px com fundo `rgba(245,245,247,.8)` + blur e título h1 17px 600; conteúdo com padding 24px 28px 40px e largura mínima 1000px.
    - Não renderiza "Conversas", busca ⌘K nem "Agente online".
  - **Design ref:** `.spec/init/design/Sindico Conversacional - Backoffice.dc.html` (`<!-- Sidebar -->`, `<!-- Main -->` header)
  - **Traces:** design (layout); US-1.1; US-1.2

- [ ] **Task:** Layout de visitante e mensagens flash
  - **Acceptance criteria:**
    - `layouts.guest`: card central (largura 360px) sobre `#f5f5f7` com nome "Síndico Conversacional".
    - `x-ui.toast`: sucesso (ok) e erro (esc) no canto superior direito, some em 4s, disparável por session flash e por evento Livewire.
  - **Design ref:** design system do canvas (sem mockup): tokens de `<helmet>` e card
  - **Traces:** design (layout); US-1.1

---

## Phase 4: Autenticação, papéis e tenancy

**Goal:** login do painel, permissões por papel e condomínio atual resolvido em toda requisição. · **Depends on:** Phase 2, Phase 3 · **Covers:** US-1.1, US-1.2, US-1.4 (bloqueio de inativos)

**Notas para o agente:**
- Rodar via `./vendor/bin/sail`. Skills: `livewire-development`, `laravel-best-practices`, `testing-best-practices`.
- Sem Fortify: login é componente Livewire próprio. Sem recuperação de senha e sem 2FA no MVP.
- Usa `CurrentCondominium` e o trait `BelongsToCondominium` da Phase 2. Design: `.spec/init/design/Sindico Conversacional - Backoffice.dc.html`.

### Phase 4.1: Login e sessão

- [ ] **Task:** Tela e fluxo de login
  - **Acceptance criteria:**
    - Rota `/login` (guest) com e-mail, senha e "Lembrar de mim", no `layouts.guest`.
    - Autentica apenas usuários com `is_active = true`; qualquer falha exibe "E-mail ou senha inválidos." sem revelar se o e-mail existe.
    - Limite de 5 tentativas por minuto por e-mail + IP; ao exceder exibe "Muitas tentativas. Tente novamente em N segundos."
    - Sucesso redireciona para a URL pretendida ou `dashboard`; `/` redireciona para `dashboard` (logado) ou `/login`.
    - "Sair" (POST) invalida a sessão e regenera o token CSRF.
    - A tela não tem link de recuperação de senha.
  - **Feature tests:**
    - `tests/Feature/Auth/LoginTest.php` → síndico ativo entra e cai na visão geral; senha errada mostra erro genérico; usuário inativo não entra; 6ª tentativa em 1 minuto é bloqueada; logout encerra a sessão; visitante em `/dashboard` vai para `/login`; página de login não contém "Esqueci".
  - **Design ref:** design system do canvas (sem mockup): `layouts.guest` da Phase 3
  - **Traces:** US-1.1; users

- [ ] **Task:** Comando `app:create-super-admin`
  - **Acceptance criteria:**
    - `./vendor/bin/sail artisan app:create-super-admin --name= --email=` pede a senha oculta quando não informada (mínimo 8 caracteres).
    - Cria usuário `super_admin` com `condominium_id` nulo; e-mail duplicado encerra com erro e código de saída ≠ 0.
  - **Feature tests:**
    - `tests/Feature/Console/CreateSuperAdminCommandTest.php` → cria super admin sem condomínio; e-mail duplicado falha sem criar usuário.
  - **Traces:** US-1.1; users; roles

- [ ] **Task:** Encerrar sessão de usuário desativado
  - **Acceptance criteria:**
    - Middleware `EnsureUserIsActive` nas rotas do painel: usuário com `is_active = false` é deslogado, a sessão é invalidada e ele volta ao login com "Seu acesso foi desativado."
  - **Feature tests:**
    - `tests/Feature/Auth/InactiveUserSessionTest.php` → usuário logado desativado durante a sessão é deslogado na requisição seguinte.
  - **Traces:** US-1.4; US-1.1

### Phase 4.2: Papéis e permissões

- [ ] **Task:** Gates por papel
  - **Acceptance criteria:**
    - Gates definidos em `AppServiceProvider`: `platform.manage` e `integration.manage` (super_admin); `condominium.settings`, `residents.manage`, `categories.manage`, `knowledge.manage`, `reservations.manage`, `webhooks.failures` (super_admin, sindico); `tickets.operate`, `escalations.operate`, `dashboard.view` (os três papéis); `escalations.reassign` (super_admin, sindico).
    - Rotas do painel usam `can:<gate>`; negação responde 403.
    - Constante/array central com o mapa rota → gate, estendido pelas fases seguintes.
  - **Feature tests:**
    - `tests/Feature/Auth/RoleAccessTest.php` → dataset papel × gate com o resultado esperado para cada combinação (zelador nega `knowledge.manage`, `reservations.manage`, `residents.manage`, `categories.manage`, `condominium.settings`, `integration.manage`, `webhooks.failures`; síndico nega `platform.manage` e `integration.manage`; super admin permite todos).
  - **Traces:** US-1.1; US-8.4; US-8.5

- [ ] **Task:** Menu do painel por papel
  - **Acceptance criteria:**
    - Sidebar mostra apenas itens cujo gate o usuário tem: zelador vê Visão geral, Escalonamentos e Chamados; síndico vê tudo exceto Plataforma; super admin vê tudo, incluindo Plataforma.
    - Rodapé mostra o papel em pt-BR ("Super admin", "Síndico", "Zelador") e o nome do condomínio atual.
  - **Feature tests:**
    - `tests/Feature/Auth/NavigationTest.php` → zelador não vê link "Comunicados" nem "Configurações"; síndico não vê "Condomínios"; super admin vê "Condomínios" e "Usuários".
  - **Design ref:** `.spec/init/design/Sindico Conversacional - Backoffice.dc.html` (`<!-- Sidebar -->`)
  - **Traces:** US-1.1

### Phase 4.3: Tenancy no painel

- [ ] **Task:** Resolver o condomínio atual no painel
  - **Acceptance criteria:**
    - Middleware `SetPanelCondominium` (após auth): síndico/zelador usam `user.condominium_id`; super admin usa `session('current_condominium_id')`.
    - Super admin sem condomínio selecionado que acessa rota de condomínio é redirecionado para a lista de condomínios (Phase 5).
    - Define `CurrentCondominium`, de modo que consultas e route model binding de outro condomínio retornam 404.
    - Chave de sessão é ignorada para síndico/zelador.
  - **Feature tests:**
    - `tests/Feature/Tenancy/PanelTenancyTest.php` → síndico de A acessando por URL um morador de B recebe 404 (rota de teste registrada no próprio teste); super admin sem seleção é redirecionado; síndico com `current_condominium_id` de B na sessão continua vendo A.
  - **Traces:** US-1.2

- [ ] **Task:** Seletor de condomínio do super admin
  - **Acceptance criteria:**
    - Dropdown da sidebar lista todos os condomínios (iniciais, nome, cidade), destacando o atual; selecionar grava a sessão e recarrega a visão geral.
    - "+ Novo condomínio" leva ao cadastro da Phase 5.
    - Endpoint de troca autorizado só para super admin (403 para os demais).
  - **Feature tests:**
    - `tests/Feature/Tenancy/CondominiumSwitchTest.php` → super admin troca para B e a visão geral mostra o nome de B; síndico que chama a troca recebe 403.
  - **Design ref:** `.spec/init/design/Sindico Conversacional - Backoffice.dc.html` (`<!-- Sidebar -->` dropdown de condomínios)
  - **Traces:** US-1.2

- [ ] **Task:** Rota `dashboard` provisória
  - **Acceptance criteria:**
    - Página Livewire `dashboard` (gate `dashboard.view`) no `layouts.app`, título "Visão geral", com empty state; o conteúdo final chega na Phase 12.
  - **Design ref:** `.spec/init/design/Sindico Conversacional - Backoffice.dc.html` (`<!-- Main -->` header)
  - **Traces:** US-1.1; US-9.2

---

## Phase 5: Plataforma, configurações e cadastros

**Goal:** super admin gerencia condomínios, usuários e integração; síndico mantém dados do condomínio, unidades, moradores e categorias. · **Depends on:** Phase 4 · **Covers:** US-1.3, US-1.4, US-1.5, US-1.6, US-2.1, US-2.2, US-2.3, US-2.4, US-9.4 (interações por morador, chamadas/7d)

**Notas para o agente:**
- Rodar via `./vendor/bin/sail`. Skills: `livewire-development`, `tailwindcss-development`, `laravel-best-practices`, `testing-best-practices`.
- Lógica de domínio em `app/Services/<Área>`. Telas usam os componentes `x-ui.*` da Phase 3; telas sem mockup seguem o design system do canvas.
- Design: `.spec/init/design/Sindico Conversacional - Backoffice.dc.html`. Não construir do canvas: card "Comportamento do agente", "Importar planilha", chip "Sem WhatsApp", coluna "WhatsApp".
- Registrar cada rota nova no mapa rota → gate da Phase 4.

### Phase 5.1: Plataforma (super admin)

- [ ] **Task:** Listar, criar e editar condomínios
  - **Acceptance criteria:**
    - `/plataforma/condominios` (gate `platform.manage`): tabela com nome, cidade, unidades, síndicos e data de criação; busca por nome; 20 por página.
    - Modal de criar/editar com nome (obrigatório, único) e cidade.
    - `CondominiumService::create()` cria o condomínio e as 6 categorias padrão (`eletrica` Elétrica, `hidraulica` Hidráulica, `elevador` Elevador, `limpeza` Limpeza, `seguranca` Segurança, `outros` Outros) na mesma transação e seleciona o novo condomínio na sessão.
  - **Feature tests:**
    - `tests/Feature/Platform/CondominiumManagementTest.php` → super admin cria condomínio com as 6 categorias; nome duplicado e nome vazio geram erro; busca filtra por nome; síndico recebe 403.
  - **Design ref:** design system do canvas (sem mockup): `x-ui.table`, `x-ui.modal`
  - **Traces:** US-1.3; condominiums; ticket_categories

- [ ] **Task:** Gerenciar síndicos e zeladores
  - **Acceptance criteria:**
    - `/plataforma/usuarios` (gate `platform.manage`): tabela com nome, e-mail, papel, condomínio e status; filtros por condomínio e papel.
    - Criar: nome, e-mail (único), papel (`sindico` | `zelador`), condomínio (obrigatório), senha inicial (mín. 8, confirmada). Editar: nome, e-mail, papel, condomínio. Ativar/desativar.
    - Papel `super_admin` não pode ser atribuído nesta tela; usuários super admin não aparecem para edição.
    - Um condomínio aceita vários síndicos e zeladores.
  - **Feature tests:**
    - `tests/Feature/Platform/UserManagementTest.php` → cria zelador em A; e-mail duplicado gera erro; papel `super_admin` rejeitado; condomínio obrigatório; desativar impede login; síndico recebe 403.
  - **Design ref:** design system do canvas (sem mockup): `x-ui.table`, `x-ui.modal`
  - **Traces:** US-1.4; users; roles

### Phase 5.2: Configurações

- [ ] **Task:** Página Configurações com card "Condomínio"
  - **Acceptance criteria:**
    - Rota `settings` (gate `condominium.settings`), grade de 2 colunas como no design.
    - Card "Condomínio" em linhas label/valor: Nome (somente leitura para síndico; editável para super admin), Cidade, Unidades ("128 · 2 blocos", calculado), Número WhatsApp, Zelador (nome + telefone), Horário de silêncio (início e fim `HH:MM`); botão Salvar.
    - Telefones validados com `E164Phone`; horário de silêncio exige os dois campos ou nenhum; início maior que fim é aceito.
    - Não renderiza o card "Comportamento do agente".
  - **Feature tests:**
    - `tests/Feature/Settings/CondominiumInfoTest.php` → síndico atualiza cidade, WhatsApp, zelador e horário; telefone inválido gera erro; só início informado gera erro; 22:00–08:00 aceito; alteração de nome pelo síndico é ignorada; super admin altera o nome; zelador recebe 403.
  - **Design ref:** `.spec/init/design/Sindico Conversacional - Backoffice.dc.html` (`<!-- CONFIG -->` card "Condomínio")
  - **Traces:** US-2.4; condominiums

- [ ] **Task:** Card "Integração": tokens de API e endpoints
  - **Acceptance criteria:**
    - Visível apenas com gate `integration.manage` (super admin); ações respondem 403 para outros papéis.
    - Lista tokens do condomínio (nome, criado em, último uso); "Gerar token" (nome obrigatório) exibe o token em texto puro **uma única vez** num modal com botão copiar; "Revogar" remove o token após confirmação.
    - Lista dos 12 `agent_tools`: pill do método (GET ok, POST ia, DELETE esc), rota em monoespaçada, nome da tool e descrição, e "N / 7d" = tool calls do condomínio atual nos últimos 7 dias.
  - **Feature tests:**
    - `tests/Feature/Settings/ApiTokenManagementTest.php` → super admin gera token (texto exibido, banco guarda hash, `tokenable` = condomínio); revogar apaga o token; síndico não vê o card e recebe 403 ao gerar; contagem 7d ignora chamadas de 8 dias atrás e de outro condomínio.
  - **Design ref:** `.spec/init/design/Sindico Conversacional - Backoffice.dc.html` (`<!-- CONFIG -->` card "Integração · tools do agente")
  - **Traces:** US-1.5; US-9.4; personal_access_tokens; agent_tools; agent_tool_calls

- [ ] **Task:** Card "Integração": webhook do n8n
  - **Acceptance criteria:**
    - Visível apenas para super admin; campo URL (https obrigatória fora de `local`) e ação "Gerar segredo"/"Regenerar segredo" que cria segredo aleatório de 64 caracteres, grava cifrado e exibe uma única vez.
    - Sem URL, o card mostra "Webhook não configurado".
  - **Feature tests:**
    - `tests/Feature/Settings/WebhookConfigTest.php` → URL `http://` rejeitada fora de `local`; `https://` aceita; regenerar troca o segredo; síndico recebe 403.
  - **Design ref:** `.spec/init/design/Sindico Conversacional - Backoffice.dc.html` (`<!-- CONFIG -->` card "Integração") + design system
  - **Traces:** US-1.6; condominiums

- [ ] **Task:** Card "Categorias de chamado"
  - **Acceptance criteria:**
    - Em Configurações (gate `categories.manage`): lista nome, slug, ativa (toggle) e quantidade de chamados.
    - Criar gera `slug` via `Str::slug(nome)`; nome e slug únicos por condomínio; renomear mantém o slug; inativar sempre permitido; excluir só sem chamados.
  - **Feature tests:**
    - `tests/Feature/Settings/TicketCategoryTest.php` → criar gera slug; nome duplicado gera erro; excluir categoria em uso é bloqueado; inativar categoria em uso funciona; renomear mantém slug; zelador recebe 403.
  - **Design ref:** `.spec/init/design/Sindico Conversacional - Backoffice.dc.html` (`<!-- CONFIG -->` padrão dos cards) + design system
  - **Traces:** US-2.3; ticket_categories

### Phase 5.3: Unidades e moradores

- [ ] **Task:** Blocos e unidades
  - **Acceptance criteria:**
    - Tela "Moradores e unidades" (gate `residents.manage`) com abas Moradores e Unidades; aba Unidades lista blocos ("+ Bloco") e unidades (label, bloco, nº de moradores; "+ Unidade").
    - Nome de bloco único no condomínio; número de unidade único no bloco (ou no condomínio quando sem bloco), com mensagem pt-BR ao violar.
    - Excluir bloco com unidades é bloqueado com "Remova as unidades do bloco antes de excluí-lo."; excluir unidade com moradores, chamados ou reservas é bloqueado.
  - **Feature tests:**
    - `tests/Feature/Registry/BlockUnitTest.php` → bloco duplicado gera erro; unidade duplicada no mesmo bloco gera erro e em outro bloco é aceita; duplicada sem bloco gera erro; excluir bloco com unidades bloqueado; excluir unidade com morador bloqueado; unidade vazia é excluída; zelador recebe 403.
  - **Design ref:** `.spec/init/design/Sindico Conversacional - Backoffice.dc.html` (`<!-- MORADORES -->` título "Moradores e unidades") + design system
  - **Traces:** US-2.1; blocks; units

- [ ] **Task:** Lista de moradores
  - **Acceptance criteria:**
    - Aba Moradores: chips "Todos · N" e um chip por bloco (preto quando ativo); busca por nome ou telefone; filtro de unidade; filtro Ativos/Inativos/Todos (padrão Todos).
    - Tabela com colunas Unidade (label em negrito), Nome (avatar com iniciais), Telefone (`PhoneNumber::format`), Perfil ("Proprietário" ou "Inquilino"), Interações (total histórico de `agent_tool_calls` do morador); ordenada por label; 25 por página.
    - Botão "+ Morador"; não renderiza "Importar planilha", chip "Sem WhatsApp" nem coluna "WhatsApp".
  - **Feature tests:**
    - `tests/Feature/Registry/ResidentListTest.php` → chip de bloco filtra; busca por telefone encontra; coluna Interações bate com a contagem de tool calls; moradores de outro condomínio não aparecem.
  - **Design ref:** `.spec/init/design/Sindico Conversacional - Backoffice.dc.html` (`<!-- MORADORES -->`)
  - **Traces:** US-2.2; US-9.4; residents; agent_tool_calls

- [ ] **Task:** Criar, editar, ativar, inativar e excluir morador
  - **Acceptance criteria:**
    - Modal com nome, telefone, unidade, perfil (obrigatórios) e ativo; telefone normalizado em E.164.
    - Telefone repetido no condomínio: "Telefone já cadastrado neste condomínio."; em outro condomínio é aceito.
    - Toggle ativo/inativo na linha; excluir só é permitido sem chamados, reservas e escalonamentos, caso contrário mostra "Morador com histórico: inative em vez de excluir."
  - **Feature tests:**
    - `tests/Feature/Registry/ResidentManagementTest.php` → cria com telefone normalizado; sem telefone gera erro; telefone inválido gera erro; duplicado no condomínio gera erro e em outro condomínio é aceito; excluir com chamado bloqueado; excluir sem histórico funciona; inativar grava `is_active = false`; zelador recebe 403.
  - **Design ref:** `.spec/init/design/Sindico Conversacional - Backoffice.dc.html` (`<!-- MORADORES -->` botão "+ Morador") + `x-ui.modal`
  - **Traces:** US-2.2; residents; resident_profiles

---

## Phase 6: Integração n8n — API base, log de tool calls e webhooks

**Goal:** contrato de autenticação e erros da API, verificação de morador, registro de toda tool call e envio de webhooks assinados. · **Depends on:** Phase 5 · **Covers:** US-3.1, US-3.2, US-9.1, US-8.3, US-8.5

**Notas para o agente:**
- Rodar via `./vendor/bin/sail`. Skills: `laravel-best-practices`, `testing-best-practices`.
- Rotas em `routes/api.php` dentro de `prefix('v1')->name('api.v1.')`; o nome de cada rota é `api.v1.<slug de agent_tools>` (ex.: `api.v1.residents_lookup`). Sem rate limit.
- Mensagens da API em pt-BR. Exceções de domínio em `app/Exceptions/Api`; serviços em `app/Services/Integration`; contexto do log em `app/Support`.
- Testes de webhook usam `Http::fake()` e `Queue::fake()`.

### Phase 6.1: API base

- [ ] **Task:** Autenticação por token do condomínio
  - **Acceptance criteria:**
    - Grupo `v1` com middlewares `auth:sanctum`, `EnsureCondominiumToken` (token cujo `tokenable` não é `Condominium` → 401) e `SetApiCondominium` (define `CurrentCondominium` a partir do token), além de `LogToolCall` (Phase 6.2).
    - Nenhum parâmetro de request altera o condomínio; `last_used_at` do token é atualizado a cada uso.
  - **Feature tests:**
    - `tests/Feature/Api/AuthenticationTest.php` → sem token responde 401 `{code:"unauthenticated"}`; token revogado responde 401; token de A só enxerga moradores de A; `last_used_at` é preenchido; token emitido para `User` responde 401.
  - **Traces:** US-3.1; US-1.5; US-1.2; personal_access_tokens

- [ ] **Task:** Contrato de erros JSON da API
  - **Acceptance criteria:**
    - Em `bootstrap/app.php` (que já renderiza JSON para `api/*`): `ValidationException` → 422 `{code:"validation_error", message:"Os dados enviados são inválidos.", errors:{...}}`; `AuthenticationException` → 401 `unauthenticated`; `ModelNotFoundException`/rota inexistente em `api/*` → 404 `not_found`; demais → 500 `server_error` sem stack trace fora de `local`.
    - `App\Exceptions\Api\ApiException` (status, code, message pt-BR, extras) renderizada como `{code, message, ...extras}`.
    - Subclasses: `ResidentNotFound` (403 `resident_not_found`), `TicketNotFound` (404 `ticket_not_found`), `AreaNotFound` (404 `area_not_found`), `ReservationNotFound` (404 `reservation_not_found`), `InvalidCategory` (422 `invalid_category`), `AreaUnavailable` (422 `area_unavailable`), `AdvanceNoticeViolation` (422 `advance_notice_violation`, extras `min_advance_hours` e `max_advance_days`), `SlotUnavailable` (422 `slot_unavailable`), `CancellationDeadlinePassed` (422 `cancellation_deadline_passed`, extra `cancellation_deadline_hours`), `EscalationTicketNotFound` (422 `ticket_not_found`).
  - **Feature tests:**
    - `tests/Feature/Api/ErrorContractTest.php` → erro de validação tem `code`, `message` pt-BR e `errors`; `ApiException` de teste renderiza `code`, `message` e extras; rota inexistente em `/api/v1` responde 404 JSON.
  - **Traces:** US-3.1

- [ ] **Task:** Resolução do morador pelo telefone
  - **Acceptance criteria:**
    - `App\Services\Integration\ResidentResolver::resolve(string $phone): Resident` normaliza o telefone, busca morador **ativo** do condomínio atual e lança `ResidentNotFound` caso contrário.
    - Guarda o morador e o telefone normalizado no `ToolCallContext` (Phase 6.2) para o log.
  - **Feature tests:**
    - `tests/Feature/Api/ResidentResolverTest.php` → telefone formatado resolve o morador; morador inativo lança `resident_not_found`; telefone de outro condomínio lança `resident_not_found`.
  - **Traces:** US-3.2; residents

- [ ] **Task:** `GET /api/v1/residents/lookup`
  - **Acceptance criteria:**
    - `phone` obrigatório; inválido após normalização → 422 `validation_error`.
    - Morador ativo → 200 `{exists:true, resident:{id,name,phone}, unit:{id,number,block}}` com `block` nulo quando a unidade não tem bloco.
    - Inexistente, inativo ou de outro condomínio → 200 `{exists:false}` sem outras chaves.
  - **Feature tests:**
    - `tests/Feature/Api/ResidentLookupTest.php` → formato exato para morador ativo; inativo retorna só `exists:false`; telefone de outro condomínio retorna `exists:false`; telefone ausente ou inválido responde 422; `block` nulo sem bloco.
  - **Traces:** US-3.2; residents; units

### Phase 6.2: Log de tool calls

- [ ] **Task:** `ToolCallContext` para entidades e resultado
  - **Acceptance criteria:**
    - `App\Support\ToolCallContext` (binding `scoped`) com `setResident(?Resident, ?string $phone)`, `addArticles(array $ids)`, `setTicket(int)`, `setReservation(int)`, `setEscalation(int)`, `markEmpty()`, `entities(): ?array` (nulo quando vazio).
    - Estado não vaza entre requisições.
  - **Feature tests:**
    - `tests/Feature/Api/ToolCallContextTest.php` → valores definidos por uma rota de teste aparecem em `entities` do registro; segunda requisição começa com contexto vazio.
  - **Traces:** US-9.1; agent_tool_calls

- [ ] **Task:** Middleware `LogToolCall`
  - **Acceptance criteria:**
    - Middleware terminável: mede latência desde o início da requisição e grava `AgentToolCall` em `terminate()` dentro de try/catch (`report()` em falha), sem alterar status, corpo ou tempo da resposta.
    - Não grava quando não há condomínio atual (401).
    - `agent_tool_id` resolvido pelo sufixo do nome da rota; `personal_access_token_id` do token atual; `resident_id`/`phone` do contexto ou do input `phone` normalizado; `http_status`; `latency_ms`; `entities` do contexto.
    - Resultado: 2xx com `markEmpty()` → `vazio`; demais 2xx → `sucesso` (lookup com `exists:false` é `sucesso` só com telefone); 401/403/404/422 → `recusa` com `error_code` = `code` do JSON; 5xx → `recusa` com `error_code` `server_error`.
    - Chamadas do painel ("Testar pergunta" e demais) não passam por este middleware.
  - **Feature tests:**
    - `tests/Feature/Api/ToolCallLogTest.php` → lookup de morador ativo grava `sucesso` com morador, token e latência ≥ 0; lookup de telefone desconhecido grava `sucesso` com `phone` e sem morador; requisição 422 grava `recusa` com `error_code` `validation_error`; 401 não grava; falha ao gravar (tabela indisponível simulada) mantém a resposta 200; toda rota nomeada `api.v1.*` tem `agent_tools` correspondente.
  - **Traces:** US-9.1; agent_tool_calls; agent_tools; tool_call_results

### Phase 6.3: Webhooks

- [ ] **Task:** `WebhookService::dispatch`
  - **Acceptance criteria:**
    - `dispatch(string $eventSlug, Model $subject, ?string $residentPhone, array $data): ?WebhookDelivery`.
    - Condomínio sem `webhook_url` → retorna `null` e não enfileira nada.
    - Com URL: cria `WebhookDelivery` `pendente` com `url` copiada e `payload` `{event, condominium_id, resident_phone, data, occurred_at}` (ISO-8601 com offset de `America/Sao_Paulo`) e enfileira `SendWebhookDelivery` com `afterCommit`.
  - **Feature tests:**
    - `tests/Feature/Webhooks/WebhookDispatchTest.php` → sem URL não cria entrega nem job; com URL cria entrega `pendente` com payload no formato e enfileira job; dentro de transação revertida nenhum job é enviado.
  - **Traces:** US-8.3; webhook_deliveries; webhook_events; webhook_delivery_statuses

- [ ] **Task:** Job `SendWebhookDelivery` assinado com 3 tentativas
  - **Acceptance criteria:**
    - `$tries` = 3, `backoff` = `config('condo.webhooks.backoff')`, timeout HTTP 10s.
    - Corpo bruto = `json_encode(payload)`; headers `Content-Type: application/json` e `X-Signature: sha256=<hash_hmac('sha256', corpo, segredo)>`.
    - Cada tentativa incrementa `attempts` e grava `last_response_code`/`last_error`; 2xx → `enviado` com `delivered_at`; não-2xx ou timeout → lança exceção para nova tentativa; `failed()` → `falhou` com `failed_at`.
    - Falha nunca reverte a ação que originou o evento.
  - **Feature tests:**
    - `tests/Feature/Webhooks/SendWebhookDeliveryTest.php` → resposta 200 marca `enviado` e o header de assinatura confere com o HMAC do corpo; resposta 500 incrementa `attempts` e lança exceção; `failed()` marca `falhou` com `failed_at` e último código 500; timeout grava `last_error`; `tries` do job é 3.
  - **Traces:** US-8.3; webhook_deliveries

- [ ] **Task:** Lista "Falhas de webhook" em Configurações
  - **Acceptance criteria:**
    - Card em Configurações (gate `webhooks.failures`: síndico e super admin; zelador 403) com entregas `falhou` do condomínio, mais recentes primeiro, 10 por página.
    - Colunas: evento (nome), morador (telefone formatado), referência ("Chamado #N", "Reserva · <área> · dd/mm", "Escalonamento #id" a partir do `subject`), tentativas, último código HTTP ou erro, data.
    - Somente leitura (sem botão de reenvio); sem falhas mostra "Nenhuma falha de envio."
  - **Feature tests:**
    - `tests/Feature/Settings/WebhookFailuresTest.php` → lista só `falhou` do condomínio atual; `enviado` e `pendente` não aparecem; rótulo de referência por tipo de subject; nenhum botão de reenvio renderizado; zelador recebe 403.
  - **Design ref:** `.spec/init/design/Sindico Conversacional - Backoffice.dc.html` (`<!-- CONFIG -->` padrão de cards) + `x-ui.table`
  - **Traces:** US-8.5; webhook_deliveries

---

## Phase 7: Comunicados

**Goal:** síndico controla comunicados por flag ativo/inativo e o agente lista os ativos. · **Depends on:** Phase 6 · **Covers:** US-5.1, US-5.2

**Notas para o agente:**
- Rodar via `./vendor/bin/sail`. Skills: `livewire-development`, `tailwindcss-development`, `testing-best-practices`.
- Comunicado **não** tem RAG, embedding nem datas de validade. API sob `api.v1.notices_list` (log automático pela Phase 6).
- Design: `.spec/init/design/Sindico Conversacional - Backoffice.dc.html` `<!-- COMUNICADOS -->`. Não construir: tipo do comunicado, "N respostas", "Válido de–até".

### Phase 7.1: API e painel

- [ ] **Task:** `GET /api/v1/notices`
  - **Acceptance criteria:**
    - Retorna `{notices:[{id,title,text,updated_at}]}` com comunicados ativos e não excluídos do condomínio do token, ordenados por `updated_at` desc; parâmetros de query são ignorados.
    - Lista vazia → `notices: []` e `ToolCallContext::markEmpty()`.
  - **Feature tests:**
    - `tests/Feature/Api/NoticeListTest.php` → só ativos do condomínio do token; inativos, excluídos e de outro condomínio ficam fora; ordem por `updated_at`; lista vazia grava log `vazio`; lista com itens grava `sucesso`.
  - **Traces:** US-5.2; US-9.1; notices

- [ ] **Task:** Tela Comunicados com abas Ativos/Inativos
  - **Acceptance criteria:**
    - Rota `notices.index` (gate `knowledge.manage`); abas "Ativos · N" e "Inativos · N"; botão "+ Novo comunicado".
    - Texto de apoio: "Só comunicados **ativos** chegam ao agente. Inativos ficam no histórico e não são citados."
    - Grade de 2 colunas de cards: pill "Ativo" (ok) ou "Inativo" (grey, card com opacidade .75), título 15px 600, texto, rodapé "Atualizado em dd/mm" + link "Editar".
  - **Feature tests:**
    - `tests/Feature/Notices/NoticeIndexTest.php` → contadores das abas corretos; aba Inativos lista só inativos; comunicados de outro condomínio não aparecem; zelador recebe 403.
  - **Design ref:** `.spec/init/design/Sindico Conversacional - Backoffice.dc.html` (`<!-- COMUNICADOS -->`)
  - **Traces:** US-5.1; notices

- [ ] **Task:** Criar, editar, ativar/desativar e excluir comunicado
  - **Acceptance criteria:**
    - Modal com título (obrigatório, até 255), texto (obrigatório) e toggle ativo (padrão ligado); `created_by_user_id` preenchido.
    - Ativar/desativar pelo card tem efeito imediato na API; excluir pede confirmação e faz soft delete.
    - Nenhum job é enfileirado ao salvar.
  - **Feature tests:**
    - `tests/Feature/Notices/NoticeManagementTest.php` → título e texto obrigatórios; desativar remove da API na chamada seguinte; excluir remove das duas abas e da API mantendo a linha com `deleted_at`; salvar não enfileira jobs; zelador recebe 403.
  - **Design ref:** `.spec/init/design/Sindico Conversacional - Backoffice.dc.html` (`<!-- COMUNICADOS -->` "+ Novo comunicado", "Editar") + `x-ui.modal`
  - **Traces:** US-5.1; US-5.2; notices

---

## Phase 8: Chamados

**Goal:** abertura, consulta, ciclo de status, prioridade e avisos de chamados pela API e pelo painel. · **Depends on:** Phase 6 · **Covers:** US-6.1, US-6.2, US-6.3, US-6.4, US-6.5, US-6.6, US-6.7, US-8.3 (eventos de chamado)

**Notas para o agente:**
- Rodar via `./vendor/bin/sail`. Skills: `laravel-best-practices`, `livewire-development`, `tailwindcss-development`, `testing-best-practices`.
- Regras em `app/Services/Tickets/TicketService.php` (compartilhado por API e painel). Webhooks via `WebhookService::dispatch` (Phase 6). Rotas `api.v1.tickets_create|tickets_list|tickets_show`.
- Design: `.spec/init/design/Sindico Conversacional - Backoffice.dc.html` `<!-- CHAMADOS -->`. Não construir: "Atribuir zelador", "Reabrir".
- Fotos em disco privado `local`, caminho `tickets/{condominium_id}/{ticket_id}/`.

### Phase 8.1: Domínio

- [ ] **Task:** Protocolo sequencial por condomínio
  - **Acceptance criteria:**
    - `TicketService::nextProtocol(Condominium)` roda dentro da transação da abertura: `lockForUpdate` na linha do condomínio, incrementa `last_ticket_protocol` e devolve o número.
    - Sequência nunca reinicia e é independente por condomínio.
  - **Feature tests:**
    - `tests/Feature/Tickets/TicketProtocolTest.php` → três aberturas em A geram 1, 2, 3; primeira abertura em B gera 1; aberturas pelo painel e pela API compartilham a sequência; duplicata forçada de `protocol_number` é rejeitada pelo banco.
  - **Traces:** US-6.1; US-6.5; condominiums; tickets

- [ ] **Task:** Abertura de chamado
  - **Acceptance criteria:**
    - `TicketService::open(...)` recebe descrição, local, categoria (slug ou id), prioridade (slug, padrão `media`), unidade, morador, usuário, origem e fotos.
    - Categoria inexistente ou inativa → `InvalidCategory`; morador informado fora da unidade informada → erro de validação.
    - Em uma transação: cria chamado `aberto` com protocolo, grava `ticket_status_changes` (`from` nulo → `aberto`, usuário opcional) e salva fotos em `ticket_photos` com `mime_type` e `size_bytes`; arquivos gravados são removidos se a transação falhar.
    - Abrir chamado não dispara webhook.
  - **Feature tests:**
    - `tests/Feature/Tickets/OpenTicketTest.php` → cria `aberto` com histórico inicial; prioridade padrão `media`; categoria inativa lança `invalid_category`; fotos salvas no disco e nas linhas; morador de outra unidade gera erro; nenhuma entrega de webhook criada.
  - **Traces:** US-6.1; US-6.5; tickets; ticket_photos; ticket_status_changes; ticket_origins; ticket_priorities

- [ ] **Task:** Transições de status
  - **Acceptance criteria:**
    - `TicketService::changeStatus(Ticket, string $to, ?string $comment, User)`: permitidas `aberto → em_andamento | resolvido | cancelado` e `em_andamento → resolvido | cancelado`; a partir de status final lança erro "Chamado finalizado não pode mudar de status."; outras transições lançam "Transição de status inválida."
    - Comentário obrigatório para `resolvido` e `cancelado`; opcional para `em_andamento`.
    - Grava `ticket_status_changes` (de, para, comentário, usuário) e, se houver morador, dispara `ticket.status_changed` com `{protocol, status, comment}`.
  - **Feature tests:**
    - `tests/Feature/Tickets/ChangeTicketStatusTest.php` → dataset das 5 transições permitidas; `em_andamento → aberto` inválida; qualquer transição a partir de `resolvido`/`cancelado` bloqueada; `resolvido` e `cancelado` sem comentário geram erro; `em_andamento` sem comentário funciona; histórico gravado; webhook só quando há morador; nenhum webhook quando o condomínio não tem URL.
  - **Traces:** US-6.4; US-8.3; ticket_status_changes; ticket_statuses; webhook_deliveries

- [ ] **Task:** Alteração de prioridade
  - **Acceptance criteria:**
    - `TicketService::changePriority(Ticket, string $prioritySlug)` só para chamados não finais; atualiza `ticket_priority_id` sem histórico de status e sem webhook.
  - **Feature tests:**
    - `tests/Feature/Tickets/ChangeTicketPriorityTest.php` → altera prioridade; chamado final bloqueado; nenhuma linha em `ticket_status_changes`; nenhuma entrega de webhook.
  - **Traces:** US-6.6; ticket_priorities

- [ ] **Task:** Aviso ao morador
  - **Acceptance criteria:**
    - `TicketService::notifyResident(Ticket, string $message, User): TicketResidentNotice` exige morador vinculado e mensagem de 1 a 1000 caracteres; permitido em qualquer status.
    - Cria `ticket_resident_notices` e dispara `ticket.resident_notified` com `{protocol, message}`, gravando `webhook_delivery_id` (nulo quando o condomínio não tem webhook).
  - **Feature tests:**
    - `tests/Feature/Tickets/NotifyTicketResidentTest.php` → cria aviso com entrega vinculada; chamado sem morador bloqueado; mensagem vazia e de 1001 caracteres geram erro; chamado resolvido aceita aviso; sem webhook grava aviso com `webhook_delivery_id` nulo.
  - **Traces:** US-6.7; US-8.3; ticket_resident_notices

### Phase 8.2: API

- [ ] **Task:** `POST /api/v1/tickets`
  - **Acceptance criteria:**
    - `multipart/form-data`; `phone` e `description` obrigatórios; `location` até 255; `category` string opcional; `priority` em `alta|media|baixa`; `photos` até 5, cada `mimes:jpg,jpeg,png,webp,heic` e até 10240 KB.
    - Resolve o morador (403 `resident_not_found`); unidade = unidade do morador; origem `whatsapp`.
    - 201 `{protocol:int, status:"aberto", priority, created_at}`; `ToolCallContext::setTicket`.
  - **Feature tests:**
    - `tests/Feature/Api/TicketStoreTest.php` → 201 com protocolo inteiro incremental; prioridade padrão `media`; prioridade inválida 422; morador desconhecido 403; categoria inativa 422 `invalid_category`; 6 fotos 422; foto de 11MB 422; GIF rejeitado; fotos persistidas; log `sucesso` com `entities.ticket_id`.
  - **Traces:** US-6.1; US-9.1; tickets; ticket_photos

- [ ] **Task:** `GET /api/v1/tickets` e `GET /api/v1/tickets/{protocol}`
  - **Acceptance criteria:**
    - Lista: `phone` obrigatório; chamados da unidade do morador; `status` em `open|closed|all` (padrão `all`, inválido 422); mais recentes primeiro; no máximo 10; itens `{protocol, description, category, priority, status, created_at, updated_at}`; lista vazia → `markEmpty()`.
    - Detalhe: busca `protocol_number` dentro da unidade, senão 404 `ticket_not_found`; inclui `history:[{status, at, comment}]` em ordem cronológica (apenas mudanças de status); `setTicket`.
  - **Feature tests:**
    - `tests/Feature/Api/TicketQueryTest.php` → lista só chamados da unidade (outra unidade e outro condomínio fora); filtros `open`/`closed`; limite de 10; histórico cronológico no detalhe; protocolo de outra unidade 404 `ticket_not_found`; morador desconhecido 403; lista vazia grava `vazio`.
  - **Traces:** US-6.2; US-9.1; tickets; ticket_status_changes

### Phase 8.3: Painel

- [ ] **Task:** Lista de chamados com abas e filtros
  - **Acceptance criteria:**
    - Rota `tickets.index` (gate `tickets.operate`); abas Todos, Abertos (`aberto`), Em andamento (`em_andamento`), Concluídos (`resolvido` + `cancelado`) com contagem; botão "+ Novo chamado".
    - Tabela com colunas `90px 2fr 90px 130px 100px 120px 110px`: protocolo `#N` monoespaçado; descrição truncada + origem ("WhatsApp · agente" ou "Painel · <nome>"); unidade (label ou "Área comum"); categoria ou "—"; prioridade com ponto (`alta` `#e5484d`, `media` `#f5a623`, `baixa` `#8e8e93`); pill de status (`aberto` esc, `em_andamento` warn, `resolvido` ok, `cancelado` grey); abertura ("hoje 09:38", "ontem 18:20", "13 set").
    - Filtros por categoria, prioridade, bloco e busca por protocolo ou descrição, refletidos na query string; 25 por página, ordem por abertura desc.
  - **Feature tests:**
    - `tests/Feature/Tickets/TicketIndexTest.php` → contagens das abas; Concluídos inclui resolvidos e cancelados; filtros de prioridade e bloco; busca por número de protocolo; zelador vê todos os chamados; outro condomínio fora.
  - **Design ref:** `.spec/init/design/Sindico Conversacional - Backoffice.dc.html` (`<!-- CHAMADOS -->` tabs e tabela)
  - **Traces:** US-6.3; tickets

- [ ] **Task:** Drawer de detalhe do chamado
  - **Acceptance criteria:**
    - Clique na linha abre `x-ui.drawer` (query `?chamado=N`): protocolo + pill de status + fechar; título (descrição truncada em 80 caracteres); meta "unidade · categoria · origem".
    - Seções: "Descrição gerada pelo agente" (origem whatsapp) ou "Descrição" (painel); "Fotos enviadas" em grade 2×, proporção 4:3, abrindo imagem em tamanho real por rota privada autorizada ao condomínio atual; "Linha do tempo" unindo `ticket_status_changes` e `ticket_resident_notices` em ordem cronológica ("Chamado aberto pelo agente", "Chamado aberto manualmente", "Em andamento · <comentário>", "Aviso ao morador: <mensagem>").
  - **Feature tests:**
    - `tests/Feature/Tickets/TicketDrawerTest.php` → linha do tempo intercala status e avisos na ordem certa; rota da foto responde para o mesmo condomínio e 404 para outro; rótulo da descrição muda com a origem.
  - **Design ref:** `.spec/init/design/Sindico Conversacional - Backoffice.dc.html` (`<!-- CHAMADOS -->` drawer)
  - **Traces:** US-6.3; ticket_photos; ticket_status_changes; ticket_resident_notices

- [ ] **Task:** Ações do drawer: status, prioridade e aviso ao morador
  - **Acceptance criteria:**
    - Botão principal por status: `aberto` → "Iniciar atendimento" (`em_andamento`, comentário opcional); `em_andamento` → "Marcar concluído" (modal com comentário obrigatório → `resolvido`); ação secundária "Cancelar chamado" (comentário obrigatório) em não finais; chamados finais não mostram ação de transição.
    - Seletor de prioridade no drawer em chamados não finais.
    - Botão "Avisar morador" desabilitado (com dica) sem morador; modal com textarea e contador 0/1000; após enviar mostra toast "Aviso enviado ao morador." ou "Aviso registrado — condomínio sem webhook configurado."
    - Lista, contagens e linha do tempo atualizam após cada ação; erros do `TicketService` aparecem em pt-BR.
  - **Feature tests:**
    - `tests/Feature/Tickets/TicketDrawerActionsTest.php` → iniciar atendimento muda para `em_andamento`; concluir sem comentário mostra erro; cancelar com comentário funciona; chamado final não renderiza ações de transição nem seletor de prioridade; troca de prioridade persiste; aviso cria registro; botão desabilitado sem morador; toast de "sem webhook"; zelador executa as ações.
  - **Design ref:** `.spec/init/design/Sindico Conversacional - Backoffice.dc.html` (`<!-- CHAMADOS -->` rodapé do drawer: ação principal e "Avisar morador")
  - **Traces:** US-6.4; US-6.6; US-6.7

- [ ] **Task:** Novo chamado pelo painel
  - **Acceptance criteria:**
    - Modal "+ Novo chamado": descrição (obrigatória), local, categoria (só ativas), prioridade (padrão Média), unidade opcional, morador opcional filtrado pela unidade, fotos (até 5, 10MB, jpg/png/webp/heic) com prévia.
    - Usa `TicketService::open` com origem `painel` e `opened_by_user_id`; ao salvar abre o drawer do novo chamado.
  - **Feature tests:**
    - `tests/Feature/Tickets/CreateTicketFromPanelTest.php` → cria chamado `painel` com protocolo da mesma sequência; morador fora da unidade gera erro; categoria inativa rejeitada; nenhum webhook; zelador consegue abrir.
  - **Design ref:** `.spec/init/design/Sindico Conversacional - Backoffice.dc.html` (`<!-- CHAMADOS -->` "+ Novo chamado") + `x-ui.modal`
  - **Traces:** US-6.5; tickets

---

## Phase 9: Áreas comuns e reservas

**Goal:** regras de antecedência e conflito, tools de reserva, cadastro de áreas, agenda semanal, reserva manual e cancelamentos. · **Depends on:** Phase 6 · **Covers:** US-7.1, US-7.2, US-7.3, US-7.4, US-7.5, US-7.6, US-7.7, US-7.8, US-8.3 (evento de reserva)

**Notas para o agente:**
- Rodar via `./vendor/bin/sail`. Skills: `laravel-best-practices`, `livewire-development`, `tailwindcss-development`, `testing-best-practices`.
- Regras em `app/Services/Reservations/ReservationService.php`. "Hoje" e horários sempre em `America/Sao_Paulo` (`config('condo.timezone')`); usar `travelTo` nos testes.
- Rotas `api.v1.areas_list|areas_availability|reservations_create|reservations_list|reservations_cancel`.
- Design: `.spec/init/design/Sindico Conversacional - Backoffice.dc.html` `<!-- RESERVAS -->` e `<!-- CONFIG -->`. Não construir: status "Aguardando"/"Recusada", capacidade da área, aprovação.

### Phase 9.1: Domínio

- [ ] **Task:** Regras de antecedência, disponibilidade e prazo de cancelamento
  - **Acceptance criteria:**
    - `ReservationService::slotStart(CommonAreaSlot, date)` retorna data + início no fuso do condomínio.
    - `withinAdvanceWindow`: `slotStart − agora ≥ min_advance_hours` **e** `data ≤ hoje + max_advance_days`.
    - `isSlotTaken(slot, date)`: existe reserva com `cancelled_at` nulo.
    - `availability(CommonArea, date)`: faixas não excluídas com `available = !taken && withinWindow`.
    - `isCancellableByResident(Reservation)`: `slotStart − agora ≥ cancellation_deadline_hours`.
  - **Feature tests:**
    - `tests/Feature/Reservations/ReservationRulesTest.php` → exatamente 24h antes é permitido e 23h59 não; hoje + 60 dias permitido e + 61 não; faixa ocupada indisponível; reserva cancelada libera a faixa; prazo de cancelamento de 24h no limite; às 22:30 de São Paulo (01:30 UTC do dia seguinte) "hoje" continua sendo a data de São Paulo.
  - **Traces:** US-7.2; US-7.3; US-7.5; common_areas; common_area_slots

- [ ] **Task:** Criação de reserva (API e manual)
  - **Acceptance criteria:**
    - `ReservationService::create(CommonArea, CommonAreaSlot, date, Resident, string $origin, ?User)`.
    - Área inativa, faixa de outra área ou faixa excluída → `AreaUnavailable`.
    - Origem `whatsapp`: fora da janela → `AdvanceNoticeViolation` com limites. Origem `painel`: ignora a janela, mas data anterior a hoje → erro "Não é possível reservar uma data passada."
    - Faixa ocupada (verificação prévia) ou violação do índice parcial capturada → `SlotUnavailable`.
    - Grava `confirmada`, origem, `created_by_user_id` (painel), snapshot de `starts_at`/`ends_at`, morador e unidade do morador; não dispara webhook.
  - **Feature tests:**
    - `tests/Feature/Reservations/CreateReservationTest.php` → caminho feliz via WhatsApp; área inativa `area_unavailable`; faixa de outra área `area_unavailable`; dentro da antecedência mínima `advance_notice_violation` com limites; além da máxima idem; faixa ocupada `slot_unavailable`; corrida simulada (reserva concorrente inserida após a verificação prévia) resulta em `slot_unavailable` e só uma linha; painel ignora antecedência mas recusa data passada; snapshot de horário copiado; nenhuma entrega de webhook.
  - **Traces:** US-7.3; US-7.8; reservations; reservation_origins; reservation_statuses

- [ ] **Task:** Cancelamentos pelo morador e pelo síndico
  - **Acceptance criteria:**
    - `cancelByResident(Reservation, Resident)`: reserva `confirmada` da unidade do morador, senão `ReservationNotFound`; fora do prazo → `CancellationDeadlinePassed`; grava `cancelada`, `cancelled_at`, origem `morador`; sem webhook.
    - `cancelBySyndic(Reservation, string $reason, User)`: reserva `confirmada` com data ≥ hoje, motivo obrigatório, sem prazo; grava origem `sindico`, `cancelled_by_user_id`, `cancellation_reason`; dispara `reservation.cancelled` com `{reservation_id, area, date, starts, ends, reason}`.
  - **Feature tests:**
    - `tests/Feature/Reservations/CancelReservationTest.php` → morador cancela dentro do prazo; fora do prazo lança `cancellation_deadline_passed`; reserva de outra unidade e reserva já cancelada lançam `reservation_not_found`; síndico cancela dentro do prazo do morador; síndico não cancela reserva passada; motivo obrigatório; webhook só no cancelamento pelo síndico.
  - **Traces:** US-7.5; US-7.7; US-8.3; reservation_cancellation_origins; reservations

### Phase 9.2: API

- [ ] **Task:** `GET /api/v1/areas` e `GET /api/v1/areas/{id}/availability`
  - **Acceptance criteria:**
    - Áreas ativas: `{id, name, description, slots:[{id, starts, ends}], rules:{min_advance_hours, max_advance_days, cancellation_deadline_hours}}`; lista vazia → `markEmpty()`.
    - Disponibilidade: `date` obrigatório `Y-m-d` (inválido 422); área inexistente, inativa ou de outro condomínio → 404 `area_not_found`; resposta `{area:{id,name}, date, slots:[{id, starts, ends, available}]}`.
  - **Feature tests:**
    - `tests/Feature/Api/AreaAvailabilityTest.php` → área inativa não listada; faixa ocupada e faixa fora da janela vêm `available:false`; área inativa 404 `area_not_found`; data inválida 422; faixas excluídas não aparecem.
  - **Traces:** US-7.2; US-9.1; common_areas; common_area_slots

- [ ] **Task:** `POST`, `GET` e `DELETE /api/v1/reservations`
  - **Acceptance criteria:**
    - `POST`: `phone`, `area_id`, `slot_id`, `date` obrigatórios → `create` com origem `whatsapp` → 201 `{id, status:"confirmada", area, date, starts, ends}`; `setReservation`.
    - `GET ?phone=`: reservas `confirmada` da unidade com data ≥ hoje, ordenadas por data e início: `{id, area, date, starts, ends, resident, cancellable}`; vazia → `markEmpty()`.
    - `DELETE /reservations/{id}?phone=` → `cancelByResident` → 200 `{id, status:"cancelada"}`; `setReservation`.
  - **Feature tests:**
    - `tests/Feature/Api/ReservationApiTest.php` → 201 no formato; cada recusa (403, `area_unavailable`, `advance_notice_violation` com limites, `slot_unavailable`) sem criar linha e com log `recusa` + `error_code`; listagem só futuras confirmadas da unidade, incluindo reservas manuais, com `cancellable` correto; cancelar dentro do prazo e ver a faixa disponível; cancelar fora do prazo 422 `cancellation_deadline_passed`; cancelar reserva de outra unidade 404; `entities.reservation_id` no log.
  - **Traces:** US-7.3; US-7.4; US-7.5; US-9.1; reservations

### Phase 9.3: Painel

- [ ] **Task:** Cadastro de áreas comuns e faixas
  - **Acceptance criteria:**
    - Card "Áreas comuns" em Configurações (gate `reservations.manage`): linha por área com nome, resumo ("24 h · até 60 dias · cancela até 24 h") e "Editar"; link "+ Adicionar".
    - Modal: nome (único), descrição, ativa, antecedência mínima em horas (padrão 24, inteiro ≥ 0), máxima em dias (padrão 60, inteiro ≥ 1), prazo de cancelamento em horas (padrão 24, inteiro ≥ 0) e lista de faixas (início/fim `HH:MM`).
    - Validações: início < fim; faixas sem sobreposição; área ativa exige ≥ 1 faixa; faixa com reserva futura não cancelada não pode ser removida nem ter horário alterado (mensagem lista as datas); remover faixa faz soft delete.
  - **Feature tests:**
    - `tests/Feature/Reservations/CommonAreaManagementTest.php` → padrões 24/60/24; sobreposição gera erro; início ≥ fim gera erro; ativar sem faixas gera erro; remover ou alterar faixa com reserva futura bloqueado; remover faixa só com reservas passadas faz soft delete; nome duplicado gera erro; zelador recebe 403; área desativada some da API.
  - **Design ref:** `.spec/init/design/Sindico Conversacional - Backoffice.dc.html` (`<!-- CONFIG -->` card "Áreas comuns") + `x-ui.modal`
  - **Traces:** US-7.1; common_areas; common_area_slots

- [ ] **Task:** Agenda semanal de reservas
  - **Acceptance criteria:**
    - Rota `reservations.index` (gate `reservations.manage`); cabeçalho com ‹ "14 – 20 set 2026" › (segunda a domingo, fuso do condomínio) e botão "+ Reserva manual".
    - Grade `140px + 7 colunas`: rótulos "seg 14"… com hoje em accent 600; linhas = áreas ativas (nome + descrição curta em cinza no lugar da capacidade); células com chips das reservas não canceladas ("19h–23h" + "Sobrenome · unidade"), origem `whatsapp` em paleta ok e `painel` em paleta ia.
    - Clique no chip abre popover/modal com data, faixa, área, unidade, morador, origem e ação "Cancelar reserva".
    - Coluna direita: card "Pedidos pelo WhatsApp" (últimas 10 reservas de origem `whatsapp`: nome · unidade, "Área · dia dd/mm · faixa", pill Confirmada ok / Cancelada grey e nota "Cancelada pelo morador"/"Cancelada pelo síndico"); card "Regras aplicadas pela tool" com texto "O agente só confirma se reservar_area aceitar." e uma linha por área ativa (faixas, antecedência, prazo de cancelamento).
  - **Feature tests:**
    - `tests/Feature/Reservations/ReservationAgendaTest.php` → semana de segunda a domingo; canceladas não aparecem na grade; navegação para a semana seguinte; card do WhatsApp limitado a 10 e só origem `whatsapp`; zelador recebe 403.
  - **Design ref:** `.spec/init/design/Sindico Conversacional - Backoffice.dc.html` (`<!-- RESERVAS -->`)
  - **Traces:** US-7.6; reservations

- [ ] **Task:** Reserva manual e cancelamento pelo painel
  - **Acceptance criteria:**
    - Modal "+ Reserva manual": área (ativas), data (≥ hoje), faixa (da área, ocupadas desabilitadas), unidade e morador ativo da unidade → `create` com origem `painel`; erros em pt-BR ("Faixa já reservada nesta data").
    - "Cancelar reserva": modal com motivo obrigatório → `cancelBySyndic`; toast de sucesso; grade e cards atualizam.
  - **Feature tests:**
    - `tests/Feature/Reservations/ReservationPanelActionsTest.php` → reserva manual dentro da antecedência mínima é criada com origem `painel` e `created_by_user_id`; faixa ocupada gera erro; data passada gera erro; morador de outra unidade gera erro; cancelamento exige motivo e cria entrega de webhook; reserva manual aparece em `GET /api/v1/reservations` do morador.
  - **Design ref:** `.spec/init/design/Sindico Conversacional - Backoffice.dc.html` (`<!-- RESERVAS -->` "+ Reserva manual") + `x-ui.modal`
  - **Traces:** US-7.7; US-7.8; reservations

---

## Phase 10: Escalonamentos

**Goal:** agente escala com motivo; equipe assume, reassume e responde pela fila; morador é avisado. · **Depends on:** Phase 8 · **Covers:** US-8.1, US-8.2, US-8.4, US-8.3 (evento de escalonamento)

**Notas para o agente:**
- Rodar via `./vendor/bin/sail`. Skills: `laravel-best-practices`, `livewire-development`, `tailwindcss-development`, `testing-best-practices`.
- Regras em `app/Services/Escalations/EscalationService.php`; rota `api.v1.escalations_create`; gate `escalations.reassign` (Phase 4) para reassumir.
- Design: `.spec/init/design/Sindico Conversacional - Backoffice.dc.html` `<!-- ESCALONAMENTOS -->` e `<!-- Sidebar -->`. Não construir: "Última mensagem", "Ver conversa", "Abrir conversa", "Devolver à IA".

### Phase 10.1: API e regras

- [ ] **Task:** `POST /api/v1/escalations`
  - **Acceptance criteria:**
    - `phone`, `reason` (slug de `escalation_reasons`) e `summary` obrigatórios; `ticket_protocol` inteiro opcional.
    - Morador desconhecido 403; protocolo inexistente ou de outra unidade → 422 `ticket_not_found`.
    - Cria `pendente` com morador, unidade, motivo e chamado; 201 `{id, status:"pendente"}`; `setEscalation`.
  - **Feature tests:**
    - `tests/Feature/Api/EscalationStoreTest.php` → 201 no formato; `reason` inválido 422 `validation_error`; sem `summary` 422; morador desconhecido 403; chamado de outra unidade 422 `ticket_not_found`; chamado válido vinculado; log com `entities.escalation_id`.
  - **Traces:** US-8.1; US-9.1; escalations; escalation_reasons

- [ ] **Task:** Assumir e reassumir escalonamento
  - **Acceptance criteria:**
    - `EscalationService::assign(Escalation, User)`: `pendente` → `em_atendimento` com `assigned_user_id`, `assigned_at` e linha em `escalation_assignments` (`previous_user_id` nulo).
    - Em `em_atendimento` de outro usuário: permitido só com gate `escalations.reassign`, gravando `previous_user_id`; zelador recebe `AuthorizationException` (403); o próprio responsável assumir de novo não altera nada.
    - `resolvido` não pode ser assumido; assumir não dispara webhook.
  - **Feature tests:**
    - `tests/Feature/Escalations/AssignEscalationTest.php` → zelador assume pendente; síndico assume no lugar do zelador com histórico `previous_user_id`; zelador não assume o de outro (403); resolvido não pode ser assumido; nenhuma entrega de webhook.
  - **Traces:** US-8.4; escalation_assignments; escalations

- [ ] **Task:** Responder escalonamento
  - **Acceptance criteria:**
    - `EscalationService::answer(Escalation, string $response, User)`: exige `em_atendimento` e usuário = responsável; resposta obrigatória.
    - Grava `response`, `responded_by_user_id`, `resolved_at`, status `resolvido` e dispara `escalation.answered` com `{escalation_id, reason, response}`.
  - **Feature tests:**
    - `tests/Feature/Escalations/AnswerEscalationTest.php` → responsável responde e o escalonamento vira `resolvido` com entrega de webhook; `pendente` não pode ser respondido; quem não é responsável (inclusive síndico) não responde; `resolvido` não é respondido de novo; resposta vazia gera erro.
  - **Traces:** US-8.2; US-8.3; escalations

### Phase 10.2: Painel

- [ ] **Task:** Tela da fila de escalonamentos
  - **Acceptance criteria:**
    - Rota `escalations.index` (gate `escalations.operate`); texto de apoio "O agente entregou estas conversas porque a regra não cobria, a tool recusou ou o morador pediu um humano."; filtros "Em aberto" (padrão: `pendente` + `em_atendimento`) e "Resolvidos".
    - Cards (grade `1fr 170px`), mais antigos primeiro: ponto (`#e5484d` para `pediu_humano`, `#f5a623` para os demais), nome · unidade, pill do motivo ("Pediu humano", "Tool recusou", "Sem regra"), "· esperando 14 min" / "1 h 05", resumo e link "Chamado #N" que abre o drawer do chamado.
    - Ações: `pendente` → botão "Assumir"; `em_atendimento` do usuário → pill "Com você" + "Responder" (modal com resposta obrigatória); `em_atendimento` de outro → pill "Com <nome>" e, com `escalations.reassign`, botão secundário "Assumir"; resolvidos mostram resposta, quem respondeu e data.
  - **Feature tests:**
    - `tests/Feature/Escalations/EscalationQueueTest.php` → ordem do mais antigo; resolvidos ocultos no filtro padrão; ações renderizadas por estado, usuário e papel; responder pelo modal resolve; outro condomínio fora.
  - **Design ref:** `.spec/init/design/Sindico Conversacional - Backoffice.dc.html` (`<!-- ESCALONAMENTOS -->`)
  - **Traces:** US-8.2; US-8.4

- [ ] **Task:** Badge de pendentes no menu
  - **Acceptance criteria:**
    - Item "Escalonamentos" da sidebar mostra `count-badge` com a quantidade de `pendente` do condomínio atual; oculto quando 0; atualizado após assumir ou responder (evento Livewire) e a cada navegação.
  - **Feature tests:**
    - `tests/Feature/Escalations/EscalationBadgeTest.php` → badge conta só `pendente` (não `em_atendimento`); não renderiza com zero; outro condomínio não conta.
  - **Design ref:** `.spec/init/design/Sindico Conversacional - Backoffice.dc.html` (`<!-- Sidebar -->` badge)
  - **Traces:** US-8.2

---

## Phase 11: Regimento e convenção

**Goal:** PDF vira artigos revisados, publicados e indexados; agente busca com citação; síndico testa perguntas. · **Depends on:** Phase 6 · **Covers:** US-4.1, US-4.2, US-4.3, US-4.4, US-4.5, US-9.4 (citado N×)

**Notas para o agente:**
- Rodar via `./vendor/bin/sail`. Skills: `laravel-best-practices`, `livewire-development`, `tailwindcss-development`, `testing-best-practices`. Consultar `search-docs` do AI SDK para gerar e **simular (fake)** embeddings nos testes.
- APIs do Laravel 13: `Schema::ensureVectorExtensionExists()`, `$table->vector('embedding', dimensions: 1536)->index()`, `Laravel\Ai\Embeddings::for([...])->generate()`, `whereVectorSimilarTo('embedding', $vetor, minSimilarity: 0.5)`, `selectVectorDistance`.
- Serviços em `app/Services/RuleDocuments`; rota `api.v1.rules_search`. PDFs em disco privado `local`, caminho `rule-documents/{condominium_id}/`.
- Design: `.spec/init/design/Sindico Conversacional - Backoffice.dc.html` `<!-- REGIMENTO -->`. "Testar pergunta" não gera resposta por LLM.

### Phase 11.1: Upload e extração

- [ ] **Task:** Instalar `laravel/ai` e o parser de PDF
  - **Acceptance criteria:**
    - `./vendor/bin/sail composer require laravel/ai smalot/pdfparser` (dependências aprovadas); config do AI SDK publicada com provider OpenAI, modelo `text-embedding-3-small`, 1536 dimensões; `OPENAI_API_KEY` em `.env.example`.
    - `php.ini` do Sail com `upload_max_filesize` e `post_max_size` de 100M, já que a aplicação não limita o tamanho do PDF.
    - Suíte existente continua verde.
  - **Traces:** Tech Stack (RAG / embeddings, Extração de PDF); US-4.1

- [ ] **Task:** Divisor de artigos
  - **Acceptance criteria:**
    - `RuleArticleSplitter::split(string $text): array` separa em cabeçalhos no início de linha que casam `Art.`/`Artigo` + número (com `º`, `o` ou `°` opcional), `Cláusula`/`Clausula`/`Cl.` + número (com `ª`/`a` opcional).
    - `reference` normalizada ("Art. 14", "Cl. 9ª"); `title` = texto após "-", "–" ou ":" na linha do cabeçalho quando tiver até 80 caracteres, senão nulo; `body` = restante com espaços normalizados; parágrafos (§) ficam no corpo do artigo.
    - Texto antes do primeiro cabeçalho vira artigo "Preâmbulo" se tiver ≥ 200 caracteres, senão é descartado; sem nenhum cabeçalho → um único artigo "Documento" com o texto inteiro; `position` sequencial a partir de 1.
  - **Feature tests:**
    - `tests/Feature/RuleDocuments/RuleArticleSplitterTest.php` → dataset: "Art. 1º", "Artigo 3", "Cláusula 9ª", "Art. 14 - Obras e reformas" (título extraído), texto sem cabeçalho (artigo único), § mantido no corpo, preâmbulo curto descartado.
  - **Traces:** US-4.1; rule_articles

- [ ] **Task:** Upload do PDF e job de extração
  - **Acceptance criteria:**
    - Modal "+ Enviar PDF" (gate `knowledge.manage`): tipo (`regimento`|`convencao`) e título obrigatórios, arquivo `mimes:pdf` sem regra de tamanho máximo.
    - Salva o arquivo, cria `RuleDocument` `processando` com `uploaded_by_user_id` e enfileira `ExtractRuleArticles`.
    - Job: extrai texto com o parser; texto vazio → `falha_extracao` com "Não foi possível extrair texto do PDF (pode ser um documento escaneado)."; exceção do parser → `falha_extracao` com a mensagem; sucesso → cria artigos via `RuleArticleSplitter` e muda para `em_revisao`.
    - Documento em `falha_extracao` aceita "Reenviar PDF", que substitui o arquivo e reenfileira o job.
    - Download do PDF por rota privada autorizada ao condomínio atual.
  - **Feature tests:**
    - `tests/Feature/RuleDocuments/UploadRuleDocumentTest.php` → arquivo não PDF rejeitado; upload cria `processando` e enfileira job; job com `tests/Fixtures/regimento.pdf` (dois artigos) gera `em_revisao` com 2 artigos na ordem; `tests/Fixtures/sem-texto.pdf` gera `falha_extracao` com mensagem; exceção do parser gera `falha_extracao`; reenviar reprocessa; download de outro condomínio 404; zelador recebe 403.
  - **Design ref:** `.spec/init/design/Sindico Conversacional - Backoffice.dc.html` (`<!-- REGIMENTO -->` "+ Enviar PDF") + `x-ui.modal`
  - **Traces:** US-4.1; rule_documents; document_types; document_statuses

### Phase 11.2: Revisão e publicação

- [ ] **Task:** Tela Regimento: documentos e artigos
  - **Acceptance criteria:**
    - Rota `rule-documents.index` (gate `knowledge.manage`), grade `240px 1fr 340px`.
    - Coluna esquerda: botão por documento (nome e meta "Regimento · Publicado · 48 artigos"; status em pt-BR: Processando, Em revisão, Falha na extração, Indexando, Falha na indexação, Publicado, Substituído), selecionado com fundo branco e borda; botão tracejado "+ Enviar PDF".
    - Coluna central: cabeçalho com título e pill por status (publicado → ok "Indexado · N trechos" com N = artigos com embedding; em revisão → warn; processando/indexando → grey com atualização a cada 5s; falhas → esc com `processing_error` e ação "Reenviar PDF"/"Tentar novamente"); link para baixar o PDF.
    - Linhas de artigo em grade `64px 1fr auto`: referência em accent, título + texto, "citado N×" (documentos publicados e substituídos) contado por `AgentToolCall::forArticle`.
  - **Feature tests:**
    - `tests/Feature/RuleDocuments/RuleDocumentScreenTest.php` → lista documentos do condomínio com rótulos de status; "citado N×" igual à contagem de tool calls com o artigo; atualização periódica só em `processando`/`indexando`; zelador recebe 403.
  - **Design ref:** `.spec/init/design/Sindico Conversacional - Backoffice.dc.html` (`<!-- REGIMENTO -->` lista de documentos e artigos)
  - **Traces:** US-4.1; US-4.3; US-9.4; rule_documents; rule_articles; agent_tool_calls

- [ ] **Task:** Revisão de artigos
  - **Acceptance criteria:**
    - Em documento `em_revisao`: edição inline de referência, título e texto com salvar; "+ Artigo" adiciona ao final; excluir artigo; mover para cima/baixo reordenando `position` contíguo.
    - Referência e texto obrigatórios; botão "Publicar" desabilitado sem artigos.
    - Documentos `publicado` e `substituido` são somente leitura (sem controles de edição e ações bloqueadas no servidor).
  - **Feature tests:**
    - `tests/Feature/RuleDocuments/ReviewArticlesTest.php` → edição salva; sem referência gera erro; adicionar e excluir; reordenar mantém posições contíguas; publicar sem artigos bloqueado; editar artigo de documento publicado é recusado.
  - **Design ref:** `.spec/init/design/Sindico Conversacional - Backoffice.dc.html` (`<!-- REGIMENTO -->` lista de artigos) + `x-ui.input`/`x-ui.textarea`
  - **Traces:** US-4.2; rule_articles

- [ ] **Task:** Publicação e indexação dos artigos
  - **Acceptance criteria:**
    - `RuleDocumentService::publish(RuleDocument, User)`: aceita `em_revisao` ou `falha_indexacao` com ≥ 1 artigo; muda para `indexando`, grava `published_by_user_id` e enfileira `IndexRuleArticles`.
    - Job: gera embeddings em lotes de até 100 artigos com `Embeddings::for()` usando "referência + título + texto"; grava `embedding` e `embedded_at`.
    - Com todos os artigos indexados, em transação: documento `publicado` anterior do mesmo tipo e condomínio → `substituido`; este → `publicado` com `published_at`.
    - Exceção → `falha_indexacao` com `processing_error`; o documento não entra na busca e pode ser publicado de novo.
  - **Feature tests:**
    - `tests/Feature/RuleDocuments/PublishRuleDocumentTest.php` (embeddings simulados) → publica e grava embeddings de 1536 dimensões; publicado anterior do mesmo tipo vira `substituido` e o de outro tipo não muda; falha do provider gera `falha_indexacao` e documento fora da busca; nova tentativa publica; sem artigos bloqueado; nunca há dois `publicado` do mesmo tipo no condomínio.
  - **Traces:** US-4.3; rule_documents; rule_articles; document_statuses

### Phase 11.3: Busca e teste de pergunta

- [ ] **Task:** Serviço de busca de artigos
  - **Acceptance criteria:**
    - `RuleSearchService::search(string $query, int $limit): array` gera o embedding da pergunta, consulta `rule_articles` de documentos `publicado` do condomínio atual com `whereVectorSimilarTo(minSimilarity: config('condo.rag.min_similarity'))` e limite.
    - Cada resultado traz artigo, documento e `score` (similaridade 0–1, 3 casas); também devolve a latência em ms.
  - **Feature tests:**
    - `tests/Feature/RuleDocuments/RuleSearchServiceTest.php` (vetores controlados) → exclui documentos `em_revisao`, `substituido` e de outro condomínio; resultados abaixo de 0.5 descartados; ordem por score desc; limite respeitado.
  - **Traces:** US-4.4; rule_articles

- [ ] **Task:** `POST /api/v1/rules/search`
  - **Acceptance criteria:**
    - `query` string obrigatória não vazia; `limit` inteiro 1–10 (padrão 5; acima de 10 → 422).
    - 200 `{results:[{document:{id,type,title}, article:{id,reference,title}, text, score}]}`; sem resultado → `results: []` e `markEmpty()`; `addArticles` com os ids na ordem retornada.
  - **Feature tests:**
    - `tests/Feature/Api/RuleSearchTest.php` → formato da resposta; `limit` 11 responde 422; `query` vazia 422; sem resultado retorna `[]` e grava `vazio`; `entities.article_ids` no log; documentos de outro condomínio nunca retornam.
  - **Traces:** US-4.4; US-9.1; rule_articles; agent_tool_calls

- [ ] **Task:** Card "Testar pergunta"
  - **Acceptance criteria:**
    - Coluna direita da tela Regimento: título "Testar pergunta", subtítulo "Simula o que o agente encontraria no WhatsApp.", campo + botão "Testar" e sugestões clicáveis "Posso fechar a sacada?", "Até que horas pode barulho?", "Posso ter cachorro grande?".
    - Resultado: bolha da pergunta (`#dcf8c6`), bolha com "Documento · Referência" em accent e trecho (até 200 caracteres) com borda esquerda accent, e rodapé monoespaçado "consultar_regimento · 412 ms · score 0.93"; sem resultado → "Nenhum artigo encontrado".
    - Sem documento publicado → aviso "Publique o regimento ou a convenção para testar." e botão desabilitado.
    - Usa `RuleSearchService` diretamente e não grava `agent_tool_calls`.
  - **Feature tests:**
    - `tests/Feature/RuleDocuments/TestQuestionTest.php` → mostra o melhor resultado com score e latência; sem documento publicado não chama embeddings e mostra aviso; nenhum registro em `agent_tool_calls`.
  - **Design ref:** `.spec/init/design/Sindico Conversacional - Backoffice.dc.html` (`<!-- REGIMENTO -->` card "Testar pergunta")
  - **Traces:** US-4.5

---

## Phase 12: Visão geral

**Goal:** tela inicial com indicadores do dia, atividade do agente, fila humana e resolução por tool; fechamento do release. · **Depends on:** Phase 7, Phase 8, Phase 9, Phase 10, Phase 11 · **Covers:** US-9.2, US-9.3, US-9.4, US-1.1, US-1.2

**Notas para o agente:**
- Rodar via `./vendor/bin/sail`. Skills: `laravel-best-practices`, `livewire-development`, `tailwindcss-development`, `testing-best-practices`.
- Métricas calculadas por consulta (nada pré-agregado) em `app/Services/Dashboard`; "hoje" = dia em `America/Sao_Paulo`; usar `travelTo` nos testes.
- Design: `.spec/init/design/Sindico Conversacional - Backoffice.dc.html` `<!-- DASHBOARD -->`. Não construir: "Conversas hoje", "Ver conversas", intenções.
- Esta fase fecha o MVP: suíte completa, Pint e Larastan verdes.

### Phase 12.1: Métricas

- [ ] **Task:** `DashboardMetricsService`
  - **Acceptance criteria:**
    - `attendancesToday`: moradores distintos (por `resident_id`, ou `phone` quando não identificado) com tool call hoje; `attendancesYesterday` idem; `deltaPercent` = variação arredondada, `null` quando ontem = 0.
    - `resolvedByAgent`: dos atendidos hoje, quantos não têm escalonamento criado hoje (atendimentos só por telefone contam como resolvidos); retorna `resolved`, `total` e `percent` (`null` com 0 atendimentos).
    - `openTickets` (`aberto` + `em_andamento`) e `urgentTickets` (desses, prioridade `alta`).
    - `waitingEscalations` (`pendente` + `em_atendimento`) e `averageWaitMinutes` (média de agora − `created_at`).
    - `recentActivity`: últimas 6 tool calls com morador, unidade, tool, resultado e entities.
    - `waitingTop`: 3 escalonamentos não resolvidos mais antigos.
    - `resolutionByTool7d`: `%` de `sucesso` nos últimos 7 dias para Regimento/convenção (`rules_search`), Comunicados (`notices_list`), Abrir chamado (`tickets_create`), Status de chamado (`tickets_list` + `tickets_show`) e Reservar área (`reservations_create`); `null` sem chamadas.
  - **Feature tests:**
    - `tests/Feature/Dashboard/DashboardMetricsTest.php` → chamada às 23:30 de São Paulo conta hoje e às 00:30 do dia seguinte não; moradores distintos; delta e ontem zerado; resolvidos excluem moradores escalados hoje; urgentes só abertos de prioridade alta; média de espera; atividade limitada às 6 mais recentes; percentuais por tool com agrupamento de status de chamado; `null` sem chamadas; isolamento entre condomínios.
  - **Traces:** US-9.2; US-9.3; agent_tool_calls; tickets; escalations

- [ ] **Task:** Apresentação das tool calls
  - **Acceptance criteria:**
    - `App\Support\ToolCallPresenter` devolve `who` ("Primeiro nome · unidade" ou telefone formatado), `text` e `pill` (texto + variante):
    - `rules_search` sucesso → "consultou o regimento" + ia "Regimento · <referência do 1º artigo>"; vazio → "consultou o regimento sem resultado" + grey "Sem artigo".
    - `notices_list` → "consultou comunicados" + ia "Comunicados".
    - `tickets_create` → "abriu chamado" + warn "Chamado #N"; `tickets_list`/`tickets_show` → "consultou status de chamado" + grey "Chamado #N" (ou "Chamados").
    - `areas_list`/`areas_availability` → "consultou disponibilidade" + grey "Áreas"; `reservations_create` sucesso → "reservou <área> dd/mm" + ok "Reserva confirmada"; `reservations_list` → "consultou reservas" + grey "Reservas"; `reservations_cancel` sucesso → "cancelou reserva" + grey "Reserva cancelada".
    - `escalations_create` → "pediu atendimento humano" + esc "Escalado"; `residents_lookup` → "foi identificado" + grey "Verificação".
    - Qualquer `recusa` → texto da tool + esc "Recusada".
  - **Feature tests:**
    - `tests/Feature/Dashboard/ToolCallPresenterTest.php` → dataset cobrindo cada tool e resultado, incluindo morador não identificado.
  - **Traces:** US-9.3; agent_tool_calls; agent_tools

### Phase 12.2: Tela e fechamento

- [ ] **Task:** Tela Visão geral
  - **Acceptance criteria:**
    - Substitui a página provisória da Phase 4; visível para os três papéis (super admin vê o condomínio selecionado); atualização a cada 60s.
    - Linha de 4 `kpi-card`: "Atendimentos hoje" (valor + "+12% vs. ontem" verde/vermelho ou "—"), "Resolvidas pelo agente" ("89%" + "42 de 47 sem humano"), "Chamados abertos" (+ "2 urgentes" em `#b3261e`), "Aguardando síndico" (+ "tempo médio 14 min").
    - Segunda linha `1.6fr 1fr`: card "Atividade do agente" com linhas `56px 1fr auto` (hora `HH:MM` tabular, quem · texto, pill); à direita card "Aguardando humano" (ponto, nome · unidade, motivo, espera) com link "Ver fila" para `escalations.index`, e card "Resolução por tool · 7 dias" com barras (label 150px, barra accent de 6px com largura = percentual, percentual à direita ou "—").
    - Não renderiza "Ver conversas".
  - **Feature tests:**
    - `tests/Feature/Dashboard/DashboardPageTest.php` → números da tela batem com o serviço para o cenário do `DemoSeeder`; zelador acessa com 200; "Ver conversas" ausente; "Ver fila" aponta para a fila.
  - **Design ref:** `.spec/init/design/Sindico Conversacional - Backoffice.dc.html` (`<!-- DASHBOARD -->`)
  - **Traces:** US-9.2; US-9.3

- [ ] **Task:** Verificação final de permissões e contadores
  - **Acceptance criteria:**
    - `RoleAccessTest` (Phase 4) estendido com todas as rotas de painel criadas nas Phases 5–12 e as respostas esperadas por papel.
    - Um mesmo conjunto de tool calls produz contagens coerentes em Moradores (interações), Regimento ("citado N×") e Integração (chamadas/7d).
    - `./vendor/bin/sail artisan test --compact`, `./vendor/bin/sail composer lint:check` e `./vendor/bin/sail composer types:check` passam.
  - **Feature tests:**
    - `tests/Feature/Auth/RoleAccessTest.php` → dataset rota × papel completo (200/403) e acesso cross-tenant 404.
    - `tests/Feature/Dashboard/ToolCallCountersTest.php` → mesmas tool calls geram as contagens esperadas nas três telas.
  - **Traces:** US-9.4; US-1.1; US-1.2
