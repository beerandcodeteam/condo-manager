<?php

use App\Services\RuleDocuments\RuleArticleSplitter;

beforeEach(function () {
    $this->splitter = new RuleArticleSplitter;
});

test('splits the text at article and clause headers', function (string $text, array $expected) {
    expect($this->splitter->split($text))->toBe($expected);
})->with([
    'Art. 1º' => [
        "Art. 1º O condomínio é regido por este regimento.\nArt. 2º Todos devem cumpri-lo.",
        [
            ['reference' => 'Art. 1º', 'title' => null, 'body' => 'O condomínio é regido por este regimento.', 'position' => 1],
            ['reference' => 'Art. 2º', 'title' => null, 'body' => 'Todos devem cumpri-lo.', 'position' => 2],
        ],
    ],
    'Artigo 3' => [
        "Artigo 3\nÉ proibido estender roupas\n   nas janelas.",
        [
            ['reference' => 'Art. 3', 'title' => null, 'body' => 'É proibido estender roupas nas janelas.', 'position' => 1],
        ],
    ],
    'Art. 4o and Art. 5°' => [
        "Art. 4o Os animais devem circular no colo.\nArt. 5° O salão fecha às 22h.",
        [
            ['reference' => 'Art. 4º', 'title' => null, 'body' => 'Os animais devem circular no colo.', 'position' => 1],
            ['reference' => 'Art. 5º', 'title' => null, 'body' => 'O salão fecha às 22h.', 'position' => 2],
        ],
    ],
    'Cláusula 9ª' => [
        "Cláusula 9ª: Das despesas\nAs despesas são rateadas pela fração ideal.\nClausula 10a As multas são aplicadas pelo síndico.\nCl. 11 Casos omissos vão à assembleia.",
        [
            ['reference' => 'Cl. 9ª', 'title' => 'Das despesas', 'body' => 'As despesas são rateadas pela fração ideal.', 'position' => 1],
            ['reference' => 'Cl. 10ª', 'title' => null, 'body' => 'As multas são aplicadas pelo síndico.', 'position' => 2],
            ['reference' => 'Cl. 11', 'title' => null, 'body' => 'Casos omissos vão à assembleia.', 'position' => 3],
        ],
    ],
    'Art. 14 - Obras e reformas' => [
        "Art. 14 - Obras e reformas\nObras só de segunda a sexta, das 8h às 17h.\nArt. 15 – Mudanças\nMudanças com agendamento prévio.",
        [
            ['reference' => 'Art. 14', 'title' => 'Obras e reformas', 'body' => 'Obras só de segunda a sexta, das 8h às 17h.', 'position' => 1],
            ['reference' => 'Art. 15', 'title' => 'Mudanças', 'body' => 'Mudanças com agendamento prévio.', 'position' => 2],
        ],
    ],
    'title longer than 80 characters stays in the body' => [
        'Art. 7 - '.str_repeat('texto longo ', 8)."\ncontinua aqui.",
        [
            ['reference' => 'Art. 7', 'title' => null, 'body' => trim(str_repeat('texto longo ', 8)).' continua aqui.', 'position' => 1],
        ],
    ],
    'text without headers becomes a single article' => [
        "Normas gerais de convivência\n\nRespeite o horário de silêncio.",
        [
            ['reference' => 'Documento', 'title' => null, 'body' => 'Normas gerais de convivência Respeite o horário de silêncio.', 'position' => 1],
        ],
    ],
    'paragraph § stays in the article body' => [
        "Art. 23 - Fachada\nÉ vedado alterar a fachada.\n§ 1º Inclui perfurações em sacadas.\n§ 2º Inclui cores de esquadrias.\nArt. 24 Das garagens.",
        [
            ['reference' => 'Art. 23', 'title' => 'Fachada', 'body' => "É vedado alterar a fachada.\n§ 1º Inclui perfurações em sacadas.\n§ 2º Inclui cores de esquadrias.", 'position' => 1],
            ['reference' => 'Art. 24', 'title' => null, 'body' => 'Das garagens.', 'position' => 2],
        ],
    ],
    'short preamble is discarded' => [
        "REGIMENTO INTERNO\nResidencial Aurora\nArt. 1º O regimento vale para todos.",
        [
            ['reference' => 'Art. 1º', 'title' => null, 'body' => 'O regimento vale para todos.', 'position' => 1],
        ],
    ],
]);

test('a preamble with at least 200 characters becomes the first article', function () {
    $preamble = str_repeat('Considerando a deliberação da assembleia geral. ', 5);

    $articles = $this->splitter->split($preamble."\nArt. 1º O regimento vale para todos.");

    expect($articles)->toHaveCount(2)
        ->and($articles[0])->toBe(['reference' => 'Preâmbulo', 'title' => null, 'body' => trim($preamble), 'position' => 1])
        ->and($articles[1])->toMatchArray(['reference' => 'Art. 1º', 'position' => 2]);
});

test('headers are only recognized at the start of a line', function () {
    $articles = $this->splitter->split("Art. 2º Conforme o Art. 1º, vale para todos.\nVer também Cláusula 3ª da convenção.");

    expect($articles)->toBe([
        ['reference' => 'Art. 2º', 'title' => null, 'body' => 'Conforme o Art. 1º, vale para todos. Ver também Cláusula 3ª da convenção.', 'position' => 1],
    ]);
});

test('blank text produces no articles', function () {
    expect($this->splitter->split("  \n\t "))->toBe([]);
});
