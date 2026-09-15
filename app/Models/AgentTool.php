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
 * @property string $http_method
 * @property string $route
 * @property string $description
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'slug', 'http_method', 'route', 'description'])]
class AgentTool extends Model
{
    use HasSlug;

    public const RESIDENTS_LOOKUP = 'residents_lookup';

    public const RULES_SEARCH = 'rules_search';

    public const NOTICES_LIST = 'notices_list';

    public const TICKETS_CREATE = 'tickets_create';

    public const TICKETS_LIST = 'tickets_list';

    public const TICKETS_SHOW = 'tickets_show';

    public const AREAS_LIST = 'areas_list';

    public const AREAS_AVAILABILITY = 'areas_availability';

    public const RESERVATIONS_CREATE = 'reservations_create';

    public const RESERVATIONS_LIST = 'reservations_list';

    public const RESERVATIONS_CANCEL = 'reservations_cancel';

    public const ESCALATIONS_CREATE = 'escalations_create';

    /**
     * @return HasMany<AgentToolCall, $this>
     */
    public function toolCalls(): HasMany
    {
        return $this->hasMany(AgentToolCall::class);
    }
}
