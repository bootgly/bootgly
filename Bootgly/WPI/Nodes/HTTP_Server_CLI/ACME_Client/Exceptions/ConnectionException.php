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
 * Transport failure toward the ACME server: connect refusal, TLS handshake
 * failure, read/write timeout or an empty response. `$code` is 0 — there is
 * no HTTP status to carry when the transport itself failed.
 */
final class ConnectionException extends Exception implements Exceptioning
{
}
