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


use function is_callable;
use Closure;
use InvalidArgumentException;

use Bootgly\API\Security\Authorization as Engine;
use Bootgly\API\Security\Authorization\Policy as PolicyContract;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authorizing\Gate;


/**
 * Resource policy authorization gate.
 */
class Policy extends Gate
{
   // * Config
   /**
    * Resource policy evaluated for the current request.
    */
   public private(set) PolicyContract $Policy;
   /**
    * Policy action name.
    */
   public private(set) string $action;
   /**
    * Optional request-to-resource resolver.
    *
    * @var null|Closure(object):mixed
    */
   public private(set) null|Closure $Resource;

   // * Data
   /**
    * Transport-agnostic policy evaluator.
    */
   private Engine $Engine;

   // * Metadata
   // ...


   /**
    * Configure resource policy authorization.
    *
    * @param null|Closure(object):mixed $Resource
    */
   public function __construct (
      PolicyContract $Policy,
      string $action,
      null|Closure $Resource = null,
      null|Engine $Engine = null
   )
   {
      if ($action === '' || is_callable([$Policy, $action]) === false) {
         throw new InvalidArgumentException('Authorization policy action must reference a public policy method.');
      }

      // * Config
      $this->Policy = $Policy;
      $this->action = $action;
      $this->Resource = $Resource;

      // * Data
      $this->Engine = $Engine ?? new Engine;
   }

   /**
    * Authorize a request by a resource policy.
    */
   public function authorize (object $Request): bool
   {
      $Identity = $this->resolve($Request);
      if ($Identity === null) {
         return false;
      }

      // @ Resolve resource from request.
      $Resource = $this->Resource;
      $Resolved = $Resource === null ? $Request : $Resource($Request);

      // :
      return $this->Engine->authorize($Identity, $this->Policy, $this->action, $Resolved);
   }
}
