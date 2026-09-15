<?php

use App\Models\AgentTool;
use App\Models\AgentToolCall;
use App\Models\Condominium;
use App\Models\Resident;
use App\Models\RuleArticle;
use App\Models\RuleDocument;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\LookupSeeder;

beforeEach(function () {
    $this->seed(LookupSeeder::class);
    $this->withoutVite();
    $this->travelTo(CarbonImmutable::parse('2026-09-15 14:00:00', 'America/Sao_Paulo'));

    $this->condominium = Condominium::factory()->create();
    $this->document = RuleDocument::factory()->publicado()->for($this->condominium)->create();
    $this->frequentArticle = RuleArticle::factory()->for($this->document)->create(['reference' => 'Art. 14', 'position' => 1]);
    $this->rareArticle = RuleArticle::factory()->for($this->document)->create(['reference' => 'Art. 22', 'position' => 2]);
    $this->marina = Resident::factory()->for($this->condominium)->create();
    $this->jorge = Resident::factory()->for($this->condominium)->create();

    $toolCall = fn (?Resident $resident, string $tool, ?array $entities, CarbonImmutable $calledAt, string $state = 'sucesso') => AgentToolCall::factory()->{$state}()->create([
        'condominium_id' => $this->condominium->id,
        'resident_id' => $resident?->id,
        'phone' => $resident === null ? '+5541990000001' : $resident->phone,
        'agent_tool_id' => AgentTool::idFor($tool),
        'entities' => $entities,
        'created_at' => $calledAt,
    ]);

    $toolCall($this->marina, AgentTool::RULES_SEARCH, ['article_ids' => [$this->frequentArticle->id]], now()->subDay());
    $toolCall($this->marina, AgentTool::RULES_SEARCH, ['article_ids' => [$this->frequentArticle->id, $this->rareArticle->id]], now()->subDays(2));
    $toolCall($this->marina, AgentTool::RULES_SEARCH, ['article_ids' => [$this->frequentArticle->id]], now()->subDays(10));
    $toolCall($this->marina, AgentTool::RULES_SEARCH, ['article_ids' => []], now()->subHour(), 'vazio');
    $toolCall($this->marina, AgentTool::TICKETS_CREATE, ['ticket_id' => 1], now()->subMinutes(30));
    $toolCall($this->jorge, AgentTool::NOTICES_LIST, null, now()->subDay());
    $toolCall(null, AgentTool::RULES_SEARCH, ['article_ids' => [$this->frequentArticle->id]], now()->subMinutes(10));

    $other = Condominium::factory()->create();
    AgentToolCall::factory()->create([
        'condominium_id' => $other->id,
        'resident_id' => Resident::factory()->for($other)->create()->id,
        'agent_tool_id' => AgentTool::idFor(AgentTool::RULES_SEARCH),
        'created_at' => now()->subDay(),
    ]);

    $this->superAdmin = User::factory()->superAdmin()->create();
});

/**
 * First number captured by the pattern in the HTML, failing the test when it is absent.
 */
function capturedCount(string $pattern, string $html): int
{
    expect(preg_match($pattern, $html, $matches))->toBe(1);

    return (int) $matches[1];
}

test('residents screen counts every tool call of the resident as an interaction', function () {
    $html = $this->actingAs($this->superAdmin)
        ->withSession(['current_condominium_id' => $this->condominium->id])
        ->get(route('residents.index'))
        ->assertOk()
        ->getContent();

    expect(capturedCount('/data-resident-row="'.$this->marina->id.'".*?data-interactions="(\d+)"/s', $html))->toBe(5)
        ->and(capturedCount('/data-resident-row="'.$this->jorge->id.'".*?data-interactions="(\d+)"/s', $html))->toBe(1);
});

test('rule documents screen counts every call that returned the article as a citation', function () {
    $this->actingAs($this->superAdmin)
        ->withSession(['current_condominium_id' => $this->condominium->id])
        ->get(route('rule-documents.index', ['documento' => $this->document->id]))
        ->assertOk()
        ->assertSeeInOrder(['Art. 14', 'citado 4×', 'Art. 22', 'citado 1×']);
});

test('integration card counts the calls of each tool in the last 7 days', function () {
    $html = $this->actingAs($this->superAdmin)
        ->withSession(['current_condominium_id' => $this->condominium->id])
        ->get(route('settings'))
        ->assertOk()
        ->getContent();

    $recentCalls = fn (string $tool): int => capturedCount('/data-agent-tool="'.$tool.'".*?data-recent-calls>(\d+) \/ 7d/s', $html);

    expect($recentCalls(AgentTool::RULES_SEARCH))->toBe(4)
        ->and($recentCalls(AgentTool::TICKETS_CREATE))->toBe(1)
        ->and($recentCalls(AgentTool::NOTICES_LIST))->toBe(1)
        ->and($recentCalls(AgentTool::RESIDENTS_LOOKUP))->toBe(0);
});
