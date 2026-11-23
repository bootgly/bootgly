<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */


use const Bootgly\WPI;
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Separator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Idle\Client;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test;


/**
 * An entry a static-route handler writes into `$this->Params` — by
 * assignment, by appending through the by-reference read, through a
 * reference — is not read by the next static request of another client on
 * the worker. One persistent Router, as the server keeps it for the worker's
 * life; every request on a connection of its own.
 */
$PersistentRouter = new Router;
$Probe = new class {
   /** @var array<string,array<string,mixed>> */
   public array $writers = [];
   /** @var array<string,array<string,mixed>> */
   public array $profiles = [];
   public string $error = '';
};

return new Test(
   Separator: new Separator(line: ''),

   request: static function (string $hostPort, int $testIndex) use ($Probe): string {
      try {
         foreach (['/r19/login', '/r19/append', '/r19/reference'] as $writer) {
            $Writer = Client::open($hostPort);
            $Probe->writers[$writer] = Client::send($Writer, Client::request($writer, $testIndex, ['X-User' => 'alice']), 4.0);
            Client::close($Writer);

            $Reader = Client::open($hostPort);
            $Probe->profiles[$writer] = Client::send($Reader, Client::request('/r19/profile', $testIndex), 4.0);
            Client::close($Reader);
         }
      }
      catch (Throwable $Throwable) {
         $Probe->error = $Throwable::class . ': ' . $Throwable->getMessage();
      }

      return "GET /r19/ping HTTP/1.1\r\nHost: localhost\r\n\r\n";
   },
   response: static function (Request $Request, Response $Response, Router $Router) use ($PersistentRouter): Response {
      // ! The production encoder routes every request on one Router
      $WPI = WPI;
      $WPI->Router = $PersistentRouter;

      $PersistentRouter->route('/r19/login', function (Request $Request, Response $Response): Response {
         $this->Params->user = $Request->Header->get('X-User') ?? 'none'; // @phpstan-ignore variable.undefined
         return $Response(body: (string) json_encode(iterator_to_array($this->Params))); // @phpstan-ignore variable.undefined
      }, GET);
      $PersistentRouter->route('/r19/append', function (Request $Request, Response $Response): Response {
         $this->Params->roles[] = 'admin'; // @phpstan-ignore variable.undefined
         return $Response(body: (string) json_encode(iterator_to_array($this->Params))); // @phpstan-ignore variable.undefined
      }, GET);
      $PersistentRouter->route('/r19/reference', function (Request $Request, Response $Response): Response {
         $token = &$this->Params->token; // @phpstan-ignore variable.undefined
         $token = 'secret';
         return $Response(body: (string) json_encode(iterator_to_array($this->Params))); // @phpstan-ignore variable.undefined
      }, GET);
      $PersistentRouter->route('/r19/profile', function (Request $Request, Response $Response): Response {
         return $Response(body: (string) json_encode(iterator_to_array($this->Params))); // @phpstan-ignore variable.undefined
      }, GET);
      $PersistentRouter->route('/r19/ping', static function (Request $Request, Response $Response): Response {
         return $Response(body: 'pong');
      }, GET);

      foreach ($PersistentRouter->routing() as $Routed) {
         if ($Routed instanceof Response) {
            return $Routed;
         }
      }

      return $Response(code: 404, body: 'not-routed');
   },

   test: new Assertions(Case: function (string $response) use ($Probe): Generator {
      $evidence = json_encode(['error' => $Probe->error, 'writers' => $Probe->writers, 'profiles' => $Probe->profiles]);

      yield new Assertion(
         description: 'Each writer stores its entry for its own request',
         fallback: "A writer did not store its entry: {$evidence}"
      )
         ->expect([
            $Probe->writers['/r19/login']['body'] ?? null,
            $Probe->writers['/r19/append']['body'] ?? null,
            $Probe->writers['/r19/reference']['body'] ?? null
         ])
         ->to->be(['{"user":"alice"}', '{"roles":["admin"]}', '{"token":"secret"}'])
         ->assert();

      foreach (['/r19/login' => 'an assignment', '/r19/append' => 'an append', '/r19/reference' => 'a reference'] as $writer => $write) {
         yield new Assertion(
            description: "Another client's static request reads no entry {$write} left",
            fallback: "The profile read a previous request's entry: {$evidence}"
         )
            ->expect($Probe->profiles[$writer]['body'] ?? null)
            ->to->be('[]')
            ->assert();
      }
   })
);
