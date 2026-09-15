<?php

namespace App\Exceptions;

use Exception;

/**
 * An escalation action is not allowed in the escalation's current state; the message explains why (pt-BR, shown to the user).
 */
class EscalationActionBlockedException extends Exception
{
    //
}
