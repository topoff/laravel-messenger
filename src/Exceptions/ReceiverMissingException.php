<?php

namespace Topoff\Messenger\Exceptions;

use Exception;

class ReceiverMissingException extends Exception
{
    public const USER_DELETED = 1000;
}
