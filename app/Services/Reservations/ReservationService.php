<?php

namespace App\Services\Reservations;

use App\Exceptions\Api\AdvanceNoticeViolation;
use App\Exceptions\Api\AreaUnavailable;
use App\Exceptions\Api\CancellationDeadlinePassed;
use App\Exceptions\Api\ReservationNotFound;
use App\Exceptions\Api\SlotUnavailable;
use App\Exceptions\ReservationActionBlockedException;
use App\Models\CommonArea;
use App\Models\CommonAreaSlot;
use App\Models\Condominium;
use App\Models\Reservation;
use App\Models\ReservationCancellationOrigin;
use App\Models\ReservationOrigin;
use App\Models\ReservationStatus;
use App\Models\Resident;
use App\Models\Unit;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Services\Integration\WebhookService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Rules of common area reservations shared by the agent API and the panel: advance window, slot
 * availability, creation and cancellations. "Today" and slot times are always resolved in the
 * condominium timezone (`condo.timezone`).
 */
class ReservationService
{
    public function __construct(private WebhookService $webhookService) {}

    /**
     * Current moment in the condominium timezone.
     */
    public function now(): CarbonImmutable
    {
        return CarbonImmutable::now(config('condo.timezone'));
    }

    /**
     * Today's date (Y-m-d) in the condominium timezone.
     */
    public function today(): string
    {
        return $this->now()->toDateString();
    }

    /**
     * The date combined with the slot start time, in the condominium timezone.
     */
    public function slotStart(CommonAreaSlot $slot, CarbonInterface|string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($this->dateString($date).' '.$slot->starts_at, config('condo.timezone'));
    }

    /**
     * Whether the slot on the date respects the area advance window: the slot starts at least
     * `min_advance_hours` from now and the date is at most `max_advance_days` after today.
     */
    public function withinAdvanceWindow(CommonArea $area, CommonAreaSlot $slot, CarbonInterface|string $date): bool
    {
        $minimumStart = $this->now()->addHours($area->min_advance_hours);
        $lastDate = $this->now()->startOfDay()->addDays($area->max_advance_days)->toDateString();

        return $this->slotStart($slot, $date)->greaterThanOrEqualTo($minimumStart)
            && $this->dateString($date) <= $lastDate;
    }

    /**
     * Whether a not cancelled reservation already holds the slot on the date.
     */
    public function isSlotTaken(CommonAreaSlot $slot, CarbonInterface|string $date): bool
    {
        return Reservation::query()
            ->withoutGlobalScopes()
            ->where('common_area_slot_id', $slot->id)
            ->whereDate('date', $this->dateString($date))
            ->active()
            ->exists();
    }

    /**
     * Not deleted slots of the area on the date, in start order. A slot is `available` when it is not
     * taken and is within the advance window; `taken` tells the panel which slots are already reserved.
     *
     * @return list<array{slot: CommonAreaSlot, id: int, starts: string, ends: string, taken: bool, available: bool}>
     */
    public function availability(CommonArea $area, CarbonInterface|string $date): array
    {
        $takenSlotIds = Reservation::query()
            ->withoutGlobalScopes()
            ->where('common_area_id', $area->id)
            ->whereDate('date', $this->dateString($date))
            ->active()
            ->pluck('common_area_slot_id')
            ->all();

        return array_values($area->slots()->get()
            ->map(function (CommonAreaSlot $slot) use ($area, $date, $takenSlotIds): array {
                $isTaken = in_array($slot->id, $takenSlotIds, true);

                return [
                    'slot' => $slot,
                    'id' => $slot->id,
                    'starts' => $slot->starts,
                    'ends' => $slot->ends,
                    'taken' => $isTaken,
                    'available' => ! $isTaken && $this->withinAdvanceWindow($area, $slot, $date),
                ];
            })
            ->all());
    }

    /**
     * Whether the resident may still cancel: the reservation starts at least `cancellation_deadline_hours` from now.
     */
    public function isCancellableByResident(Reservation $reservation): bool
    {
        $deadlineHours = $reservation->area->cancellation_deadline_hours;

        return $reservation->startsAtInCondoTimezone()->greaterThanOrEqualTo($this->now()->addHours($deadlineHours));
    }

