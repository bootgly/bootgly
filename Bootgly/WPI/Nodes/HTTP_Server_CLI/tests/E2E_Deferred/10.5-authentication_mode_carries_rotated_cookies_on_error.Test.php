<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */


use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Separator;
use Bootgly\ADI\Databases\SQL;
use Bootgly\API\Security\Tokens\Trust;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authenticating;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authentication;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authentication\Remember;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authentication\Session as SessionGuard;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Deferred\Deferrer;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Deferred\Routes;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Idle\Client;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test;


/**
 * The deferring mode keeps what its guard chain emitted on an error answer:
 * a Remember revival rotates the token and regenerates the Session, and a
 * handler that then throws — directly, in a nested deferral, or behind a
 * global middleware that already deferred — is answered 500 carrying the
 * rotated remember cookie and the new Session cookie. The rotated token
 * authenticates past the rotation grace; the replaced one reads as theft.
 */
$Store = new class {
   public null|Trust $Trust = null;
   public int $clock = 1_000_000;
};
// ! The trusted-device store lives in the worker (in-memory SQLite)
$open = static function () use ($Store): Trust {
   if ($Store->Trust === null) {
      $Database = new SQL(['driver' => 'sqlite', 'database' => ':memory:']);
      $Database->query(<<<SQL
      CREATE TABLE trusts (
         id INTEGER PRIMARY KEY AUTOINCREMENT,
         selector TEXT NOT NULL UNIQUE,
         verifier TEXT NOT NULL,
         previous TEXT DEFAULT NULL,
         rotated INTEGER DEFAULT NULL,
         user_id INTEGER NOT NULL,
         expires INTEGER NOT NULL,
         created_at TEXT DEFAULT CURRENT_TIMESTAMP
      )
      SQL);
      $Store->Trust = new Trust($Database);
      $Store->Trust->freeze($Store->clock);
   }

   return $Store->Trust;
};
$Probe = new class {
   /** @var array<string,array<string,mixed>> */
   public array $answers = [];
   /** @var array<string,string> */
   public array $tokens = [];
   public string $error = '';
};

return new Test(
   Separator: new Separator(line: ''),
   skip: extension_loaded('sqlite3') === false,
   middlewares: [new Deferrer('/am5/g/')],

   request: static function (string $hostPort, int $testIndex) use ($Probe): string {
      $get = static function (string $path, string $cookie = '') use ($hostPort, $testIndex): array {
         $headers = $cookie === '' ? [] : ['Cookie' => $cookie];
         $Socket = Client::open($hostPort);
         $answer = Client::send($Socket, Client::request($path, $testIndex, $headers), 4.0);
         Client::close($Socket);

         return $answer;
      };
      $remember = static function (array $answer): string {
         $matches = [];
         preg_match('/\r\nSet-Cookie: remember=([^;\r\n]+)/i', (string) ($answer['head'] ?? ''), $matches);

         return $matches[1] ?? '';
      };
      try {
         foreach (['route' => '/am5/throw', 'nested' => '/am5/nested', 'global' => '/am5/g/throw'] as $key => $path) {
            $issued = (string) ($get('/am5/issue')['body'] ?? '');
            $Probe->tokens["{$key}-issued"] = $issued;
            $Probe->answers[$key] = $get($path, "remember={$issued}");
            $Probe->tokens["{$key}-rotated"] = $remember($Probe->answers[$key]);
         }

         // @ Past the rotation grace: the rotated token authenticates, the
         //   replaced one reads as theft
         $Probe->answers['advance'] = $get('/am5/advance');
         $Probe->answers['rotated'] = $get('/am5/who', "remember={$Probe->tokens['route-rotated']}");
         $Probe->answers['replaced'] = $get('/am5/who', "remember={$Probe->tokens['nested-issued']}");
      }
      catch (Throwable $Throwable) {
         $Probe->error = $Throwable::class . ': ' . $Throwable->getMessage();
      }

      return "GET /deferred/ping HTTP/1.1\r\nHost: localhost\r\n\r\n";
   },
   response: static function (Request $Request, Response $Response, Router $Router) use ($open, $Store): Generator {
      $Trust = $open();
      $Mode = new Authentication(
         new Authenticating(new SessionGuard, new Remember($Trust)),
         deferred: true,
         timeout: 3
      );

      yield $Router->route('/am5/issue', static function (Request $Request, Response $Response) use ($Trust) {
         return $Response(body: $Trust->issue('7')->value);
      }, GET);
      yield $Router->route('/am5/advance', static function (Request $Request, Response $Response) use ($Trust, $Store) {
         $Store->clock += 10;
         $Trust->freeze($Store->clock);

         return $Response(body: 'advanced');
      }, GET);
      yield $Router->route('/am5/who', static function (Request $Request, Response $Response) {
         return $Response(body: (string) $Request->identity);
      }, GET, middlewares: [$Mode]);
      yield $Router->route('/am5/throw', static function (Request $Request, Response $Response) {
         throw new RuntimeException('handler failure');
      }, GET, middlewares: [$Mode]);
      yield $Router->route('/am5/nested', static function (Request $Request, Response $Response) {
         return $Response->defer(static function (Response $Response): void {
            Routes::park($Response, 0.05);

            throw new RuntimeException('nested failure');
         });
      }, GET, middlewares: [$Mode]);
      yield $Router->route('/am5/g/throw', static function (Request $Request, Response $Response) {
         throw new RuntimeException('handler failure');
      }, GET, middlewares: [$Mode]);
      yield from Routes::mount($Router);
   },

   test: new Assertions(Case: function (string $response) use ($Probe): Generator {
      $A = $Probe->answers;
      $evidence = json_encode(['error' => $Probe->error, 'tokens' => $Probe->tokens, 'answers' => $A]);

      foreach (['route' => 'a handler throw', 'nested' => 'a nested deferral that throws', 'global' => 'a throw behind a global deferral'] as $key => $case) {
         $head = (string) ($A[$key]['head'] ?? '');
         $issued = $Probe->tokens["{$key}-issued"] ?? '';
         $rotated = $Probe->tokens["{$key}-rotated"] ?? '';

         yield new Assertion(
            description: "The 500 of {$case} carries the rotated remember cookie and the new Session cookie",
            fallback: "The error answer dropped the cookies the guard chain emitted: {$evidence}"
         )
            ->expect([
               $A[$key]['code'] ?? 0,
               $issued !== '' && $rotated !== '' && $rotated !== $issued,
               preg_match('/\r\nSet-Cookie: PHPSID=[^;\r\n]+/i', $head) === 1,
               substr_count($head, 'Set-Cookie: remember=')
            ])
            ->to->be([500, true, true, 1])
            ->assert();
      }

      yield new Assertion(
         description: 'Past the rotation grace, the rotated token authenticates',
         fallback: "The rotated token the 500 carried did not authenticate: {$evidence}"
      )
         ->expect([$A['advance']['body'] ?? null, $A['rotated']['code'] ?? 0, $A['rotated']['body'] ?? null])
         ->to->be(['advanced', 200, '7'])
         ->assert();

      yield new Assertion(
         description: 'Past the rotation grace, the replaced token reads as theft',
         fallback: "The replaced token was accepted: {$evidence}"
      )
         ->expect($A['replaced']['code'] ?? 0)
         ->to->be(401)
         ->assert();
   })
);
