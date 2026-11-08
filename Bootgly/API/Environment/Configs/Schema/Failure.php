<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\API\Environment\Configs\Schema;


use RuntimeException;


/**
 * The aggregated report of a config scope that failed its `Schema`.
 *
 * Thrown once per scope with every failure found, so a deployment sees the
 * whole list instead of fixing one key per boot. The message names the
 * scope, each key path and the reason — never a resolved or rejected value,
 * so secrets (passwords, tokens) do not reach logs through it.
 */
final class Failure extends RuntimeException
{
   // * Config
   /**
    * The scope that failed.
    */
   public private(set) string $scope;
   /**
    * The reasons, keyed by the key path that failed, in declaration order.
    *
    * @var array<string,array<int,string>>
    */
   public private(set) array $failures;


   /**
    * @param string $scope The scope that failed.
    * @param array<string,array<int,string>> $failures The reasons, keyed by key path.
    */
   public function __construct (string $scope, array $failures)
   {
      // * Config
      $this->scope = $scope;
      $this->failures = $failures;

      // ! One line per reason
      $count = 0;
      $lines = '';
      foreach ($failures as $path => $reasons) {
         foreach ($reasons as $reason) {
            $count++;
            $lines .= "\n- {$path}: {$reason}";
         }
      }
      $noun = $count === 1 ? 'failure' : 'failures';

      parent::__construct("Invalid config scope `{$scope}` ({$count} {$noun}):{$lines}");
   }
}
