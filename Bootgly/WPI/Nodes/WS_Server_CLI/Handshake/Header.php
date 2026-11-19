<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\WS_Server_CLI\Handshake;


use function is_array;
use function is_string;
use function strtolower;

use Bootgly\ABI\Debugging\Data\Vars\Redactable;
use Bootgly\ABI\Debugging\Data\Vars\Redactable\Secret;
use Bootgly\WPI\Modules\HTTP;


/**
 * A case-insensitive header bag for the handshake auth adapter. Reads (`get`)
 * back the parsed upgrade headers for guards, and captures (`set`) a guard's
 * challenge header (e.g. `WWW-Authenticate`).
 *
 * A debug dump never shows the credential headers (`HTTP::SECRET_HEADERS`).
 */
class Header
{
   use Redactable;


   // * Data
   /** @var array<string, mixed> Lowercased header map. */
   #[Secret(HTTP::SECRET_HEADERS)]
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
