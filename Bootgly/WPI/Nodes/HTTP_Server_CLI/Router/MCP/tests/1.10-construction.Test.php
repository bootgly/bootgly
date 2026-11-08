<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */


use Bootgly\ABI\Resources\Cache;
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Separator;
use Bootgly\API\RPC;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\MCP;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\MCP\Client;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test;


/**
 * Construction: origins must be serialized origins (`scheme://host[:port]`,
 * no path, no wildcard) and are kept normalized; TTL and body must be
 * positive and streams not negative — each refusal is an
 * `InvalidArgumentException` at boot, never a request-time failure.
 */
$Server = new RPC\MCP(
   name: 'Spec',
   version: '1.0.0',
   Lister: static fn (mixed $context): array => [['name' => 'echo', 'inputSchema' => ['type' => 'object']]],
   Caller: static fn (string $name, array $arguments, mixed $context): array => [
      'content' => [['type' => 'text', 'text' => (string) json_encode($arguments)]],
      'isError' => false
   ]
);
$initialize = [
   'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize',
   'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => new stdClass, 'clientInfo' => ['name' => 'spec', 'version' => '1']]
];
$list = ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'];
$Probe = new class {
   /** @var array<string,mixed> */
   public array $answers = [];
};
$Refuse = static function (Closure $Build): string {
   try {
      $Build();
   }
   catch (InvalidArgumentException) {
      return 'refused';
   }

   return 'built';
};
$Cache = new Cache(['driver' => 'memory']);
$Probe->answers['path'] = $Refuse(static fn () => new MCP($Server, origins: ['https://app.example.com/path'], Cache: $Cache));
$Probe->answers['wildcard'] = $Refuse(static fn () => new MCP($Server, origins: ['*'], Cache: $Cache));
$Probe->answers['bare'] = $Refuse(static fn () => new MCP($Server, origins: ['app.example.com'], Cache: $Cache));
$Probe->answers['null'] = $Refuse(static fn () => new MCP($Server, origins: ['null'], Cache: $Cache));
$Probe->answers['TTL'] = $Refuse(static fn () => new MCP($Server, Cache: $Cache, TTL: 0));
$Probe->answers['body'] = $Refuse(static fn () => new MCP($Server, Cache: $Cache, body: 0));
$Probe->answers['streams'] = $Refuse(static fn () => new MCP($Server, Cache: $Cache, streams: -1));
$Transport = new MCP($Server, origins: ['HTTPS://App.Example.com/', 'http://localhost:6274'], Cache: $Cache);
$Probe->answers['origins'] = $Transport->origins;
$Probe->answers['defaults'] = [$Transport->TTL, $Transport->body, $Transport->streams, $Transport->Server === $Server];
// ! The default store (built lazily: nothing attaches here) is a segment of its own
$Default = (new ReflectionProperty(MCP::class, 'Cache'))->getValue(new MCP($Server));
$scope = defined('BOOTGLY_WORKING_DIR') ? (string) constant('BOOTGLY_WORKING_DIR') : '';
$Probe->answers['store'] = $Default instanceof Cache
   ? [
      $Default->Driver->Config->driver,
      $Default->Driver->Config->segment === (crc32("bootgly.mcp.sessions\0{$scope}") & 0x7fffffff),
      $Default->Driver->Config->size,
      $Default->Driver->Config->prefix
   ]
   : null;

return new Test(
   Separator: new Separator(line: ''),

   request: static function (string $hostPort, int $testIndex): string {
      return "GET /health HTTP/1.1\r\nHost: localhost\r\n\r\n";
   },
   response: static function (Request $Request, Response $Response, Router $Router) use ($Transport): Generator {
      yield $Router->route('/mcp', static function (Request $Request, Response $Response) use ($Transport): Response {
         $Request->identity = $Request->Header->get('X-User');

         return $Transport($Request, $Response);
      }, [GET, POST, DELETE, PUT]);
   },

   test: new Assertions(Case: function (string $response) use ($Probe): Generator {
      $A = $Probe->answers;
      $evidence = json_encode($A);

      yield new Assertion(
         description: 'an origin with a path, a wildcard, a bare host or null is refused',
         fallback: "Failed — {$evidence}"
      )
         ->expect([$A['path'], $A['wildcard'], $A['bare'], $A['null']])
         ->to->be(['refused', 'refused', 'refused', 'refused'])
         ->assert();

      yield new Assertion(
         description: 'a non-positive TTL or body, or negative streams, is refused',
         fallback: "Failed — {$evidence}"
      )
         ->expect([$A['TTL'], $A['body'], $A['streams']])
         ->to->be(['refused', 'refused', 'refused'])
         ->assert();

      yield new Assertion(
         description: 'origins are kept normalized; the defaults are 3600 s, 1 MiB, one stream',
         fallback: "Failed — {$evidence}"
      )
         ->expect([$A['origins'], $A['defaults']])
         ->to->be([['https://app.example.com', 'http://localhost:6274'], [3600, 1_048_576, 1, true]])
         ->assert();

      yield new Assertion(
         description: 'the default session store is a 1 MiB shared-memory segment of its own, derived from the project directory',
         fallback: "Failed — {$evidence}"
      )
         ->expect($A['store'])
         ->to->be(['shared', true, 1_048_576, 'mcp:'])
         ->assert();
   })
);
