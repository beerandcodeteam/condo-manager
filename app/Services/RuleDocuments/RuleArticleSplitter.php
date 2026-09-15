<?php

namespace App\Services\RuleDocuments;

/**
 * Splits the plain text extracted from a regimento/convenção PDF into citable articles.
 *
 * A new article starts at every line beginning with an article header (`Art. 1º`, `Artigo 3`) or a clause
 * header (`Cláusula 9ª`, `Cl. 9a`). Paragraphs (§) and any other line stay in the body of the current article.
 */
class RuleArticleSplitter
{
    public const PREAMBLE_REFERENCE = 'Preâmbulo';

    public const WHOLE_DOCUMENT_REFERENCE = 'Documento';

    /**
     * Minimum length of the text before the first header to keep it as a preamble article.
     */
    public const PREAMBLE_MIN_LENGTH = 200;

    public const TITLE_MAX_LENGTH = 80;

    private const HEADER_PATTERN = '/^[ \t\x{00A0}]*(?:'
        .'(?<article>Art\.|Artigo)[ \t\x{00A0}]*(?<article_number>\d+)(?<article_ordinal>[º°]|o(?!\p{L}))?'
        .'|(?<clause>Cl[áa]usula|Cl\.)[ \t\x{00A0}]*(?<clause_number>\d+)(?<clause_ordinal>ª|a(?!\p{L}))?'
        .')(?!\d)(?<rest>[^\n]*)$/imu';

    /**
     * @return list<array{reference: string, title: string|null, body: string, position: int}>
     */
    public function split(string $text): array
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        if ($this->normalize($text) === '') {
            return [];
        }

        preg_match_all(self::HEADER_PATTERN, $text, $headers, PREG_SET_ORDER | PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL);

        if ($headers === []) {
            return [$this->article(self::WHOLE_DOCUMENT_REFERENCE, null, $this->normalize($text), 1)];
        }

        $articles = [];
        $preamble = $this->normalize(substr($text, 0, $headers[0][0][1]));

        if (mb_strlen($preamble) >= self::PREAMBLE_MIN_LENGTH) {
            $articles[] = $this->article(self::PREAMBLE_REFERENCE, null, $preamble, 1);
        }

        foreach ($headers as $index => $header) {
            $headerEnd = $header[0][1] + strlen($header[0][0]);
            $nextHeaderStart = $headers[$index + 1][0][1] ?? strlen($text);

            [$title, $headerText] = $this->titleAndText($header['rest'][0] ?? '');
            $body = $this->normalize($headerText."\n".substr($text, $headerEnd, $nextHeaderStart - $headerEnd));

            if ($body === '' && $title !== null) {
                [$title, $body] = [null, $title];
            }

            $articles[] = $this->article($this->reference($header), $title, $body, count($articles) + 1);
        }

        return $articles;
    }

    /**
     * "Art. 14", "Art. 1º", "Cl. 9ª" or "Cl. 9".
     *
     * @param  array<int|string, array{0: string|null, 1: int}>  $header
     */
    private function reference(array $header): string
    {
        if ($header['article'][0] !== null) {
            return 'Art. '.$header['article_number'][0].($header['article_ordinal'][0] !== null ? 'º' : '');
        }

        return 'Cl. '.$header['clause_number'][0].($header['clause_ordinal'][0] !== null ? 'ª' : '');
    }

    /**
     * Title after "-", "–" or ":" on the header line when it has up to 80 characters; any other text on the
     * header line belongs to the body.
     *
     * @return array{0: string|null, 1: string}
     */
    private function titleAndText(string $rest): array
    {
        if (preg_match('/^[ \t\x{00A0}]*[-–:][ \t\x{00A0}]*(?<title>.*)$/u', $rest, $matches) === 1) {
            $title = $this->normalize($matches['title']);

            if ($title !== '' && mb_strlen($title) <= self::TITLE_MAX_LENGTH) {
                return [$title, ''];
            }

            return [null, $matches['title']];
        }

        return [null, (string) preg_replace('/^[ \t\x{00A0}]*[.\-–:]?/u', '', $rest)];
    }

    /**
     * Collapses whitespace; paragraphs (§ or "Parágrafo único") starting a line keep their own line.
     */
    private function normalize(string $text): string
    {
        $paragraphs = preg_split('/\n(?=[ \t\x{00A0}]*(?:§|Par[áa]grafo [úu]nico))/iu', $text) ?: [$text];

        return collect($paragraphs)
            ->map(fn (string $paragraph): string => trim((string) preg_replace('/[\s\x{00A0}]+/u', ' ', $paragraph)))
            ->filter(fn (string $paragraph): bool => $paragraph !== '')
            ->implode("\n");
    }

    /**
     * @return array{reference: string, title: string|null, body: string, position: int}
     */
    private function article(string $reference, ?string $title, string $body, int $position): array
    {
        return [
            'reference' => $reference,
            'title' => $title,
            'body' => $body,
            'position' => $position,
        ];
    }
}
