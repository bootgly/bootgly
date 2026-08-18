<?php

use Bootgly\ACI\Tests\Suite\Test\Separator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test;


/**
 * Security PoC M2 (2026-07-27) — `View::export()` data is request-scoped.
 *
 * `View` is a PERSISTENT response resource, so `Response::reset()` keeps the
 * same instance between requests — and with it the `$uses` map that
 * `export()` filled. Request A exporting a user, tenant or CSRF value left it
 * there for request B on the same worker, where `render()` merges
 * `$data + $this->uses` and hands A's value to B's template for any key B did
 * not override.
 *
 * Leg 1 exports a secret. Leg 2 is a DIFFERENT route that exports nothing and
 * reports what the persistent View still carries — read from the real resource
 * by reflection, since `$uses` is protected and rendering a template would only
 * add fixture surface without changing what is being proved.
 *
 * Control: within ONE request, export-then-read must still work, so a fix that
 * simply drops exports at the wrong moment cannot pass.
 */
return new Test(
   description: 'View::export() data must not survive into the next request',
   Separator: new Separator(line: true),

   requests: [
      static fn (): string => "GET /m2-export HTTP/1.1\r\nHost: localhost\r\n\r\n",
      static fn (): string => "GET /m2-read HTTP/1.1\r\nHost: localhost\r\n\r\n",
      static fn (): string => "GET /m2-same HTTP/1.1\r\nHost: localhost\r\n\r\n",
   ],

   response: static function (Request $Request, Response $Response, Router $Router) {
      // @ Read the live `$uses` map out of the real persistent resource.
      $Uses = static function (Response $Response): array {
         $View = $Response->View;
         $Property = new ReflectionProperty($View, 'uses');

         /** @var array<string,mixed> $uses */
         $uses = $Property->getValue($View);

         return $uses;
      };

      yield $Router->route('/m2-export', static function (
         Request $Request,
         Response $Response
      ): Response {
         $Response->View->export(['m2secret' => 'M2-SECRET-A']);

         return $Response(body: 'M2-EXPORTED');
      }, GET);

      yield $Router->route('/m2-read', static function (
         Request $Request,
         Response $Response
      ) use ($Uses): Response {
         // ! Exports nothing of its own: whatever is here came from another request.
         return $Response(body: 'M2-CARRIED:' . json_encode($Uses($Response)));
      }, GET);

      yield $Router->route('/m2-same', static function (
         Request $Request,
         Response $Response
      ) use ($Uses): Response {
         $Response->View->export(['m2own' => 'M2-OWN-VALUE']);

         return $Response(body: 'M2-SAME:' . json_encode($Uses($Response)));
      }, GET);

      yield $Router->route('/*', static function (Request $Request, Response $Response) {
         return $Response(code: 404, body: 'Not Found');
      });
   },

   test: static function (array $responses): bool|string {
      if (count($responses) !== 3) {
         return 'M2 fixture failed: expected three live responses, got ' . count($responses) . '.';
      }

      [$exported, $read, $same] = $responses;

      if (! str_contains($exported, 'M2-EXPORTED')) {
         return 'M2 harness control failed: the exporting route did not run: '
            . json_encode(substr($exported, -80));
      }

      // ? Control — a request must still see its OWN export, so a fix that
      //   clears at the wrong point cannot pass.
      if (! str_contains($same, 'M2-OWN-VALUE')) {
         return 'M2 control failed: a request could not read back its own export, so export() '
            . 'is broken rather than scoped: ' . json_encode(substr($same, -120));
      }

      if (str_contains($read, 'M2-SECRET-A')) {
         return 'CONFIRMED M2: a value exported by one request survived into the NEXT request '
            . 'on the same worker — the persistent View resource still carried '
            . json_encode(substr($read, strpos($read, 'M2-CARRIED:') ?: 0, 120))
            . '. Any template key that request did not override would render the earlier '
            . 'request\'s value.';
      }

      return true;
   },
);
