# Síndico Conversacional — Database Schema

<!-- inputs: project-description.md@sha256:4ed5866b9b9c user-stories.md@sha256:269a63afa9a9 -->

## Overview

O modelo gira em torno do **condomínio** (`condominiums`), o tenant. Todo dado de domínio carrega `condominium_id` — inclusive tabelas filhas onde ele seria derivável — para escopo global simples e índices compostos por tenant (US-1.2). A estrutura física é **condomínio → blocos (opcionais) → unidades → moradores**; os **usuários** do painel (`users`) têm um papel e, exceto o super admin, pertencem a um condomínio. O n8n autentica com tokens Sanctum cujo `tokenable` é o condomínio.

O conhecimento consultado pelo agente vive em **documentos normativos** (`rule_documents`) quebrados em **artigos** (`rule_articles`, com coluna `vector(1536)` — pgvector, OpenAI `text-embedding-3-small`) e em **comunicados** (`notices`), que são apenas registros com flag `is_active`, sem embedding. A operação vive em **chamados** (`tickets`, com prioridade, fotos, histórico de status e avisos ao morador), **áreas comuns** com **faixas de horário** e **reservas** (via WhatsApp ou manuais), **escalonamentos** (com motivo e histórico de quem assumiu) e **entregas de webhook**. Toda chamada do agente fica no **log de tool calls** (`agent_tool_calls`), fonte da visão geral e dos contadores do painel.

Convenções: **Eloquent / Laravel 13** (tabelas plurais snake_case, `id` bigint, FK `<singular>_id`, `created_at`/`updated_at`), **PostgreSQL 18 + pgvector**. **Nenhuma coluna enum**: todo valor categórico é FK para lookup table global com `name` + `slug`. `is_active` para usuários, moradores, categorias e áreas; **soft delete apenas em `notices` e `common_area_slots`**; demais exclusões são bloqueadas quando o registro está em uso.

## Schema (DBML)

