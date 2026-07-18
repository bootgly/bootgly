<?php

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\ABI\Events\Emitter;
use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Session\Events as SessionEvents;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middleware;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\CSRF;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


/**
 * Security PoC M6 residual B — replacing the global Emitter after CSRF
 * construction leaves an upstream Session::regenerate() without its hook.
 * CSRF::process() subscribes to the replacement only after the transition.
 */
$knownToken = str_repeat('b2', 32);
$maskedToken = CSRF::mask($knownToken);
$invalidToken = str_repeat('ee', 32);
$sessionKey = '_m6_replaced_emitter_token';
$headerName = 'X-M6-Replaced-Token';
$tokenBytes = 19;

$Request = static function (
   string $mode,
   string|null $token = null
) use ($headerName): string {
   $header = $token === null ? '' : "{$headerName}: {$token}\r\n";

   return "POST /m6/residual/emitter-replacement HTTP/1.1\r\n"
      . "Host: localhost\r\n"
      . "X-M6-Mode: {$mode}\r\n"
      . $header
      . "Content-Length: 0\r\n\r\n";
};

return new Specification(
   description: 'CSRF rotation must survive replacement of the application Emitter',
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
      new class($sessionKey, $headerName, $tokenBytes) implements Middleware {
         public function __construct (
            private string $sessionKey,
            private string $headerName,
            private int $tokenBytes
         )
         {
         }

         public function process (object $Request, object $Response, Closure $next): object
         {
            $PreviousEmitter = Emitter::$Instance;
            $EmitterA = new Emitter;
            Emitter::$Instance = $EmitterA;

            $CSRF = new CSRF(
               sessionKey: $this->sessionKey,
               headerName: $this->headerName,
               tokenBytes: $this->tokenBytes
            );
            $listenerOnA = $EmitterA->check(SessionEvents::Regenerate);

            $EmitterB = new Emitter;
            Emitter::$Instance = $EmitterB;

            $Request->attributes['m6ReplacementCSRF'] = $CSRF;
            $Request->attributes['m6ReplacementTopology'] = [
               'different' => $EmitterA !== $EmitterB,
               'listenerOnA' => $listenerOnA,
               'listenerOnBBefore' => $EmitterB->check(SessionEvents::Regenerate),
               'EmitterB' => $EmitterB,
            ];

            try {
               return $next($Request, $Response);
            }
            finally {
               Emitter::$Instance = $PreviousEmitter;
            }
         }
      },
      new class($knownToken, $sessionKey, $headerName, $tokenBytes) implements Middleware {
         public function __construct (
            private string $knownToken,
            private string $sessionKey,
            private string $headerName,
            private int $tokenBytes
         )
         {
         }

         public function process (object $Request, object $Response, Closure $next): object
         {
            $mode = $Request->Header->get('X-M6-Mode') ?? 'unknown';
            $Session = $Request->Session;
            $oldId = $Session->id;

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

            $Topology = $Request->attributes['m6ReplacementTopology'] ?? [];
            $EmitterB = $Topology['EmitterB'] ?? null;

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
               'X-M6-Buses-Different',
               ($Topology['different'] ?? false) === true ? 'yes' : 'no'
            );
            $Response->Header->append(
               'X-M6-Listener-On-A',
               ($Topology['listenerOnA'] ?? false) === true ? 'yes' : 'no'
            );
            $Response->Header->append(
               'X-M6-Listener-On-B-Before',
               ($Topology['listenerOnBBefore'] ?? true) === false ? 'no' : 'yes'
            );
            $Response->Header->append(
               'X-M6-Emitter-B-Current',
               $EmitterB instanceof Emitter && Emitter::$Instance === $EmitterB ? 'yes' : 'no'
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
            $CSRF = $Request->attributes['m6ReplacementCSRF'] ?? null;
            if (($CSRF instanceof CSRF) === false) {
               return $Response(code: 500, body: 'M6 replacement CSRF fixture missing');
            }

            $Result = $CSRF->process($Request, $Response, $next);
            $Topology = $Request->attributes['m6ReplacementTopology'] ?? [];
            $EmitterB = $Topology['EmitterB'] ?? null;
            $Response->Header->append(
               'X-M6-Listener-On-B-After',
               $EmitterB instanceof Emitter && $EmitterB->check(SessionEvents::Regenerate)
                  ? 'yes'
                  : 'no'
            );

            return $Result;
         }
      },
   ],

   response: static function (Request $Request, Response $Response): Response {
      $mode = $Request->Header->get('X-M6-Mode') ?? 'unknown';

      return $Response(body: "M6-REPLACEMENT-PROTECTED-HANDLER:{$mode}");
   },

   test: static function (array $responses): bool|string {
      if (count($responses) !== 7) {
         return 'M6 Emitter-replacement probe did not receive all seven responses.';
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
            || ! str_contains($response, "M6-REPLACEMENT-PROTECTED-HANDLER:{$mode}")
            || ! str_contains($response, 'X-M6-ID-Rotated: no')
         ) {
            Vars::$labels = ["M6 replacement {$mode} response"];
            dump(json_encode($response));

            return "M6 replacement {$mode} acceptance control failed.";
         }
      }

      if (
         ! str_contains($invalidControl, 'HTTP/1.1 403 Forbidden')
         || ! str_contains($invalidControl, 'Invalid CSRF token')
         || str_contains($invalidControl, 'M6-REPLACEMENT-PROTECTED-HANDLER:')
      ) {
         Vars::$labels = ['M6 replacement invalid-token response'];
         dump(json_encode($invalidControl));

         return 'M6 replacement invalid-token control did not prove CSRF enforcement.';
      }

      foreach (
         ['current-raw' => $currentRaw, 'current-masked' => $currentMasked]
         as $mode => $response
      ) {
         if (
            ! str_contains($response, 'HTTP/1.1 200 OK')
            || ! str_contains($response, "M6-REPLACEMENT-PROTECTED-HANDLER:{$mode}")
            || ! str_contains($response, 'X-M6-ID-Rotated: yes')
         ) {
            Vars::$labels = ["M6 replacement {$mode} response"];
            dump(json_encode($response));

            return "M6 replacement {$mode} post-regeneration control failed.";
         }
      }

      $bypasses = [];
      foreach (
         ['attack-raw' => $rawAttack, 'attack-masked' => $maskedAttack]
         as $mode => $response
      ) {
         if (
            str_contains($response, 'HTTP/1.1 200 OK')
            && str_contains($response, "M6-REPLACEMENT-PROTECTED-HANDLER:{$mode}")
         ) {
            if (
               ! str_contains($response, 'X-M6-ID-Rotated: yes')
               || ! str_contains($response, 'X-M6-Token-Preserved: yes')
               || ! str_contains($response, 'X-M6-Buses-Different: yes')
               || ! str_contains($response, 'X-M6-Listener-On-A: yes')
               || ! str_contains($response, 'X-M6-Listener-On-B-Before: no')
               || ! str_contains($response, 'X-M6-Emitter-B-Current: yes')
               || ! str_contains($response, 'X-M6-Listener-On-B-After: yes')
            ) {
               Vars::$labels = ["M6 replacement {$mode} causal response"];
               dump(json_encode($response));

               return "M6 replacement {$mode} reached the handler without proving the replacement path.";
            }

            $bypasses[] = $mode;
            continue;
         }

         if (
            ! str_contains($response, 'HTTP/1.1 403 Forbidden')
            || ! str_contains($response, 'Invalid CSRF token')
            || str_contains($response, 'M6-REPLACEMENT-PROTECTED-HANDLER:')
            || ! str_contains($response, 'X-M6-ID-Rotated: yes')
            || ! str_contains($response, 'X-M6-Token-Preserved: no')
         ) {
            Vars::$labels = ["M6 replacement unexpected {$mode} response"];
            dump(json_encode($response));

            return "M6 replacement {$mode} neither proved the bypass nor the secure rejection.";
         }
      }

      if ($bypasses !== []) {
         Vars::$labels = ['M6 replacement raw bypass', 'M6 replacement masked bypass'];
         dump(json_encode($rawAttack), json_encode($maskedAttack));

         return 'CONFIRMED M6: Emitter replacement before upstream regeneration preserved and '
            . 'accepted the old CSRF token: ' . implode(', ', $bypasses) . '.';
      }

      foreach ([$currentRaw, $currentMasked] as $response) {
         if (
            ! str_contains($response, 'X-M6-Token-Preserved: no')
            || ! str_contains($response, 'X-M6-Token-Length: 38')
            || ! str_contains($response, 'X-M6-Expected-Token-Length: 38')
         ) {
            Vars::$labels = ['M6 replacement custom token rotation response'];
            dump(json_encode($response));

            return 'M6 replacement secure path did not rotate the custom token to its configured length.';
         }
      }

      return true;
   },
);
