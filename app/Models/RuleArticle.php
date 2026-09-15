<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToCondominium;
use Database\Factories\RuleArticleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $condominium_id
 * @property int $rule_document_id
 * @property string $reference
 * @property string|null $title
 * @property string $body
 * @property int $position
 * @property list<float>|null $embedding
 * @property Carbon|null $embedded_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['rule_document_id', 'reference', 'title', 'body', 'position', 'embedding', 'embedded_at'])]
class RuleArticle extends Model
{
    /** @use HasFactory<RuleArticleFactory> */
    use BelongsToCondominium, HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'embedding' => 'array',
            'embedded_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<RuleDocument, $this>
     */
    public function ruleDocument(): BelongsTo
    {
        return $this->belongsTo(RuleDocument::class);
    }
}
