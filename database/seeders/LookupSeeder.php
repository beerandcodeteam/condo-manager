<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class LookupSeeder extends Seeder
{
    /**
     * Seed the global lookup tables, upserting by slug so it can run repeatedly.
     */
    public function run(): void
    {
        foreach ($this->lookups() as $table => $rows) {
            $this->upsertBySlug($table, $rows);
        }
    }

    /**
     * Lookup values from database-schema.md § Lookup Table Seeds.
     *
     * @return array<string, list<array<string, string|bool>>>
     */
    private function lookups(): array
    {
        return [
            'roles' => [
                ['slug' => 'super_admin', 'name' => 'Super admin'],
                ['slug' => 'sindico', 'name' => 'Síndico'],
                ['slug' => 'zelador', 'name' => 'Zelador'],
            ],
            'resident_profiles' => [
                ['slug' => 'proprietario', 'name' => 'Proprietário'],
                ['slug' => 'inquilino', 'name' => 'Inquilino'],
            ],
            'ticket_priorities' => [
                ['slug' => 'alta', 'name' => 'Alta'],
                ['slug' => 'media', 'name' => 'Média'],
                ['slug' => 'baixa', 'name' => 'Baixa'],
            ],
            'reservation_origins' => [
                ['slug' => 'whatsapp', 'name' => 'WhatsApp'],
                ['slug' => 'painel', 'name' => 'Painel'],
            ],
            'escalation_reasons' => [
                ['slug' => 'pediu_humano', 'name' => 'Pediu humano'],
                ['slug' => 'tool_recusou', 'name' => 'Tool recusou'],
                ['slug' => 'sem_regra', 'name' => 'Sem regra'],
            ],
            'tool_call_results' => [
                ['slug' => 'sucesso', 'name' => 'Sucesso'],
                ['slug' => 'vazio', 'name' => 'Vazio'],
                ['slug' => 'recusa', 'name' => 'Recusa'],
            ],
            'agent_message_roles' => [
                ['slug' => 'morador', 'name' => 'Morador'],
                ['slug' => 'agente', 'name' => 'Agente'],
            ],
            'agent_media_kinds' => [
                ['slug' => 'imagem', 'name' => 'Imagem'],
                ['slug' => 'audio', 'name' => 'Áudio'],
                ['slug' => 'video', 'name' => 'Vídeo'],
                ['slug' => 'documento', 'name' => 'Documento'],
            ],
            'agent_tools' => $this->agentTools(),
            'document_types' => [
                ['slug' => 'regimento', 'name' => 'Regimento interno'],
                ['slug' => 'convencao', 'name' => 'Convenção'],
            ],
            'document_statuses' => [
                ['slug' => 'processando', 'name' => 'Processando'],
                ['slug' => 'em_revisao', 'name' => 'Em revisão'],
                ['slug' => 'falha_extracao', 'name' => 'Falha na extração'],
                ['slug' => 'indexando', 'name' => 'Indexando'],
                ['slug' => 'falha_indexacao', 'name' => 'Falha na indexação'],
                ['slug' => 'publicado', 'name' => 'Publicado'],
                ['slug' => 'substituido', 'name' => 'Substituído'],
            ],
            'ticket_statuses' => [
                ['slug' => 'aberto', 'name' => 'Aberto', 'is_final' => false],
                ['slug' => 'em_andamento', 'name' => 'Em andamento', 'is_final' => false],
                ['slug' => 'resolvido', 'name' => 'Resolvido', 'is_final' => true],
                ['slug' => 'cancelado', 'name' => 'Cancelado', 'is_final' => true],
            ],
            'ticket_origins' => [
                ['slug' => 'whatsapp', 'name' => 'WhatsApp'],
                ['slug' => 'painel', 'name' => 'Painel'],
            ],
            'reservation_statuses' => [
                ['slug' => 'confirmada', 'name' => 'Confirmada'],
                ['slug' => 'cancelada', 'name' => 'Cancelada'],
            ],
            'reservation_cancellation_origins' => [
                ['slug' => 'morador', 'name' => 'Morador'],
                ['slug' => 'sindico', 'name' => 'Síndico'],
            ],
            'escalation_statuses' => [
                ['slug' => 'pendente', 'name' => 'Pendente'],
                ['slug' => 'em_atendimento', 'name' => 'Em atendimento'],
                ['slug' => 'resolvido', 'name' => 'Resolvido'],
            ],
            'webhook_events' => [
                ['slug' => 'ticket.status_changed', 'name' => 'Status do chamado alterado'],
                ['slug' => 'ticket.resident_notified', 'name' => 'Aviso ao morador sobre chamado'],
                ['slug' => 'reservation.cancelled', 'name' => 'Reserva cancelada'],
                ['slug' => 'escalation.answered', 'name' => 'Escalonamento respondido'],
            ],
            'webhook_delivery_statuses' => [
                ['slug' => 'pendente', 'name' => 'Pendente'],
                ['slug' => 'enviado', 'name' => 'Enviado'],
                ['slug' => 'falhou', 'name' => 'Falhou'],
            ],
        ];
    }

    /**
     * The agent tools exposed to n8n as api/v1 endpoints.
     *
     * @return list<array{slug: string, name: string, http_method: string, route: string, description: string}>
     */
    private function agentTools(): array
    {
        return [
            [
                'slug' => 'residents_lookup',
                'name' => 'verificar_morador',
                'http_method' => 'GET',
                'route' => '/api/v1/residents/lookup',
                'description' => 'Verifica se o telefone pertence a um morador ativo do condomínio.',
            ],
            [
                'slug' => 'rules_search',
                'name' => 'consultar_regimento',
                'http_method' => 'POST',
                'route' => '/api/v1/rules/search',
                'description' => 'Busca artigos do regimento interno e da convenção relevantes para a pergunta.',
            ],
            [
                'slug' => 'notices_list',
                'name' => 'consultar_comunicados',
                'http_method' => 'GET',
                'route' => '/api/v1/notices',
                'description' => 'Lista os comunicados ativos do condomínio.',
            ],
            [
                'slug' => 'tickets_create',
                'name' => 'abrir_chamado',
                'http_method' => 'POST',
                'route' => '/api/v1/tickets',
                'description' => 'Abre um chamado de manutenção para a unidade do morador.',
            ],
            [
                'slug' => 'tickets_list',
                'name' => 'listar_chamados',
                'http_method' => 'GET',
                'route' => '/api/v1/tickets',
                'description' => 'Lista os chamados da unidade do morador.',
            ],
            [
                'slug' => 'tickets_show',
                'name' => 'consultar_chamado',
                'http_method' => 'GET',
                'route' => '/api/v1/tickets/{protocol}',
                'description' => 'Consulta o status e o histórico de um chamado pelo protocolo.',
            ],
            [
                'slug' => 'areas_list',
                'name' => 'listar_areas',
                'http_method' => 'GET',
                'route' => '/api/v1/areas',
                'description' => 'Lista as áreas comuns ativas com faixas de horário e regras de reserva.',
            ],
            [
                'slug' => 'areas_availability',
                'name' => 'consultar_disponibilidade',
                'http_method' => 'GET',
                'route' => '/api/v1/areas/{id}/availability',
                'description' => 'Consulta as faixas livres de uma área comum em uma data.',
            ],
            [
                'slug' => 'reservations_create',
                'name' => 'reservar_area',
                'http_method' => 'POST',
                'route' => '/api/v1/reservations',
                'description' => 'Reserva uma faixa de horário de uma área comum para o morador.',
            ],
            [
                'slug' => 'reservations_list',
                'name' => 'listar_reservas',
                'http_method' => 'GET',
                'route' => '/api/v1/reservations',
                'description' => 'Lista as reservas futuras da unidade do morador.',
            ],
            [
                'slug' => 'reservations_cancel',
                'name' => 'cancelar_reserva',
                'http_method' => 'DELETE',
                'route' => '/api/v1/reservations/{id}',
                'description' => 'Cancela uma reserva do morador dentro do prazo de cancelamento.',
            ],
            [
                'slug' => 'escalations_create',
                'name' => 'escalar_humano',
                'http_method' => 'POST',
                'route' => '/api/v1/escalations',
                'description' => 'Encaminha a conversa para atendimento humano na fila de escalonamentos.',
            ],
        ];
    }

    /**
     * @param  list<array<string, string|bool>>  $rows
     */
    private function upsertBySlug(string $table, array $rows): void
    {
        $now = now();

        $rows = array_map(fn (array $row): array => [...$row, 'created_at' => $now, 'updated_at' => $now], $rows);

        $updateColumns = array_values(array_diff(array_keys($rows[0]), ['slug', 'created_at']));

        DB::table($table)->upsert($rows, ['slug'], $updateColumns);
    }
}
