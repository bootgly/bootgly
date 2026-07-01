<?php


use function shell_exec;
use function trim;

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should interoperate with curl --http2-prior-knowledge (real nghttp2 client)',
   test: new Assertions(Case: function (): Generator {
      // ? curl with HTTP/2 support required
      $features = (string) shell_exec('curl --version 2>/dev/null');
      if (str_contains($features, 'nghttp2') === false && str_contains($features, 'HTTP2') === false) {
         yield new Assertion(
            description: 'curl without HTTP/2 support — interop spec skipped',
         )
            ->expect(true)
            ->to->be(true)
            ->assert();
         return;
      }

      // @ GET
      $output = (string) shell_exec(
         'curl -s --max-time 5 --http2-prior-knowledge'
         . ' -o - -w "|%{http_code}|%{http_version}"'
         . ' http://127.0.0.1:8085/curl 2>/dev/null'
      );
      yield new Assertion(
         description: 'curl GET /curl → routed body + 200 + HTTP/2',
      )
         ->expect(trim($output))
         ->to->be('method=GET;uri=/curl;protocol=HTTP/2;body=|200|2')
         ->assert();

      // @ POST with body (form)
      $output = (string) shell_exec(
         'curl -s --max-time 5 --http2-prior-knowledge'
         . ' -d "a=1&b=2" -o - -w "|%{http_code}|%{http_version}"'
         . ' http://127.0.0.1:8085/post 2>/dev/null'
      );
      yield new Assertion(
         description: 'curl POST → body echoed + 200 + HTTP/2',
      )
         ->expect(trim($output))
         ->to->be('method=POST;uri=/post;protocol=HTTP/2;body=a=1&b=2|200|2')
         ->assert();

      // @ Two sequential requests on one curl connection (stream 1 + stream 3)
      $output = (string) shell_exec(
         'curl -s --max-time 5 --http2-prior-knowledge'
         . ' -o - -w "|%{http_code}"'
         . ' http://127.0.0.1:8085/first http://127.0.0.1:8085/second 2>/dev/null'
      );
      yield new Assertion(
         description: 'curl connection reuse → two streams served',
      )
         ->expect(trim($output))
         ->to->be(
            'method=GET;uri=/first;protocol=HTTP/2;body=|200'
            . 'method=GET;uri=/second;protocol=HTTP/2;body=|200'
         )
         ->assert();
   })
);
