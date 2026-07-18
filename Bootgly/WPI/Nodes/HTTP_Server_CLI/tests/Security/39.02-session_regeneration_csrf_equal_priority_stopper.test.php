<?php

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\ABI\Events\Emission;
use Bootgly\ABI\Events\Emitter;
use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Session;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Session\Events as SessionEvents;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middleware;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\CSRF;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


/**
 * Security PoC M6 residual A — an earlier equal-priority listener can stop
 * the ordinary Regenerate event before the CSRF rotation listener executes.
 *
 * Every request receives an isolated Emitter. The stopping listener is
 * registered first, then the real CSRF middleware is constructed on that
 * same bus. The outer middleware restores the suite bus in finally, so this
 * retained PoC cannot affect another Security case.
 */
$knownToken = str_repeat('a1', 32);
$maskedToken = CSRF::mask($knownToken);
$invalidToken = str_repeat('ff', 32);
$sessionKey = '_m6_equal_priority_token';
$headerName = 'X-M6-Equal-Token';
$guardKey = '_m6_equal_priority_guard';
$stopperKey = '_m6_equal_priority_stopper';
$sameBusKey = '_m6_equal_priority_same_bus';
$stoppedKey = '_m6_equal_priority_stopped';
$tokenBytes = 17;

$Request = static function (
   string $mode,
   string|null $token = null
) use ($headerName): string {
   $header = $token === null ? '' : "{$headerName}: {$token}\r\n";

   return "POST /m6/residual/equal-priority HTTP/1.1\r\n"
      . "Host: localhost\r\n"
      . "X-M6-Mode: {$mode}\r\n"
      . $header
      . "Content-Length: 0\r\n\r\n";
};

