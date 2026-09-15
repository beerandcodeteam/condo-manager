<?php

namespace App\Exceptions\Api;

class AdvanceNoticeViolation extends ApiException
{
    public function __construct(int $minAdvanceHours, int $maxAdvanceDays, string $message = 'A data está fora da antecedência permitida para esta área.')
    {
        parent::__construct(422, 'advance_notice_violation', $message, [
            'min_advance_hours' => $minAdvanceHours,
            'max_advance_days' => $maxAdvanceDays,
        ]);
    }
}
