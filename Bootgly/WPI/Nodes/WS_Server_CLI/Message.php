<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\WS_Server_CLI;


use Bootgly\WPI\Modules\WS;


class Message
{
   // * Data
   public int $opcode;
   public string $payload;

   // * Metadata
   public bool $binary;


   public function __construct (int $opcode, string $payload)
   {
      // * Data
      $this->opcode = $opcode;
      $this->payload = $payload;

      // * Metadata
      $this->binary = $opcode === WS::OPCODE_BINARY;
   }
}
