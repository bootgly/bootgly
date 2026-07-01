<?php


use function fread;
use function fwrite;
use function shell_exec;
use function stream_get_meta_data;
use function stream_set_blocking;
use function str_contains;
use function trim;
use function usleep;

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Modules\HTTP2;
use Bootgly\WPI\Modules\HTTP2\Frame;
use Bootgly\WPI\Modules\HTTP2\HPACK;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\tests\HTTP2\Client;


return new Specification(
   description: 'It should negotiate h2 via TLS-ALPN, serve streams over TLS and keep the http/1.1 fallback',
   test: new Assertions(Case: function (): Generator {
      $context = [
         'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true,
            'alpn_protocols' => 'h2,http/1.1',
         ],
      ];

      // @ ALPN negotiates h2
      $Client = new Client('ssl://127.0.0.1:8086', $context);
      $meta = stream_get_meta_data($Client->Socket);
      yield new Assertion(
         description: 'Client-side negotiated ALPN protocol is h2',
      )
         ->expect($meta['crypto']['alpn_protocol'] ?? '')
         ->to->be('h2')
         ->assert();

      // @ Full request over TLS: preface → SETTINGS → GET → response
      $Client->preface();
      $settings = $Client->expect(HTTP2::FRAME_SETTINGS);
      yield new Assertion(
         description: 'Server SETTINGS arrive over TLS',
      )
         ->expect($settings['type'] ?? null)
         ->to->be(HTTP2::FRAME_SETTINGS)
         ->assert();

      $Client->send(Frame::pack(
         HTTP2::FRAME_HEADERS,
         HTTP2::FLAG_END_HEADERS | HTTP2::FLAG_END_STREAM,
         1,
         HPACK::encode([
            [':method', 'GET'],
            [':scheme', 'https'],
            [':path', '/tls'],
            [':authority', 'localhost:8086']
         ])
      ));
      $data = $Client->expect(HTTP2::FRAME_DATA);
      yield new Assertion(
         description: 'GET /tls over h2+TLS → routed response with https scheme',
      )
         ->expect($data['payload'] ?? '')
         ->to->be('method=GET;uri=/tls;protocol=HTTP/2;scheme=https')
         ->assert();
      $Client->close();

      // @ ALPN fallback: client offering only http/1.1 gets HTTP/1.1
      $fallback = [
         'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true,
            'alpn_protocols' => 'http/1.1',
         ],
      ];
      $Client = new Client('ssl://127.0.0.1:8086', $fallback);
      $meta = stream_get_meta_data($Client->Socket);
      yield new Assertion(
         description: 'Client offering only http/1.1 negotiates http/1.1',
      )
         ->expect($meta['crypto']['alpn_protocol'] ?? '')
         ->to->be('http/1.1')
         ->assert();

      @fwrite($Client->Socket, "GET /fallback HTTP/1.1\r\nHost: localhost:8086\r\n\r\n");
      stream_set_blocking($Client->Socket, false);
      $response = '';
      for ($i = 0; $i < 100 && str_contains($response, 'scheme=https') === false; $i++) {
         $chunk = @fread($Client->Socket, 65536);
         if ($chunk !== false && $chunk !== '') {
            $response .= $chunk;
            continue;
         }
         usleep(10000);
      }
      yield new Assertion(
         description: 'HTTP/1.1 fallback serves the routed handler over TLS',
      )
         ->expect(
            str_contains($response, 'HTTP/1.1 200')
            && str_contains($response, 'method=GET;uri=/fallback;protocol=HTTP/1.1;scheme=https')
         )
         ->to->be(true)
         ->assert();
      $Client->close();

      // @ curl interop: real nghttp2 client over TLS-ALPN
      $features = (string) shell_exec('curl --version 2>/dev/null');
      if (str_contains($features, 'nghttp2') || str_contains($features, 'HTTP2')) {
         $output = (string) shell_exec(
            'curl -sk --max-time 5 --http2'
            . ' -o - -w "|%{http_code}|%{http_version}"'
            . ' https://127.0.0.1:8086/curl 2>/dev/null'
         );
         yield new Assertion(
            description: 'curl --http2 over TLS → ALPN h2 + routed response',
         )
            ->expect(trim($output))
            ->to->be('method=GET;uri=/curl;protocol=HTTP/2;scheme=https|200|2')
            ->assert();
      }
   })
);
