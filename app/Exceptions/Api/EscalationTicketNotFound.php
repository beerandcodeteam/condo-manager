<?php

namespace App\Exceptions\Api;

class EscalationTicketNotFound extends ApiException
{
    public function __construct(string $message = 'Chamado informado não encontrado.')
    {
        parent::__construct(422, 'ticket_not_found', $message);
    }
}