```dbml
// ---------------------------------------------------------------
// Lookup tables (global, seeded)
// ---------------------------------------------------------------

Table roles {
  id bigint [pk, increment]
  name varchar [not null]
  slug varchar [unique, not null]
  created_at timestamp
  updated_at timestamp
}

Table resident_profiles {
  id bigint [pk, increment]
  name varchar [not null]
  slug varchar [unique, not null]
  created_at timestamp
  updated_at timestamp
}

Table ticket_priorities {
  id bigint [pk, increment]
  name varchar [not null]
  slug varchar [unique, not null]
  created_at timestamp
  updated_at timestamp
}

Table reservation_origins {
  id bigint [pk, increment]
  name varchar [not null]
  slug varchar [unique, not null]
  created_at timestamp
  updated_at timestamp
}

Table escalation_reasons {
  id bigint [pk, increment]
  name varchar [not null]
  slug varchar [unique, not null]
  created_at timestamp
  updated_at timestamp
}

Table tool_call_results {
  id bigint [pk, increment]
  name varchar [not null]
  slug varchar [unique, not null]
  created_at timestamp
  updated_at timestamp
}

Table agent_tools {
  id bigint [pk, increment]
  name varchar [not null, note: 'nome exibido, ex.: consultar_regimento']
  slug varchar [unique, not null]
  http_method varchar(10) [not null]
  route varchar [not null, note: 'ex.: /api/v1/rules/search']
  description varchar [not null]
  created_at timestamp
  updated_at timestamp
}

Table document_types {
  id bigint [pk, increment]
  name varchar [not null]
  slug varchar [unique, not null]
  created_at timestamp
  updated_at timestamp
}

Table document_statuses {
  id bigint [pk, increment]
  name varchar [not null]
  slug varchar [unique, not null]
  created_at timestamp
  updated_at timestamp
}

Table ticket_statuses {
  id bigint [pk, increment]
  name varchar [not null]
  slug varchar [unique, not null]
  is_final boolean [not null, default: false]
  created_at timestamp
  updated_at timestamp
}

Table ticket_origins {
  id bigint [pk, increment]
  name varchar [not null]
  slug varchar [unique, not null]
  created_at timestamp
  updated_at timestamp
}

Table reservation_statuses {
  id bigint [pk, increment]
  name varchar [not null]
  slug varchar [unique, not null]
  created_at timestamp
  updated_at timestamp
}

Table reservation_cancellation_origins {
  id bigint [pk, increment]
  name varchar [not null]
  slug varchar [unique, not null]
  created_at timestamp
  updated_at timestamp
}

Table escalation_statuses {
  id bigint [pk, increment]
  name varchar [not null]
  slug varchar [unique, not null]
  created_at timestamp
  updated_at timestamp
}

Table webhook_events {
  id bigint [pk, increment]
  name varchar [not null]
  slug varchar [unique, not null]
  created_at timestamp
  updated_at timestamp
}

Table webhook_delivery_statuses {
  id bigint [pk, increment]
  name varchar [not null]
  slug varchar [unique, not null]
  created_at timestamp
  updated_at timestamp
}

// ---------------------------------------------------------------
// Tenant, access and API
// ---------------------------------------------------------------

Table condominiums {
  id bigint [pk, increment]
  name varchar [unique, not null]
  city varchar [null]
  whatsapp_number varchar(20) [null, note: 'E.164']
  caretaker_name varchar [null]
  caretaker_phone varchar(20) [null, note: 'E.164']
  quiet_hours_start time [null, note: 'informativo; pode ser > end (atravessa meia-noite)']
  quiet_hours_end time [null]
  webhook_url varchar [null]
  webhook_secret text [null, note: 'encrypted cast']
  last_ticket_protocol integer [not null, default: 0, note: 'contador do protocolo sequencial; incrementado com lock']
  created_at timestamp
  updated_at timestamp
}

Table users {
  id bigint [pk, increment]
  name varchar [not null]
  email varchar [unique, not null]
  email_verified_at timestamp [null]
  password varchar [not null]
  remember_token varchar(100) [null]
  role_id bigint [ref: > roles.id, not null]
  condominium_id bigint [ref: > condominiums.id, null, note: 'null apenas para super_admin']
  is_active boolean [not null, default: true]
  created_at timestamp
  updated_at timestamp

  indexes {
    (condominium_id, role_id)
  }
}

Table personal_access_tokens {
  id bigint [pk, increment]
  tokenable_type varchar [not null, note: 'App\\Models\\Condominium']
  tokenable_id bigint [not null]
  name text [not null]
  token varchar(64) [unique, not null]
  abilities text [null]
  last_used_at timestamp [null]
  expires_at timestamp [null]
  created_at timestamp
  updated_at timestamp

  indexes {
    (tokenable_type, tokenable_id)
  }
}

// ---------------------------------------------------------------
// Condominium structure
// ---------------------------------------------------------------

Table blocks {
  id bigint [pk, increment]
  condominium_id bigint [ref: > condominiums.id, not null]
  name varchar [not null]
  created_at timestamp
  updated_at timestamp

  indexes {
    (condominium_id, name) [unique]
  }
}

Table units {
  id bigint [pk, increment]
  condominium_id bigint [ref: > condominiums.id, not null]
  block_id bigint [ref: > blocks.id, null]
  number varchar [not null]
  created_at timestamp
  updated_at timestamp

  indexes {
    (block_id, number) [unique, note: 'partial: where block_id is not null']
    (condominium_id, number) [unique, note: 'partial: where block_id is null']
  }
}

Table residents {
  id bigint [pk, increment]
  condominium_id bigint [ref: > condominiums.id, not null]
  unit_id bigint [ref: > units.id, not null]
  resident_profile_id bigint [ref: > resident_profiles.id, not null]
  name varchar [not null]
  phone varchar(20) [not null, note: 'E.164 normalizado']
  is_active boolean [not null, default: true]
  created_at timestamp
  updated_at timestamp

  indexes {
    (condominium_id, phone) [unique]
    unit_id
  }
}

// ---------------------------------------------------------------
// Knowledge: rules (regimento/convenção) and notices
// ---------------------------------------------------------------

Table rule_documents {
  id bigint [pk, increment]
  condominium_id bigint [ref: > condominiums.id, not null]
  document_type_id bigint [ref: > document_types.id, not null]
  document_status_id bigint [ref: > document_statuses.id, not null]
  title varchar [not null]
  file_path varchar [not null]
  processing_error text [null, note: 'falha de extração ou de indexação']
  uploaded_by_user_id bigint [ref: > users.id, not null]
  published_by_user_id bigint [ref: > users.id, null]
  published_at timestamp [null]
  created_at timestamp
  updated_at timestamp

  indexes {
    (condominium_id, document_type_id, document_status_id)
  }
}

Table rule_articles {
  id bigint [pk, increment]
  condominium_id bigint [ref: > condominiums.id, not null]
  rule_document_id bigint [ref: > rule_documents.id, not null]
  reference varchar [not null, note: 'ex.: Art. 23, §1º']
  title varchar [null]
  body text [not null]
  position integer [not null]
  embedding vector(1536) [null]
  embedded_at timestamp [null]
  created_at timestamp
  updated_at timestamp

  indexes {
    (rule_document_id, position)
    embedding [note: 'HNSW vector_cosine_ops']
  }
}

Table notices {
  id bigint [pk, increment]
  condominium_id bigint [ref: > condominiums.id, not null]
  title varchar [not null]
  body text [not null]
  is_active boolean [not null, default: true]
  created_by_user_id bigint [ref: > users.id, not null]
  created_at timestamp
  updated_at timestamp
  deleted_at timestamp [null]

  indexes {
    (condominium_id, is_active, updated_at)
  }
}

// ---------------------------------------------------------------
// Maintenance tickets
// ---------------------------------------------------------------

Table ticket_categories {
  id bigint [pk, increment]
  condominium_id bigint [ref: > condominiums.id, not null]
  name varchar [not null]
  slug varchar [not null]
  is_active boolean [not null, default: true]
  created_at timestamp
  updated_at timestamp

  indexes {
    (condominium_id, slug) [unique]
    (condominium_id, name) [unique]
  }
}

Table tickets {
  id bigint [pk, increment]
  condominium_id bigint [ref: > condominiums.id, not null]
  protocol_number integer [not null]
  ticket_status_id bigint [ref: > ticket_statuses.id, not null]
  ticket_priority_id bigint [ref: > ticket_priorities.id, not null, note: 'padrão media']
  ticket_origin_id bigint [ref: > ticket_origins.id, not null]
  ticket_category_id bigint [ref: > ticket_categories.id, null]
  unit_id bigint [ref: > units.id, null, note: 'obrigatório quando origem = whatsapp']
  resident_id bigint [ref: > residents.id, null, note: 'obrigatório quando origem = whatsapp']
  opened_by_user_id bigint [ref: > users.id, null, note: 'preenchido quando origem = painel']
  description text [not null]
  location varchar [null]
  created_at timestamp
  updated_at timestamp

  indexes {
    (condominium_id, protocol_number) [unique]
    (condominium_id, ticket_status_id, ticket_priority_id)
    (unit_id, created_at)
  }
}

Table ticket_photos {
  id bigint [pk, increment]
  condominium_id bigint [ref: > condominiums.id, not null]
  ticket_id bigint [ref: > tickets.id, not null]
  file_path varchar [not null]
  mime_type varchar [not null]
  size_bytes integer [not null]
  created_at timestamp
  updated_at timestamp
}

Table ticket_status_changes {
  id bigint [pk, increment]
  condominium_id bigint [ref: > condominiums.id, not null]
  ticket_id bigint [ref: > tickets.id, not null]
  from_ticket_status_id bigint [ref: > ticket_statuses.id, null, note: 'null na entrada inicial']
  to_ticket_status_id bigint [ref: > ticket_statuses.id, not null]
  comment text [null]
  user_id bigint [ref: > users.id, null, note: 'null quando aberto via API']
  created_at timestamp
  updated_at timestamp

  indexes {
    (ticket_id, created_at)
  }
}

Table ticket_resident_notices {
  id bigint [pk, increment]
  condominium_id bigint [ref: > condominiums.id, not null]
  ticket_id bigint [ref: > tickets.id, not null]
  user_id bigint [ref: > users.id, not null]
  message text [not null, note: '1 a 1000 caracteres']
  webhook_delivery_id bigint [ref: > webhook_deliveries.id, null, note: 'null quando o condomínio não tem webhook configurado']
  created_at timestamp
  updated_at timestamp

  indexes {
    (ticket_id, created_at)
  }
}

// ---------------------------------------------------------------
// Common areas and reservations
// ---------------------------------------------------------------

Table common_areas {
  id bigint [pk, increment]
  condominium_id bigint [ref: > condominiums.id, not null]
  name varchar [not null]
  description text [null]
  is_active boolean [not null, default: true]
  min_advance_hours integer [not null, default: 24]
  max_advance_days integer [not null, default: 60]
  cancellation_deadline_hours integer [not null, default: 24]
  created_at timestamp
  updated_at timestamp

  indexes {
    (condominium_id, name) [unique]
  }
}

Table common_area_slots {
  id bigint [pk, increment]
  condominium_id bigint [ref: > condominiums.id, not null]
  common_area_id bigint [ref: > common_areas.id, not null]
  starts_at time [not null]
  ends_at time [not null]
  created_at timestamp
  updated_at timestamp
  deleted_at timestamp [null]

  indexes {
    (common_area_id, starts_at)
  }
}

Table reservations {
  id bigint [pk, increment]
  condominium_id bigint [ref: > condominiums.id, not null]
  common_area_id bigint [ref: > common_areas.id, not null]
  common_area_slot_id bigint [ref: > common_area_slots.id, not null]
  unit_id bigint [ref: > units.id, not null]
  resident_id bigint [ref: > residents.id, not null]
  reservation_status_id bigint [ref: > reservation_statuses.id, not null]
  reservation_origin_id bigint [ref: > reservation_origins.id, not null]
  created_by_user_id bigint [ref: > users.id, null, note: 'preenchido na reserva manual']
  date date [not null]
  starts_at time [not null, note: 'snapshot da faixa na criação']
  ends_at time [not null, note: 'snapshot da faixa na criação']
  cancelled_at timestamp [null]
  reservation_cancellation_origin_id bigint [ref: > reservation_cancellation_origins.id, null]
  cancellation_reason text [null, note: 'obrigatório quando cancelado pelo síndico']
  cancelled_by_user_id bigint [ref: > users.id, null]
  created_at timestamp
  updated_at timestamp

  indexes {
    (common_area_slot_id, date) [unique, note: 'partial: where cancelled_at is null']
    (unit_id, date)
    (common_area_id, date)
  }
}

// ---------------------------------------------------------------
// Escalations and webhooks
// ---------------------------------------------------------------

Table escalations {
  id bigint [pk, increment]
  condominium_id bigint [ref: > condominiums.id, not null]
  resident_id bigint [ref: > residents.id, not null]
  unit_id bigint [ref: > units.id, not null]
  ticket_id bigint [ref: > tickets.id, null]
  escalation_status_id bigint [ref: > escalation_statuses.id, not null]
  escalation_reason_id bigint [ref: > escalation_reasons.id, not null]
  summary text [not null]
  assigned_user_id bigint [ref: > users.id, null, note: 'responsável atual']
  assigned_at timestamp [null]
  response text [null]
  responded_by_user_id bigint [ref: > users.id, null]
  resolved_at timestamp [null]
  created_at timestamp
  updated_at timestamp

  indexes {
    (condominium_id, escalation_status_id, created_at)
  }
}

Table escalation_assignments {
  id bigint [pk, increment]
  condominium_id bigint [ref: > condominiums.id, not null]
  escalation_id bigint [ref: > escalations.id, not null]
  user_id bigint [ref: > users.id, not null, note: 'quem assumiu']
  previous_user_id bigint [ref: > users.id, null, note: 'responsável substituído, quando o síndico reassume']
  created_at timestamp
  updated_at timestamp

  indexes {
    (escalation_id, created_at)
  }
}

Table webhook_deliveries {
  id bigint [pk, increment]
  condominium_id bigint [ref: > condominiums.id, not null]
  webhook_event_id bigint [ref: > webhook_events.id, not null]
  webhook_delivery_status_id bigint [ref: > webhook_delivery_statuses.id, not null]
  subject_type varchar [not null, note: 'morph: App\\Models\\Ticket | Reservation | Escalation']
  subject_id bigint [not null]
  resident_phone varchar(20) [null]
  url varchar [not null, note: 'snapshot da URL no envio']
  payload jsonb [not null]
  attempts integer [not null, default: 0, note: 'máximo 3']
  last_response_code integer [null]
  last_error text [null]
  delivered_at timestamp [null]
  failed_at timestamp [null]
  created_at timestamp
  updated_at timestamp

  indexes {
    (condominium_id, webhook_delivery_status_id, created_at)
    (subject_type, subject_id)
  }
}

// ---------------------------------------------------------------
// Agent tool call log
// ---------------------------------------------------------------

Table agent_tool_calls {
  id bigint [pk, increment]
  condominium_id bigint [ref: > condominiums.id, not null]
  agent_tool_id bigint [ref: > agent_tools.id, not null]
  personal_access_token_id bigint [ref: > personal_access_tokens.id, null]
  resident_id bigint [ref: > residents.id, null, note: 'quando o telefone foi identificado']
  phone varchar(20) [null]
  tool_call_result_id bigint [ref: > tool_call_results.id, not null]
  http_status smallint [not null]
  error_code varchar [null, note: 'code da resposta de recusa']
  entities jsonb [null, note: '{article_ids:[..], ticket_id, reservation_id, escalation_id}']
  latency_ms integer [not null]
  created_at timestamp
  updated_at timestamp

  indexes {
    (condominium_id, created_at)
    (condominium_id, agent_tool_id, created_at)
    (resident_id, created_at)
    entities [note: 'GIN jsonb_path_ops']
  }
}
```

