<?php

use const Bootgly\WPI;

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middleware;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


/**
 * Security PoC C3 — deferred work must retain the route and ambient Request
 * context that passed authorization, even when another dynamic request is
 * resolved while the first Fiber is suspended.
 *
 * Encoder_Testing intentionally creates a Router per request because every
 * native test may define different routes. This case owns one persistent
 * Router inside its Specification so the exercised path matches Encoder_'s
 * worker-persistent Router without changing production code or the harness.
 *
 * The first request is a no-interleaving positive control. The second request
 * closure starts authorized request A on a side connection and waits for its
 * Fiber-ready marker before returning denied request B. Only after B's 403 is
 * received does the test release A. A secure implementation must still target
 * `self`; targeting B's denied `victim` parameter confirms C3.
 */
$dependencyPair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
if ($dependencyPair === false) {
   throw new RuntimeException('C3 fixture could not create the deferred rendezvous pair.');
}
[$dependencyWorker, $dependencyTest] = $dependencyPair;
$Probe = new class {
   public mixed $connection = null;
};

$PersistentRouter = new Router;
$Authorization = new class($PersistentRouter) implements Middleware {
   public function __construct (private Router $Router)
   {
   }

   public function process (object $Request, object $Response, Closure $Next): object
   {
      /** @var Request $Request */
      /** @var Response $Response */
      $params = iterator_to_array($this->Router->Route->Params);
      $routeValue = $params['id'] ?? '';
      $routeID = is_array($routeValue) ? '' : (string) $routeValue;
      $token = $Request->Header->get('X-C3-Token');

      if ($routeID !== 'self' || $token !== 'c3-self-secret') {
         return $Response(code: 403, body: "C3-DENIED:id={$routeID}");
      }

      // @ Security context that Request::__clone() normally scrubs. Deferred
      //   capture must preserve the state admitted by this middleware.
      $Request->identity = (object) ['id' => $routeID];
      $Request->claims = ['account' => $routeID];
      $Request->authorizedRoute = $routeID;

      return $Next($Request, $Response);
   }
};

