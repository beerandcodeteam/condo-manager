<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToCondominium;
use Database\Factories\RuleDocumentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $condominium_id
 * @property int $document_type_id
 * @property int $document_status_id
 * @property string $title
 * @property string $file_path
 * @property string|null $processing_error
 * @property int $uploaded_by_user_id
 * @property int|null $published_by_user_id
 * @property Carbon|null $published_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['document_type_id', 'document_status_id', 'title', 'file_path', 'processing_error', 'uploaded_by_user_id', 'published_by_user_id', 'published_at'])]
class RuleDocument extends Model
{
    /** @use HasFactory<RuleDocumentFactory> */
    use BelongsToCondominium, HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn('document_status_id'), DocumentStatus::idFor(DocumentStatus::PUBLICADO));
    }

    public function hasStatus(string ...$statusSlugs): bool
    {
        return in_array(
            $this->document_status_id,
            array_map(fn (string $statusSlug): int => DocumentStatus::idFor($statusSlug), $statusSlugs),
            true,
        );
    }

    /**
     * @return BelongsTo<DocumentType, $this>
     */
    public function documentType(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class);
    }

    /**
     * @return BelongsTo<DocumentStatus, $this>
     */
    public function documentStatus(): BelongsTo
    {
        return $this->belongsTo(DocumentStatus::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function publishedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by_user_id');
    }

    /**
     * @return HasMany<RuleArticle, $this>
     */
    public function articles(): HasMany
    {
        return $this->hasMany(RuleArticle::class)->orderBy('position');
    }
}
