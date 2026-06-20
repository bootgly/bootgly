<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Logs;


use function is_array;
use function is_int;
use function is_string;
use InvalidArgumentException;

use Bootgly\ACI\Logs;
use Bootgly\ACI\Logs\Data\Display;
use Bootgly\ACI\Logs\Data\Levels;
use Bootgly\ACI\Logs\Data\Record;
use Bootgly\ACI\Logs\Handlers\Stream;


class Logger extends Logs
{
   // * Config
   public string $channel;
   // @ Opt-in: when true, this logger also fans its records out to the global sinks ($Sinks).
   //   Default false — a module must explicitly choose to write to the global channel.
   public bool $global;

   // * Data
   public Handlers $Handlers;
   public Processors $Processors;
   // @ Global sink handlers for persistent, framework-wide logging (e.g. a file). Records reach
   //   them only from loggers that opted in via $global. Null = no global sinks configured.
   public static null|Handlers $Sinks = null;
   // @ Live tap fed by EVERY record regardless of $global — the Monitor live-viewer pipe.
   //   Framework-managed and transient (set on entering Monitor, cleared on leaving). Null = none.
   public static null|Handler $Tap = null;


   /**
    * Build a logger for a channel, wired with a default stdout stream handler.
    *
    * @param string $channel Channel name identifying the log source.
    * @param bool $global Opt this logger into the global sinks ($Sinks) for framework-wide logging.
    */
   public function __construct (string $channel = '', bool $global = false)
   {
      // * Config
      $this->channel = $channel;
      $this->global = $global;

      // * Data
      $this->Handlers = new Handlers;
      $this->Processors = new Processors;

      // @ Default handler stack
      $this->Handlers->push(new Stream);
   }

   /**
    * Log one or more messages, each at its own named severity level.
    *
    * Levels are passed as named arguments — `log(error: 'msg')`,
    * `log(info: 'a', error: 'b', context: [...])`. Each `level: message` pair emits its own record,
    * in call order; `context` (when given) is shared by all of them. Positional calls are rejected.
    * Recognized levels: emergency, alert, critical, error, warning, notice, info, debug.
    *
    * @param string|array<string,mixed> ...$args One or more `level: message` pairs, plus optional `context: [...]`.
    * @return bool True once the records are dispatched (or suppressed by display mode).
    * @throws InvalidArgumentException On positional args, unknown level, missing level, or invalid value types.
    */
   public function log (string|array ...$args): bool
   {
      // ? Nothing to do — display off, this logger isn't global (or no sinks), and no live tap
      if (
         Display::$segments === Display::NONE
         && self::$Tap === null
         && ($this->global === false || self::$Sinks === null)
      ) {
         return true;
      }

      // ! Resolve named arguments: shared context + ordered level/message pairs
      $context = [];
      /** @var array<int,array{0:Levels,1:string}> $entries */
      $entries = [];
      foreach ($args as $name => $value) {
         // ? Positional argument not allowed
         if (is_int($name) === true) {
            throw new InvalidArgumentException('Logger->log() requires named arguments.');
         }
         // # Context (shared by every record)
         if ($name === 'context') {
            if (is_array($value) === false) {
               throw new InvalidArgumentException('Logger->log() "context" must be an array.');
            }
            $context = $value;
            continue;
         }
         // # Level => message
         $Level = Levels::fetch($name);
         if ($Level === null) {
            throw new InvalidArgumentException("Logger->log() unknown level \"$name\".");
         }
         if (is_string($value) === false) {
            throw new InvalidArgumentException("Logger->log() message for \"$name\" must be a string.");
         }
         $entries[] = [$Level, $value];
      }

      // ? At least one level required
      if ($entries === []) {
         throw new InvalidArgumentException('Logger->log() requires at least one level argument.');
      }

      // @ Emit one record per level, in call order
      foreach ($entries as [$Level, $message]) {
         $Record = new Record($Level, $this->channel, $message, $context);
         $Record = $this->Processors->process($Record);

         // @ Local handlers (skipped when display is suppressed, e.g. Monitor mode)
         if (Display::$segments !== Display::NONE) {
            $this->Handlers->handle($Record);
         }
         // @ Global sinks — opt-in per logger (persistent, e.g. a file)
         if ($this->global === true) {
            self::$Sinks?->handle($Record);
         }
         // @ Live tap — every record, regardless of opt-in (the Monitor viewer)
         self::$Tap?->handle($Record);
      }

      // :
      return true;
   }
}
