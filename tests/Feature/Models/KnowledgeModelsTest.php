<?php

use App\Models\Notice;
use App\Models\RuleArticle;
use App\Models\RuleDocument;
use Database\Seeders\LookupSeeder;

beforeEach(function () {
    $this->seed(LookupSeeder::class);
});

test('Notice::active excludes inactive and deleted notices', function () {
    $activeNotice = Notice::factory()->create();
    Notice::factory()->inactive()->create();
    Notice::factory()->create()->delete();

    expect(Notice::active()->pluck('id')->all())->toBe([$activeNotice->id]);
});

test('RuleDocument::published only returns documents with status publicado', function () {
    $publishedDocument = RuleDocument::factory()->publicado()->create();
    RuleDocument::factory()->processando()->create();
    RuleDocument::factory()->emRevisao()->create();
    RuleDocument::factory()->substituido()->create();

    expect(RuleDocument::published()->pluck('id')->all())->toBe([$publishedDocument->id]);
});

test('document articles are ordered by position', function () {
    $document = RuleDocument::factory()->publicado()->create();
    RuleArticle::factory()->for($document)->create(['position' => 2, 'reference' => 'Art. 14']);
    RuleArticle::factory()->for($document)->create(['position' => 1, 'reference' => 'Art. 12']);

    expect($document->articles->pluck('reference')->all())->toBe(['Art. 12', 'Art. 14']);
});

test('article embedding round-trips as an array', function () {
    $embedding = array_fill(0, 1536, 0.25);

    $article = RuleArticle::factory()->create(['embedding' => $embedding, 'embedded_at' => now()]);

    expect($article->fresh()->embedding)->toBe($embedding)
        ->and($article->fresh()->embedded_at)->toBeInstanceOf(DateTimeInterface::class);
});
