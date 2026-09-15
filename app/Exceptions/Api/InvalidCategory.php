<?php

namespace App\Exceptions\Api;

class InvalidCategory extends ApiException
{
    public function __construct(string $message = 'Categoria inexistente ou inativa.')
    {
        parent::__construct(422, 'invalid_category', $message);
    }
}