## Relationships

- Um **condomínio** tem muitos **usuários** (síndicos/zeladores); um **usuário** pertence a um **papel** (`roles`) e a zero ou um **condomínio** (zero = super admin).
- Um **condomínio** tem muitos **tokens de API** (`personal_access_tokens`, polimórfico via `tokenable`).
- Um **condomínio** tem muitos **blocos**, **unidades**, **moradores**, **categorias de chamado**, **documentos normativos**, **comunicados**, **chamados**, **áreas comuns**, **reservas**, **escalonamentos**, **entregas de webhook** e **tool calls**.
- Um **bloco** tem muitas **unidades**; uma **unidade** pertence a zero ou um **bloco**.
- Uma **unidade** tem muitos **moradores**, **chamados**, **reservas** e **escalonamentos**.
- Um **morador** pertence a um **perfil** (`resident_profiles`).
- Um **documento normativo** pertence a um **tipo** (`document_types`) e a um **status** (`document_statuses`), foi enviado por um **usuário** e opcionalmente publicado por um **usuário**; tem muitos **artigos**.
- Um **comunicado** foi criado por um **usuário**.
- Um **chamado** pertence a um **status**, a uma **prioridade** (`ticket_priorities`), a uma **origem**, opcionalmente a uma **categoria**, a uma **unidade** e a um **morador** (opcionais quando aberto pelo painel) e opcionalmente ao **usuário** que o abriu; tem muitas **fotos**, muitas **mudanças de status** e muitos **avisos ao morador**.
- Uma **mudança de status** referencia o **status anterior** e o **novo status** (`ticket_statuses`) e opcionalmente o **usuário** autor.
- Um **aviso ao morador** (`ticket_resident_notices`) pertence a um **chamado**, ao **usuário** que enviou e opcionalmente à **entrega de webhook** gerada.
- Uma **área comum** tem muitas **faixas de horário** e muitas **reservas**.
- Uma **reserva** pertence a uma **área**, a uma **faixa**, a uma **unidade**, a um **morador**, a um **status** e a uma **origem** (`reservation_origins`), e opcionalmente ao **usuário** que a criou manualmente; quando cancelada, a uma **origem de cancelamento** e opcionalmente ao **usuário** que cancelou.
- Um **escalonamento** pertence a um **morador**, a uma **unidade**, a um **status**, a um **motivo** (`escalation_reasons`), opcionalmente a um **chamado**, ao **usuário responsável atual** e ao **usuário** que respondeu; tem muitas **atribuições** (`escalation_assignments`).
- Uma **atribuição** pertence a um **escalonamento**, ao **usuário** que assumiu e opcionalmente ao **usuário substituído**.
- Uma **entrega de webhook** pertence a um **evento** e a um **status de entrega**, e a um **assunto** polimórfico (`subject`: chamado, reserva ou escalonamento).
- Uma **tool call** (`agent_tool_calls`) pertence a uma **tool** (`agent_tools`), a um **resultado** (`tool_call_results`), opcionalmente ao **token** usado e ao **morador** identificado.

