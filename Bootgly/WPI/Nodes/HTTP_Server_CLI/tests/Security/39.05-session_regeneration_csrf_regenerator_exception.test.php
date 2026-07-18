<?php

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Session;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Session\Regenerators;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middleware;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\CSRF;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


/**
 * Security PoC M6 residual D — one invariant callback throwing must not prevent
 * later security callbacks from rotating privilege-bound state.
 */
$knownToken = str_repeat('e5', 32);
$maskedToken = CSRF::mask($knownToken);
$invalidToken = str_repeat('aa', 32);
$headerName = 'X-M6-Regenerator-Exception-Token';
$tokenBytes = 19;

$Request = static function (
   string $mode,
   string|null $token = null
) use ($headerName): string {
   $header = $token === null ? '' : "{$headerName}: {$token}\r\n";

   return "POST /m6/residual/regenerator-exception HTTP/1.1\r\n"
      . "Host: localhost\r\n"
      . "X-M6-Mode: {$mode}\r\n"
      . $header
      . "Content-Length: 0\r\n\r\n";
};

return new Specification(
   description: 'CSRF rotation must survive an earlier throwing invariant callback',
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
      new class($knownToken, $headerName, $tokenBytes) implements Middleware {
         public function __construct (
            private string $knownToken,
            private string $headerName,
            private int $tokenBytes
         )
         {
         }

         public function process (object $Request, object $Response, Closure $next): object
         {
            $mode = $Request->Header->get('X-M6-Mode') ?? 'unknown';
            $TargetSession = $Request->Session;
            $suffix = str_replace('-', '_', $mode) . '_' . spl_object_id($TargetSession);
            $sessionKey = '_m6_regenerator_exception_' . $suffix;
            $guardKey = $sessionKey . '_guard';
            $throwerKey = $sessionKey . '_thrower';
            $sawOldKey = $sessionKey . '_saw_old';
            $tailKey = $sessionKey . '_tail';
            $knownToken = $this->knownToken;
            $Failure = new RuntimeException('M6 regenerator exception sentinel');
            $ThrowerOwner = new stdClass;

            Regenerators::register(
               $ThrowerOwner,
               static function (Session $Session) use (
                  $TargetSession,
                  $guardKey,
                  $throwerKey,
                  $sawOldKey,
                  $sessionKey,
                  $Failure,
                  $knownToken
               ): void {
                  if (
                     $Session !== $TargetSession
                     || $Session->get($guardKey) !== true
                  ) {
                     return;
                  }

                  $Session->set($throwerKey, true);
                  $Session->set($sawOldKey, $Session->get($sessionKey) === $knownToken);
                  throw $Failure;
               }
            );

            $CSRF = new CSRF(
               sessionKey: $sessionKey,
               headerName: $this->headerName,
               tokenBytes: $this->tokenBytes
            );

            $TailOwner = new stdClass;
            Regenerators::register(
               $TailOwner,
               static function (Session $Session) use (
                  $TargetSession,
                  $guardKey,
                  $tailKey
               ): void {
                  if (
                     $Session === $TargetSession
                     && $Session->get($guardKey) === true
                  ) {
                     $Session->set($tailKey, true);
                  }
               }
            );

            $Request->attributes['m6Exception'] = [
               'CSRF' => $CSRF,
               'Failure' => $Failure,
               'Owners' => [$ThrowerOwner, $TailOwner],
               'sessionKey' => $sessionKey,
               'guardKey' => $guardKey,
               'throwerKey' => $throwerKey,
               'sawOldKey' => $sawOldKey,
               'tailKey' => $tailKey,
               'mode' => $mode,
            ];

            return $next($Request, $Response);
         }
      },
      new class($knownToken, $headerName, $tokenBytes) implements Middleware {
         public function __construct (
            private string $knownToken,
            private string $headerName,
            private int $tokenBytes
         )
         {
         }

         public function process (object $Request, object $Response, Closure $next): object
         {
            $State = $Request->attributes['m6Exception'] ?? [];
            $mode = $State['mode'] ?? 'unknown';
            $sessionKey = $State['sessionKey'] ?? '';
            $guardKey = $State['guardKey'] ?? '';
            $throwerKey = $State['throwerKey'] ?? '';
            $sawOldKey = $State['sawOldKey'] ?? '';
            $tailKey = $State['tailKey'] ?? '';
            $Failure = $State['Failure'] ?? null;
            $Session = $Request->Session;
            $oldID = $Session->id;

            $Session->set($sessionKey, $this->knownToken);

            $attack = $mode === 'attack-raw' || $mode === 'attack-masked';
            $regenerated = $attack
               || $mode === 'current-raw'
               || $mode === 'current-masked';
            if ($attack) {
               $Session->set($guardKey, true);
            }

            $caught = false;
            $matched = false;
            if ($regenerated) {
               try {
                  $Session->regenerate();
               }
               catch (Throwable $Throwable) {
                  $caught = true;
                  $matched = $Throwable === $Failure;
               }
            }

            $currentToken = $Session->get($sessionKey, '');
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
               $Session->id !== $oldID ? 'yes' : 'no'
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
               'X-M6-Thrower-Ran',
               $Session->get($throwerKey) === true ? 'yes' : 'no'
            );
            $Response->Header->append(
               'X-M6-Thrower-Saw-Old',
               $Session->get($sawOldKey) === true ? 'yes' : 'no'
            );
            $Response->Header->append('X-M6-Exception-Caught', $caught ? 'yes' : 'no');
            $Response->Header->append('X-M6-Exception-Matched', $matched ? 'yes' : 'no');
            $Response->Header->append(
               'X-M6-Tail-Ran',
               $Session->get($tailKey) === true ? 'yes' : 'no'
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
            $State = $Request->attributes['m6Exception'] ?? [];
            $CSRF = $State['CSRF'] ?? null;
            if (($CSRF instanceof CSRF) === false) {
               return $Response(code: 500, body: 'M6 regenerator-exception CSRF fixture missing');
            }

            return $CSRF->process($Request, $Response, $next);
         }
      },
   ],

   response: static function (Request $Request, Response $Response): Response {
      $State = $Request->attributes['m6Exception'] ?? [];
      $mode = $State['mode'] ?? 'unknown';

      return $Response(body: "M6-REGENERATOR-EXCEPTION-PROTECTED-HANDLER:{$mode}");
   },

   test: static function (array $responses): bool|string {
      if (count($responses) !== 7) {
         return 'M6 regenerator-exception probe did not receive all seven responses.';
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
            || ! str_contains($response, "M6-REGENERATOR-EXCEPTION-PROTECTED-HANDLER:{$mode}")
            || ! str_contains($response, 'X-M6-ID-Rotated: no')
         ) {
            Vars::$labels = ["M6 regenerator-exception {$mode} response"];
            dump(json_encode($response));

            return "M6 regenerator-exception {$mode} acceptance control failed.";
         }
      }

      if (
         ! str_contains($invalidControl, 'HTTP/1.1 403 Forbidden')
         || ! str_contains($invalidControl, 'Invalid CSRF token')
         || str_contains($invalidControl, 'M6-REGENERATOR-EXCEPTION-PROTECTED-HANDLER:')
      ) {
         Vars::$labels = ['M6 regenerator-exception invalid-token response'];
         dump(json_encode($invalidControl));

         return 'M6 regenerator-exception invalid-token control did not prove CSRF enforcement.';
      }

      $bypasses = [];
      foreach (
         ['attack-raw' => $rawAttack, 'attack-masked' => $maskedAttack]
         as $mode => $response
      ) {
         if (
            str_contains($response, 'HTTP/1.1 200 OK')
            && str_contains($response, "M6-REGENERATOR-EXCEPTION-PROTECTED-HANDLER:{$mode}")
         ) {
            $required = [
               'id-rotated' => 'X-M6-ID-Rotated: yes',
               'token-preserved' => 'X-M6-Token-Preserved: yes',
               'thrower-ran' => 'X-M6-Thrower-Ran: yes',
               'thrower-saw-old' => 'X-M6-Thrower-Saw-Old: yes',
               'exception-caught' => 'X-M6-Exception-Caught: yes',
               'exception-matched' => 'X-M6-Exception-Matched: yes',
               'tail-stopped' => 'X-M6-Tail-Ran: no',
            ];
            $missing = [];
            foreach ($required as $label => $marker) {
               if (! str_contains($response, $marker)) {
                  $missing[] = $label;
               }
            }

            if ($missing !== []) {
               Vars::$labels = ["M6 regenerator-exception {$mode} causal response"];
               dump(json_encode($response));

               return "M6 regenerator-exception {$mode} reached the handler without proving: "
                  . implode(', ', $missing) . '.';
            }

            $bypasses[] = $mode;
            continue;
         }

         if (
            ! str_contains($response, 'HTTP/1.1 403 Forbidden')
            || ! str_contains($response, 'Invalid CSRF token')
            || str_contains($response, 'M6-REGENERATOR-EXCEPTION-PROTECTED-HANDLER:')
            || ! str_contains($response, 'X-M6-ID-Rotated: yes')
            || ! str_contains($response, 'X-M6-Token-Preserved: no')
            || ! str_contains($response, 'X-M6-Token-Length: 38')
            || ! str_contains($response, 'X-M6-Thrower-Ran: yes')
            || ! str_contains($response, 'X-M6-Thrower-Saw-Old: yes')
            || ! str_contains($response, 'X-M6-Tail-Ran: yes')
         ) {
            Vars::$labels = ["M6 regenerator-exception unexpected {$mode} response"];
            dump(json_encode($response));

            return "M6 regenerator-exception {$mode} neither proved the bypass nor the secure rejection.";
         }
      }

      if ($bypasses !== []) {
         Vars::$labels = [
            'M6 regenerator-exception raw bypass',
            'M6 regenerator-exception masked bypass',
         ];
         dump(json_encode($rawAttack), json_encode($maskedAttack));

         return 'CONFIRMED M6: an earlier throwing session regenerator aborted CSRF rotation and '
            . 'upstream recovery accepted the old token: ' . implode(', ', $bypasses) . '.';
      }

      foreach (
         ['current-raw' => $currentRaw, 'current-masked' => $currentMasked]
         as $mode => $response
      ) {
         if (
            ! str_contains($response, 'HTTP/1.1 200 OK')
            || ! str_contains($response, "M6-REGENERATOR-EXCEPTION-PROTECTED-HANDLER:{$mode}")
            || ! str_contains($response, 'X-M6-ID-Rotated: yes')
            || ! str_contains($response, 'X-M6-Token-Preserved: no')
            || ! str_contains($response, 'X-M6-Token-Length: 38')
            || ! str_contains($response, 'X-M6-Expected-Token-Length: 38')
         ) {
            Vars::$labels = ["M6 regenerator-exception {$mode} response"];
            dump(json_encode($response));

            return "M6 regenerator-exception {$mode} post-regeneration control failed.";
         }
      }

      return true;
   },
);
