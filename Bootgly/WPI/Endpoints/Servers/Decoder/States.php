<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Endpoints\Servers\Decoder;


/**
 * Outcome of a single `Decoder::decode()` invocation.
 *
 * Replaces the integer-overloaded return value where `0` simultaneously
 * meant "still waiting for bytes" and "rejected, connection being closed",
 * preventing the caller from distinguishing the two. The byte count of
 * consumed bytes is now carried out-of-band on `Packages::$consumed`.
 */
enum States
{
   case Incomplete;
   case Complete;
   case Rejected;
}
