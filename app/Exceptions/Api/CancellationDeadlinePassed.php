<?php

namespace App\Exceptions\Api;

class CancellationDeadlinePassed extends ApiException
{
    public function __construct(int $cancellationDeadlineHours, string $message = 'O prazo para cancelar esta reserva já passou.')
    {
        parent::__construct(422, 'cancellation_deadline_passed', $message, [
            'cancellation_deadline_hours' => $cancellationDeadlineHours,
        ]);
    }
}
