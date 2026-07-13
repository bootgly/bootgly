<?php

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\ADI\Databases\SQL;
use Bootgly\API\Security\Tokens\Trust;
use Bootgly\WPI\Nodes\HTTP_Server_CLI;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authenticating;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authentication;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authentication\Remember;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authentication\Session as SessionGuard;


return new Specification(
   description: 'It should revive sessions from the remember cookie with rotation and theft defense',
   skip: extension_loaded('sqlite3') === false,
   test: new Assertions(Case: function (): Generator {
      $createMocks = require __DIR__ . '/0.mock.php';
      $passthrough = function (object $Request, object $Response): object {
         $Response->Body->raw = 'passed';
         return $Response;
      };

      // ! Trusted-device store on a real in-memory SQLite.
      $Database = new SQL(['driver' => 'sqlite', 'database' => ':memory:']);
      $Database->query(<<<SQL
      CREATE TABLE trusts (
         id INTEGER PRIMARY KEY AUTOINCREMENT,
         selector TEXT NOT NULL UNIQUE,
         verifier TEXT NOT NULL,
         user_id INTEGER NOT NULL,
         expires INTEGER NOT NULL,
         created_at TEXT DEFAULT CURRENT_TIMESTAMP
      )
      SQL);
      $Trust = new Trust($Database);

      // ! Doubles.
      $createSession = function (array $data = []): object {
         return new class ($data) {
            /** @var array<string,mixed> */
            public array $data;
            public int $regenerated = 0;
            /** @param array<string,mixed> $data */
            public function __construct (array $data)
            {
               $this->data = $data;
            }
            public function check (string $name): bool
            {
               return array_key_exists($name, $this->data);
            }
            public function get (string $name, mixed $default = null): mixed
            {
               return $this->data[$name] ?? $default;
            }
            public function set (string $name, mixed $value): void
            {
               $this->data[$name] = $value;
            }
            public function regenerate (): void
            {
               $this->regenerated++;
            }
         };
      };
      $createCookies = function (array $cookies = []): object {
         return new class ($cookies) {
            /** @var array<string,string> */
            public array $cookies;
            /** @param array<string,string> $cookies */
            public function __construct (array $cookies)
            {
               $this->cookies = $cookies;
            }
            public function get (string $name): string
            {
               return $this->cookies[$name] ?? '';
            }
         };
      };

      // @ No remember cookie → guard declines.
      HTTP_Server_CLI::$Response = new Response;
      [$Request, $Response] = $createMocks();
      $Request->Session = $createSession();
      $Request->Cookies = $createCookies();
      $Guard = new Remember($Trust);

      yield new Assertion(description: 'Missing remember cookie should decline')
         ->expect($Guard->authenticate($Request))
         ->to->be(false)
         ->assert();

      // @ Valid cookie → revive: regenerate + identity + rotated Set-Cookie.
      $Issued = $Trust->issue('7');
      HTTP_Server_CLI::$Response = new Response;
      [$Request, $Response] = $createMocks();
      $Session = $createSession();
      $Request->Session = $Session;
      $Request->Cookies = $createCookies(['remember' => $Issued->value]);

      $authenticated = $Guard->authenticate($Request);
      $emitted = HTTP_Server_CLI::$Response->Header->Cookies->cookies;

      yield new Assertion(description: 'Valid remember cookie should authenticate')
         ->expect($authenticated)
         ->to->be(true)
         ->assert();

      yield new Assertion(description: 'Revival should regenerate the session id (fixation defense)')
         ->expect($Session->regenerated)
         ->to->be(1)
         ->assert();

      yield new Assertion(description: 'Revival should install the session identity and expose it')
         ->expect($Session->data['identity'] === '7' && $Request->identity === '7')
         ->to->be(true)
         ->assert();

      $rotated = count($emitted) === 1
         && str_starts_with($emitted[0], 'remember=')
         && str_contains($emitted[0], $Issued->selector)
         && str_contains($emitted[0], $Issued->value) === false
         && str_contains($emitted[0], 'Max-Age=2592000')
         && str_contains($emitted[0], 'Secure')
         && str_contains($emitted[0], 'HttpOnly')
         && str_contains($emitted[0], 'SameSite=Lax');

      yield new Assertion(description: 'Revival should re-emit a rotated cookie with hardened flags')
         ->expect($rotated)
         ->to->be(true)
         ->assert();

      // @ Replay of the pre-rotation cookie → theft: revoke all + clearing cookie.
      HTTP_Server_CLI::$Response = new Response;
      [$Request, $Response] = $createMocks();
      $Request->Session = $createSession();
      $Request->Cookies = $createCookies(['remember' => $Issued->value]);

      $replayed = $Guard->authenticate($Request);
      $cleared = HTTP_Server_CLI::$Response->Header->Cookies->cookies;
      $remaining = $Database->query('SELECT count(*) AS total FROM trusts')->Result?->cell;

      $theft = $replayed === false
         && count($cleared) === 1
         && str_starts_with($cleared[0], 'remember=;')
         && str_contains($cleared[0], 'Max-Age=0')
         && $remaining === 0;

      yield new Assertion(description: 'Replaying a rotated cookie should revoke every device and clear the cookie')
         ->expect($theft)
         ->to->be(true)
         ->assert();

      // @ Canonical composition: Session guard first, Remember on session miss.
      $Fresh = $Trust->issue('7');
      HTTP_Server_CLI::$Response = new Response;
      [$Request, $Response] = $createMocks();
      $Request->Session = $createSession();
      $Request->Cookies = $createCookies(['remember' => $Fresh->value]);
      $Middleware = new Authentication(new Authenticating(
         new SessionGuard,
         new Remember($Trust)
      ));
      $Result = $Middleware->process($Request, $Response, $passthrough);

      yield new Assertion(description: 'Authenticating(Session, Remember) should pass through on cookie revival')
         ->expect($Result->Body->raw)
         ->to->be('passed')
         ->assert();
   })
);
