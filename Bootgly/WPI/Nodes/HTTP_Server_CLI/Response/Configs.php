<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;


use function is_string;
use Closure;
use InvalidArgumentException;

use Bootgly\ABI\Argument;
use Bootgly\ABI\Configs as Configuring;


/**
 * Response configuration of an HTTP Server.
 *
 * Handed to `HTTP_Server_CLI->configure()`; every entry left `null` keeps
 * whatever is currently configured — these are process-global statics, not
 * per-instance state, so `null` never resets one to the framework default.
 *
 * Named arguments only: `$Named` is a guard slot no named call ever fills, so
 * a positional `new Configs([...])` is rejected by the engine (and flagged by
 * static analysis) before it can silently bind the wrong value.
 */
class Configs implements Configuring
{
   // * Config
   /** @var null|array<string,Closure> Lazy response resource factories, by name. */
   public private(set) null|array $Resources;
   /** Seconds a deferred response may stay parked before it times out (`0` = unbounded). */
   public private(set) null|int|float $deferredTimeout;


   /**
    * @param Argument $Named Guard slot — never pass it; it only rejects positional calls.
    * @param null|array<array-key,mixed> $Resources Lazy response resource factories, by name — every value must be a Closure.
    *
    * @throws InvalidArgumentException When a factory is not a named Closure.
    */
   public function __construct (
      Argument $Named = Argument::Undefined,
      null|array $Resources = null,
      null|int|float $deferredTimeout = null
   )
   {
      // ? Validate here, not at apply time — a factory rejected halfway through
      //   `configure()` would leave the Configs applied before it in place
      if ($Resources !== null) {
         foreach ($Resources as $name => $Factory) {
            if (is_string($name) === false) {
               throw new InvalidArgumentException(
                  'Response resource definitions must be keyed by name.'
               );
            }
            if ($Factory instanceof Closure === false) {
               throw new InvalidArgumentException(
                  "Response resource definition `{$name}` must be a Closure factory."
               );
            }
         }
      }

      // * Config
      /** @var null|array<string,Closure> $Resources — proved by the loop above */
      $this->Resources = $Resources;
      $this->deferredTimeout = $deferredTimeout;
   }
}
