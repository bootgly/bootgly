<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Tests\Benchmark;


class Result
{
   // * Data
   /**
    * Execution time in seconds (code benchmarks).
    */
   public readonly null|string $time;

   /**
    * Memory usage in bytes (code benchmarks).
    */
   public readonly null|int $memory;

   /**
    * Requests per second (server benchmarks).
    */
   public readonly null|float $rps;

   /**
    * Average latency string (server benchmarks).
    */
   public readonly null|string $latency;

   /**
    * Transfer per second string (server benchmarks).
    */
   public readonly null|string $transfer;

   /** Logical requests queued for transmission by the load worker. */
   public readonly null|int $scheduled;

   /** Logical requests whose final byte reached the socket API. */
   public readonly null|int $sent;

   /** Structurally complete final HTTP responses. */
   public readonly null|int $responses;

   /** Informational HTTP responses, which do not consume a request. */
   public readonly null|int $informational;

   /** Fully sent requests still awaiting a terminal response outcome. */
   public readonly null|int $outstanding;

   /** Fully sent requests terminated without a complete final response. */
   public readonly null|int $failed;

   /** Queued requests that were not fully transmitted. */
   public readonly null|int $writeFailed;

   /** Configured connections that the worker could not establish. */
   public readonly null|int $connectionFailed;

   /** Observed write reconciliations that left a non-empty queued suffix. */
   public readonly null|int $partialWrites;

   /** Whether both request/response accounting equations closed exactly. */
   public readonly null|bool $accounting;

   /** @var null|array<int,int> Final HTTP status => count. */
   public readonly null|array $statuses;

   /** @var null|array<string,int> Fully sent request failure => count. */
   public readonly null|array $failures;

   /** @var null|array<string,int> Unsent request failure => count. */
   public readonly null|array $writeFailures;


   public function __construct (
      null|string $time = null,
      null|int $memory = null,
      null|float $rps = null,
      null|string $latency = null,
      null|string $transfer = null,
      null|int $scheduled = null,
      null|int $sent = null,
      null|int $responses = null,
      null|int $informational = null,
      null|int $outstanding = null,
      null|int $failed = null,
      null|int $writeFailed = null,
      null|int $connectionFailed = null,
      null|int $partialWrites = null,
      null|bool $accounting = null,
      null|array $statuses = null,
      null|array $failures = null,
      null|array $writeFailures = null,
   )
   {
      $this->time = $time;
      $this->memory = $memory;
      $this->rps = $rps;
      $this->latency = $latency;
      $this->transfer = $transfer;
      $this->scheduled = $scheduled;
      $this->sent = $sent;
      $this->responses = $responses;
      $this->informational = $informational;
      $this->outstanding = $outstanding;
      $this->failed = $failed;
      $this->writeFailed = $writeFailed;
      $this->connectionFailed = $connectionFailed;
      $this->partialWrites = $partialWrites;
      $this->accounting = $accounting;
      $this->statuses = $statuses;
      $this->failures = $failures;
      $this->writeFailures = $writeFailures;
   }
}
