<?php

namespace Database\Seeders;

use App\Models\AgentTool;
use App\Models\AgentToolCall;
use App\Models\Block;
use App\Models\CommonArea;
use App\Models\CommonAreaSlot;
use App\Models\Condominium;
use App\Models\DocumentStatus;
use App\Models\DocumentType;
use App\Models\Escalation;
use App\Models\EscalationReason;
use App\Models\EscalationStatus;
use App\Models\Reservation;
use App\Models\ReservationOrigin;
use App\Models\ReservationStatus;
use App\Models\Resident;
use App\Models\ResidentProfile;
use App\Models\Role;
use App\Models\RuleArticle;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\TicketOrigin;
use App\Models\TicketPriority;
use App\Models\TicketStatus;
use App\Models\ToolCallResult;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Local demo data mirroring the backoffice design (.spec/init/design).
 */
class DemoSeeder extends Seeder
{
    private const PASSWORD = 'password';

    /**
     * "Now" in the condominium timezone; converted to UTC before being stored.
     */
    private CarbonImmutable $now;

    public function run(): void
    {
        if (Condominium::where('name', 'Residencial Aurora')->exists()) {
            return;
        }

        $this->now = CarbonImmutable::now(config('condo.timezone'));

        DB::transaction(fn () => Model::unguarded(function (): void {
            User::create([
                'name' => 'Super Admin',
                'email' => 'admin@teste.com',
                'password' => self::PASSWORD,
                'role_id' => Role::idFor(Role::SUPER_ADMIN),
                'condominium_id' => null,
                'email_verified_at' => now(),
            ]);

            $aurora = $this->createCondominium([
                'name' => 'Residencial Aurora',
                'city' => 'Curitiba',
                'whatsapp_number' => '+554130002200',
                'caretaker_name' => 'José Carvalho',
                'caretaker_phone' => '+5541995550102',
                'quiet_hours_start' => '22:00',
                'quiet_hours_end' => '08:00',
            ]);
            $this->createCondominium(['name' => 'Edifício Solar das Palmeiras', 'city' => 'São Paulo']);
            $this->createCondominium(['name' => 'Villa Serena', 'city' => 'Florianópolis']);

            $this->seedAurora($aurora);
        }));
    }

    /**
     * @param  array<string, string>  $attributes
     */
    private function createCondominium(array $attributes): Condominium
    {
        $condominium = Condominium::create($attributes);

        foreach (TicketCategory::DEFAULTS as $slug => $name) {
            $condominium->ticketCategories()->create(['slug' => $slug, 'name' => $name]);
        }

        return $condominium;
    }

    private function seedAurora(Condominium $aurora): void
    {
        $sindica = $this->createPanelUser($aurora, 'Teste Sindico', 'sindico@teste.com', Role::SINDICO);
        $zelador = $this->createPanelUser($aurora, 'Teste Zelador', 'zelador@teste.com', Role::ZELADOR);

        $residents = $this->seedResidents($aurora);
        $articles = $this->seedRuleDocuments($aurora, $sindica);
        $this->seedNotices($aurora, $sindica);
        $tickets = $this->seedTickets($aurora, $residents, $sindica, $zelador);
        $escalations = $this->seedEscalations($aurora, $residents);
        $reservations = $this->seedReservations($aurora, $residents);
        $this->seedToolCalls($aurora, $residents, $articles, $tickets, $escalations, $reservations);
    }

    private function createPanelUser(Condominium $condominium, string $name, string $email, string $roleSlug): User
    {
        return User::create([
            'name' => $name,
            'email' => $email,
            'password' => self::PASSWORD,
            'role_id' => Role::idFor($roleSlug),
            'condominium_id' => $condominium->id,
            'email_verified_at' => now(),
        ]);
    }

