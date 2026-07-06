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
 * The server violated the SMTP grammar: malformed reply line, mixed codes
 * inside a multiline reply, or a reply exceeding the line/size limits.
 * `$code` is always 0 — there is no trustworthy reply code to carry.
 */
final class ProtocolException extends Exception implements Exceptioning
{
}
