# Condo Manager

Painel de gestão de condomínios + agente de WhatsApp.

O morador fala com o condomínio pelo WhatsApp. Um agente de IA rodando no **n8n** atende, e todas as ações dele (abrir chamado, reservar área, consultar o regimento) são chamadas HTTP para a **API do Laravel** — que é a única fonte de verdade. Síndico e zelador acompanham tudo pelo painel web.

```
WhatsApp (Meta Cloud API)
        │
        ▼
   n8n — flow "Sindico"                     LangSmith
   buffer → mídia → AI Agent  ─── traces ──▶ (observabilidade)
        │
        │ HTTP (tools)
        ▼
   Laravel API /api/v1/*  ──▶  PostgreSQL 18 + pgvector
        │                          (regimento vetorizado)
        ▼
   Painel Livewire (síndico/zelador)
```

## Stack

| Camada | O que usamos |
|---|---|
| Backend | PHP 8.5, Laravel 13, Livewire 4 (+ Blaze) |
| Frontend | Blade + Tailwind 4, Vite 8 (`vite-plus`) |
| Banco | PostgreSQL 18 com `pgvector` (embeddings 1536) |
| Fila / cache | `database` (padrão) + Redis disponível |
| IA | `laravel/ai` → OpenAI (`text-embedding-3-small`) |
| Automação | n8n (flow em `n8n/Sindico.json`) |
| Observabilidade | LangSmith (tracing dos nós LangChain do n8n) |
| Testes | Pest 5, Larastan, Pint |

## Requisitos

Só **Docker** (Engine + Compose). PHP, Composer, Node e Postgres ficam todos dentro dos containers via Laravel Sail.

> Todo comando de PHP/artisan/npm roda **dentro** do container, com `./vendor/bin/sail`. Rodar `php artisan` no host falha (sem PHP, sem banco).

## Subindo do zero com Docker

### 1. Clonar e preparar o `.env`

```bash
git clone git@github.com:beerandcodeteam/condo-manager.git
cd condo-manager
cp .env.example .env
```

### 2. Instalar as dependências sem ter PHP na máquina

O Sail mora em `vendor/`, mas `vendor/` só existe depois do `composer install` — ovo e galinha. Resolve-se com um container descartável de Composer, que monta o projeto e instala as dependências:

```bash
docker run --rm \
    -u "$(id -u):$(id -g)" \
    -v "$(pwd):/var/www/html" \
    -w /var/www/html \
    laravelsail/php84-composer:latest \
    composer install --ignore-platform-reqs
```

- `-u "$(id -u):$(id -g)"` → os arquivos criados ficam com o seu usuário, não com `root`.
- `--ignore-platform-reqs` → a imagem é PHP 8.4 e o app roda em 8.5; as extensões reais são validadas depois, dentro do container do Sail.

Depois disso `./vendor/bin/sail` existe. Opcional, para encurtar:

```bash
alias sail='./vendor/bin/sail'
```

### 3. Subir os containers

```bash
export WWWUSER=$(id -u) WWWGROUP=$(id -g)   # evita arquivo com dono errado em storage/
./vendor/bin/sail up -d
```

Na primeira vez a imagem `sail-8.5/app` é construída a partir de `docker/8.5/Dockerfile` — leva alguns minutos.

### 4. Chave, banco e front

```bash
./vendor/bin/sail artisan key:generate
./vendor/bin/sail artisan migrate --seed
./vendor/bin/sail npm install
./vendor/bin/sail npm run build
```

O `migrate --seed` roda o `LookupSeeder` (tabelas de domínio: status, tipos, papéis) e, em `local`, o `DemoSeeder` — condomínio, unidades, moradores, chamados e reservas de exemplo.

### 5. Entrar

| | |
|---|---|
| Painel | http://localhost |
| n8n | http://localhost:5678 |
| Login demo | `admin@teste.com` / `password` |

> A porta do painel vem de `APP_PORT` (padrão `80`). Se mudar, ajuste `APP_URL` junto.

### 6. Durante o desenvolvimento

O container já serve o app, mas a fila e o Vite você sobe à parte:

```bash
./vendor/bin/sail artisan queue:listen --tries=1   # extração/indexação de PDF, webhooks
./vendor/bin/sail npm run dev                      # hot reload do front
./vendor/bin/sail artisan pail                     # logs em tempo real
```

**A fila não é opcional**: upload de regimento e entrega de webhook são jobs. Sem worker, o PDF fica preso em "processando".

### Comandos úteis

