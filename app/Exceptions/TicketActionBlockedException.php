<?php

namespace App\Exceptions;

use Exception;

/**
 * A ticket action is not allowed in the ticket's current state; the message explains why (pt-BR, shown to the user).
 */
class TicketActionBlockedException extends Exception
{
    //
}
