<?php

namespace App\Exceptions\Api;

class ResidentNotFound extends ApiException
{
    public function __construct(string $message = 'Telefone não pertence a um morador ativo do condomínio.')
    {
        parent::__construct(403, 'resident_not_found', $message);
    }
}