    /**
     * Confirmed reservations of the unit from today on, by date and start time.
     *
     * @return Collection<int, Reservation>
     */
    public function upcomingOfUnit(Unit $unit): Collection
    {
        return $unit->reservations()
            ->active()
            ->where('reservation_status_id', ReservationStatus::idFor(ReservationStatus::CONFIRMADA))
            ->whereDate('date', '>=', $this->today())
            ->with(['area', 'resident'])
            ->orderBy('date')
            ->orderBy('starts_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * Monday of the week containing the date (Y-m-d) or of the current week, at midnight in the condominium timezone.
     */
    public function weekStart(?string $date = null): CarbonImmutable
    {
        $reference = filled($date) && CarbonImmutable::hasFormat($date, 'Y-m-d')
            ? CarbonImmutable::createFromFormat('!Y-m-d', $date, config('condo.timezone'))
            : $this->now();

        return $reference->startOfWeek(CarbonInterface::MONDAY)->startOfDay();
    }

    /**
     * Not cancelled reservations of the condominium active areas in the week starting on the Monday, by date and start time.
     *
     * @return Collection<int, Reservation>
     */
    public function weekReservations(Condominium $condominium, CarbonImmutable $weekStart): Collection
    {
        return $condominium->reservations()
            ->active()
            ->whereIn('common_area_id', $condominium->commonAreas()->active()->select('id'))
            ->whereDate('date', '>=', $weekStart->toDateString())
            ->whereDate('date', '<=', $weekStart->addDays(6)->toDateString())
            ->with(['area', 'resident', 'unit.block', 'origin'])
            ->orderBy('date')
            ->orderBy('starts_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * Latest reservations requested through WhatsApp, confirmed or cancelled, most recent first.
     *
     * @return Collection<int, Reservation>
     */
    public function latestWhatsappReservations(Condominium $condominium, int $limit): Collection
    {
        return $condominium->reservations()
            ->where('reservation_origin_id', ReservationOrigin::idFor(ReservationOrigin::WHATSAPP))
            ->with(['area', 'resident', 'unit.block', 'cancellationOrigin'])
            ->latest()
            ->latest('id')
            ->limit($limit)
            ->get();
    }

    /**
     * Create a `confirmada` reservation of the slot for the resident's unit, copying the slot times.
     * WhatsApp reservations must respect the advance window; panel reservations only refuse past dates.
     * Creation never dispatches webhooks.
     *
     * @throws AreaUnavailable when the area is inactive, from another condominium, or the slot is deleted or from another area
     * @throws AdvanceNoticeViolation when a WhatsApp reservation is outside the advance window
     * @throws SlotUnavailable when the slot is already reserved on the date, even under concurrency
     * @throws ValidationException when a panel reservation is for a past date
     */
    public function create(
        CommonArea $area,
        CommonAreaSlot $slot,
        CarbonInterface|string $date,
        Resident $resident,
        string $origin,
        ?User $user = null,
    ): Reservation {
        $date = $this->dateString($date);

        if (! $area->is_active
            || $slot->common_area_id !== $area->id
            || $slot->trashed()
            || $area->condominium_id !== $resident->condominium_id) {
            throw new AreaUnavailable;
        }

        if ($origin === ReservationOrigin::WHATSAPP && ! $this->withinAdvanceWindow($area, $slot, $date)) {
            throw new AdvanceNoticeViolation($area->min_advance_hours, $area->max_advance_days);
        }

        if ($origin !== ReservationOrigin::WHATSAPP && $date < $this->today()) {
            throw ValidationException::withMessages(['date' => 'Não é possível reservar uma data passada.']);
        }

        if ($this->isSlotTaken($slot, $date)) {
            throw new SlotUnavailable;
        }

        /*
         * The partial unique index (slot, date) where cancelled_at is null is the real guarantee: a concurrent
         * reservation that slipped past the check above makes the insert fail and is reported as the same refusal.
         */
        try {
            return DB::transaction(function () use ($area, $slot, $date, $resident, $origin, $user): Reservation {
                $reservation = new Reservation([
                    'common_area_id' => $area->id,
                    'common_area_slot_id' => $slot->id,
                    'unit_id' => $resident->unit_id,
                    'resident_id' => $resident->id,
                    'reservation_status_id' => ReservationStatus::idFor(ReservationStatus::CONFIRMADA),
                    'reservation_origin_id' => ReservationOrigin::idFor($origin),
                    'created_by_user_id' => $origin === ReservationOrigin::PAINEL ? $user?->id : null,
                    'date' => $date,
                    'starts_at' => $slot->starts_at,
                    'ends_at' => $slot->ends_at,
                ]);

                $reservation->condominium_id = $area->condominium_id;
                $reservation->save();

                return $reservation;
            });
        } catch (UniqueConstraintViolationException) {
            throw new SlotUnavailable;
        }
    }

    /**
     * Cancel a `confirmada` reservation of the resident's unit before the area cancellation deadline.
     * Records origin `morador` and dispatches no webhook.
     *
     * @throws ReservationNotFound when the reservation is from another unit or not confirmed
     * @throws CancellationDeadlinePassed when the reservation starts in less than the deadline hours
     */
    public function cancelByResident(Reservation $reservation, Resident $resident): Reservation
    {
        return DB::transaction(function () use ($reservation, $resident): Reservation {
            $this->refreshLocked($reservation);

            if ($reservation->unit_id !== $resident->unit_id
                || $reservation->condominium_id !== $resident->condominium_id
                || ! $reservation->isConfirmed()) {
                throw new ReservationNotFound;
            }

            if (! $this->isCancellableByResident($reservation)) {
                throw new CancellationDeadlinePassed($reservation->area->cancellation_deadline_hours);
            }

            $reservation->forceFill([
                'reservation_status_id' => ReservationStatus::idFor(ReservationStatus::CANCELADA),
                'cancelled_at' => now(),
                'reservation_cancellation_origin_id' => ReservationCancellationOrigin::idFor(ReservationCancellationOrigin::MORADOR),
            ])->save();

            return $reservation;
        });
    }

    /**
     * Cancel a `confirmada` reservation from today on, with no deadline, recording origin `sindico`, the user and
     * the reason, and dispatching `reservation.cancelled` with `{reservation_id, area, date, starts, ends, reason}`.
     *
     * @throws ReservationActionBlockedException when the reservation is not confirmed or its date already passed
     * @throws ValidationException when the reason is missing
     */
    public function cancelBySyndic(Reservation $reservation, string $reason, User $user): Reservation
    {
        $reason = trim($reason);

        return DB::transaction(function () use ($reservation, $reason, $user): Reservation {
            $this->refreshLocked($reservation);

            if (! $reservation->isConfirmed()) {
                throw new ReservationActionBlockedException('Esta reserva já foi cancelada.');
            }

            if ($reservation->date->format('Y-m-d') < $this->today()) {
                throw new ReservationActionBlockedException('Reservas de datas passadas não podem ser canceladas.');
            }

            if ($reason === '') {
                throw ValidationException::withMessages(['reason' => 'Informe o motivo do cancelamento.']);
            }

            $reservation->forceFill([
                'reservation_status_id' => ReservationStatus::idFor(ReservationStatus::CANCELADA),
                'cancelled_at' => now(),
                'reservation_cancellation_origin_id' => ReservationCancellationOrigin::idFor(ReservationCancellationOrigin::SINDICO),
                'cancelled_by_user_id' => $user->id,
                'cancellation_reason' => $reason,
            ])->save();

            $this->webhookService->dispatch(WebhookEvent::RESERVATION_CANCELLED, $reservation, $reservation->resident->phone, [
                'reservation_id' => $reservation->id,
                'area' => $reservation->area->name,
                'date' => $reservation->date->format('Y-m-d'),
                'starts' => CommonAreaSlot::shortTime($reservation->starts_at),
                'ends' => CommonAreaSlot::shortTime($reservation->ends_at),
                'reason' => $reason,
            ]);

            return $reservation;
        });
    }

    /**
     * Reload the reservation attributes under a row lock, so concurrent cancellations see the latest state.
     */
    private function refreshLocked(Reservation $reservation): void
    {
        $lockedReservation = Reservation::query()->withoutGlobalScopes()->whereKey($reservation->id)->lockForUpdate()->firstOrFail();

        $reservation->setRawAttributes($lockedReservation->getAttributes(), true);
    }

    private function dateString(CarbonInterface|string $date): string
    {
        return $date instanceof CarbonInterface ? $date->format('Y-m-d') : CarbonImmutable::parse($date)->format('Y-m-d');
    }
}
