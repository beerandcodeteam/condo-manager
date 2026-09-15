<?php

namespace App\Exceptions\Api;

class ReservationNotFound extends ApiException
{
    public function __construct(string $message = 'Reserva não encontrada.')
    {
        parent::__construct(404, 'reservation_not_found', $message);
    }
}
