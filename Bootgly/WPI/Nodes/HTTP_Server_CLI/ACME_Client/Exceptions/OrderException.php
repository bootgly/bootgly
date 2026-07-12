<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\ACME_Client\Exceptions;


use Exception;

use Bootgly\WPI\Nodes\HTTP_Server_CLI\ACME_Client\Exceptioning;


/**
 * An order or one of its authorizations reached the `invalid` state, or the
 * polling budget was exhausted before the ACME server settled. The message
 * carries the failed challenge's `error.detail` when the server provided one.
 */
final class OrderException extends Exception implements Exceptioning
{
}