    /**
     * Residents keyed by unit label (e.g. "1201A").
     *
     * @return array<string, Resident>
     */
    private function seedResidents(Condominium $aurora): array
    {
        $blocks = collect(['A', 'B'])->mapWithKeys(fn (string $name): array => [$name => $aurora->blocks()->create(['name' => $name])]);

        $residentBase = [
            ['101A', 'Helena Barros', '+55 41 99201-1100', ResidentProfile::PROPRIETARIO],
            ['102A', 'Ana Beatriz', '+55 41 99123-8800', ResidentProfile::PROPRIETARIO],
            ['305A', 'Paula Ribeiro', '+55 41 98877-1290', ResidentProfile::PROPRIETARIO],
            ['402B', 'Marina Souza', '+55 41 99655-0021', ResidentProfile::INQUILINO],
            ['607A', 'Lucia Prado', '+55 41 99444-2312', ResidentProfile::PROPRIETARIO],
            ['708B', 'Jorge Amaral', '+55 41 99700-4411', ResidentProfile::INQUILINO],
            ['801A', 'Bruno Martins', '+55 41 98111-0909', ResidentProfile::PROPRIETARIO],
            ['911B', 'Rafael Nunes', '+55 41 98300-7765', ResidentProfile::PROPRIETARIO],
            ['1201A', 'Carlos Mendes', '+55 41 99812-3344', ResidentProfile::PROPRIETARIO],
            ['1504B', 'Fernando Lima', '+55 41 99080-5566', ResidentProfile::INQUILINO],
        ];

        return collect($residentBase)->mapWithKeys(function (array $resident) use ($aurora, $blocks): array {
            [$unitLabel, $name, $phone, $profileSlug] = $resident;
            /** @var Block $block */
            $block = $blocks[substr($unitLabel, -1)];
            $unit = $aurora->units()->create(['block_id' => $block->id, 'number' => substr($unitLabel, 0, -1)]);

            return [$unitLabel => $aurora->residents()->create([
                'unit_id' => $unit->id,
                'resident_profile_id' => ResidentProfile::idFor($profileSlug),
                'name' => $name,
                'phone' => $phone,
            ])];
        })->all();
    }

    /**
     * Published regimento and convenção; articles keyed by reference.
     *
     * @return array<string, RuleArticle>
     */
    private function seedRuleDocuments(Condominium $aurora, User $sindica): array
    {
        $documents = [
            [DocumentType::REGIMENTO, 'Regimento Interno', [
                ['Art. 12', 'Horário de silêncio', 'É vedado produzir ruído que perturbe o sossego entre 22h e 8h, inclusive em áreas comuns.'],
                ['Art. 14', 'Obras e reformas', 'Obras são permitidas de segunda a sexta, das 8h às 17h, e aos sábados das 9h às 13h. Proibidas em domingos e feriados.'],
                ['Art. 18', 'Animais', 'Permitidos animais de pequeno e médio porte, conduzidos com guia nas áreas comuns e transportados no elevador de serviço.'],
                ['Art. 22', 'Uso das áreas comuns', 'A limpeza após o uso de salão e churrasqueira é responsabilidade do condômino reservante, sob pena de multa.'],
                ['Art. 31', 'Fachada e sacadas', 'É proibido alterar a fachada, incluindo fechamento de sacadas, sem aprovação em assembleia.'],
            ]],
            [DocumentType::CONVENCAO, 'Convenção do Condomínio', [
                ['Cl. 9ª', 'Fração ideal e rateio', 'As despesas ordinárias são rateadas conforme a fração ideal de cada unidade.'],
                ['Cl. 15ª', 'Vagas de garagem', 'As vagas são de uso exclusivo, vinculadas à unidade, vedada a locação a terceiros não condôminos.'],
                ['Cl. 27ª', 'Multas', 'Infrações ao regimento sujeitam o condômino a multa de até 5 vezes a cota mensal.'],
                ['Cl. 40ª', 'Assembleias', 'A assembleia ordinária ocorre anualmente para aprovação de contas e orçamento.'],
            ]],
        ];

        $articles = [];

        foreach ($documents as [$typeSlug, $title, $documentArticles]) {
            $document = $aurora->ruleDocuments()->create([
                'document_type_id' => DocumentType::idFor($typeSlug),
                'document_status_id' => DocumentStatus::idFor(DocumentStatus::PUBLICADO),
                'title' => $title,
                'file_path' => "rule-documents/demo/{$typeSlug}.pdf",
                'uploaded_by_user_id' => $sindica->id,
                'published_by_user_id' => $sindica->id,
                'published_at' => $this->utc($this->now->subMonths(6)),
            ]);

            foreach ($documentArticles as $position => [$reference, $articleTitle, $body]) {
                $articles[$reference] = RuleArticle::create([
                    'condominium_id' => $aurora->id,
                    'rule_document_id' => $document->id,
                    'reference' => $reference,
                    'title' => $articleTitle,
                    'body' => $body,
                    'position' => $position + 1,
                ]);
            }
        }

        return $articles;
    }

