<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ADI\Databases\SQL\Drivers\MySQL;


/**
 * MySQL wire packet.
 *
 * MySQL packets carry no type byte: their meaning depends on the connection
 * phase and the first payload byte, so the driver interprets `payload` and
 * fills `fields` through the Decoder read modes.
 */
class Message
{
   // * Config
   public int $sequence;
   public string $payload;

   // * Data
   /** @var array<int|string,mixed> */
   public array $fields;

   // * Metadata
   // ...


   /**
    * Create a decoded wire packet.
    *
    * @param array<int|string,mixed> $fields
    */
   public function __construct (int $sequence, string $payload = '', array $fields = [])
   {
      // * Config
      $this->sequence = $sequence;
      $this->payload = $payload;

      // * Data
      $this->fields = $fields;
   }
}
