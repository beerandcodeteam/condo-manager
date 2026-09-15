<?php

namespace App\Exceptions;

use Exception;

/**
 * A record in use cannot be deleted; the message explains what to do instead (pt-BR, shown to the user).
 */
class DeletionBlockedException extends Exception
{
    //
}