return new Specification(
   description: 'CSRF rotation must survive an earlier equal-priority stopping listener',
   Separator: new Separator(line: true),

   requests: [
      static fn (): string => $Request('control-raw', $knownToken),
      static fn (): string => $Request('control-masked', $maskedToken),
      static fn (): string => $Request('control-invalid', $invalidToken),
      static fn (): string => $Request('attack-raw', $knownToken),
      static fn (): string => $Request('attack-masked', $maskedToken),
      static fn (): string => $Request('current-raw'),
      static fn (): string => $Request('current-masked'),
   ],

   middlewares: [
      new class(
         $sessionKey,
         $headerName,
         $guardKey,
         $stopperKey,
         $sameBusKey,
         $stoppedKey,
         $tokenBytes
      ) implements Middleware {
         public function __construct (
            private string $sessionKey,
            private string $headerName,
            private string $guardKey,
            private string $stopperKey,
            private string $sameBusKey,
            private string $stoppedKey,
            private int $tokenBytes
         )
         {
         }

         public function process (object $Request, object $Response, Closure $next): object
         {
            $PreviousEmitter = Emitter::$Instance;
            $IsolatedEmitter = new Emitter;
            Emitter::$Instance = $IsolatedEmitter;

            $guardKey = $this->guardKey;
            $stopperKey = $this->stopperKey;
            $sameBusKey = $this->sameBusKey;
            $stoppedKey = $this->stoppedKey;

            $IsolatedEmitter->listen(
               SessionEvents::Regenerate,
               static function (Emission $Emission) use (
                  $IsolatedEmitter,
                  $guardKey,
                  $stopperKey,
                  $sameBusKey,
                  $stoppedKey
               ): void {
                  $Session = $Emission->payload[2] ?? null;
                  if (
                     ($Session instanceof Session) === false
                     || $Session->get($guardKey) !== true
                  ) {
                     return;
                  }

                  $Session->set($stopperKey, true);
                  $Session->set($sameBusKey, Emitter::$Instance === $IsolatedEmitter);
                  $Emission->stop();
                  $Session->set($stoppedKey, $Emission->stopped);
               },
               PHP_INT_MAX
            );

            $Request->attributes['m6EqualCSRF'] = new CSRF(
               sessionKey: $this->sessionKey,
               headerName: $this->headerName,
               tokenBytes: $this->tokenBytes
            );

            try {
               return $next($Request, $Response);
            }
            finally {
               Emitter::$Instance = $PreviousEmitter;
            }
         }
      },
      new class(
         $knownToken,
         $sessionKey,
         $headerName,
         $guardKey,
         $stopperKey,
         $sameBusKey,
         $stoppedKey,
         $tokenBytes
      ) implements Middleware {
         public function __construct (
            private string $knownToken,
            private string $sessionKey,
            private string $headerName,
            private string $guardKey,
            private string $stopperKey,
            private string $sameBusKey,
            private string $stoppedKey,
            private int $tokenBytes
         )
         {
         }

         public function process (object $Request, object $Response, Closure $next): object
         {
            $mode = $Request->Header->get('X-M6-Mode') ?? 'unknown';
            $Session = $Request->Session;
            $oldId = $Session->id;

            $Session->set($this->guardKey, true);
            $Session->set($this->sessionKey, $this->knownToken);

            $regenerated = $mode === 'attack-raw'
               || $mode === 'attack-masked'
               || $mode === 'current-raw'
               || $mode === 'current-masked';

            if ($regenerated) {
               $Session->regenerate();
            }

            $currentToken = $Session->get($this->sessionKey, '');
            if (
               is_string($currentToken)
               && ($mode === 'current-raw' || $mode === 'current-masked')
            ) {
               $submitted = $mode === 'current-masked'
                  ? CSRF::mask($currentToken)
                  : $currentToken;
               $Request->Header->append($this->headerName, $submitted);
            }

            $Response->Header->append(
               'X-M6-ID-Rotated',
               $Session->id !== $oldId ? 'yes' : 'no'
            );
            $Response->Header->append(
               'X-M6-Token-Preserved',
               $currentToken === $this->knownToken ? 'yes' : 'no'
            );
            $Response->Header->append(
               'X-M6-Token-Length',
               is_string($currentToken) ? (string) strlen($currentToken) : 'invalid'
            );
            $Response->Header->append(
               'X-M6-Stopper-Ran',
               $Session->get($this->stopperKey) === true ? 'yes' : 'no'
            );
            $Response->Header->append(
               'X-M6-Same-Bus',
               $Session->get($this->sameBusKey) === true ? 'yes' : 'no'
            );
            $Response->Header->append(
               'X-M6-Emission-Stopped',
               $Session->get($this->stoppedKey) === true ? 'yes' : 'no'
            );
            $Response->Header->append(
               'X-M6-Expected-Token-Length',
               (string) (2 * $this->tokenBytes)
            );

            return $next($Request, $Response);
         }
      },
      new class implements Middleware {
         public function process (object $Request, object $Response, Closure $next): object
         {
            $CSRF = $Request->attributes['m6EqualCSRF'] ?? null;
            if (($CSRF instanceof CSRF) === false) {
               return $Response(code: 500, body: 'M6 equal-priority CSRF fixture missing');
            }

            return $CSRF->process($Request, $Response, $next);
         }
      },
   ],

   response: static function (Request $Request, Response $Response): Response {
      $mode = $Request->Header->get('X-M6-Mode') ?? 'unknown';

      return $Response(body: "M6-EQUAL-PROTECTED-HANDLER:{$mode}");
   },

   test: static function (array $responses): bool|string {
      if (count($responses) !== 7) {
         return 'M6 equal-priority probe did not receive all seven responses.';
      }

      [
         $rawControl,
         $maskedControl,
         $invalidControl,
         $rawAttack,
         $maskedAttack,
         $currentRaw,
         $currentMasked,
      ] = $responses;

      foreach (
         ['control-raw' => $rawControl, 'control-masked' => $maskedControl]
         as $mode => $response
      ) {
         if (
            ! str_contains($response, 'HTTP/1.1 200 OK')
            || ! str_contains($response, "M6-EQUAL-PROTECTED-HANDLER:{$mode}")
            || ! str_contains($response, 'X-M6-ID-Rotated: no')
         ) {
            Vars::$labels = ["M6 equal-priority {$mode} response"];
            dump(json_encode($response));

            return "M6 equal-priority {$mode} acceptance control failed.";
         }
      }

      if (
         ! str_contains($invalidControl, 'HTTP/1.1 403 Forbidden')
         || ! str_contains($invalidControl, 'Invalid CSRF token')
         || str_contains($invalidControl, 'M6-EQUAL-PROTECTED-HANDLER:')
      ) {
         Vars::$labels = ['M6 equal-priority invalid-token response'];
         dump(json_encode($invalidControl));

         return 'M6 equal-priority invalid-token control did not prove CSRF enforcement.';
      }

      foreach (
         ['current-raw' => $currentRaw, 'current-masked' => $currentMasked]
         as $mode => $response
      ) {
         if (
            ! str_contains($response, 'HTTP/1.1 200 OK')
            || ! str_contains($response, "M6-EQUAL-PROTECTED-HANDLER:{$mode}")
            || ! str_contains($response, 'X-M6-ID-Rotated: yes')
         ) {
            Vars::$labels = ["M6 equal-priority {$mode} response"];
            dump(json_encode($response));

            return "M6 equal-priority {$mode} post-regeneration control failed.";
         }
      }

      $bypasses = [];
      foreach (
         ['attack-raw' => $rawAttack, 'attack-masked' => $maskedAttack]
         as $mode => $response
      ) {
         if (
            str_contains($response, 'HTTP/1.1 200 OK')
            && str_contains($response, "M6-EQUAL-PROTECTED-HANDLER:{$mode}")
         ) {
            if (
               ! str_contains($response, 'X-M6-ID-Rotated: yes')
               || ! str_contains($response, 'X-M6-Token-Preserved: yes')
               || ! str_contains($response, 'X-M6-Stopper-Ran: yes')
               || ! str_contains($response, 'X-M6-Same-Bus: yes')
               || ! str_contains($response, 'X-M6-Emission-Stopped: yes')
            ) {
               Vars::$labels = ["M6 equal-priority {$mode} causal response"];
               dump(json_encode($response));

               return "M6 equal-priority {$mode} reached the handler without proving the stopper path.";
            }

            $bypasses[] = $mode;
            continue;
         }

         if (
            ! str_contains($response, 'HTTP/1.1 403 Forbidden')
            || ! str_contains($response, 'Invalid CSRF token')
            || str_contains($response, 'M6-EQUAL-PROTECTED-HANDLER:')
            || ! str_contains($response, 'X-M6-ID-Rotated: yes')
            || ! str_contains($response, 'X-M6-Token-Preserved: no')
         ) {
            Vars::$labels = ["M6 equal-priority unexpected {$mode} response"];
            dump(json_encode($response));

            return "M6 equal-priority {$mode} neither proved the bypass nor the secure rejection.";
         }
      }

      if ($bypasses !== []) {
         Vars::$labels = ['M6 equal-priority raw bypass', 'M6 equal-priority masked bypass'];
         dump(json_encode($rawAttack), json_encode($maskedAttack));

         return 'CONFIRMED M6: an earlier equal-priority stopping listener preserved and accepted '
            . 'the old CSRF token: ' . implode(', ', $bypasses) . '.';
      }

      foreach ([$currentRaw, $currentMasked] as $response) {
         if (
            ! str_contains($response, 'X-M6-Token-Preserved: no')
            || ! str_contains($response, 'X-M6-Token-Length: 34')
            || ! str_contains($response, 'X-M6-Expected-Token-Length: 34')
         ) {
            Vars::$labels = ['M6 equal-priority custom token rotation response'];
            dump(json_encode($response));

            return 'M6 equal-priority secure path did not rotate the custom token to its configured length.';
         }
      }

      return true;
   },
);
