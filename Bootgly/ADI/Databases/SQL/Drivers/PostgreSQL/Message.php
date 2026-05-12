<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ADI\Databases\SQL\Drivers\PostgreSQL;


/**
 * PostgreSQL backend message.
 */
class Message
{
   // * Config
   public string $type;
   public string $payload;

   // * Data
   /** @var array<int|string,mixed> */
   public array $fields;

   // * Metadata
   // ...


   /**
    * Create a decoded backend message.
    *
    * @param array<int|string,mixed> $fields
    */
   public function __construct (string $type, string $payload = '', array $fields = [])
   {
      // * Config
      $this->type = $type;
      $this->payload = $payload;

      // * Data
      $this->fields = $fields;
   }
}
