<?php

namespace App\Exceptions\Api;

class AreaNotFound extends ApiException
{
    public function __construct(string $message = 'Área comum não encontrada.')
    {
        parent::__construct(404, 'area_not_found', $message);
    }
}
