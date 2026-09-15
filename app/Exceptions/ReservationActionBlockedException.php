<?php

namespace App\Exceptions;

use Exception;

/**
 * A reservation action is not allowed in the reservation's current state; the message explains why (pt-BR, shown to the user).
 */
class ReservationActionBlockedException extends Exception
{
    //
}
