<?php

namespace App\Exceptions;

use Exception;

/**
 * A rule document action is not allowed in the document's current status; the message explains why (pt-BR, shown to the user).
 */
class RuleDocumentActionBlockedException extends Exception
{
    //
}