    private function seedNotices(Condominium $aurora, User $sindica): void
    {
        $noticeBase = [
            ['Manutenção da caixa d’água', 'Quinta 18/09, das 9h às 14h, não haverá abastecimento nos blocos A e B. Reserve água com antecedência.', true, 0],
            ['Obra no hall do bloco A', 'Troca do piso do hall de entrada. Acesso pela porta lateral até o fim do mês. Ruído entre 8h e 17h.', true, 14],
            ['Assembleia ordinária', 'Terça 30/09, 19h30, no salão de festas. Pauta: previsão orçamentária 2027 e reforma da fachada.', true, 5],
            ['Dedetização das áreas comuns', 'Sábado 06/09, das 8h às 12h. Evite circular na garagem e no salão durante o serviço.', false, 14],
            ['Limpeza da piscina', 'Piscina fechada para tratamento de 25 a 27 de agosto.', false, 24],
        ];

        foreach ($noticeBase as [$title, $body, $isActive, $daysAgo]) {
            $publishedAt = $this->utc($this->now->subDays($daysAgo)->setTime(8, 0));

            $aurora->notices()->create([
                'title' => $title,
                'body' => $body,
                'is_active' => $isActive,
                'created_by_user_id' => $sindica->id,
                'created_at' => $publishedAt,
                'updated_at' => $publishedAt,
            ]);
        }
    }

    /**
     * Tickets keyed by protocol number.
     *
     * @param  array<string, Resident>  $residents
     * @return array<int, Ticket>
     */
    private function seedTickets(Condominium $aurora, array $residents, User $sindica, User $zelador): array
    {
        $today = $this->now->startOfDay();

        $tickets = [
            4821 => $this->createTicket($aurora, 4821, 'Elevador do bloco B parado', 'Morador relata que o elevador do bloco B parou no térreo com pessoas aguardando. Segunda ocorrência na semana. Sem pessoas presas.', 'elevador', TicketPriority::ALTA, $residents['1201A'], null, 'Elevador do bloco B', [
                [TicketStatus::ABERTO, $this->now->subMinutes(8), null, null],
            ]),
            4819 => $this->createTicket($aurora, 4819, 'Lâmpada queimada garagem G2', 'Luminária próxima às vagas 41–44 do G2 apagada.', 'eletrica', TicketPriority::BAIXA, $residents['101A'], null, 'Garagem G2', [
                [TicketStatus::ABERTO, $today->subDay()->setTime(18, 20), null, null],
            ]),
            4815 => $this->createTicket($aurora, 4815, 'Portão da garagem não fecha', 'Portão automático fica aberto após passagem. Técnico da Portec acionado.', 'seguranca', TicketPriority::ALTA, null, $zelador, 'Portão da garagem', [
                [TicketStatus::ABERTO, $today->subDays(2)->setTime(7, 10), $zelador, null],
                [TicketStatus::EM_ANDAMENTO, $today->subDays(2)->setTime(11, 0), $zelador, 'Técnico agendado.'],
            ]),
            4802 => $this->createTicket($aurora, 4802, 'Infiltração no teto do banheiro', 'Mancha e gotejamento no teto do banheiro social, provável origem na unidade 1011B.', 'hidraulica', TicketPriority::MEDIA, $residents['911B'], null, 'Banheiro social', [
                [TicketStatus::ABERTO, $today->subDays(5)->setTime(14, 2), null, null],
                [TicketStatus::EM_ANDAMENTO, $today->subDays(3)->setTime(10, 30), $sindica, 'Vistoria realizada.'],
            ]),
            4798 => $this->createTicket($aurora, 4798, 'Interfone 305A sem áudio', 'Interfone não recebe áudio da portaria.', 'outros', TicketPriority::BAIXA, $residents['305A'], null, null, [
                [TicketStatus::ABERTO, $today->subDays(7)->setTime(10, 0), null, null],
                [TicketStatus::RESOLVIDO, $today->subDays(6)->setTime(15, 0), $zelador, 'Concluído, morador avisado.'],
            ]),
            4791 => $this->createTicket($aurora, 4791, 'Vazamento na torneira do salão', 'Torneira da copa do salão de festas pingando.', 'hidraulica', TicketPriority::MEDIA, null, $sindica, 'Salão de festas', [
                [TicketStatus::ABERTO, $today->subDays(10)->setTime(9, 0), $sindica, null],
                [TicketStatus::RESOLVIDO, $today->subDays(9)->setTime(16, 0), $zelador, null],
            ]),
        ];

        $noticeSentAt = $this->utc($today->subDays(3)->setTime(16, 0));
        $tickets[4802]->residentNotices()->create([
            'condominium_id' => $aurora->id,
            'user_id' => $sindica->id,
            'message' => 'Reparo agendado para quarta 17/09, das 9h às 12h. Precisa que alguém esteja em casa.',
            'created_at' => $noticeSentAt,
            'updated_at' => $noticeSentAt,
        ]);

        $aurora->update(['last_ticket_protocol' => max(array_keys($tickets))]);

        return $tickets;
    }