```bash
./vendor/bin/sail down                  # derruba (adicione -v para apagar os volumes)
./vendor/bin/sail artisan app:create-super-admin
./vendor/bin/sail psql                  # shell do Postgres
./vendor/bin/sail composer test         # pint --test + phpstan + pest
```

## Serviços do compose

| Serviço | Imagem | Porta | Papel |
|---|---|---|---|
| `laravel.test` | build de `docker/8.5` | `${APP_PORT:-80}`, `5173` | app + Vite |
| `pgsql` | `pgvector/pgvector:pg18` | `${FORWARD_DB_PORT:-5432}` | banco do app, do n8n e o de testes |
| `n8n` | `n8nio/n8n:latest` | `${FORWARD_N8N_PORT:-5678}` | agente de WhatsApp |
| `redis` | `redis:7-alpine` | `${FORWARD_REDIS_PORT:-6379}` | cache/fila opcional |

Os bancos `n8n` e `testing` são criados no primeiro boot do Postgres por `docker/pgsql/*.sql`.

## Variáveis de ambiente

Além das padrão do Laravel:

| Grupo | Variáveis | Para quê |
|---|---|---|
| IA | `OPENAI_API_KEY`, `OPENAI_EMBEDDINGS_MODEL` | embeddings do regimento |
| RAG | `RAG_MIN_SIMILARITY`, `RAG_MAX_QUERY_LENGTH` | corte de similaridade da busca |
| Agente | `CONDO_PLATFORM_TOKEN`, `CONDO_AGENT_TOKEN_TTL_MINUTES`, `CONDO_BUFFER_SECONDS` | autenticação e buffer de mensagens |
| WhatsApp | `WHATSAPP_PHONE_NUMBER_ID`, `WHATSAPP_BUSINESS_ACCOUNT_ID`, `WHATSAPP_ACCESS_TOKEN`, `WHATSAPP_APP_ID`, `WHATSAPP_APP_SECRET`, `WHATSAPP_GRAPH_VERSION` | Meta Cloud API (as credenciais efetivas vivem no n8n) |
| n8n | `N8N_HOST`, `N8N_PROTOCOL`, `N8N_WEBHOOK_URL`, `N8N_TIMEZONE`, `N8N_DB_DATABASE`, `N8N_ENCRYPTION_KEY`, `N8N_API_TOKEN` | container do n8n |
| LangSmith | `LANGSMITH_API_KEY`, `LANGSMITH_PROJECT`, `LANGSMITH_ENDPOINT` | tracing do agente |

`N8N_WEBHOOK_URL` precisa ser a URL pública do túnel (ngrok/cloudflared) quando você testa com o WhatsApp de verdade — é ela que o n8n registra como callback na Meta.

⚠️ `.env` é ignorado pelo git e guarda tokens reais de OpenAI, Meta e LangSmith. Nunca commite, nunca cole em slide.

## API do agente

Prefixo `/api/v1`, respostas JSON com `code` + `message` em erro. Três camadas de acesso:

1. **`platform`** — `CONDO_PLATFORM_TOKEN` no header. Só `POST /auth/token` (resolve o condomínio pelo número do WhatsApp e devolve um token Sanctum de curta duração) e o guarda de eco.
2. **`conversation`** — token do condomínio. Memória e mídia: histórico, buffer de fragmentos, upload de áudio/imagem.
3. **`agent`** — token do condomínio + `LogToolCall`. São as tools do agente; toda chamada vira linha em `agent_tool_calls`.

| Tool | Rota |
|---|---|
| `verificar_morador` | `GET /residents/lookup` |
| `consultar_regimento` | `POST /rules/search` |
| `consultar_comunicados` | `GET /notices` |
| `abrir_chamado` / `listar_chamados` / `consultar_chamado` | `POST /tickets`, `GET /tickets`, `GET /tickets/{protocol}` |
| `listar_areas` / `consultar_disponibilidade` | `GET /areas`, `GET /areas/{area}/availability` |
| `reservar_area` / `listar_reservas` / `cancelar_reserva` | `POST /reservations`, `GET /reservations`, `DELETE /reservations/{reservation}` |
| `escalar_humano` | `POST /escalations` |

Contrato detalhado de cada uma: [`docs/agent-tools.md`](docs/agent-tools.md). Integração com a Meta: [`docs/whatsapp-cloud-api.md`](docs/whatsapp-cloud-api.md).

## Regimento e busca vetorial (RAG)

Fluxo do upload no painel (`/regimento`):

