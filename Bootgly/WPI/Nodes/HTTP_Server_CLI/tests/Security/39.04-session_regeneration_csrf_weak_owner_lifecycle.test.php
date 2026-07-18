<?php

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middleware;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\CSRF;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


/**
 * Security PoC M6 residual C — a transient CSRF owner can disappear from the
 * weak invariant registry while its configured token remains in the Session.
 * A fresh CSRF instance created after regeneration then registers too late.
 */
$knownToken = str_repeat('c3', 32);
$maskedToken = CSRF::mask($knownToken);
$invalidToken = str_repeat('dd', 32);
$headerName = 'X-M6-Weak-Owner-Token';
$tokenBytes = 17;

$Request = static function (
   string $mode,
   string|null $token = null
) use ($headerName): string {
   $header = $token === null ? '' : "{$headerName}: {$token}\r\n";

   return "POST /m6/residual/weak-owner HTTP/1.1\r\n"
      . "Host: localhost\r\n"
      . "X-M6-Mode: {$mode}\r\n"
      . $header
      . "Content-Length: 0\r\n\r\n";
};

return new Specification(
   description: 'CSRF rotation must survive collection of a transient registry owner',
   Separator: new Separator(line: true),

   requests: [
      static fn (): string => $Request('control-raw', $knownToken),
      static fn (): string => $Request('control-masked', $maskedToken),
      static fn (): string => $Request('control-invalid', $invalidToken),
      static fn (): string => $Request('live-owner', $knownToken),
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
            $sessionKey = '_m6_weak_owner_' . str_replace('-', '_', $mode);
            $Session = $Request->Session;
            $oldID = $Session->id;

            $Session->set($sessionKey, $this->knownToken);

            $regenerated = $mode === 'live-owner'
               || $mode === 'attack-raw'
               || $mode === 'attack-masked'
               || $mode === 'current-raw'
               || $mode === 'current-masked';
            $transient = $mode === 'attack-raw'
               || $mode === 'attack-masked'
               || $mode === 'current-raw'
               || $mode === 'current-masked';

            $ownerCollected = false;
            $freshAfterRegeneration = false;

            if ($mode === 'live-owner') {
               $LiveCSRF = new CSRF(
                  sessionKey: $sessionKey,
                  headerName: $this->headerName,
                  tokenBytes: $this->tokenBytes
               );
               $Request->attributes['m6WeakOwnerCSRF'] = $LiveCSRF;
               $Request->attributes['m6WeakOwnerLive'] = true;
            }
            else if ($transient) {
               $TransientCSRF = new CSRF(
                  sessionKey: $sessionKey,
                  headerName: $this->headerName,
                  tokenBytes: $this->tokenBytes
               );
               $WeakOwner = WeakReference::create($TransientCSRF);
               unset($TransientCSRF);
               gc_collect_cycles();
               $ownerCollected = $WeakOwner->get() === null;
            }

            if ($regenerated) {
               $Session->regenerate();
            }

            if ($transient) {
               $FreshCSRF = new CSRF(
                  sessionKey: $sessionKey,
                  headerName: $this->headerName,
                  tokenBytes: $this->tokenBytes
               );
               $Request->attributes['m6WeakOwnerCSRF'] = $FreshCSRF;
               $freshAfterRegeneration = true;
            }
            else if ($mode !== 'live-owner') {
               $Request->attributes['m6WeakOwnerCSRF'] = new CSRF(
                  sessionKey: $sessionKey,
                  headerName: $this->headerName,
                  tokenBytes: $this->tokenBytes
               );
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
               'X-M6-Owner-Collected',
               $ownerCollected ? 'yes' : 'no'
            );
            $Response->Header->append(
               'X-M6-Live-Owner',
               ($Request->attributes['m6WeakOwnerLive'] ?? false) === true ? 'yes' : 'no'
            );
            $Response->Header->append(
               'X-M6-Fresh-After-Regeneration',
               $freshAfterRegeneration ? 'yes' : 'no'
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
            $CSRF = $Request->attributes['m6WeakOwnerCSRF'] ?? null;
            if (($CSRF instanceof CSRF) === false) {
               return $Response(code: 500, body: 'M6 weak-owner CSRF fixture missing');
            }

            return $CSRF->process($Request, $Response, $next);
         }
      },
   ],

   response: static function (Request $Request, Response $Response): Response {
      $mode = $Request->Header->get('X-M6-Mode') ?? 'unknown';

      return $Response(body: "M6-WEAK-OWNER-PROTECTED-HANDLER:{$mode}");
   },

   test: static function (array $responses): bool|string {
      if (count($responses) !== 8) {
         return 'M6 weak-owner probe did not receive all eight responses.';
      }

      [
         $rawControl,
         $maskedControl,
         $invalidControl,
         $liveOwner,
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
            || ! str_contains($response, "M6-WEAK-OWNER-PROTECTED-HANDLER:{$mode}")
            || ! str_contains($response, 'X-M6-ID-Rotated: no')
         ) {
            Vars::$labels = ["M6 weak-owner {$mode} response"];
            dump(json_encode($response));

            return "M6 weak-owner {$mode} acceptance control failed.";
         }
      }

      if (
         ! str_contains($invalidControl, 'HTTP/1.1 403 Forbidden')
         || ! str_contains($invalidControl, 'Invalid CSRF token')
         || str_contains($invalidControl, 'M6-WEAK-OWNER-PROTECTED-HANDLER:')
      ) {
         Vars::$labels = ['M6 weak-owner invalid-token response'];
         dump(json_encode($invalidControl));

         return 'M6 weak-owner invalid-token control did not prove CSRF enforcement.';
      }

      if (
         ! str_contains($liveOwner, 'HTTP/1.1 403 Forbidden')
         || ! str_contains($liveOwner, 'Invalid CSRF token')
         || str_contains($liveOwner, 'M6-WEAK-OWNER-PROTECTED-HANDLER:')
         || ! str_contains($liveOwner, 'X-M6-ID-Rotated: yes')
         || ! str_contains($liveOwner, 'X-M6-Token-Preserved: no')
         || ! str_contains($liveOwner, 'X-M6-Token-Length: 34')
         || ! str_contains($liveOwner, 'X-M6-Live-Owner: yes')
      ) {
         Vars::$labels = ['M6 weak-owner retained-owner response'];
         dump(json_encode($liveOwner));

         return 'M6 weak-owner retained-owner control did not prove live invariant rotation.';
      }

      $bypasses = [];
      foreach (
         ['attack-raw' => $rawAttack, 'attack-masked' => $maskedAttack]
         as $mode => $response
      ) {
         if (
            str_contains($response, 'HTTP/1.1 200 OK')
            && str_contains($response, "M6-WEAK-OWNER-PROTECTED-HANDLER:{$mode}")
         ) {
            if (
               ! str_contains($response, 'X-M6-ID-Rotated: yes')
               || ! str_contains($response, 'X-M6-Token-Preserved: yes')
               || ! str_contains($response, 'X-M6-Owner-Collected: yes')
               || ! str_contains($response, 'X-M6-Fresh-After-Regeneration: yes')
            ) {
               Vars::$labels = ["M6 weak-owner {$mode} causal response"];
               dump(json_encode($response));

               return "M6 weak-owner {$mode} reached the handler without proving the owner-lifecycle path.";
            }

            $bypasses[] = $mode;
            continue;
         }

         if (
            ! str_contains($response, 'HTTP/1.1 403 Forbidden')
            || ! str_contains($response, 'Invalid CSRF token')
            || str_contains($response, 'M6-WEAK-OWNER-PROTECTED-HANDLER:')
            || ! str_contains($response, 'X-M6-ID-Rotated: yes')
            || ! str_contains($response, 'X-M6-Token-Preserved: no')
            || ! str_contains($response, 'X-M6-Token-Length: 34')
            || ! str_contains($response, 'X-M6-Expected-Token-Length: 34')
            || ! str_contains($response, 'X-M6-Fresh-After-Regeneration: yes')
         ) {
            Vars::$labels = ["M6 weak-owner unexpected {$mode} response"];
            dump(json_encode($response));

            return "M6 weak-owner {$mode} neither proved the bypass nor the secure rejection.";
         }
      }

      if ($bypasses !== []) {
         Vars::$labels = ['M6 weak-owner raw bypass', 'M6 weak-owner masked bypass'];
         dump(json_encode($rawAttack), json_encode($maskedAttack));

         return 'CONFIRMED M6: a collected CSRF owner removed the regeneration invariant and '
            . 'the fresh middleware accepted the old token: ' . implode(', ', $bypasses) . '.';
      }

      foreach (
         ['current-raw' => $currentRaw, 'current-masked' => $currentMasked]
         as $mode => $response
      ) {
         if (
            ! str_contains($response, 'HTTP/1.1 200 OK')
            || ! str_contains($response, "M6-WEAK-OWNER-PROTECTED-HANDLER:{$mode}")
            || ! str_contains($response, 'X-M6-ID-Rotated: yes')
            || ! str_contains($response, 'X-M6-Token-Preserved: no')
            || ! str_contains($response, 'X-M6-Token-Length: 34')
            || ! str_contains($response, 'X-M6-Expected-Token-Length: 34')
            || ! str_contains($response, 'X-M6-Fresh-After-Regeneration: yes')
         ) {
            Vars::$labels = ["M6 weak-owner {$mode} response"];
            dump(json_encode($response));

            return "M6 weak-owner {$mode} post-regeneration control failed.";
         }
      }

      return true;
   },
);
