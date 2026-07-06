<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\WS_Server_CLI\Handshake;


use function is_array;
use function is_string;
use function strtolower;


/**
 * A case-insensitive header bag for the handshake auth adapter. Reads (`get`)
 * back the parsed upgrade headers for guards, and captures (`set`) a guard's
 * challenge header (e.g. `WWW-Authenticate`).
 */
class Header
{
   // * Data
   /** @var array<string, mixed> Lowercased header map. */
   public array $fields;


   /**
    * @param array<string, mixed> $fields Lowercased header map (or empty).
    */
   public function __construct (array $fields = [])
   {
      $this->fields = $fields;
   }

   public function get (string $name): null|string
   {
      $value = $this->fields[strtolower($name)] ?? null;

      if (is_array($value)) {
         $value = $value[0] ?? null;
      }

      return is_string($value)
         ? $value
         : null;
   }

   public function set (string $name, string $value): void
   {
      $this->fields[strtolower($name)] = $value;
   }
}
