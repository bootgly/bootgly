<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Endpoints\Servers;


use function get_debug_type;
use function mb_scrub;
use function mb_strcut;
use function strlen;
use Throwable;

use Bootgly\ABI\Code\__String\Controls;
use Bootgly\ABI\Debugging\Data\Throwables;
use Bootgly\ABI\Templates\Template\Escaped;
use Bootgly\ACI\Logs\Logger;


/**
 * The record a server keeps of the failures it catches and survives.
 *
 * A server reports what it catches from application code — a request or
 * message handler, a signal handler on the loop, a recurring timer, a
 * shutdown listener —
 * through `Throwables::notify()` and goes on. `register()` makes that report
 * a line of the `exceptions` log channel, on every server alike.
 */
final class Reporter
{
   // * Metadata
   private static bool $registered = false;


   /**
    * Log every reported Throwable to the `exceptions` channel.
    *
    * Registered once per process, however many servers start in it; called
    * before the workers are forked, so each of them inherits it.
    */
   public static function register (): void
   {
      // ? Once per process
      if (self::$registered) {
         return;
      }
      self::$registered = true;

      // @
      Throwables::$reporters[] = static function (Throwable $Throwable, array $context): void {
         static $Logger = null;
         $Logger ??= new Logger(channel: 'exceptions', global: true);

         // ! The message may echo a peer's bytes (a query string a PHP
         //   exception quotes): made inert before it reaches the log
         $Logger->log(error: self::defuse($Throwable->getMessage()), context: [
            'class' => get_debug_type($Throwable),
            'file' => $Throwable->getFile(),
            'line' => $Throwable->getLine(),
            ...$context
         ]);
      };
   }
   /**
    * Make a message the server did not author inert for a log line: capped
    * (an exception may quote a whole request body), bytes that are not UTF-8
    * become `?` (a raw 0x9B is a CSI on a non-UTF-8 terminal), control
    * characters — line feeds included — are escaped visibly and the Bootgly
    * markup is defused, so the text can neither drive the operator's terminal
    * nor forge a record.
    *
    * @param string $message The message as it was received.
    *
    * @return string The message, safe to log.
    */
   public static function defuse (string $message): string
   {
      // ? A log line needs far less than a request body — capped first, on a
      //   character boundary, so the scrub still sees every `@` next to the cut
      if (strlen($message) > 4096) {
         $message = mb_strcut($message, 0, 4096, 'UTF-8') . '...';
      }

      // :
      return Escaped::scrub(Controls::escape(mb_scrub($message, 'UTF-8')));
   }
}
