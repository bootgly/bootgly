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


use Bootgly\WPI\Nodes\WS_Server_CLI\Channels\Channel;


/**
 * Per-worker room registry. Single-worker only: each fork+SO_REUSEPORT worker
 * holds its own set, so a broadcast reaches only same-worker connections.
 */
class Channels
{
   // * Metadata
   /** @var array<string, Channel> */
   public static array $Channels = [];


   /**
    * Get (or lazily create) a channel by name.
    */
   public static function fetch (string $name): Channel
   {
      return self::$Channels[$name] ??= new Channel($name);
   }

   /**
    * Look up a channel by name without creating it; null when absent.
    */
   public static function find (string $name): null|Channel
   {
      return self::$Channels[$name] ?? null;
   }

   /**
    * Remove a channel from the registry.
    */
   public static function drop (string $name): void
   {
      unset(self::$Channels[$name]);
   }
}
