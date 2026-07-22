<?php

use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


/**
 * Security PoC C5 — duplicate byte ranges must not multiply one file into an
 * attacker-controlled number of disk reads and response-body copies.
 *
 * A legitimate three-range response and the primary harness response are live
 * controls. The attack leg sends 32 identical full-file ranges for the existing
 * 82,651-byte image fixture and reads the complete advertised body, with an
 * eight-MiB safety ceiling. Vulnerable code emits 32 verified copies through
 * the real Request -> Response::upload() -> Raw encoder -> TCP writer path.
 */
/**
 * @var array{
 *    error:string,
 *    control:string,
 *    attack:string,
 *    duplicate:string,
 *    overlap:string,
 *    order:string,
 *    malformed:string
 * } $probe
 */
$probe = [
   'error' => '',
   'control' => '',
   'attack' => '',
   'duplicate' => '',
   'overlap' => '',
   'order' => '',
   'malformed' => '',
];
$Read = static function ($Socket, int $limit, array &$probe): string {
   $wire = '';
   $expected = null;

   while (strlen($wire) < $limit) {
      $chunk = @fread($Socket, 65536);
      if ($chunk === false) {
         $probe['error'] = 'C5 fixture failed while reading a live response.';
         break;
      }
      if ($chunk === '') {
         $metadata = stream_get_meta_data($Socket);
         if ($metadata['timed_out'] === true) {
            $probe['error'] = 'C5 fixture timed out while reading a live response.';
         }
         break;
      }

      $wire .= $chunk;
      $separator = strpos($wire, "\r\n\r\n");
      if ($separator !== false && $expected === null) {
         $head = substr($wire, 0, $separator + 2);
         if (preg_match('/\r\nContent-Length: (\d+)\r\n/i', $head, $matches) !== 1) {
            $probe['error'] = 'C5 fixture received a response without Content-Length.';
            break;
         }

         $expected = $separator + 4 + (int) $matches[1];
         if ($expected > $limit) {
            $probe['error'] = 'C5 fixture stopped at its eight-MiB response safety ceiling.';
            break;
         }
      }

      if ($expected !== null && strlen($wire) >= $expected) {
         break;
      }
   }

   if ($probe['error'] === '' && $expected === null) {
      $probe['error'] = 'C5 fixture did not receive a complete response head.';
   }
   else if ($probe['error'] === '' && strlen($wire) !== $expected) {
      $received = strlen($wire);
      $probe['error'] = "C5 fixture received {$received} bytes; expected {$expected}.";
   }

   return $wire;
};
$Exchange = static function (
   string $hostPort,
   string $request,
   array &$probe,
) use ($Read): string {
   $errno = 0;
   $error = '';
   $Socket = @stream_socket_client(
      "tcp://{$hostPort}",
      $errno,
      $error,
      5,
   );
   if ($Socket === false) {
      $probe['error'] = "C5 fixture could not connect to the live server: {$errno} {$error}";

      return '';
   }

   stream_set_blocking($Socket, true);
   stream_set_timeout($Socket, 10);
   $offset = 0;
   $length = strlen($request);

   while ($offset < $length) {
      $written = @fwrite($Socket, substr($request, $offset));
      if ($written === false || $written === 0) {
         $probe['error'] = 'C5 fixture failed while writing a live request.';
         fclose($Socket);

         return '';
      }
      $offset += $written;
   }

   $wire = $Read($Socket, 8 * 1024 * 1024, $probe);
   fclose($Socket);

   return $wire;
};

