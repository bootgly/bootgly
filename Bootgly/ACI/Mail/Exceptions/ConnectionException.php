<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Mail\Exceptions;


use Exception;

use Bootgly\ACI\Mail\Exceptioning;


/**
 * Transport failure: connect refusal, read/write timeout, unexpected EOF
 * or broken pipe. `$code` carries the socket errno when available
 * (0 otherwise).
 */
final class ConnectionException extends Exception implements Exceptioning
{
}
