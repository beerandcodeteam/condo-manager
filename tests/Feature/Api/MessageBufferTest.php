<?php

use App\Models\AgentToolCall;
use App\Models\Condominium;
use App\Services\Integration\MessageBuffer;
use App\Support\Tenancy\CurrentCondominium;
use Database\Seeders\LookupSeeder;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

beforeEach(function () {
    $this->seed(LookupSeeder::class);

    $this->condominium = Condominium::factory()->create();
    $this->token = $this->condominium->createToken('n8n')->plainTextToken;
    $this->phone = '+5511999990000';
});

function appendFragment(string $content): int
{
    return test()->withToken(test()->token)
        ->postJson(route('api.v1.buffer_append'), ['phone' => test()->phone, 'content' => $content])
        ->assertOk()
        ->json('sequence');
}

function flushBuffer(int $sequence)
{
    return test()->withToken(test()->token)
        ->postJson(route('api.v1.buffer_flush'), ['phone' => test()->phone, 'sequence' => $sequence])
        ->assertOk();
}

test('a single message flushes as itself', function () {
    $sequence = appendFragment('quero reservar o salão');

    expect($sequence)->toBe(1);

    flushBuffer($sequence)
        ->assertJsonPath('ready', true)
        ->assertJsonPath('content', 'quero reservar o salão');
});

test('a burst is joined and only the last fragment answers', function () {
    $first = appendFragment('oi');
    $second = appendFragment('tudo bem?');
    $third = appendFragment('queria saber do salão de festas');

    expect([$first, $second, $third])->toBe([1, 2, 3]);

    // As execuções dos fragmentos antigos param sem responder.
    flushBuffer($first)->assertJsonPath('ready', false)->assertJsonPath('content', '');
    flushBuffer($second)->assertJsonPath('ready', false);

    flushBuffer($third)
        ->assertJsonPath('ready', true)
        ->assertJsonPath('content', "oi\ntudo bem?\nqueria saber do salão de festas");
});

test('the burst is taken only once', function () {
    $sequence = appendFragment('oi');

    flushBuffer($sequence)->assertJsonPath('ready', true);
    // Retry do mesmo nó não pode responder duas vezes.
    flushBuffer($sequence)->assertJsonPath('ready', false);
});

test('a new burst starts clean after a flush', function () {
    flushBuffer(appendFragment('primeira pergunta'))->assertJsonPath('ready', true);

    $sequence = appendFragment('segunda pergunta');

    flushBuffer($sequence)
        ->assertJsonPath('ready', true)
        ->assertJsonPath('content', 'segunda pergunta');
});

test('buffers of different phones do not mix', function () {
    appendFragment('do primeiro numero');

    $other = test()->withToken($this->token)
        ->postJson(route('api.v1.buffer_append'), ['phone' => '+5511888880000', 'content' => 'do segundo numero'])
        ->assertOk()
        ->json('sequence');

    expect($other)->toBe(1);

    $this->withToken($this->token)
        ->postJson(route('api.v1.buffer_flush'), ['phone' => '+5511888880000', 'sequence' => $other])
        ->assertJsonPath('content', 'do segundo numero');
});

test('buffers of different condominiums do not mix', function () {
    $other = Condominium::factory()->create();
    $otherToken = $other->createToken('n8n')->plainTextToken;

    appendFragment('do condominio A');

    // O guard do Sanctum memoriza o tokenable resolvido, e o AuthManager sobrevive entre requests
    // do mesmo teste. Sem isto o segundo request continuaria autenticado como o primeiro condomínio
    // e o teste passaria pelo motivo errado. Em produção cada request tem container novo.
    $this->app['auth']->forgetGuards();

    $this->withToken($otherToken)
        ->postJson(route('api.v1.buffer_append'), ['phone' => $this->phone, 'content' => 'do condominio B'])
        ->assertOk()
        ->assertJsonPath('sequence', 1);

    $this->withToken($otherToken)
        ->postJson(route('api.v1.buffer_flush'), ['phone' => $this->phone, 'sequence' => 1])
        ->assertJsonPath('content', 'do condominio B');

    // E o buffer do primeiro condomínio continua intacto.
    $this->app['auth']->forgetGuards();

    $this->withToken($this->token)
        ->postJson(route('api.v1.buffer_flush'), ['phone' => $this->phone, 'sequence' => 1])
        ->assertJsonPath('ready', true)
        ->assertJsonPath('content', 'do condominio A');
});

test('the response carries the configured wait so n8n and the app cannot drift', function () {
    config(['condo.conversations.buffer_seconds' => 17]);

    $this->withToken($this->token)
        ->postJson(route('api.v1.buffer_append'), ['phone' => $this->phone, 'content' => 'oi'])
        ->assertOk()
        ->assertJsonPath('wait_seconds', 17);
});

test('a runaway sender cannot grow the buffer without bound', function () {
    $max = (int) config('condo.conversations.buffer_max_fragments');
    $last = 0;

    foreach (range(1, $max + 3) as $i) {
        $last = appendFragment("msg {$i}");
    }

    $content = flushBuffer($last)->json('content');

    expect(substr_count($content, "\n") + 1)->toBe($max)
        // Mantém os mais recentes, que são os que importam.
        ->and($content)->toEndWith('msg '.($max + 3))
        ->and($content)->not->toContain('msg 1'.PHP_EOL);
});

test('an unknown sequence never yields content', function () {
    appendFragment('oi');

    flushBuffer(999)->assertJsonPath('ready', false)->assertJsonPath('content', '');
});

test('flushing an empty buffer is not ready', function () {
    flushBuffer(1)->assertJsonPath('ready', false);
});

test('the phone is normalized so the same person shares one buffer', function () {
    appendFragment('primeiro');

    $sequence = $this->withToken($this->token)
        ->postJson(route('api.v1.buffer_append'), ['phone' => '+55 11 99999-0000', 'content' => 'segundo'])
        ->assertOk()
        ->json('sequence');

    expect($sequence)->toBe(2);

    flushBuffer($sequence)->assertJsonPath('content', "primeiro\nsegundo");
});

test('buffering records no agent tool call', function () {
    flushBuffer(appendFragment('oi'));

    expect(AgentToolCall::withoutGlobalScopes()->count())->toBe(0);
});

test('the service refuses to work without a tenant', function () {
    app(CurrentCondominium::class)->clear();

    expect(fn () => app(MessageBuffer::class)->append($this->phone, 'oi'))
        ->toThrow(NotFoundHttpException::class);
});
