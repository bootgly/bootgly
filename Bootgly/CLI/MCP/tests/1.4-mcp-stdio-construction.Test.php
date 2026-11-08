<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */


namespace Bootgly\CLI\MCP;


use function assert;
use InvalidArgumentException;

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\API\RPC;
use Bootgly\CLI\MCP;


/**
 * Construction: the line bound must be positive and the three streams must
 * be resources; the Config is readable and defaults to the process's own
 * stdin/stdout/stderr with a 4 MiB line.
 */
return new Test(
   description: 'CLI\MCP: construction refuses a bad bound or stream and keeps its Config',
   test: function () {
      // !
      $Server = new RPC\MCP(
         name: 'Stdio',
         version: '1',
         Lister: static fn (mixed $context): array => [],
         Caller: static fn (string $name, array $arguments, mixed $context): array => []
      );
      $Refuse = static function (callable $Build): bool {
         try {
            $Build();
         }
         catch (InvalidArgumentException) {
            return true;
         }

         return false;
      };

      // @
      yield assert(
         assertion: $Refuse(static fn () => new MCP($Server, line: 0)),
         description: 'a zero line bound is refused'
      );
      yield assert(
         assertion: $Refuse(static fn () => new MCP($Server, input: 'php://stdin')),
         description: 'a stream that is not a resource is refused'
      );
      $MCP = new MCP($Server, context: ['user' => 'alice']);
      yield assert(
         assertion: $MCP->Server === $Server && $MCP->context === ['user' => 'alice'] && $MCP->line === 4_194_304,
         description: 'Server, context and the default line bound are readable'
      );
   }
);
