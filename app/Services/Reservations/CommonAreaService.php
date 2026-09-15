<?php

namespace App\Services\Reservations;

use App\Models\CommonArea;
use App\Models\CommonAreaSlot;
use App\Models\Condominium;
use App\Models\Reservation;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Common areas of a condominium with their slots and reservation rules, as maintained by the syndic.
 */
class CommonAreaService
{
    /**
     * Create or update the area and sync its slots in one transaction. Slots missing from the list are soft deleted.
     * Every rule is checked before anything is written. Error keys: `slots` for list-wide errors and
     * `slots.{index}.ends` for a slot whose start is not before its end.
     *
     * @param  array{name: string, description: string|null, is_active: bool, min_advance_hours: int, max_advance_days: int, cancellation_deadline_hours: int}  $attributes
     * @param  list<array{id: int|null, starts: string, ends: string}>  $slots  times as `HH:MM`
     *
     * @throws ValidationException
     */
    public function save(Condominium $condominium, ?CommonArea $area, array $attributes, array $slots): CommonArea
    {
        $slots = array_map(fn (array $slot): array => [
            'id' => filled($slot['id']) ? (int) $slot['id'] : null,
            'starts' => CommonAreaSlot::shortTime(trim($slot['starts'])),
            'ends' => CommonAreaSlot::shortTime(trim($slot['ends'])),
        ], $slots);

        $existingSlots = $area === null
            ? collect()
            : CommonAreaSlot::query()->withoutGlobalScopes()->where('common_area_id', $area->id)->get()->keyBy('id');

        $this->validateSlots($slots, (bool) $attributes['is_active'], $existingSlots->all());

        return DB::transaction(function () use ($condominium, $area, $attributes, $slots, $existingSlots): CommonArea {
            $area ??= new CommonArea;
            $area->fill([
                'name' => trim($attributes['name']),
                'description' => filled($attributes['description']) ? trim($attributes['description']) : null,
                'is_active' => (bool) $attributes['is_active'],
                'min_advance_hours' => $attributes['min_advance_hours'],
                'max_advance_days' => $attributes['max_advance_days'],
                'cancellation_deadline_hours' => $attributes['cancellation_deadline_hours'],
            ]);
            $area->condominium_id = $condominium->id;
            $area->save();

            $keptSlotIds = [];

            foreach ($slots as $slot) {
                $slotModel = $slot['id'] === null ? null : $existingSlots->get($slot['id']);

                if ($slotModel === null) {
                    $slotModel = new CommonAreaSlot(['common_area_id' => $area->id]);
                    $slotModel->condominium_id = $condominium->id;
                }

                $slotModel->fill([
                    'starts_at' => "{$slot['starts']}:00",
                    'ends_at' => "{$slot['ends']}:00",
                ])->save();

                $keptSlotIds[] = $slotModel->id;
            }

            $existingSlots->except($keptSlotIds)->each(fn (CommonAreaSlot $slot) => $slot->delete());

            return $area;
        });
    }

    /**
     * Short rules summary of the area, e.g. "24 h · até 60 dias · cancela até 24 h".
     */
    public function rulesSummary(CommonArea $area): string
    {
        return "{$area->min_advance_hours} h · até {$area->max_advance_days} dias · cancela até {$area->cancellation_deadline_hours} h";
    }

    /**
     * @param  list<array{id: int|null, starts: string, ends: string}>  $slots
     * @param  array<int, CommonAreaSlot>  $existingSlots
     *
     * @throws ValidationException
     */
    private function validateSlots(array $slots, bool $isActive, array $existingSlots): void
    {
        $errors = [];

        foreach ($slots as $index => $slot) {
            if ($slot['starts'] >= $slot['ends']) {
                $errors["slots.{$index}.ends"] = 'O início da faixa deve ser antes do fim.';
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $sortedSlots = collect($slots)->sortBy('starts')->values();

        foreach ($sortedSlots as $position => $slot) {
            $previousSlot = $sortedSlots->get($position - 1);

            if ($previousSlot !== null && $slot['starts'] < $previousSlot['ends']) {
                throw ValidationException::withMessages([
                    'slots' => "As faixas {$previousSlot['starts']}–{$previousSlot['ends']} e {$slot['starts']}–{$slot['ends']} se sobrepõem.",
                ]);
            }
        }

        if ($isActive && $slots === []) {
            throw ValidationException::withMessages(['slots' => 'Área ativa precisa de pelo menos uma faixa de horário.']);
        }

        $submittedSlots = collect($slots)->whereNotNull('id')->keyBy('id');

        foreach ($existingSlots as $existingSlot) {
            $submittedSlot = $submittedSlots->get($existingSlot->id);
            $isRemoved = $submittedSlot === null;
            $isChanged = ! $isRemoved && ($submittedSlot['starts'] !== $existingSlot->starts || $submittedSlot['ends'] !== $existingSlot->ends);

            if (! $isRemoved && ! $isChanged) {
                continue;
            }

            $futureDates = $this->futureReservationDates($existingSlot);

            if ($futureDates !== []) {
                $action = $isRemoved ? 'não pode ser removida' : 'não pode ter o horário alterado';

                $errors[] = "A faixa {$existingSlot->starts}–{$existingSlot->ends} tem reservas futuras em ".implode(', ', $futureDates)." e {$action}.";
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages(['slots' => $errors]);
        }
    }

    /**
     * Dates (dd/mm/yyyy) of not cancelled reservations of the slot from today on.
     *
     * @return list<string>
     */
    private function futureReservationDates(CommonAreaSlot $slot): array
    {
        return array_values(Reservation::query()
            ->withoutGlobalScopes()
            ->where('common_area_slot_id', $slot->id)
            ->active()
            ->whereDate('date', '>=', CarbonImmutable::now(config('condo.timezone'))->toDateString())
            ->orderBy('date')
            ->pluck('date')
            ->map(fn (CarbonInterface $date): string => $date->format('d/m/Y'))
            ->unique()
            ->all());
    }
}
