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
 * The ACME server violated the protocol grammar (RFC 8555): a non-JSON body
 * where JSON is required, a missing `Location`/`Replay-Nonce` header or a
 * response object missing required fields. `$code` is 0 — nothing in such a
 * response is trustworthy enough to carry.
 */
final class ProtocolException extends Exception implements Exceptioning
{
}