    /**
     * Creates a ticket (WhatsApp when it has a resident, panel otherwise) with its status history.
     *
     * @param  non-empty-list<array{0: string, 1: CarbonImmutable, 2: User|null, 3: string|null}>  $timeline
     */
    private function createTicket(Condominium $aurora, int $protocolNumber, string $title, string $description, string $categorySlug, string $prioritySlug, ?Resident $resident, ?User $openedBy, ?string $location, array $timeline): Ticket
    {
        $openedAt = $timeline[0][1];
        $lastChange = $timeline[array_key_last($timeline)];

        $ticket = $aurora->tickets()->create([
            'protocol_number' => $protocolNumber,
            'ticket_status_id' => TicketStatus::idFor($lastChange[0]),
            'ticket_priority_id' => TicketPriority::idFor($prioritySlug),
            'ticket_origin_id' => TicketOrigin::idFor($resident === null ? TicketOrigin::PAINEL : TicketOrigin::WHATSAPP),
            'ticket_category_id' => $aurora->ticketCategories()->where('slug', $categorySlug)->value('id'),
            'unit_id' => $resident?->unit_id,
            'resident_id' => $resident?->id,
            'opened_by_user_id' => $openedBy?->id,
            'description' => "{$title}. {$description}",
            'location' => $location,
            'created_at' => $this->utc($openedAt),
            'updated_at' => $this->utc($lastChange[1]),
        ]);

        $previousStatusId = null;

        foreach ($timeline as [$statusSlug, $changedAt, $author, $comment]) {
            $ticket->statusChanges()->create([
                'condominium_id' => $aurora->id,
                'from_ticket_status_id' => $previousStatusId,
                'to_ticket_status_id' => TicketStatus::idFor($statusSlug),
                'comment' => $comment,
                'user_id' => $author?->id,
                'created_at' => $this->utc($changedAt),
                'updated_at' => $this->utc($changedAt),
            ]);
            $previousStatusId = TicketStatus::idFor($statusSlug);
        }

        return $ticket;
    }

    /**
     * Pending escalations keyed by unit label.
     *
     * @param  array<string, Resident>  $residents
     * @return array<string, Escalation>
     */
    private function seedEscalations(Condominium $aurora, array $residents): array
    {
        $escBase = [
            ['102A', EscalationReason::PEDIU_HUMANO, 'Cobrança de taxa extra de setembro que ela diz já ter pago.', 14],
            ['1504B', EscalationReason::TOOL_RECUSOU, 'Quer o salão de festas em 20/09 à noite, mesma data já ocupada.', 32],
            ['607A', EscalationReason::SEM_REGRA, 'Pergunta se pode instalar carregador de carro elétrico na vaga.', 65],
        ];

        return collect($escBase)->mapWithKeys(function (array $escalation) use ($aurora, $residents): array {
            [$unitLabel, $reasonSlug, $summary, $waitingMinutes] = $escalation;
            $createdAt = $this->utc($this->now->subMinutes($waitingMinutes));

            return [$unitLabel => $aurora->escalations()->create([
                'resident_id' => $residents[$unitLabel]->id,
                'unit_id' => $residents[$unitLabel]->unit_id,
                'escalation_status_id' => EscalationStatus::idFor(EscalationStatus::PENDENTE),
                'escalation_reason_id' => EscalationReason::idFor($reasonSlug),
                'summary' => $summary,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ])];
        })->all();
    }

