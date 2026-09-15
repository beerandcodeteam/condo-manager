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
class ToolCallResult extends Model
{
    use HasSlug;

    public const SUCESSO = 'sucesso';

    public const VAZIO = 'vazio';

    public const RECUSA = 'recusa';

    /**
     * @return HasMany<AgentToolCall, $this>
     */
    public function toolCalls(): HasMany
    {
        return $this->hasMany(AgentToolCall::class, 'tool_call_result_id');
    }
}