## Lookup Table Seeds

- **roles** → `super_admin` (Super admin), `sindico` (Síndico), `zelador` (Zelador)
- **resident_profiles** → `proprietario` (Proprietário), `inquilino` (Inquilino)
- **ticket_priorities** → `alta` (Alta), `media` (Média), `baixa` (Baixa)
- **reservation_origins** → `whatsapp`, `painel`
- **escalation_reasons** → `pediu_humano` (Pediu humano), `tool_recusou` (Tool recusou), `sem_regra` (Sem regra)
- **tool_call_results** → `sucesso`, `vazio`, `recusa`
- **agent_tools** → `residents_lookup` (GET /api/v1/residents/lookup), `rules_search` (POST /api/v1/rules/search), `notices_list` (GET /api/v1/notices), `tickets_create` (POST /api/v1/tickets), `tickets_list` (GET /api/v1/tickets), `tickets_show` (GET /api/v1/tickets/{protocol}), `areas_list` (GET /api/v1/areas), `areas_availability` (GET /api/v1/areas/{id}/availability), `reservations_create` (POST /api/v1/reservations), `reservations_list` (GET /api/v1/reservations), `reservations_cancel` (DELETE /api/v1/reservations/{id}), `escalations_create` (POST /api/v1/escalations)
- **document_types** → `regimento` (Regimento interno), `convencao` (Convenção)
- **document_statuses** → `processando`, `em_revisao`, `falha_extracao`, `indexando`, `falha_indexacao`, `publicado`, `substituido`
- **ticket_statuses** → `aberto` (is_final false), `em_andamento` (false), `resolvido` (true), `cancelado` (true)
- **ticket_origins** → `whatsapp`, `painel`
- **reservation_statuses** → `confirmada`, `cancelada`
- **reservation_cancellation_origins** → `morador`, `sindico`
- **escalation_statuses** → `pendente`, `em_atendimento`, `resolvido`
- **webhook_events** → `ticket.status_changed`, `ticket.resident_notified`, `reservation.cancelled`, `escalation.answered`
- **webhook_delivery_statuses** → `pendente`, `enviado`, `falhou`
- **ticket_categories** (por condomínio, semeado na criação do condomínio — US-1.3) → `eletrica` (Elétrica), `hidraulica` (Hidráulica), `elevador` (Elevador), `limpeza` (Limpeza), `seguranca` (Segurança), `outros` (Outros)

