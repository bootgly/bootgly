<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Tests\Doubles\Mock;


use Throwable;


/**
 * Value object — one recorded invocation on a Mock or Spy.
 */
final class Call
{
   // * Data
   /**
    * Method name invoked on the generated Proxy.
    */
   public string $method;
   /** @var array<int,mixed> */
   public array $arguments;
   /**
    * Value returned by the invocation, when it completed normally.
    */
   public mixed $returned;
   /**
    * Throwable raised by the invocation, when it failed.
    */
   public null|Throwable $Threw;
   /**
    * Timestamp captured when the invocation was recorded.
    */
   public float $at;


   /**
    * @param array<int,mixed> $arguments
    */
   public function __construct (
      string $method,
      array $arguments,
      mixed $returned = null,
      null|Throwable $Threw = null,
      float $at = 0.0,
   )
   {
      $this->method = $method;
      $this->arguments = $arguments;
      $this->returned = $returned;
      $this->Threw = $Threw;
      $this->at = $at;
   }
}