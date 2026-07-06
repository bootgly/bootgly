<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
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
   // * Data
   public Levels $Level;
   public string $channel;
   public string $message;
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
    * @param array<string,mixed> $data Decoded fields: level, channel, message, context, extra, timestamp.
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