1. O PDF é salvo em `storage/app/private/rule-documents/{condominium}/`.
2. Job `ExtractRuleArticles` — `smalot/pdfparser` extrai o texto e o `RuleArticleSplitter` quebra em artigos (`rule_articles`).
3. Job `IndexRuleArticles` — gera o embedding de cada artigo (`text-embedding-3-small`, 1536 dimensões) e grava na coluna `vector`.
4. Publicado o documento, `POST /rules/search` responde ao agente por similaridade de cosseno, respeitando `RAG_MIN_SIMILARITY`.

Para zerar e refazer a demonstração do upload:

```bash
./vendor/bin/sail psql -c "TRUNCATE rule_articles, rule_documents RESTART IDENTITY;"
rm -rf storage/app/private/rule-documents/* storage/app/private/livewire-tmp/*
```

## n8n

O flow do agente está versionado em [`n8n/Sindico.json`](n8n/Sindico.json). Para usar:

1. Abra http://localhost:5678 e importe o arquivo.
2. Crie as credenciais **WhatsApp Cloud API (Condo)** e **WhatsApp Trigger (Condo)** com os dados da Meta, e a credencial da OpenAI.
3. Confira a URL da API nos nós HTTP. O flow versionado usa `http://condo-manager-laravel.test-1/api/v1/...` — o hostname do container do app na rede do compose, que muda com o nome do diretório do projeto. Se o seu clone estiver em outra pasta, ajuste (ou use `laravel.test`, o nome do serviço). O header vai com o `CONDO_PLATFORM_TOKEN`.
4. Ative o flow e registre a URL do túnel como webhook na Meta.

O que o flow faz, em ordem: recebe a mensagem → junta fragmentos enviados em rajada (buffer de `CONDO_BUFFER_SECONDS`) → transcreve áudio / descreve imagem → descarta eco das próprias respostas → resolve o condomínio e verifica se o número é de morador → roda o AI Agent com as tools → responde e grava a conversa no Laravel.

## Observabilidade com LangSmith

Os nós de IA do n8n são LangChain de verdade, então o tracing é **só configuração** — nada a instalar no container. As variáveis já estão no `compose.yaml`:

```yaml
LANGCHAIN_TRACING_V2: 'true'
LANGCHAIN_ENDPOINT: '${LANGSMITH_ENDPOINT:-https://api.smith.langchain.com}'
LANGCHAIN_API_KEY: '${LANGSMITH_API_KEY}'
LANGCHAIN_PROJECT: '${LANGSMITH_PROJECT:-condo-manager-local}'
LANGCHAIN_CALLBACKS_BACKGROUND: 'true'
```

Preencha `LANGSMITH_API_KEY` no `.env` e recrie o container (a variável só é lida no boot):

```bash
./vendor/bin/sail up -d n8n
```

**Aparece no trace**: o AI Agent (span raiz), o modelo de chat com prompt/tokens/latência/custo, as tools chamadas dentro do agent, transcrição de áudio, análise de imagem, guardrails e memória.

**Não aparece**: nós `httpRequest` comuns, nós de WhatsApp, Code, If/Switch — esses continuam só no log de execução do n8n e, do lado do app, na tabela `agent_tool_calls`.

Pontos de atenção:

- Só funciona em n8n self-hosted; o n8n Cloud não expõe essas variáveis.
- O conteúdo das mensagens do morador sai do seu ambiente e vai para o LangSmith. Se isso for um problema, use o endpoint da UE (`https://eu.api.smith.langchain.com`, com key criada na org da UE) ou desligue o envio de payload com `LANGSMITH_HIDE_INPUTS` / `LANGSMITH_HIDE_OUTPUTS`.
- Use projetos separados por ambiente (`condo-manager-local` × `condo-manager-prod`), senão trace de teste polui as métricas.

## Testes e qualidade

```bash
./vendor/bin/sail artisan test --compact           # suíte toda
./vendor/bin/sail artisan test --filter=RuleSearch # um caso
./vendor/bin/sail pint --dirty                     # formatação
./vendor/bin/sail composer types:check             # Larastan
```

Os testes usam o banco `testing` (ver `phpunit.xml`), criado junto com o container do Postgres.

## Estrutura

```
app/
├── Http/Controllers/Api/   endpoints consumidos pelo n8n
├── Http/Middleware/        tokens de plataforma/condomínio, log de tool call
├── Jobs/                   extração e indexação de PDF, entrega de webhook
├── Models/                 domínio + enums (status, tipos, papéis)
├── Services/               regras de negócio por área (RuleDocuments, Integration, ...)
└── Support/                helpers de painel, telefone, protocolo
docker/                     imagem PHP 8.5 e SQL de inicialização do Postgres
docs/                       contrato das tools e integração WhatsApp
n8n/Sindico.json            flow do agente
resources/views/            painel Livewire
```
