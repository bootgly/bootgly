<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ADI\Databases\SQL;


use function microtime;

use Bootgly\ABI\Events\Emitter;
use Bootgly\ADI\Database\Connection;
use Bootgly\ADI\Database\Operation as DatabaseOperation;
use Bootgly\ADI\Database\Operation\Result;
use Bootgly\ADI\Databases\SQL\Events;


/**
 * Pending SQL database operation.
 */
class Operation extends DatabaseOperation
{
   // * Config
   public string $sql;

   // * Data
   public string $statement = '';
   public string $portal = '';
   public bool $prepared = false;
   public string $write = '';
   public string $status = '';
   /** @var array<int,array<string,mixed>> */
   public array $rows = [];
   /** @var array<int,string> */
   public array $columns = [];
   /** @var array<int,int> */
   public array $types = [];
   /** @var array<int,int> */
   public array $parameterTypes = [];
   public int $affected = 0;

   // * Metadata
   /** Slow-query threshold in seconds; `0.0` disables detection (zero overhead — no `microtime()`). */
   public static float $slow = 0.0;
   protected float $started = 0.0;


   /**
    * Create a pending SQL operation.
    *
    * @param array<int|string,mixed> $parameters
    */
   public function __construct (null|Connection $Connection, string $sql, array $parameters = [], float $timeout = 0.0)
   {
      parent::__construct($Connection, $parameters, $timeout);

      // * Config
      $this->sql = $sql;

      // ? Slow-query timing — only pay the microtime() syscall when enabled
      if (self::$slow > 0.0) {
         $this->started = microtime(true);
      }
   }

   /**
    * Resolve this operation with a result.
    */
   public function resolve (Result $Result): self
   {
      $this->write = '';
      parent::resolve($Result);

      // @ Events — query executed (guarded: zero-alloc when no listeners)
      $Emitter = Emitter::$Instance;
      $Emitter->check(Events::Executed) && $Emitter->emit(Events::Executed, $this);

      // ?: Slow query — only when detection is enabled (no microtime() otherwise)
      if (self::$slow > 0.0) {
         $elapsed = microtime(true) - $this->started;
         if ($elapsed >= self::$slow) {
            $Emitter->check(Events::Slow) && $Emitter->emit(Events::Slow, $this, $elapsed);
         }
      }

      return $this;
   }

   /**
    * Fail this operation with an error message.
    */
   public function fail (string $error): self
   {
      $this->write = '';
      parent::fail($error);

      return $this;
   }
}
