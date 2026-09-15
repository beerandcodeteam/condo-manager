<?php

namespace App\Http\Controllers;

use App\Models\TicketPhoto;
use App\Services\Tickets\TicketService;
use App\Support\Tenancy\CurrentCondominium;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Full-size ticket photo from the private disk, only for the panel's current condominium.
 */
class TicketPhotoController extends Controller
{
    public function __invoke(TicketPhoto $photo, CurrentCondominium $currentCondominium): StreamedResponse
    {
        $disk = Storage::disk(TicketService::PHOTO_DISK);

        abort_unless($photo->condominium_id === $currentCondominium->id(), 404);
        abort_unless($disk->exists($photo->file_path), 404);

        return $disk->response($photo->file_path, basename($photo->file_path), [
            'Content-Type' => $photo->mime_type,
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }
}
