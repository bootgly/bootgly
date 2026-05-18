<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;


use function is_string;
use Closure;
use InvalidArgumentException;
use RuntimeException;

use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response as ServerResponse;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resources\JSON;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resources\JSONP;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resources\Pre;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resources\View;


/**
 * Mounted HTTP response resources.
 */
class Resources
{
   // * Config
   /** @var null|Closure(Resource):mixed */
   private null|Closure $Attach;
   private null|object $Context;

   // * Data
   /** @var array<string,Resource> */
   public private(set) array $resources = [];
   /** @var array<string,Closure(object):Resource> */
   public private(set) array $definitions = [];

   // * Metadata
   // ...


   /**
    * Construct the response resources registry.
    *
    * @param null|Closure(Resource):mixed $Attach
    */
   public function __construct (null|Closure $Attach = null, null|object $Context = null)
   {
      // * Config
      $this->Attach = $Attach;
      $this->Context = $Context;

      if ($Context instanceof ServerResponse) {
         // * Data
         $this->define('JSON', static fn (object $Context): JSON => new JSON(self::guard($Context)));
         $this->define('JSONP', static fn (object $Context): JSONP => new JSONP(self::guard($Context)));
         $this->define('Pre', static fn (object $Context): Pre => new Pre(self::guard($Context)));
         $this->define('View', static fn (object $Context): View => new View(self::guard($Context)));
      }
   }

   /**
    * Fork definitions into a new response context.
    *
    * @param Closure(Resource):mixed $Attach
    */
   public function fork (Closure $Attach, object $Context): self
   {
      $Resources = new self($Attach, $Context);
      $Resources->definitions = $this->definitions;

      return $Resources;
   }

   /**
    * Define one lazy response resource factory.
    *
    * @param Closure(object):Resource $Factory
    */
   public function define (string $name, Closure $Factory): static
   {
      if ($this->Context === null) {
         throw new RuntimeException('Response resource definitions require a context.');
      }

      $this->definitions[$name] = $Factory;
      unset($this->resources[$name]);

      return $this;
   }

   /**
    * Load lazy response resource factories.
    *
    * @param array<array-key,mixed> $definitions
    */
   public function load (array $definitions): static
   {
      foreach ($definitions as $name => $Factory) {
         if (is_string($name) === false) {
            throw new InvalidArgumentException('Response resource definitions must be keyed by name.');
         }

         if ($Factory instanceof Closure === false) {
            throw new InvalidArgumentException('Response resource definition must be a Closure factory.');
         }

         /** @var Closure(object):Resource $Factory */
         $this->define($name, $Factory);
      }

      return $this;
   }

   /**
    * Set one mounted resource by name.
    *
    * @template T of Resource
    * @param T $Resource
    * @return T
    */
   public function set (string $name, Resource $Resource): Resource
   {
      $this->resources[$name] = $this->attach($Resource);

      return $this->resources[$name];
   }

   /**
    * Fetch one mounted resource by name.
    */
   public function fetch (string $name): null|Resource
   {
      $Resource = $this->resources[$name] ?? null;

      if ($Resource !== null) {
         return $Resource;
      }

      $Factory = $this->definitions[$name] ?? null;

      if ($Factory === null) {
         return null;
      }

      return $this->set($name, $this->create($Factory));
   }

   /**
    * Reset per-request resource instances while keeping boot definitions.
    */
   public function reset (): void
   {
      foreach ($this->resources as $name => $Resource) {
         if ($Resource->persistent) {
            continue;
         }

         unset($this->resources[$name]);
      }
   }

   /**
    * Create one resource from a lazy factory.
    */
   private function create (Closure $Factory): Resource
   {
      $Context = $this->Context;

      if ($Context === null) {
         throw new RuntimeException('Response resource factory expects a context, but none was configured.');
      }

      $Resource = $Factory($Context);

      if ($Resource instanceof Resource === false) {
         throw new RuntimeException('Response resource factory must return a Resource.');
      }

      return $Resource;
   }

   /**
    * Attach one resource to the current response lifecycle.
    *
    * @template T of Resource
    * @param T $Resource
    * @return T
    */
   private function attach (Resource $Resource): Resource
   {
      $Attach = $this->Attach;

      if ($Attach === null) {
         return $Resource;
      }

      $Attached = $Attach($Resource);

      if ($Attached instanceof Resource === false) {
         throw new RuntimeException('Response resource attach hook must return a Resource.');
      }

      /** @var T $Attached */
      return $Attached;
   }

   /**
    * Validate one built-in factory context.
    */
   private static function guard (object $Context): ServerResponse
   {
      if ($Context instanceof ServerResponse === false) {
         throw new RuntimeException('Built-in response resource factory expects a Response context.');
      }

      return $Context;
   }
}
