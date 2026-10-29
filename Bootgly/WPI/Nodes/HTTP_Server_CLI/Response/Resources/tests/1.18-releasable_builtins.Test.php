<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resources\Tests\ReleasableBuiltins;


use function array_keys;
use function assert;
use function class_uses;
use function json_encode;
use function ksort;
use ReflectionClassConstant;

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resource\Releasable;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resources;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resources\Database;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resources\SSE;


/**
 * `Resources::fetch()` cleans a built-in it builds on a registry whose
 * deferred job ended only when its exact class is in `Resources::RELEASABLE`
 * — a list kept beside the definitions the registry's constructor registers.
 * A built-in whose clean() lets its response go, missing from it, is handed
 * over holding that response strongly: a cycle only the collector breaks,
 * with every other spec green.
 *
 * Pinned: the list is exactly what the registry itself derives — every
 * built-in its constructor registers that uses `Resource\Releasable`, built
 * by that built-in's own factory — plus the two built-ins whose own clean()
 * lets their response go instead of the trait: SSE (registered by the
 * constructor too) and Database (configured by a project).
 */
return new Test(
   description: 'The built-ins fetch() cleans after release() should be exactly those whose clean() lets their response go: every Releasable built-in the registry constructor registers, read from the registry, plus SSE and Database',
   test: function () {
      // ! What fetch() cleans once its registry let its response go
      /** @var array<class-string,true> $releasable */
      $releasable = new ReflectionClassConstant(Resources::class, 'RELEASABLE')->getValue();

      // ! Derived from the registry: every built-in its constructor
      //   registers, built by its own factory, that uses the trait
      $Response = new Response;
      $derived = [];
      foreach ($Response->Resources->definitions as $Factory) {
         $Resource = $Factory($Response);

         if (isset(class_uses($Resource)[Releasable::class])) {
            $derived[$Resource::class] = true;
         }
      }
      // ! ... and the built-ins whose own clean() lets their response go
      //   instead of the trait
      $derived[SSE::class] = true;
      $derived[Database::class] = true;

      ksort($releasable);
      ksort($derived);

      yield assert(
         assertion: $releasable === $derived,
         description: 'RELEASABLE lists every built-in the registry constructor registers that uses Releasable, plus SSE and Database, and nothing else: '
            . json_encode(['RELEASABLE' => array_keys($releasable), 'derived' => array_keys($derived)])
      );
   }
);
