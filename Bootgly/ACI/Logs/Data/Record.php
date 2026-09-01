<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Logs\Data;


use function is_array;
use function is_float;
use function is_int;
use function is_string;
use function microtime;


class Record
{
   // * Config
   /**
    * Process-scoped provenance stamped on every new record: the booted project's name, set
    * ONCE per process by the boot context (Project::mount(), which boot() calls); 'framework'
    * when no project is booted. Never derived per record — no path heuristics, no
    * BOOTGLY_PROJECT reads here.
    */
   public static string $provenance = 'framework';
   /**
    * Process-scoped instance qualifier stamped on every new record: the identity the process
    * registry keys this process by — the bound port for servers, the master PID for console
    * and TUI processes. Set by the owner process where it claims its registry instance
    * (a server start(), `project start`, `project <Name> schedule run`, the terminal loop)
    * and re-set only when the same process claims another (the test runner booting several
    * Test-mode servers); '' when the process claimed none (kit commands, plain scripts, WPI
    * clients). Never derived here — ACI cannot read the registry.
    */
   public static string $qualifier = '';

   // * Data
   public Levels $Level;
   public string $channel;
   public string $message;
   public string $project;
   /** Instance qualifier of the writing process ('' when it claimed none). */
   public string $instance;
   /** @var array<string,mixed> */
   public array $context;
   /** @var array<string,mixed> */
   public array $extra;
   public float $timestamp;


   /**
    * Build a log record carried through the logging pipeline.
    *
    * @param Levels $Level Severity level.
    * @param string $channel Originating channel.
    * @param string $message Log message (may contain Bootgly template tokens).
    * @param array<string,mixed> $context Caller-supplied context data.
    */
   public function __construct (
      Levels $Level,
      string $channel,
      string $message,
      array $context = []
   )
   {
      // * Data
      $this->Level = $Level;
      $this->channel = $channel;
      $this->message = $message;
      $this->project = self::$provenance;
      $this->instance = self::$qualifier;
      $this->context = $context;
      $this->extra = [];
      $this->timestamp = microtime(true);
   }

   /**
    * Rebuild a record from a decoded JSON line (as produced by the JSON formatter).
    *
    * Used by the master process to reconstruct records streamed from workers over a pipe, so they
    * can be re-filtered and re-formatted for the live viewer.
    *
    * @param array<string,mixed> $data Decoded fields: level, project, instance, channel, message, context, extra, timestamp.
    * @return self
    */
   public static function import (array $data): self
   {
      // @ Level (fall back to Debug on unknown/missing label)
      $level = $data['level'] ?? null;
      $Level = Levels::fetch(is_string($level) ? $level : 'debug') ?? Levels::Debug;

      // @ Build with shared context
      $channel = $data['channel'] ?? null;
      $message = $data['message'] ?? null;
      $context = isSet($data['context']) && is_array($data['context']) ? $data['context'] : [];
      $Record = new self(
         $Level,
         is_string($channel) ? $channel : '',
         is_string($message) ? $message : '',
         $context
      );

      // @ Restore provenance ('framework' for lines written before the field existed) —
      //   unconditional, so a booted process never leaks its own provenance onto imported lines
      $project = $data['project'] ?? null;
      $Record->project = is_string($project) && $project !== '' ? $project : 'framework';

      // @ Restore the instance qualifier ('' for lines written before the field existed) —
      //   unconditional, so a stamped process never leaks its own instance onto imported lines
      $instance = $data['instance'] ?? null;
      $Record->instance = is_string($instance) ? $instance : '';

      // @ Restore processor-enriched extra + original timestamp
      $Record->extra = isSet($data['extra']) && is_array($data['extra']) ? $data['extra'] : [];
      $timestamp = $data['timestamp'] ?? null;
      if ( is_int($timestamp) || is_float($timestamp) ) {
         $Record->timestamp = (float) $timestamp;
      }

      // :
      return $Record;
   }
}
