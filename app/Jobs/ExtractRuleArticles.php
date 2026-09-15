<?php

namespace App\Jobs;

use App\Models\DocumentStatus;
use App\Models\RuleArticle;
use App\Models\RuleDocument;
use App\Services\RuleDocuments\RuleArticleSplitter;
use App\Services\RuleDocuments\RuleDocumentService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Smalot\PdfParser\Parser;
use Throwable;

/**
 * Extracts the text of an uploaded rule document PDF and splits it into articles for review.
 * A PDF without text (e.g. scanned) or a parser error leaves the document as `falha_extracao`.
 */
class ExtractRuleArticles implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public bool $deleteWhenMissingModels = true;

    public function __construct(public RuleDocument $document) {}

    /**
     * Execute the job.
     */
    public function handle(Parser $parser, RuleArticleSplitter $splitter): void
    {
        if (! $this->document->hasStatus(DocumentStatus::PROCESSANDO)) {
            return;
        }

        try {
            $text = $parser->parseContent((string) Storage::disk(RuleDocumentService::DISK)->get($this->document->file_path))->getText();
        } catch (Throwable $exception) {
            $this->markAsFailed($exception->getMessage());

            return;
        }

        $articles = $splitter->split($text);

        if ($articles === []) {
            $this->markAsFailed(RuleDocumentService::EMPTY_TEXT_ERROR);

            return;
        }

        DB::transaction(function () use ($articles): void {
            RuleArticle::query()->withoutGlobalScopes()->where('rule_document_id', $this->document->id)->delete();

            foreach ($articles as $article) {
                $ruleArticle = new RuleArticle([...$article, 'rule_document_id' => $this->document->id]);
                $ruleArticle->condominium_id = $this->document->condominium_id;
                $ruleArticle->save();
            }

            $this->document->forceFill([
                'document_status_id' => DocumentStatus::idFor(DocumentStatus::EM_REVISAO),
                'processing_error' => null,
            ])->save();
        });
    }

    /**
     * The worker failed or timed out: the síndico can upload the PDF again.
     */
    public function failed(?Throwable $exception): void
    {
        $this->markAsFailed($exception?->getMessage() ?: 'Falha ao extrair o texto do PDF.');
    }

    private function markAsFailed(string $message): void
    {
        $this->document->forceFill([
            'document_status_id' => DocumentStatus::idFor(DocumentStatus::FALHA_EXTRACAO),
            'processing_error' => $message,
        ])->save();
    }
}
