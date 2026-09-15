<?php

namespace App\Http\Controllers;

use App\Models\RuleDocument;
use App\Services\RuleDocuments\RuleDocumentService;
use App\Support\Tenancy\CurrentCondominium;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Original PDF of a rule document from the private disk, only for the panel's current condominium.
 */
class RuleDocumentDownloadController extends Controller
{
    public function __invoke(RuleDocument $document, CurrentCondominium $currentCondominium): StreamedResponse
    {
        $disk = Storage::disk(RuleDocumentService::DISK);

        abort_unless($document->condominium_id === $currentCondominium->id(), 404);
        abort_unless($disk->exists($document->file_path), 404);

        return $disk->download($document->file_path, Str::slug($document->title).'.pdf', [
            'Content-Type' => 'application/pdf',
        ]);
    }
}
