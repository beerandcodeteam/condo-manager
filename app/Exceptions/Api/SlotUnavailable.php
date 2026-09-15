<?php

namespace App\Exceptions\Api;

class SlotUnavailable extends ApiException
{
    public function __construct(string $message = 'Faixa já reservada nesta data.')
    {
        parent::__construct(422, 'slot_unavailable', $message);
    }
}