    /**
     * Common areas with their slots and the reservations of the current week, keyed by "area|unit|weekday".
     *
     * @param  array<string, Resident>  $residents
     * @return array<string, Reservation>
     */
    private function seedReservations(Condominium $aurora, array $residents): array
    {
        $areaBase = [
            'Salão de festas' => ['Até 60 pessoas.', 168, [['19:00', '23:00']]],
            'Churrasqueira' => ['Até 25 pessoas.', 48, [['12:00', '18:00'], ['18:00', '22:00']]],
            'Quadra' => ['Reservas em blocos de 1 hora.', 24, array_map(fn (int $hour): array => [sprintf('%02d:00', $hour), sprintf('%02d:00', $hour + 1)], range(8, 21))],
            'Espaço gourmet' => ['Até 20 pessoas.', 48, [['12:00', '17:00'], ['18:00', '23:00']]],
        ];

        /** @var array<string, CommonArea> $areas */
        $areas = [];

        foreach ($areaBase as $name => [$description, $minAdvanceHours, $slots]) {
            $areas[$name] = $aurora->commonAreas()->create([
                'name' => $name,
                'description' => $description,
                'min_advance_hours' => $minAdvanceHours,
            ]);

            foreach ($slots as [$startsAt, $endsAt]) {
                $areas[$name]->slots()->create(['condominium_id' => $aurora->id, 'starts_at' => $startsAt, 'ends_at' => $endsAt]);
            }
        }

        $weekBookings = [
            ['Salão de festas', '19:00', '402B', 4],
            ['Salão de festas', '19:00', '801A', 6],
            ['Churrasqueira', '12:00', '305A', 5],
            ['Churrasqueira', '12:00', '708B', 6],
            ['Quadra', '19:00', '911B', 0],
            ['Quadra', '19:00', '911B', 2],
            ['Quadra', '09:00', '1504B', 5],
        ];

        $startOfWeek = $this->now->startOfWeek(CarbonImmutable::MONDAY);
        $reservations = [];

        foreach ($weekBookings as [$areaName, $startsAt, $unitLabel, $weekday]) {
            /** @var Collection<int, CommonAreaSlot> $slots */
            $slots = $areas[$areaName]->slots;
            $slot = $slots->first(fn (CommonAreaSlot $slot): bool => str_starts_with($slot->starts_at, $startsAt));
            $resident = $residents[$unitLabel];
            $bookedAt = $this->utc($startOfWeek->subDays(3)->setTime(10, 0));

            $reservations["{$areaName}|{$unitLabel}|{$weekday}"] = $aurora->reservations()->create([
                'common_area_id' => $slot->common_area_id,
                'common_area_slot_id' => $slot->id,
                'unit_id' => $resident->unit_id,
                'resident_id' => $resident->id,
                'reservation_status_id' => ReservationStatus::idFor(ReservationStatus::CONFIRMADA),
                'reservation_origin_id' => ReservationOrigin::idFor(ReservationOrigin::WHATSAPP),
                'date' => $startOfWeek->addDays($weekday)->toDateString(),
                'starts_at' => $slot->starts_at,
                'ends_at' => $slot->ends_at,
                'created_at' => $bookedAt,
                'updated_at' => $bookedAt,
            ]);
        }

        return $reservations;
    }

