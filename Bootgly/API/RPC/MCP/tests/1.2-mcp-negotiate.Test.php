<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\API\RPC\MCP;


use function array_is_list;
use function array_unique;
use function assert;
use function count;
use function in_array;
use function rsort;
use ReflectionMethod;
use stdClass;
use Stringable;

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\API\RPC\MCP;


/**
 * `MCP::negotiate()` maps a client's requested revision to the one to speak:
 * the same when it is one of `MCP::VERSIONS` (strictly — a string equal to
 * it), the newest otherwise. `MCP::VERSIONS` lists the revisions newest
 * first, and `MCP::RESOURCE_NOT_FOUND` is MCP's code for an unknown URI.
 */
return new Test(
   description: 'RPC\MCP: negotiate() and the protocol constants',
   test: function () {
      // @ The constants
      yield assert(
         assertion: MCP::VERSIONS === ['2025-11-25', '2025-06-18', '2025-03-26', '2024-11-05'],
         description: 'MCP::VERSIONS are the four revisions this server speaks'
      );
      $sorted = MCP::VERSIONS;
      rsort($sorted);
      yield assert(
         assertion: array_is_list(MCP::VERSIONS)
            && $sorted === MCP::VERSIONS
            && count(array_unique(MCP::VERSIONS)) === count(MCP::VERSIONS),
         description: 'MCP::VERSIONS is a list without repetition, newest first'
      );
      yield assert(
         assertion: MCP::RESOURCE_NOT_FOUND === -32002,
         description: 'MCP::RESOURCE_NOT_FOUND is -32002'
      );

      // @ The method: public, static, (mixed): string
      $Method = new ReflectionMethod(MCP::class, 'negotiate');
      yield assert(
         assertion: $Method->isPublic() && $Method->isStatic()
            && (string) $Method->getReturnType() === 'string'
            && $Method->getNumberOfParameters() === 1
            && (string) $Method->getParameters()[0]->getType() === 'mixed',
         description: '`negotiate (mixed $requested): string` is public and static'
      );

      // @@ Every revision spoken is kept
      foreach (MCP::VERSIONS as $revision) {
         yield assert(
            assertion: MCP::negotiate($revision) === $revision,
            description: "negotiate('{$revision}') keeps {$revision}"
         );
      }

      // @@ Any other string gets the newest
      foreach (['2099-12-31', '2024-11-04', '2025-11-26', '', ' 2025-06-18', '2025-06-18 ', '2025-6-18', 'latest', '2025-11-25T00:00:00Z'] as $revision) {
         yield assert(
            assertion: MCP::negotiate($revision) === '2025-11-25',
            description: "negotiate('{$revision}') gets the newest, 2025-11-25"
         );
      }

      // @@ A non-string gets the newest — strictly, no coercion
      $Version = new class implements Stringable {
         public function __toString (): string
         {
            return '2025-06-18';
         }
      };
      $values = [
         'null' => null,
         'true' => true,
         'false' => false,
         'int' => 20250618,
         'int 2025' => 2025,
         'float' => 2025.06,
         'zero' => 0,
         'list' => ['2025-06-18'],
         'map' => ['protocolVersion' => '2025-06-18'],
         'object' => new stdClass,
         'Stringable' => $Version
      ];
      foreach ($values as $type => $value) {
         yield assert(
            assertion: MCP::negotiate($value) === MCP::VERSIONS[0],
            description: "a {$type} requested revision gets the newest"
         );
      }

      // @@ The answer is always one of MCP::VERSIONS
      $answers = [];
      foreach ([...MCP::VERSIONS, 'x', null, 1] as $requested) {
         $answers[] = MCP::negotiate($requested);
      }
      $spoken = true;
      foreach ($answers as $answer) {
         $spoken = $spoken && in_array($answer, MCP::VERSIONS, true);
      }
      yield assert(
         assertion: $spoken,
         description: 'negotiate() never answers a revision outside MCP::VERSIONS'
      );
   }
);
