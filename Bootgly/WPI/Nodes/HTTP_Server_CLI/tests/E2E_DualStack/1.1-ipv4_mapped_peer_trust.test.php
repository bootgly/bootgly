<?php


use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\TrustedProxy;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


/**
 * MW-6 regression — on a dual-stack `host: '[::]'` listener an IPv4 hop
 * arrives as the IPv4-mapped `::ffff:a.b.c.d`. Peer::parse() canonicalizes
 * it to the dotted quad, so TrustedProxy's exact-string match trusts the
 * documented default list (`127.0.0.1`, `::1`) and any explicit IPv4 entry
 * the operator writes.
 *
 * The harness leg reaches the server over `[::1]` (control: a genuine IPv6
 * loopback peer, trusted by the default list). The test: closure then opens
 * its OWN IPv4 connection to `127.0.0.1` — which the dual-stack listener
 * presents as `::ffff:127.0.0.1` — and asserts the canonical peer plus the
 * applied forwarded values.
 */

$state = [
   'index' => 0,
];

return new Specification(
   description: 'An IPv4 hop on a dual-stack listener canonicalizes and is trusted',

   request: function (string $hostPort, int $testIndex) use (&$state): string {
      $state['index'] = $testIndex;

      return "GET /dual/proxy HTTP/1.1\r\n"
         . "Host: localhost\r\n"
         . "X-Forwarded-For: 203.0.113.77\r\n"
         . "X-Forwarded-Proto: https\r\n\r\n";
   },
   middlewares: [new TrustedProxy],
   response: function (Request $Request, Response $Response): Response {
      return $Response(
         body: "peer={$Request->peer}|address={$Request->address}|scheme={$Request->scheme}"
      );
   },

   test: function (string $response) use (&$state): bool|string {
      // @ Control — the harness leg arrives over [::1]: a genuine IPv6
      //   loopback peer the default list already trusts
      if (! str_contains($response, 'peer=::1|address=203.0.113.77|scheme=https')) {
         Vars::$labels = ['Harness leg:'];
         dump(json_encode($response));
         return 'The IPv6 loopback control leg was not trusted as ::1';
      }

      // @ The MW-6 core — an explicit IPv4 connection to the dual-stack
      //   listener arrives as ::ffff:127.0.0.1 and must canonicalize
      $Socket = @stream_socket_client(
         'tcp://127.0.0.1:8101',
         $errorNumber,
         $errorMessage,
         2
      );
      if (! is_resource($Socket)) {
         return "IPv4 leg could not connect: {$errorNumber} {$errorMessage}";
      }

      try {
         stream_set_timeout($Socket, 2);
         @fwrite(
            $Socket,
            "GET /dual/proxy HTTP/1.1\r\n"
               . "Host: localhost\r\n"
               . "X-Bootgly-Test: {$state['index']}\r\n"
               . "X-Forwarded-For: 203.0.113.77\r\n"
               . "X-Forwarded-Proto: https\r\n"
               . "Connection: close\r\n\r\n"
         );

         $ipv4 = '';
         while (! feof($Socket)) {
            $chunk = fread($Socket, 65536);
            if ($chunk === false || $chunk === '') {
               break;
            }
            $ipv4 .= $chunk;
         }
      }
      finally {
         @fclose($Socket);
      }

      if (str_contains($ipv4, '::ffff:')) {
         Vars::$labels = ['IPv4 leg:'];
         dump(json_encode($ipv4));
         return 'The IPv4-mapped peer leaked uncanonicalized into the layer';
      }
      if (! str_contains($ipv4, 'peer=127.0.0.1|address=203.0.113.77|scheme=https')) {
         Vars::$labels = ['IPv4 leg:'];
         dump(json_encode($ipv4));
         return 'The IPv4 hop was not canonicalized and trusted (MW-6)';
      }

      return true;
   }
);