return new Specification(
   description: 'Deferred work must retain the Route and Request context admitted for request A',
   Separator: new Separator(line: true),

   requests: [
      static fn (): string => "GET /c3/accounts/self HTTP/1.1\r\n"
         . "Host: localhost\r\n"
         . "X-C3-Token: c3-self-secret\r\n"
         . "X-C3-Mode: control\r\n\r\n",
      static function (string $hostPort, int $testIndex) use (
         $dependencyWorker,
         $dependencyTest,
         $Probe,
      ): string {
         // @ The master does not use its duplicate of the worker endpoint.
         if (is_resource($dependencyWorker)) {
            fclose($dependencyWorker);
         }

         $Probe->connection = stream_socket_client(
            "tcp://{$hostPort}",
            $errorCode,
            $errorMessage,
            timeout: 5,
         );
         if (is_resource($Probe->connection) === false) {
            throw new RuntimeException(
               "C3 fixture could not open request A: {$errorCode} {$errorMessage}"
            );
         }

         $requestA = "GET /c3/accounts/self HTTP/1.1\r\n"
            . "Host: localhost\r\n"
            . "X-Bootgly-Test: {$testIndex}\r\n"
            . "X-C3-Token: c3-self-secret\r\n"
            . "X-C3-Mode: attack\r\n\r\n";
         $offset = 0;
         while ($offset < strlen($requestA)) {
            $written = fwrite($Probe->connection, substr($requestA, $offset));
            if ($written === false || $written === 0) {
               break;
            }
            $offset += $written;
         }
         if ($offset !== strlen($requestA)) {
            throw new RuntimeException('C3 fixture did not send request A completely.');
         }

         // ! Do not send B until A has reached the deferred suspension point.
         stream_set_blocking($dependencyTest, true);
         stream_set_timeout($dependencyTest, 10);
         $ready = '';
         while (str_contains($ready, "\n") === false) {
            $chunk = fread($dependencyTest, 8192);
            if ($chunk === false || $chunk === '') {
               break;
            }
            $ready .= $chunk;
         }
         if ($ready !== "C3-A-READY\n") {
            throw new RuntimeException(
               'C3 fixture did not observe request A at the deferred suspension point: '
               . json_encode($ready)
            );
         }

         // : B reaches the same persistent Router but fails route admission.
         return "GET /c3/accounts/victim HTTP/1.1\r\n"
            . "Host: localhost\r\n"
            . "X-C3-Token: invalid\r\n\r\n";
      },
   ],

   response: static function (
      Request $Request,
      Response $Response,
      Router $Router,
   ) use (
      $Authorization,
      $dependencyWorker,
      $dependencyTest,
      $PersistentRouter,
   ): Response {
      // ! Encoder_Testing normally supplies a per-request Router. Bind this
      //   fixture's persistent Router as the ambient production Router too,
      //   so WPI->Router->Route exercises the same source-to-sink path.
      $WPI = WPI;
      $WPI->Router = $PersistentRouter;

      // ! Direct registration plus routing() retains one Route across requests,
      //   matching the production Encoder_ path under test.
      $PersistentRouter->route('/c3/accounts/:id', function (
         Request $Request,
         Response $Response,
      ) use ($dependencyWorker, $dependencyTest): Response {
         $authorizedID = (string) $this->Params->id; // @phpstan-ignore variable.undefined
         $mode = $Request->Header->get('X-C3-Mode');

         return $Response->defer(function (Response $DeferredResponse) use (
            $authorizedID,
            $dependencyWorker,
            $dependencyTest,
            $mode,
         ): void {
            if ($mode === 'attack') {
               // @ The worker does not use its duplicate of the test endpoint.
               if (is_resource($dependencyTest)) {
                  fclose($dependencyTest);
               }

               stream_set_blocking($dependencyWorker, false);
               fwrite($dependencyWorker, "C3-A-READY\n");
               // ! Exercise scheduler-level context restoration too. Direct
               //   suspension must be as isolated as Response::wait().
               Fiber::suspend($dependencyWorker);
            }
            else {
               // @ Positive control: defer/resume without an interleaving request.
               $DeferredResponse->wait();
            }

            $targetID = (string) $this->Params->id; // @phpstan-ignore variable.undefined
            $WPI = WPI;
            $ambientURI = $WPI->Request->URI;
            $ambientTargetID = (string) $WPI->Router->Route->Params->id;
            $Identity = $WPI->Request->identity;
            $identityID = is_object($Identity) && isset($Identity->id)
               ? (string) $Identity->id
               : '';
            $claimID = (string) ($WPI->Request->claims['account'] ?? '');
            $attributeID = (string) ($WPI->Request->authorizedRoute ?? '');

            $DeferredResponse(
               body: "C3-ACCESS:authorized={$authorizedID};target={$targetID};"
                  . "ambient={$ambientURI};ambient_target={$ambientTargetID};"
                  . "identity={$identityID};claim={$claimID};attribute={$attributeID}"
            );
         });
      }, GET, middlewares: [$Authorization]);

      foreach ($PersistentRouter->routing() as $ResolvedResponse) {
         if ($ResolvedResponse instanceof Response) {
            return $ResolvedResponse;
         }
      }

      return $Response(code: 404, body: 'C3-FIXTURE-NOT-ROUTED');
   },

   test: static function (array $responses) use (
      $dependencyTest,
      $Probe,
   ): bool|string {
      if (count($responses) !== 2) {
         return 'C3 fixture failed: expected the control and denied interleaving responses.';
      }

      // @ B has completed. Release suspended A only now.
      if (is_resource($dependencyTest) === false) {
         return 'C3 fixture failed: deferred rendezvous endpoint was unavailable.';
      }
      if (fwrite($dependencyTest, "R") !== 1) {
         return 'C3 fixture failed: request A could not be released after request B.';
      }
      fclose($dependencyTest);

      if (is_resource($Probe->connection) === false) {
         return 'C3 fixture failed: request A side connection was unavailable.';
      }
      stream_set_blocking($Probe->connection, true);
      stream_set_timeout($Probe->connection, 10);

      $attackResponse = '';
      while (true) {
         $chunk = fread($Probe->connection, 8192);
         if ($chunk === false || $chunk === '') {
            break;
         }
         $attackResponse .= $chunk;

         $headerEnd = strpos($attackResponse, "\r\n\r\n");
         if (
            $headerEnd !== false
            && preg_match(
               '/\r\nContent-Length: (\d+)\r\n/i',
               substr($attackResponse, 0, $headerEnd + 2),
               $matches,
            ) === 1
            && strlen($attackResponse) - $headerEnd - 4 >= (int) $matches[1]
         ) {
            break;
         }
      }
      fclose($Probe->connection);

      $Body = static function (string $response): null|string {
         $separator = strpos($response, "\r\n\r\n");

         return $separator === false ? null : substr($response, $separator + 4);
      };
      $controlBody = $Body($responses[0]);
      $deniedBody = $Body($responses[1]);
      $attackBody = $Body($attackResponse);

      $evidence = [
         'control' => $controlBody,
         'denied_interleaving_request' => $deniedBody,
         'authorized_deferred_request' => $attackBody,
         'control_wire' => $responses[0],
         'authorized_deferred_wire' => $attackResponse,
      ];

      $expectedControl = 'C3-ACCESS:authorized=self;target=self;'
         . 'ambient=/c3/accounts/self;ambient_target=self;'
         . 'identity=self;claim=self;attribute=self';
      if (
         str_contains($responses[0], 'HTTP/1.1 200 OK') === false
         || $controlBody !== $expectedControl
      ) {
         Vars::$labels = ['C3 no-interleaving control evidence'];
         dump(json_encode($evidence));

         return 'C3 control failed: deferred routing did not preserve `self` without interleaving. '
            . 'Evidence: ' . json_encode($evidence);
      }

      if (
         str_contains($responses[1], 'HTTP/1.1 403 Forbidden') === false
         || $deniedBody !== 'C3-DENIED:id=victim'
         || str_contains($responses[1], 'C3-ACCESS:')
      ) {
         Vars::$labels = ['C3 denied-request control evidence'];
         dump(json_encode($evidence));

         return 'C3 control failed: request B did not reach dynamic resolution and fail admission. '
            . 'Evidence: ' . json_encode($evidence);
      }

      if (
         str_contains($attackResponse, 'HTTP/1.1 200 OK')
         && $attackBody === 'C3-ACCESS:authorized=self;target=victim;'
            . 'ambient=/c3/accounts/victim;ambient_target=victim;'
            . 'identity=;claim=;attribute='
      ) {
         Vars::$labels = ['C3 deferred cross-request context evidence'];
         dump(json_encode($evidence));

         return 'CONFIRMED C3: authorized deferred request A resumed with denied request B\'s '
            . 'mutable Route target and ambient Request URI. Evidence: '
            . json_encode($evidence);
      }

      $expectedSecure = 'C3-ACCESS:authorized=self;target=self;'
         . 'ambient=/c3/accounts/self;ambient_target=self;'
         . 'identity=self;claim=self;attribute=self';
      if (
         str_contains($attackResponse, 'HTTP/1.1 200 OK') === false
         || $attackBody !== $expectedSecure
      ) {
         Vars::$labels = ['C3 unexpected deferred-context evidence'];
         dump(json_encode($attackResponse), json_encode($evidence));

         return 'C3 fixture produced neither the confirmed substitution nor secure context isolation. '
            . 'Evidence: ' . json_encode($evidence);
      }

      return true;
   },
);