    /**
     * Agent activity for the overview: the design's latest actions today, plus volume for today,
     * yesterday and the previous days of the last week.
     *
     * @param  array<string, Resident>  $residents
     * @param  array<string, RuleArticle>  $articles
     * @param  array<int, Ticket>  $tickets
     * @param  array<string, Escalation>  $escalations
     * @param  array<string, Reservation>  $reservations
     */
    private function seedToolCalls(Condominium $aurora, array $residents, array $articles, array $tickets, array $escalations, array $reservations): void
    {
        $latestActivity = [
            ['402B', AgentTool::RULES_SEARCH, ToolCallResult::SUCESSO, ['article_ids' => [$articles['Art. 14']->id]], 4],
            ['1201A', AgentTool::TICKETS_CREATE, ToolCallResult::SUCESSO, ['ticket_id' => $tickets[4821]->id], 8],
            ['305A', AgentTool::RESERVATIONS_CREATE, ToolCallResult::SUCESSO, ['reservation_id' => $reservations['Churrasqueira|305A|5']->id], 15],
            ['708B', AgentTool::NOTICES_LIST, ToolCallResult::SUCESSO, null, 26],
            ['1504B', AgentTool::ESCALATIONS_CREATE, ToolCallResult::SUCESSO, ['escalation_id' => $escalations['1504B']->id], 32],
            ['1504B', AgentTool::RESERVATIONS_CREATE, ToolCallResult::RECUSA, null, 33],
            ['102A', AgentTool::ESCALATIONS_CREATE, ToolCallResult::SUCESSO, ['escalation_id' => $escalations['102A']->id], 37],
            ['911B', AgentTool::TICKETS_SHOW, ToolCallResult::SUCESSO, ['ticket_id' => $tickets[4802]->id], 51],
            ['607A', AgentTool::ESCALATIONS_CREATE, ToolCallResult::SUCESSO, ['escalation_id' => $escalations['607A']->id], 65],
            ['607A', AgentTool::RULES_SEARCH, ToolCallResult::VAZIO, ['article_ids' => []], 66],
        ];

        foreach ($latestActivity as [$unitLabel, $toolSlug, $resultSlug, $entities, $minutesAgo]) {
            $this->createToolCall($aurora, $residents[$unitLabel], $toolSlug, $resultSlug, $entities, $this->now->subMinutes($minutesAgo));
        }

        $articleIds = array_values(array_map(fn (RuleArticle $article): int => $article->id, $articles));
        $routineTools = [AgentTool::RESIDENTS_LOOKUP, AgentTool::RULES_SEARCH, AgentTool::RULES_SEARCH, AgentTool::NOTICES_LIST, AgentTool::TICKETS_LIST, AgentTool::AREAS_LIST, AgentTool::AREAS_AVAILABILITY, AgentTool::RESERVATIONS_LIST];
        $callsPerDay = [0 => 12, 1 => 16, 2 => 14, 3 => 9, 4 => 18, 5 => 11, 6 => 13];
        $residentsPerDay = [0 => 7, 1 => 8, 2 => 6, 3 => 5, 4 => 9, 5 => 6, 6 => 7];

        foreach ($callsPerDay as $daysAgo => $callCount) {
            $day = $this->now->subDays($daysAgo)->startOfDay();
            $activeResidents = array_slice(array_values($residents), 0, $residentsPerDay[$daysAgo]);
            $latestHour = $daysAgo === 0 ? max(7, min(21, $this->now->hour - 1)) : 21;

            for ($call = 0; $call < $callCount; $call++) {
                $toolSlug = fake()->randomElement($routineTools);
                $resultSlug = fake()->randomElement([ToolCallResult::SUCESSO, ToolCallResult::SUCESSO, ToolCallResult::SUCESSO, ToolCallResult::SUCESSO, ToolCallResult::VAZIO]);
                $entities = $toolSlug === AgentTool::RULES_SEARCH
                    ? ['article_ids' => $resultSlug === ToolCallResult::SUCESSO ? fake()->randomElements($articleIds, fake()->numberBetween(1, 3)) : []]
                    : null;
                $calledAt = $day->setTime(fake()->numberBetween(7, $latestHour), fake()->numberBetween(0, 59));

                $this->createToolCall($aurora, $activeResidents[$call % count($activeResidents)], $toolSlug, $resultSlug, $entities, $calledAt->min($this->now));
            }
        }
    }

    /**
     * @param  array<string, mixed>|null  $entities
     */
    private function createToolCall(Condominium $aurora, Resident $resident, string $toolSlug, string $resultSlug, ?array $entities, CarbonImmutable $calledAt): void
    {
        $refused = $resultSlug === ToolCallResult::RECUSA;

        AgentToolCall::create([
            'condominium_id' => $aurora->id,
            'agent_tool_id' => AgentTool::idFor($toolSlug),
            'resident_id' => $resident->id,
            'phone' => $resident->phone,
            'tool_call_result_id' => ToolCallResult::idFor($resultSlug),
            'http_status' => $refused ? 422 : 200,
            'error_code' => $refused ? 'slot_unavailable' : null,
            'entities' => $entities,
            'latency_ms' => fake()->numberBetween(90, 1400),
            'created_at' => $this->utc($calledAt),
            'updated_at' => $this->utc($calledAt),
        ]);
    }

    private function utc(CarbonImmutable $dateTime): CarbonImmutable
    {
        return $dateTime->setTimezone(config('app.timezone'));
    }
}
