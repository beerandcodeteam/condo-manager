<?php

namespace App\Services\Integration;

use App\Models\AgentMedia;
use App\Models\AgentMediaKind;
use App\Models\Resident;
use App\Support\PhoneNumber;
use App\Support\Tenancy\CurrentCondominium;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use RuntimeException;

/**
 * Media the resident sent over WhatsApp: stored on upload, offered back to the agent while no ticket
 * consumed it.
 */
class MediaService
{
    public function __construct(private CurrentCondominium $currentCondominium) {}

    /**
     * Store an incoming file under `whatsapp/{condominium_id}/{phone}/`.
     */
    public function store(UploadedFile $file, string $phone, ?string $caption, ?string $transcription): AgentMedia
    {
        $condominium = $this->currentCondominium->getOrFail();
        $normalizedPhone = PhoneNumber::normalize($phone) ?? $phone;
        $mimeType = $file->getMimeType() ?? $file->getClientMimeType();

        $path = $file->store("whatsapp/{$condominium->id}/".ltrim($normalizedPhone, '+'), (string) config('condo.media.disk'));

        if ($path === false) {
            throw new RuntimeException('Não foi possível salvar a mídia recebida.');
        }

        $media = new AgentMedia([
            'agent_media_kind_id' => AgentMediaKind::idFor(AgentMediaKind::slugForMime($mimeType)),
            'resident_id' => Resident::query()->where('phone', $normalizedPhone)->active()->value('id'),
            'phone' => $normalizedPhone,
            'file_path' => $path,
            'mime_type' => $mimeType,
            'size_bytes' => (int) $file->getSize(),
            'caption' => filled($caption) ? $caption : null,
            'transcription' => filled($transcription) ? $transcription : null,
        ]);

        $media->condominium_id = $condominium->id;
        $media->save();

        return $media->load('kind');
    }

    /**
     * Media this phone sent that is still free to attach, oldest first.
     *
     * @return Collection<int, AgentMedia>
     */
    public function pending(string $phone): Collection
    {
        /** @var Collection<int, AgentMedia> $media */
        $media = AgentMedia::query()
            ->pending($phone)
            ->with('kind')
            ->orderBy('id')
            ->limit((int) config('condo.media.pending_limit'))
            ->get();

        return $media;
    }

    /**
     * The media of these ids that belongs to this phone and can still become a ticket photo.
     *
     * Ids of another phone, of another condominium, already attached or of a kind that is not an
     * image are silently ignored: the agent must never learn that they exist.
     *
     * @param  list<int>  $ids
     * @return Collection<int, AgentMedia>
     */
    public function attachable(string $phone, array $ids): Collection
    {
        if ($ids === []) {
            return new Collection;
        }

        /** @var Collection<int, AgentMedia> $media */
        $media = AgentMedia::query()
            ->pending(PhoneNumber::normalize($phone) ?? $phone)
            ->whereKey($ids)
            ->whereHas('kind', fn ($query) => $query->whereIn('slug', AgentMediaKind::ATTACHABLE))
            ->orderBy('id')
            ->limit((int) config('condo.tickets.max_photos'))
            ->get();

        return $media;
    }
}