## Notes & Conventions

- **Tabelas do framework já existentes/instaladas:** `users` (estendida com `role_id`, `condominium_id`, `is_active` via nova migration), `password_reset_tokens`, `sessions`, `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs`. `personal_access_tokens` vem de `php artisan install:api` (Sanctum).
- **pgvector:** migration inicial executa `CREATE EXTENSION IF NOT EXISTS vector`. Só `rule_articles.embedding` é `vector(1536)` (dimensão do `text-embedding-3-small`), com índice **HNSW `vector_cosine_ops`**. Comunicados não têm embedding. `embedding` nulo = ainda não indexado e fora da busca.
- **Busca de regras (US-4.4):** filtra `condominium_id` + documento com `document_status_id = publicado` antes da similaridade. Documento só vai a `publicado` quando todos os artigos têm `embedding` (US-4.3); publicar outro do mesmo tipo muda o anterior para `substituido`.
- **Comunicados (US-5.1, US-5.2):** status é só `notices.is_active`; a API lista `is_active = true AND deleted_at IS NULL`. Excluir usa soft delete. Timestamps gravados em UTC; exibição em `America/Sao_Paulo`.
- **Soft deletes:** apenas `notices` (exclusão pelo síndico, US-5.1) e `common_area_slots` (faixa removida continua referenciada por reservas passadas, US-7.1). Faixa com reserva futura não cancelada não pode ser removida (regra de aplicação).
- **`is_active`** em `users` (US-1.4), `residents` (US-2.2), `ticket_categories` (US-2.3), `notices` (US-5.1) e `common_areas` (US-7.1). Exclusão física de blocos, unidades, moradores e categorias em uso é bloqueada na aplicação (US-2.1, US-2.2, US-2.3); FKs sem `cascade`.
- **Protocolo sequencial contínuo (US-6.1):** `condominiums.last_ticket_protocol` é incrementado dentro de transação com `lockForUpdate` e copiado para `tickets.protocol_number`; unique `(condominium_id, protocol_number)` garante unicidade mesmo em concorrência. Denormalização intencional.
- **Conflito de reserva (US-7.3):** unique **parcial** `(common_area_slot_id, date) WHERE cancelled_at IS NULL` (criado via `DB::statement`, DBML não expressa partial index). Garante uma única reserva ativa por faixa/data sob concorrência. `cancelled_at` duplica o estado `cancelada` de propósito, para o índice não depender de id de lookup.
- **Reserva manual (US-7.8):** origem `painel` + `created_by_user_id`; usa o mesmo índice parcial de conflito; a regra de ignorar antecedência é de aplicação.
- **Linha do tempo do chamado (US-6.3, US-6.7):** união de `ticket_status_changes` e `ticket_resident_notices` ordenada por `created_at`. Alterar prioridade (US-6.6) só atualiza `tickets.ticket_priority_id`, sem histórico.
- **Assumir escalonamento (US-8.4):** cada ação grava `escalation_assignments` e atualiza `escalations.assigned_user_id/assigned_at`. Só síndico reassume (regra de aplicação); `previous_user_id` guarda quem foi substituído.
- **Snapshot de horário:** `reservations.starts_at/ends_at` copiam a faixa na criação, preservando histórico se a faixa for alterada/removida. `webhook_deliveries.url` guarda a URL usada no envio.
- **Unidades sem bloco:** Postgres não trata `NULL` como igual em unique; por isso dois índices parciais em `units` (com e sem `block_id`).
- **Telefone:** `residents.phone` guarda E.164 normalizado; unique por condomínio, repetível entre condomínios (US-2.2, US-3.2).
- **Regras de consistência na aplicação (não no banco):** `tickets.unit_id`/`resident_id` obrigatórios quando origem `whatsapp`; `resident.unit_id` deve bater com `unit_id` em tickets/reservas/escalonamentos; `users.condominium_id` nulo só para `super_admin`; transições de status de chamado (US-6.4) validadas em código usando `ticket_statuses.is_final`.
- **Tenancy (US-1.2):** todas as tabelas do tenant têm `condominium_id` (inclusive filhas como `rule_articles`, `ticket_photos`, `common_area_slots`), permitindo global scope único no Eloquent. Lookup tables são globais.
- **`webhook_secret`** usa cast `encrypted` do Eloquent (US-1.6). Tokens Sanctum armazenados com hash (US-1.5).
- **`webhook_deliveries`:** criada no enfileiramento (`pendente`), atualizada a cada tentativa (`attempts`, `last_response_code`, `last_error`); `enviado` com `delivered_at`; `falhou` com `failed_at` após a **3ª tentativa** (US-8.3). A lista "Falhas de webhook" (US-8.5) filtra `falhou` por condomínio; referência e morador vêm de `subject` e `resident_phone`.
- **Log de tool calls (US-9.1):** `agent_tool_calls` recebe um registro por request autenticado em `api/v1` (401 não é registrado). `entities` jsonb guarda `article_ids` (US-4.4), `ticket_id`, `reservation_id` e `escalation_id`; índice **GIN `jsonb_path_ops`** atende "citado N×" (`entities->'article_ids' @> '[id]'`, US-9.4). Sem expurgo: retenção indefinida. Crescimento contínuo; particionamento por mês fica como otimização futura, fora do MVP.
- **Métricas (US-9.2, US-9.3, US-9.4):** calculadas por consulta sobre `agent_tool_calls`, `tickets` e `escalations` (índices `(condominium_id, created_at)` e `(condominium_id, agent_tool_id, created_at)`). Nada é pré-agregado.
- **`agent_tools`** é lookup semeada com as 12 rotas do agente; alimenta o card Integração (US-1.5, US-9.4) e o middleware de log resolve a tool pela rota nomeada.
- **Dados informativos do condomínio (US-2.4):** colunas opcionais em `condominiums`; nenhuma regra depende delas.
- **Conceitos não persistidos:**
  - *Plataforma (tenancy)* — padrão arquitetural (coluna `condominium_id` + global scope), não tabela.
  - *Verificação de morador* — consulta sobre `residents`, sem tabela própria.
  - *RAG* — processo; persiste apenas via `rule_articles.embedding`. Limiar (0.5) e limite (5/10) vivem em config.
  - *Testar pergunta* — executa a busca em tempo real; não grava nada (US-4.5).
  - *Visão geral* — métricas derivadas em consulta; sem tabela (US-9.2, US-9.3).
  - *Regras extras de reserva / valores padrão de antecedência* — decididas nas user stories: defaults como `default` em `common_areas`; sem limite por unidade no MVP.
  - *Escopo do zelador* — sem atribuição de chamados (US-6.3): nenhuma coluna de responsável em `tickets`. (Escalonamentos têm responsável via Assumir, US-8.4.)
