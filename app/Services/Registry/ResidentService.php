<?php

namespace App\Services\Registry;

use App\Exceptions\DeletionBlockedException;
use App\Models\AgentToolCall;
use App\Models\Condominium;
use App\Models\Resident;
use App\Models\ResidentProfile;
use App\Support\PhoneNumber;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class ResidentService
{
    public const PER_PAGE = 25;

    public const STATUS_ALL = 'todos';

    public const STATUS_ACTIVE = 'ativos';

    public const STATUS_INACTIVE = 'inativos';

    /**
     * Residents of the condominium ordered by unit label, with the total tool call count as `interactions_count`.
     *
     * @param  array{block_id?: int|null, unit_id?: int|null, search?: string|null, status?: string|null}  $filters
     * @return LengthAwarePaginator<int, Resident>
     */
    public function paginate(Condominium $condominium, array $filters = []): LengthAwarePaginator
    {
        $search = trim((string) ($filters['search'] ?? ''));
        $searchDigits = preg_replace('/\D+/', '', $search);

        return $condominium->residents()
            ->join('units', 'units.id', '=', 'residents.unit_id')
            ->leftJoin('blocks', 'blocks.id', '=', 'units.block_id')
            ->select('residents.*')
            ->with(['unit.block', 'residentProfile'])
            ->withCount('agentToolCalls as interactions_count')
            ->when(filled($filters['block_id'] ?? null), fn (Builder $query) => $query->where('units.block_id', $filters['block_id']))
            ->when(filled($filters['unit_id'] ?? null), fn (Builder $query) => $query->where('residents.unit_id', $filters['unit_id']))
            ->when($search !== '', fn (Builder $query) => $query->where(function (Builder $query) use ($search, $searchDigits): void {
                $query->whereLike('residents.name', "%{$search}%");

                if ($searchDigits !== '') {
                    $query->orWhereLike('residents.phone', "%{$searchDigits}%");
                }
            }))
            ->when(($filters['status'] ?? null) === self::STATUS_ACTIVE, fn (Builder $query) => $query->where('residents.is_active', true))
            ->when(($filters['status'] ?? null) === self::STATUS_INACTIVE, fn (Builder $query) => $query->where('residents.is_active', false))
            ->orderByRaw('length(units.number), units.number')
            ->orderBy('blocks.name')
            ->orderBy('residents.name')
            ->paginate(self::PER_PAGE);
    }

    /**
     * @param  array{name: string, phone: string, unit_id: int, profile: string, is_active: bool}  $attributes
     */
    public function create(Condominium $condominium, array $attributes): Resident
    {
        return $condominium->residents()->create($this->residentData($attributes));
    }

    /**
     * @param  array{name: string, phone: string, unit_id: int, profile: string, is_active: bool}  $attributes
     */
    public function update(Resident $resident, array $attributes): Resident
    {
        $resident->update($this->residentData($attributes));

        return $resident;
    }

    public function setActive(Resident $resident, bool $isActive): Resident
    {
        $resident->update(['is_active' => $isActive]);

        return $resident;
    }

    /**
     * Delete a resident without history. The tool call log keeps its rows (with the phone) but loses the link.
     *
     * @throws DeletionBlockedException when the resident has tickets, reservations or escalations
     */
    public function delete(Resident $resident): void
    {
        $hasHistory = $resident->tickets()->exists()
            || $resident->reservations()->exists()
            || $resident->escalations()->exists();

        if ($hasHistory) {
            throw new DeletionBlockedException('Morador com histórico: inative em vez de excluir.');
        }

        DB::transaction(function () use ($resident): void {
            AgentToolCall::withoutGlobalScopes()->where('resident_id', $resident->id)->update(['resident_id' => null]);

            $resident->delete();
        });
    }

    /**
     * @param  array{name: string, phone: string, unit_id: int, profile: string, is_active: bool}  $attributes
     * @return array{name: string, phone: string, unit_id: int, resident_profile_id: int, is_active: bool}
     */
    private function residentData(array $attributes): array
    {
        return [
            'name' => trim($attributes['name']),
            'phone' => PhoneNumber::normalize($attributes['phone']) ?? trim($attributes['phone']),
            'unit_id' => $attributes['unit_id'],
            'resident_profile_id' => ResidentProfile::idFor($attributes['profile']),
            'is_active' => $attributes['is_active'],
        ];
    }
}
