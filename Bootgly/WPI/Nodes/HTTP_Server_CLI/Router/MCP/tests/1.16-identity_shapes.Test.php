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
use Bootgly\API\Security\Identity;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\MCP;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\MCP\Client;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test;


/**
 * A session binds to an `Identity` with an id, a non-empty string, an int
 * or no identity (anonymous). Any other identity shape — an array, another
 * object, an empty string, an `Identity` with an empty id, a float, a bool —
 * is a wiring error: the request is answered 500 before a session opens.
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
$Transport = new MCP($Server, Cache: new Cache(['driver' => 'shared', 'segment' => 0x4d435053, 'prefix' => 'mcp-spec-1.16:']));
$shapes = [
   'identity' => static fn (): mixed => new Identity('alice'),
   'string' => static fn (): mixed => 'alice',
   'int' => static fn (): mixed => 7,
   'anonymous' => static fn (): mixed => null,
   'array' => static fn (): mixed => ['id' => 'alice'],
   'object' => static fn (): mixed => (object) ['id' => 'alice'],
   'empty' => static fn (): mixed => '',
   'unnamed' => static fn (): mixed => new Identity(''),
   'float' => static fn (): mixed => 1.5,
   'bool' => static fn (): mixed => true
];
$Probe = new class {
   /** @var array<string,array{0:int,1:bool}> */
   public array $answers = [];
   public string $error = '';
};

return new Test(
   Separator: new Separator(line: ''),

   request: static function (string $hostPort, int $testIndex) use ($Probe, $initialize, $shapes): string {
      try {
         foreach ($shapes as $shape => $value) {
            $Init = Client::call($hostPort, $testIndex, $initialize, ['X-Shape' => $shape]);
            $Probe->answers[$shape] = [$Init['code'], ($Init['headers']['mcp-session-id'] ?? '') !== ''];
         }
      }
      catch (Throwable $Throwable) {
         $Probe->error = "{$Throwable->getMessage()}";
      }

      return "GET /health HTTP/1.1\r\nHost: localhost\r\n\r\n";
   },
   response: static function (Request $Request, Response $Response, Router $Router) use ($Transport, $shapes): Generator {
      yield $Router->route('/mcp', static function (Request $Request, Response $Response) use ($Transport, $shapes): Response {
         $shape = (string) $Request->Header->get('X-Shape');
         $Request->identity = isset($shapes[$shape]) ? $shapes[$shape]() : null;

         return $Transport($Request, $Response);
      }, [GET, POST, DELETE]);
   },

   test: new Assertions(Case: function (string $response) use ($Probe): Generator {
      $evidence = json_encode(['error' => $Probe->error, 'answers' => $Probe->answers]);

      yield new Assertion(
         description: 'An Identity with an id, a non-empty string, an int and no identity open a session',
         fallback: "A supported identity shape was refused: {$evidence}"
      )
         ->expect(array_intersect_key($Probe->answers, array_flip(['identity', 'string', 'int', 'anonymous'])))
         ->to->be(['identity' => [200, true], 'string' => [200, true], 'int' => [200, true], 'anonymous' => [200, true]])
         ->assert();

      yield new Assertion(
         description: 'Any other identity shape is answered 500 before a session opens',
         fallback: "An unsupported identity shape opened a session: {$evidence}"
      )
         ->expect(array_intersect_key($Probe->answers, array_flip(['array', 'object', 'empty', 'unnamed', 'float', 'bool'])))
         ->to->be(['array' => [500, false], 'object' => [500, false], 'empty' => [500, false], 'unnamed' => [500, false], 'float' => [500, false], 'bool' => [500, false]])
         ->assert();
   })
);
