<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Endpoints;


use function in_array;
use ArgumentCountError;
use InvalidArgumentException;

use Bootgly\ABI\Configs as Configuring;


/**
 * The `configure()` contract every endpoint shares: one Configs per concern,
 * validated as a whole BEFORE a single one is applied.
 *
 * The whole set is checked first — every Configs is one this node applies, no
 * concern arrives twice, and the transport is present — so a set rejected on
 * those grounds leaves nothing behind. Request limits, response budgets and
 * connection caps are process-global statics shared by every endpoint in the
 * process, and on the Auto-TLS path a partial apply would retire a live
 * runtime while reporting the configuration unchanged.
 *
 * What a Configs carries is validated by its own constructor wherever it can
 * be — a resource factory, a `secure` context declared alongside Auto-TLS —
 * for the same reason. A few checks can only run against live state at apply
 * time (the Auto-TLS runtime preconditions, a resource name a response
 * property already owns); those throw after the Configs before them applied.
 *
 * Users declare, per node:
 * - `TRANSPORT` — the Configs carrying the socket (host, port, workers).
 * - `CONFIGS` — every Configs class the node knows how to apply.
 */
trait Configurable
{
   // * Metadata
   /** Whether a TRANSPORT Configs was ever applied to this endpoint. */
   private bool $transported = false;


   /**
    * Apply one Configs to this endpoint.
    *
    * Nodes override it to consume their own Configs and delegate the rest.
    */
   abstract protected function adopt (Configuring $Config): void;

   /**
    * Validate the whole Configs set, then apply it.
    *
    * @param array<int|string,Configuring> $Configs
    *
    * @throws ArgumentCountError When the set is empty, or when no Configs ever carried the transport.
    * @throws InvalidArgumentException On a repeated or unsupported Configs.
    */
   protected function apply (array $Configs): void
   {
      $node = static::class;
      $Transport = static::TRANSPORT;

      // ? A call that configures nothing is a mistake, never a no-op
      if ($Configs === []) {
         throw new ArgumentCountError(
            "{$node}->configure() requires at least one Configs."
         );
      }

      // ? Validate — one Configs per concern, all of them applicable here.
      //   Acceptance is by exact class: a subclass of a Configs this node takes
      //   carries fields the node cannot read, and dropping them silently is
      //   how a TLS context or a connection cap goes missing.
      $transported = $this->transported;
      $validated = [];
      foreach ($Configs as $Config) {
         $class = $Config::class;

         if (in_array($class, static::CONFIGS, true) === false) {
            throw new InvalidArgumentException(
               "{$node}->configure() does not accept {$class}."
            );
         }

         if (isSet($validated[$class]) === true) {
            throw new InvalidArgumentException(
               "{$node}->configure() received two {$class} instances."
            );
         }
         $validated[$class] = true;

         if ($class === $Transport) {
            $transported = true;
         }
      }

      // ? Transport is mandatory — the first configure() must carry it
      if ($transported === false) {
         throw new ArgumentCountError(
            "{$node}->configure() requires a {$Transport}."
         );
      }

      // @ Apply — the set proved legal, so no Configs is refused by halves
      foreach ($Configs as $Config) {
         $this->adopt($Config);

         // ! The socket is live from here: a later configure() may refine one
         //   concern on its own, even if this call throws further down
         if ($Config::class === $Transport) {
            $this->transported = true;
         }
      }
   }
}
