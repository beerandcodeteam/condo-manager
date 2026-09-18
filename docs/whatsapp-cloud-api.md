# WhatsApp Cloud API (Meta)

O canal de WhatsApp do síndico virtual é a **Cloud API oficial da Meta**: não há instância,
QR code nem `remoteJid` — o identificador do contato é o telefone em E.164.

O Condo Manager em si não fala com a Meta. Quem fala é o workflow **"Sindico"**
(`VgR44miVaDqKVt6a`) no n8n; a API `/api/v1` continua recebendo telefone em E.164 e não sabe qual é
o provedor.

## O que está configurado

| Item | Valor |
| --- | --- |
| Phone number ID | `1389245270931171` (número de teste `+1 555-162-6235`) |
| WhatsApp Business Account ID | `1585373583059947` |
| Versão da Graph API | `v13.0` nos nós oficiais, `v23.0` nos HTTP Request |
| App da Meta | `Teste de Mensagens whtasapp`, ID `828108407054292` |
| Credenciais no n8n | `WhatsApp Cloud API (Condo)` (`whatsAppApi`) e `WhatsApp Trigger (Condo)` (`whatsAppTriggerApi`) |
| Caminho do webhook | `/webhook/{id do nó WhatsApp Trigger}/webhook`, registrado sozinho na ativação |
| Verify token | não existe campo: o nó usa o próprio id e confere o desafio sozinho |

O access token e o WABA id ficam **dentro da credencial do n8n** (criptografados com
`N8N_ENCRYPTION_KEY`). O `.env` guarda cópia para referência e é o que abastece o container.

## Recebimento

```
Meta ──POST assinado──> WhatsApp Trigger ──> Mensagem (code) ──> Processar? ──> ...
```

O gatilho é o nó oficial **WhatsApp Trigger** (`n8n-nodes-base.whatsAppTrigger`), evento `messages`.
Ele resolve três coisas que antes eram nó na mão:

- **Verificação.** Responde o `GET ?hub.challenge` da Meta sozinho, comparando o `hub.verify_token`
  com o **id do próprio nó**. Não há verify token para inventar nem campo na credencial.
- **Assinatura.** Confere o header `x-hub-signature-256` (HMAC-SHA256 do corpo cru com o App
  Secret) e **descarta em silêncio** o que não bate. Chamada forjada não vira execução.
- **Registro.** Na ativação ele cria a subscription no **app** da Meta apontando para a URL pública
  que o n8n conhece (`N8N_WEBHOOK_URL`). Na desativação, apaga.

Por causa disso, o app só aguenta **um** WhatsApp Trigger: ativar outro n8n no mesmo App ID toma o
webhook deste. Para devolver o webhook a outra instância, basta reativar o workflow lá — e aí é o
nosso que para.

### Nunca aperte "Execute workflow" nesse gatilho

O "Execute workflow" do editor registra um webhook de **teste** (`/webhook-test/{id}/webhook`), que
é uma URL diferente da de produção. Como a Meta só aceita uma subscription por app, o que acontece é:

1. o `checkExists` vê a subscription de produção com a mesma lista de campos e URL diferente e
   lança *"The WhatsApp App ID … already has a webhook subscription"*;
2. no caminho de teste o registro de produção é **sobrescrito** pelo de `/webhook-test/…`, e ao
   encerrar a sessão de teste ele é apagado. A config "some" no painel da Meta e mensagem real
   nenhuma chega, sem erro em lugar nenhum.

O n8n protege alguns gatilhos disso (`telegramTrigger`, `slackTrigger`, `facebookLeadAdsTrigger`
estão numa lista de *single webhook triggers* que recusa execução de teste com o workflow ativo).
O `whatsAppTrigger` **não está nessa lista**.

Para testar o fluxo sem tocar na Meta, o nó `WhatsApp Trigger` tem **pin data** com uma mensagem de
exemplo: com o pin, o n8n começa a execução pelo item fixado e não registra webhook de teste
nenhum. Alternativas: mandar mensagem de verdade (produção está no ar) ou usar *Retry* numa execução
anterior.

Se o registro se perder, o conserto é desativar e reativar o workflow — o nó registra de novo na
ativação.

A opção *Receive Message Status Updates* está com lista vazia: confirmação de entrega e leitura do
que nós mandamos não gera execução.

A saída do nó é um item por `change`, já achatado (`{...value, field}`). O nó `Mensagem` aceita
tanto essa forma quanto o payload cru (`entry[].changes[]`), então trocar o gatilho não quebra o
parse.

O nó `Mensagem` traduz o payload da Meta para os campos que o resto do fluxo usa:

| Campo | Vem de |
| --- | --- |
| `phone` | `messages[].from` com `+` e o 9º dígito brasileiro reinserido |
| `wa_id` | `messages[].from` cru — é com ele que se **responde** |
| `phone_number_id` | `metadata.phone_number_id` |
| `message_id` | `messages[].id` (`wamid.…`) |
| `kind` | `type` mapeado para `texto \| imagem \| audio \| video \| documento` |
| `media_id`, `mimetype`, `caption` | `messages[].<type>.{id, mime_type, caption}` |

Sai com `processar: false` e o motivo (nunca em silêncio) para: `statuses` (confirmação de entrega do
que nós mandamos), `errors`, `field` diferente de `messages` e tipos não suportados.

## Envio

Onde o nó oficial **WhatsApp Business Cloud** (`n8n-nodes-base.whatsApp`) cobre a operação, é ele
que é usado — com a credencial `whatsAppApi`. Ele fixa a Graph API em `v13.0`, que a Meta ainda
atende.

