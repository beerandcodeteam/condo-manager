<?php

namespace App\Models;

use App\Support\Lookups\HasSlug;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'slug'])]
class AgentMediaKind extends Model
{
    use HasSlug;

    public const IMAGEM = 'imagem';

    public const AUDIO = 'audio';

    public const VIDEO = 'video';

    public const DOCUMENTO = 'documento';

    /**
     * Only images can become a ticket photo; the rest is kept for the record.
     */
    public const ATTACHABLE = [self::IMAGEM];

    /**
     * The kind of a media file, from its MIME type. Anything unrecognized is a document.
     */
    public static function slugForMime(string $mimeType): string
    {
        return match (true) {
            str_starts_with($mimeType, 'image/') => self::IMAGEM,
            str_starts_with($mimeType, 'audio/') => self::AUDIO,
            str_starts_with($mimeType, 'video/') => self::VIDEO,
            default => self::DOCUMENTO,
        };
    }

    /**
     * @return HasMany<AgentMedia, $this>
     */
    public function media(): HasMany
    {
        return $this->hasMany(AgentMedia::class);
    }
}
