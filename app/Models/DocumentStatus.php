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
class DocumentStatus extends Model
{
    use HasSlug;

    public const PROCESSANDO = 'processando';

    public const EM_REVISAO = 'em_revisao';

    public const FALHA_EXTRACAO = 'falha_extracao';

    public const INDEXANDO = 'indexando';

    public const FALHA_INDEXACAO = 'falha_indexacao';

    public const PUBLICADO = 'publicado';

    public const SUBSTITUIDO = 'substituido';

    /**
     * @return HasMany<RuleDocument, $this>
     */
    public function ruleDocuments(): HasMany
    {
        return $this->hasMany(RuleDocument::class);
    }
}