- **Texto** (`Responder`, `Nao cadastrado`, `Bloqueado`): nó oficial, *Message → Send*, com
  `phoneNumberId`, `recipientPhoneNumber` (o `wa_id`) e `textBody`. A resposta da Meta sai como
  está, então `messages[0].id` continua sendo o que os nós `Registrar …` mandam para `/api/v1/echo`.
- **"Digitando…"** (`Digitando`): o nó oficial não tem essa operação, então é um HTTP Request com a
  mesma credencial: `POST /v23.0/{phone_number_id}/messages` com
  `{ messaging_product, status: 'read', message_id, typing_indicator: { type: 'text' } }`.
  Marca como lida e mostra o indicador por até 25 s ou até a resposta sair. Está com
  `onError: continueRegularOutput`: falhar o indicador não pode derrubar a resposta.

## Mídia

A Cloud API não manda os bytes no webhook, manda um `media_id`. São dois passos, e por isso cada
ramo tem um nó a mais:

```
Tipo ──> URL <ramo>      nó oficial, Media → Download   -> { url, mime_type, … }
     ──> Baixar <ramo>   HTTP Request GET {url}         -> binário em `data`
     ──> <ramo> binario  code: nomeia o arquivo
```

- O nó oficial *Media → Download* devolve só a **URL**, não os bytes — daí o HTTP Request seguinte,
  que usa a mesma credencial porque a URL só baixa com o header `Authorization`.
- A `url` devolvida **expira em 5 minutos**.
- Os nós de IA (`Transcrever`, `Analisar imagem`) **descartam o binário** do item: devolvem só o
  texto. Como o upload para `/api/v1/media` é multipart, `Juntar audio`/`Juntar imagem` reanexam o
  arquivo vindo de `Audio binario`/`Imagem binaria` antes do `Guardar …`.
- `Analisar imagem` precisa de um modelo que aceite `max_tokens`: o nó v1 manda esse parâmetro
  sempre (hardcoded em 300) e a família `gpt-5` só aceita `max_completion_tokens`. Está em
  `gpt-4.1-mini`. Subir o nó para a v2 não resolve de graça: ela usa a Responses API, manda
  `max_output_tokens: 300` (que um modelo de raciocínio gasta só pensando) e devolve um array em vez
  de `{content}`, quebrando o `Texto da mensagem`.
- O download vem sem nome de arquivo. O nó `… binario` escreve `fileName = {media_id}.{ext}` e
  normaliza o `mimeType`, porque a transcrição do OpenAI rejeita arquivo sem extensão conhecida e o
  upload para `/api/v1/media` depende do MIME.

## Configurar o webhook na Meta

A Meta só entrega em **HTTPS público na porta 443**, então o n8n local precisa de um túnel:

```bash
cloudflared tunnel --url http://localhost:5678   # ou ngrok http 5678
```

A URL que sair daí vai para `N8N_WEBHOOK_URL` no `.env` **antes** de ativar o workflow, e o
container do n8n precisa ser recriado (`docker compose up -d n8n`). O n8n monta a URL pública dos
webhooks a partir dessa variável, e é ela que o WhatsApp Trigger registra na Meta; com o padrão
`http://localhost:5678/` ele registraria um endereço que a Meta não alcança. Túnel rápido do
`trycloudflare` muda de URL a cada execução: ao reabrir, atualize a variável, recrie o container e
**desative/reative o workflow** para reregistrar o callback.

Ativar o workflow registra o **callback do app**, mas isso sozinho não entrega nada: o app também
precisa estar inscrito na **WABA**. É um `POST` separado, feito uma vez:

```bash
curl -X POST "https://graph.facebook.com/v23.0/$WHATSAPP_BUSINESS_ACCOUNT_ID/subscribed_apps" \
  -H "Authorization: Bearer $WHATSAPP_ACCESS_TOKEN"
```

Conferir com o `GET` do mesmo caminho: o app `828108407054292` tem que aparecer na lista (o
`WA DevX Webhook Events 1P App`, da própria Meta, convive sem conflito). Sem essa inscrição a Meta
aceita o callback, o painel parece certo e **nenhuma mensagem chega** — sem erro em lugar nenhum.

Fora isso, não há nada a preencher no painel da Meta: ativar o workflow basta. Para conferir onde está o
callback do app:

```bash
curl -s "https://graph.facebook.com/v23.0/$WHATSAPP_APP_ID/subscriptions?access_token=$WHATSAPP_APP_ID|$WHATSAPP_APP_SECRET"
```

E para soltar o app (o `DELETE` é o que se faz antes de entregar o webhook a outra instância):

```bash
curl -X DELETE "https://graph.facebook.com/v23.0/$WHATSAPP_APP_ID/subscriptions?object=whatsapp_business_account&access_token=$WHATSAPP_APP_ID|$WHATSAPP_APP_SECRET"
```

## Limites do número de teste

- Só envia para números **cadastrados na lista de destinatários de teste** do app. Qualquer outro
  devolve `(#131030) Recipient phone number not in allowed list`.
- O access token em uso é de **System User** (business.facebook.com → Business Settings → Usuários
  do sistema), com `whatsapp_business_messaging`, `whatsapp_business_management` e
  `business_management`, expiração **nunca**. O token de desenvolvedor do painel, que expira em
  ~24 h, serve só para experimentar. Para trocar o token: a API pública do n8n não atualiza
  credencial (só `POST`/`GET`), então ou se edita pela interface, ou se cria outra credencial e se
  repontam os 10 nós que usam `whatsAppApi`.
- Fora da janela de 24 h desde a última mensagem do morador, a Meta só aceita **template** aprovado.
  O fluxo hoje manda texto livre: ele responde, não inicia conversa.
