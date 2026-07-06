<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Mail\Exceptions;


use Exception;

use Bootgly\ACI\Mail\Exceptioning;


/**
 * TLS failure: negotiation error, certificate verification failure, or
 * STARTTLS refused/not advertised while the config demands it — the client
 * never downgrades silently. `$code` carries the SMTP reply code when the
 * server refused STARTTLS (0 for local/OpenSSL failures).
 */
final class CryptoException extends Exception implements Exceptioning
{
}
