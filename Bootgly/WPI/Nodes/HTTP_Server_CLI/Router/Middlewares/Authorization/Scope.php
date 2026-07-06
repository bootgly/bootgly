<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authorization;


use function is_string;
use InvalidArgumentException;

use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authorizing\Gate;


/**
 * Exact-scope authorization gate.
 */
class Scope extends Gate
{
   // * Config
   /**
    * Required exact scope grants.
    *
    * @var array<int,string>
    */
   public private(set) array $scopes;
   /**
    * Whether all configured scopes are required.
    */
   public private(set) bool $all;

   // * Data
   // ...

   // * Metadata
   // ...


   /**
    * Configure required scope grants.
    *
    * @param string|array<int,string> $scopes
    */
   public function __construct (string|array $scopes, bool $all = true)
   {
      // * Config
      $this->scopes = $this->normalize($scopes);
      $this->all = $all;
   }

   /**
    * Authorize a request by exact Identity scope grants.
    */
   public function authorize (object $Request): bool
   {
      $Identity = $this->resolve($Request);
      if ($Identity === null) {
         return false;
      }

      if ($this->all) {
         foreach ($this->scopes as $scope) {
            if ($Identity->check($scope) === false) {
               return false;
            }
         }

         return true;
      }

      foreach ($this->scopes as $scope) {
         if ($Identity->check($scope)) {
            return true;
         }
      }

      return false;
   }

   /**
    * Normalize a configured scope list.
    *
    * @param string|array<int|string,mixed> $scopes
    * @return array<int,string>
    */
   private function normalize (string|array $scopes): array
   {
      if (is_string($scopes)) {
         if ($scopes === '') {
            throw new InvalidArgumentException('Authorization scope must not be empty.');
         }

         return [$scopes];
      }

      $Scopes = [];
      foreach ($scopes as $scope) {
         if (is_string($scope) === false || $scope === '') {
            throw new InvalidArgumentException('Authorization scopes must be non-empty strings.');
         }

         $Scopes[] = $scope;
      }

      if ($Scopes === []) {
         throw new InvalidArgumentException('Authorization scopes must not be empty.');
      }

      return $Scopes;
   }
}