return new Specification(
   description: 'Duplicate byte ranges must not amplify one file across repeated TCP writes',
   Separator: new Separator(line: true),

   request: static function (string $hostPort, int $testIndex) use (
      &$probe,
      $Exchange,
   ): string {
      $control = "GET /c5/range-control HTTP/1.1\r\n"
         . "Host: localhost\r\n"
         . "X-Bootgly-Test: {$testIndex}\r\n"
         . "X-C5-Mode: control\r\n"
         . "Range: bytes=1-2,4-5,-1\r\n"
         . "Connection: close\r\n\r\n";
      $probe['control'] = $Exchange($hostPort, $control, $probe);

      if ($probe['error'] === '') {
         $duplicates = implode(',', array_fill(0, 32, '0-82650'));
         $attack = "GET /c5/range-attack HTTP/1.1\r\n"
            . "Host: localhost\r\n"
            . "X-Bootgly-Test: {$testIndex}\r\n"
            . "X-C5-Mode: attack\r\n"
            . "Range: bytes={$duplicates}\r\n"
            . "Connection: close\r\n\r\n";
         $probe['attack'] = $Exchange($hostPort, $attack, $probe);
      }

      // Preserve the pre-fix confirmation path: vulnerable code has already
      // emitted the complete multipart body and need not receive follow-up
      // hardening probes that could terminate the fixture process.
      $separator = strpos($probe['attack'], "\r\n\r\n");
      $attackHead = $separator === false
         ? $probe['attack']
         : substr($probe['attack'], 0, $separator);
      $vulnerableShape = str_starts_with($attackHead, 'HTTP/1.1 206 ')
         && stripos($attackHead, 'multipart/byteranges') !== false;

      if ($probe['error'] === '' && !$vulnerableShape) {
         $duplicate = "GET /c5/range-duplicate HTTP/1.1\r\n"
            . "Host: localhost\r\n"
            . "X-Bootgly-Test: {$testIndex}\r\n"
            . "X-C5-Mode: duplicate\r\n"
            . "Range: bytes=0-82650,0-82650\r\n"
            . "Connection: close\r\n\r\n";
         $probe['duplicate'] = $Exchange($hostPort, $duplicate, $probe);
      }

      if ($probe['error'] === '' && !$vulnerableShape) {
         $overlap = "GET /c5/range-overlap HTTP/1.1\r\n"
            . "Host: localhost\r\n"
            . "X-Bootgly-Test: {$testIndex}\r\n"
            . "X-C5-Mode: overlap\r\n"
            . "Range: bytes=0-9,5-14,15-19\r\n"
            . "Connection: close\r\n\r\n";
         $probe['overlap'] = $Exchange($hostPort, $overlap, $probe);
      }

      if ($probe['error'] === '' && !$vulnerableShape) {
         $order = "GET /c5/range-order HTTP/1.1\r\n"
            . "Host: localhost\r\n"
            . "X-Bootgly-Test: {$testIndex}\r\n"
            . "X-C5-Mode: order\r\n"
            . "Range: bytes=10-20,40-49,0-30\r\n"
            . "Connection: close\r\n\r\n";
         $probe['order'] = $Exchange($hostPort, $order, $probe);
      }

      if ($probe['error'] === '' && !$vulnerableShape) {
         $malformed = "GET /c5/range-malformed HTTP/1.1\r\n"
            . "Host: localhost\r\n"
            . "X-Bootgly-Test: {$testIndex}\r\n"
            . "X-C5-Mode: malformed\r\n"
            . "Range: bytes=5\r\n"
            . "Connection: close\r\n\r\n";
         $probe['malformed'] = $Exchange($hostPort, $malformed, $probe);
      }

      return "GET /c5/harness HTTP/1.1\r\n"
         . "Host: localhost\r\n"
         . "X-C5-Mode: harness\r\n"
         . "Connection: close\r\n\r\n";
   },

   response: static function (Request $Request, Response $Response): Response {
      return match ($Request->Header->get('X-C5-Mode')) {
         'control' => $Response->upload('statics/alphanumeric.txt'),
         'attack' => $Response->upload('statics/image1.jpg'),
         'duplicate' => $Response->upload('statics/image1.jpg'),
         'overlap', 'order', 'malformed' => $Response->upload(
            'statics/alphanumeric.txt',
         ),
         default => $Response(code: 200, body: 'C5-HARNESS-OK'),
      };
   },

   test: static function (string $response) use (&$probe): bool|string {
      if ($probe['error'] !== '') {
         return $probe['error'];
      }
      if (
         !str_starts_with($response, 'HTTP/1.1 200 ')
         || !str_ends_with($response, 'C5-HARNESS-OK')
      ) {
         return 'C5 fixture failed: the primary live harness response was not healthy.';
      }

      $Parse = static function (string $wire): null|array {
         $separator = strpos($wire, "\r\n\r\n");
         if ($separator === false) {
            return null;
         }

         $head = substr($wire, 0, $separator);
         $body = substr($wire, $separator + 4);
         if (preg_match('/^HTTP\/1\.1 (\d{3}) /', $head, $status) !== 1) {
            return null;
         }
         if (preg_match('/\r\nContent-Length: (\d+)\r\n/i', "\r\n{$head}\r\n", $length) !== 1) {
            return null;
         }

         return [
            'status' => (int) $status[1],
            'head' => $head,
            'body' => $body,
            'content_length' => (int) $length[1],
         ];
      };
      $control = $Parse($probe['control']);
      $attack = $Parse($probe['attack']);
      if ($control === null || $attack === null) {
         return 'C5 fixture failed: a side response could not be parsed.';
      }
      if (
         strlen($control['body']) !== $control['content_length']
         || strlen($attack['body']) !== $attack['content_length']
      ) {
         return 'C5 fixture failed: a side response violated its Content-Length.';
      }

      if (
         $control['status'] !== 206
         || preg_match(
            '/Content-Type: multipart\/byteranges; boundary=([^\r\n]+)/i',
            $control['head'],
            $controlBoundary,
         ) !== 1
      ) {
         return 'C5 fixture failed: the legitimate three-range control was not multipart 206.';
      }
      $boundary = $controlBoundary[1];
      $controlBody = $control['body'];
      $controlExpected = '';
      foreach ([[1, 2, 'bc'], [4, 5, 'ef'], [61, 61, '9']] as $part) {
         $controlExpected .= "\r\n--{$boundary}\n"
            . "Content-Type: application/octet-stream\n"
            . "Content-Range: bytes {$part[0]}-{$part[1]}/62\r\n\r\n"
            . $part[2];
      }
      $controlExpected .= "\r\n--{$boundary}--\r\n";
      if ($controlBody !== $controlExpected) {
         return 'C5 fixture failed: the legitimate three-range control body was incorrect.';
      }

      $file = dirname(__DIR__, 6)
         . '/projects/Demo/HTTP_Server_CLI/statics/image1.jpg';
      $fileBytes = @file_get_contents($file);
      if (!is_string($fileBytes) || strlen($fileBytes) !== 82651) {
         return 'C5 fixture failed: the 82,651-byte image fixture was unavailable.';
      }
      $fileSHA256 = hash('sha256', $fileBytes);
      if ($fileSHA256 !== '6f3b2b309777650742524afd50890ea44e3dfa42788bda276a56d9b253d3612a') {
         return 'C5 fixture failed: the image control hash changed.';
      }

      $status = $attack['status'];
      $attackBody = $attack['body'];
      if ($status === 206 && stripos($attack['head'], 'multipart/byteranges') !== false) {
         if (preg_match(
            '/Content-Type: multipart\/byteranges; boundary=([^\r\n]+)/i',
            $attack['head'],
            $attackBoundary,
         ) !== 1) {
            return 'C5 fixture failed: multipart attack response had no boundary.';
         }

         $boundary = $attackBoundary[1];
         $prepend = "\r\n--{$boundary}\n"
            . "Content-Type: application/octet-stream\n"
            . "Content-Range: bytes 0-82650/82651\r\n\r\n";
         $append = "\r\n--{$boundary}--\r\n";
         $cursor = 0;
         $copies = 0;
         for ($index = 0; $index < 32; $index++) {
            if (substr($attackBody, $cursor, strlen($prepend)) !== $prepend) {
               break;
            }
            $cursor += strlen($prepend);

            $partSHA256 = hash('sha256', substr($attackBody, $cursor, 82651));
            if (!hash_equals($fileSHA256, $partSHA256)) {
               break;
            }
            $cursor += 82651;
            $copies++;
         }
         $complete = $copies === 32
            && substr($attackBody, $cursor) === $append;

         if ($complete && $attack['content_length'] === 2648124) {
            $evidence = [
               'range_value_bytes' => 261,
               'ranges' => 32,
               'file_bytes' => 82651,
               'verified_file_copies' => $copies,
               'selected_payload_bytes' => 2644832,
               'multipart_body_bytes' => strlen($attackBody),
               'body_to_range_value_amplification' => round(strlen($attackBody) / 261, 2),
               'file_sha256' => $fileSHA256,
            ];

            return 'CONFIRMED C5; evidence=' . json_encode(
               $evidence,
               JSON_UNESCAPED_SLASHES,
            );
         }

         return 'C5 mitigation incomplete: duplicate ranges still produced multiple response parts.';
      }

      if (
         $status !== 416
         || preg_match(
            '/\r\nContent-Range: bytes \*\/82651\r\n/i',
            "\r\n{$attack['head']}\r\n",
         ) !== 1
         || $attackBody !== ' '
      ) {
         return 'C5 mitigation failed: the 32-member range set was not rejected with 416.';
      }

      $duplicate = $Parse($probe['duplicate']);
      $overlap = $Parse($probe['overlap']);
      $order = $Parse($probe['order']);
      $malformed = $Parse($probe['malformed']);
      if ($duplicate === null || $overlap === null || $order === null || $malformed === null) {
         return 'C5 fixture failed: a post-fix hardening response could not be parsed.';
      }
      if (
         strlen($duplicate['body']) !== $duplicate['content_length']
         || strlen($overlap['body']) !== $overlap['content_length']
         || strlen($order['body']) !== $order['content_length']
         || strlen($malformed['body']) !== $malformed['content_length']
      ) {
         return 'C5 fixture failed: a post-fix response violated its Content-Length.';
      }

      if (
         $duplicate['status'] !== 206
         || stripos($duplicate['head'], 'multipart/byteranges') !== false
         || preg_match(
            '/\r\nContent-Range: bytes 0-82650\/82651\r\n/i',
            "\r\n{$duplicate['head']}\r\n",
         ) !== 1
         || !hash_equals($fileSHA256, hash('sha256', $duplicate['body']))
      ) {
         return 'C5 mitigation failed: two full-file ranges were not coalesced into one copy.';
      }

      if (
         $overlap['status'] !== 206
         || stripos($overlap['head'], 'multipart/byteranges') !== false
         || preg_match(
            '/\r\nContent-Range: bytes 0-19\/62\r\n/i',
            "\r\n{$overlap['head']}\r\n",
         ) !== 1
         || $overlap['body'] !== 'abcdefghijklmnopqrst'
      ) {
         return 'C5 mitigation failed: overlapping and adjacent ranges were not coalesced.';
      }

      if (
         $order['status'] !== 206
         || preg_match(
            '/Content-Type: multipart\/byteranges; boundary=([^\r\n]+)/i',
            $order['head'],
            $orderBoundary,
         ) !== 1
      ) {
         return 'C5 mitigation failed: the contained-range order control was not multipart 206.';
      }
      $boundary = $orderBoundary[1];
      $orderExpected = "\r\n--{$boundary}\n"
         . "Content-Type: application/octet-stream\n"
         . "Content-Range: bytes 0-30/62\r\n\r\n"
         . 'abcdefghijklmnopqrstuvwxyzABCDE'
         . "\r\n--{$boundary}\n"
         . "Content-Type: application/octet-stream\n"
         . "Content-Range: bytes 40-49/62\r\n\r\n"
         . 'OPQRSTUVWX'
         . "\r\n--{$boundary}--\r\n";
      if ($order['body'] !== $orderExpected) {
         return 'C5 mitigation failed: coalescing reordered a contained range.';
      }

      if (
         $malformed['status'] !== 416
         || preg_match(
            '/\r\nContent-Range: bytes \*\/62\r\n/i',
            "\r\n{$malformed['head']}\r\n",
         ) !== 1
         || $malformed['body'] !== ' '
      ) {
         return 'C5 mitigation failed: an endpoint-less range was not rejected cleanly.';
      }

      return true;
   },
);
