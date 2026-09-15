<?php

namespace App\Exceptions\Api;

class AreaUnavailable extends ApiException
{
    public function __construct(string $message = 'Área indisponível para reserva.')
    {
        parent::__construct(422, 'area_unavailable', $message);
    }
}
