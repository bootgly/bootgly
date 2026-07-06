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
 * SMTP authentication failure: 535/5xx during AUTH, an XOAUTH2 challenge
 * rejection, a required mechanism not advertised by the server, or
 * credentials configured over an unencrypted session without the explicit
 * `insecure` opt-in. `$code` carries the SMTP reply code (0 when the
 * failure is local and nothing touched the wire).
 */
final class AuthenticationException extends Exception implements Exceptioning
{
}
