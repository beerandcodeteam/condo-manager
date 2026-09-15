<?php

namespace App\Exceptions\Api;

class TicketNotFound extends ApiException
{
    public function __construct(string $message = 'Chamado não encontrado.')
    {
        parent::__construct(404, 'ticket_not_found', $message);
    }
}
