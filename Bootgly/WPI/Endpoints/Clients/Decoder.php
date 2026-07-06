<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Endpoints\Clients;


interface Decoder
{
   /**
    * Decode a raw HTTP response buffer.
    *
    * @param string $buffer The raw TCP buffer.
    * @param int $size The buffer size.
    *
    * @return null|array<string, mixed> Parsed data or null if incomplete.
    */
   public function decode (string $buffer, int $size): null|array;
}
