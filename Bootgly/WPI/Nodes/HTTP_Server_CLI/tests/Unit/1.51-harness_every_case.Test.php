<?php

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ACI\Tests\Temporaries;
use Bootgly\API\Environment\Agent;


return new Test(
   description: 'The live E2E harness runs and records every case: a failure, a throwing request or a timeout never hides the cases after it',
   test: function () {
      // ? proc_open unavailable — nothing to spawn
      if (function_exists('proc_open') === false) {
         yield assert(assertion: true, description: 'Skipped: proc_open is unavailable');
         return;
      }
      // ? Nested probe guard — the child must never re-run this very suite
      if (getenv('BOOTGLY_TEST_HARNESS_PROBE') === '1') {
         yield assert(assertion: true, description: 'Skipped: nested harness probe');
         return;
      }

      // ! Free loopback ports: the child's live servers, and one that nothing
      //   listens on (the harness must fail loudly when it never connects)
      $free = static function (): int {
         $Listener = stream_socket_server('tcp://127.0.0.1:0');
         if ($Listener === false) {
            return 0;
         }
         $name = (string) stream_socket_get_name($Listener, false);
         fclose($Listener);

         return (int) substr($name, strrpos($name, ':') + 1);
      };
      $port = $free();
      $dead = $free();
      $hung = $free();
      $still = $free();
      if (in_array(0, [$port, $dead, $hung, $still], true) || count(array_unique([$port, $dead, $hung, $still])) !== 4) {
         yield assert(assertion: false, description: 'No free loopback ports');
         return;
      }

      // ! Agent environment — the child prints the JSON report
      $environment = getenv();
      foreach ([...array_merge(...array_values(Agent::MARKERS)), 'AI_AGENT', 'BOOTGLY_AGENT_STDOUT_REDIRECTED', 'BOOTGLY_TTY'] as $variable) {
         unset($environment[$variable]);
      }
      $environment['BOOTGLY_TEST_HARNESS_PROBE'] = '1';
      $environment['AI_AGENT'] = '1';

      // ! A consumer tree (exactly how a kit boots) holding one live E2E
      //   suite driven by the real harness — HTTP_Server_CLI::test() — over
      //   specs written here: pass, assertion failure, throwing request,
      //   multi-request throwing on its 2nd request, a response slower than
      //   the harness read timeout (a signal-proof busy wait: the worker's
      //   timer alarms cut a plain usleep() short), a declared skip, and a
      //   pass that must not read the slow response left on the previous
      //   connection
      $directory = Temporaries::reserve('harness-every-case');
      $entry = "{$directory}/bootgly";
      $app = "{$directory}/projects/App";
      $live = "{$app}/Live/tests";
      $gone = "{$app}/Dead/tests";
      $stuck = "{$app}/Hung/tests";
      $held = "{$app}/Stall/tests";
      $root = BOOTGLY_ROOT_DIR;
      $spec = static fn (string $body): string => "<?php\n\n"
         . "use Bootgly\\WPI\\Nodes\\HTTP_Server_CLI\\Request;\n"
         . "use Bootgly\\WPI\\Nodes\\HTTP_Server_CLI\\Response;\n"
         . "use Bootgly\\WPI\\Nodes\\HTTP_Server_CLI\\Tests\\Suite\\Test;\n\n"
         . "return new Test(\n{$body});\n";
      $get = static fn (string $path): string
         => "   request: fn (): string => \"GET {$path} HTTP/1.1\\r\\nHost: localhost\\r\\n\\r\\n\",\n";
      $answer = static fn (string $body, string $before = ''): string
         => "   response: function (Request \$Request, Response \$Response): Response {\n"
            . "      {$before}\n"
            . "      return \$Response(body: '{$body}');\n"
            . "   },\n";
      $expect = static fn (string $body): string
         => "   test: fn (string \$response): bool|string => str_ends_with(\$response, '{$body}') ?: 'probe expected {$body}',\n";
      $wait = '$until = microtime(true) + 8.0; while (microtime(true) < $until) { usleep(50_000); }';
      $stall = "   request: fn (): string => \"POST /w HTTP/1.1\\r\\nHost: localhost\\r\\nContent-Length: 10\\r\\n\\r\\n\",\n";
      $liveAutoboot = "<?php\n\n"
            . "use Bootgly\\ACI\\Logs\\Data\\Display;\n"
            . "use Bootgly\\ACI\\Tests\\Suite;\n"
            . "use Bootgly\\API\\Endpoints\\Server\\Modes;\n"
            . "use Bootgly\\WPI\\Nodes\\HTTP_Server_CLI;\n\n"
            . "return new Suite(\n"
            . "   autoBoot: function (Suite \$Suite): true {\n"
            . "      if (defined('BOOTGLY_PROJECT') === false) {\n"
            . "         define('BOOTGLY_PROJECT', require BOOTGLY_ROOT_DIR . 'projects/Demo/HTTP_Server_CLI/HTTP_Server_CLI.Project.php');\n"
            . "      }\n"
            . "      HTTP_Server_CLI::pretest(\$Suite, specs: __DIR__);\n"
            . "      \$Server = new HTTP_Server_CLI(Mode: Modes::Test);\n"
            . "      \$Server->configure(new HTTP_Server_CLI\\Configs(host: '127.0.0.1', port: {$port}, workers: 1));\n"
            . "      \$Server->start();\n"
            . "      try {\n"
            . "         Display::show(Display::NONE);\n"
            . "         \$Server->Commands->command('test');\n"
            . "      }\n"
            . "      finally {\n"
            . "         \$Server->Process->stopping = true;\n"
            . "         \$Server->Process->Children->terminate();\n"
            . "         \$Server->Process->State->clean();\n"
            . "      }\n"
            . "      return true;\n"
            . "   },\n"
            . "   suiteName: 'Live',\n"
            . "   tests: ['1.1-pass', '1.2-assert', '1.3-throw', '1.4-multi', '1.5-slow', '1.6-skip', '1.7-after', '1.8-skip-multi', '1.9-after-skip']\n"
            . ");\n";
      $files = [
         $entry => "<?php\n"
            . "define('BOOTGLY_WORKING_BASE', __DIR__);\n"
            . "define('BOOTGLY_WORKING_DIR', BOOTGLY_WORKING_BASE . DIRECTORY_SEPARATOR);\n"
            . "(include '{$root}autoboot.php') || exit(1);\n",
         "{$directory}/projects/Bootgly.projects.php" => "<?php\n\n"
            . "return ['App' => ['interfaces' => ['CLI']]];\n",
         "{$app}/tests/autoboot.php" => "<?php\n\n"
            . "use Bootgly\\ACI\\Tests\\Suites;\n\n"
            . "return new Suites(directories: ['Live/', 'Dead/', 'Hung/', 'Stall/']);\n",
         "{$live}/autoboot.php" => $liveAutoboot,
         // # The harness pointed at a port nothing listens on: it never
         //   connects, so no case can run
         "{$gone}/autoboot.php" => "<?php\n\n"
            . "use Bootgly\\ACI\\Logs\\Data\\Display;\n"
            . "use Bootgly\\ACI\\Tests\\Suite;\n"
            . "use Bootgly\\API\\Endpoints\\Server\\Modes;\n"
            . "use Bootgly\\WPI\\Nodes\\HTTP_Server_CLI;\n\n"
            . "return new Suite(\n"
            . "   autoBoot: function (Suite \$Suite): true {\n"
            . "      HTTP_Server_CLI::pretest(\$Suite, specs: __DIR__);\n"
            . "      \$Server = new HTTP_Server_CLI(Mode: Modes::Test);\n"
            . "      \$Server->configure(new HTTP_Server_CLI\\Configs(host: '127.0.0.1', port: {$dead}, workers: 1));\n"
            . "      Display::show(Display::NONE);\n"
            . "      (new ReflectionMethod(HTTP_Server_CLI::class, 'test'))->invoke(null, \$Server);\n"
            . "      return true;\n"
            . "   },\n"
            . "   suiteName: 'Dead',\n"
            . "   tests: ['2.1-first', '2.2-second']\n"
            . ");\n",
         "{$live}/1.1-pass.Test.php" => $spec($get('/a') . $answer('A') . $expect('A')),
         "{$live}/1.2-assert.Test.php" => $spec($get('/b') . $answer('B') . $expect('C')),
         "{$live}/1.3-throw.Test.php" => $spec(
            "   request: function (): string { throw new \\RuntimeException('probe request throw'); },\n"
               . $answer('T') . $expect('T')
         ),
         "{$live}/1.4-multi.Test.php" => $spec(
            "   requests: [\n"
               . "      fn (): string => \"GET /m HTTP/1.1\\r\\nHost: localhost\\r\\n\\r\\n\",\n"
               . "      function (): string { throw new \\RuntimeException('probe multi throw'); },\n"
               . "   ],\n"
               . $answer('M')
               . "   test: fn (array \$responses): bool => true,\n"
         ),
         "{$live}/1.5-slow.Test.php" => $spec($get('/s') . $answer('S', '$until = microtime(true) + 2.5; while (microtime(true) < $until) { usleep(50_000); }') . $expect('S')),
         "{$live}/1.6-skip.Test.php" => $spec("   skip: true,\n" . $get('/k') . $answer('K') . $expect('K')),
         "{$live}/1.7-after.Test.php" => $spec($get('/z') . $answer('Z') . $expect('Z')),
         // # A declared skip that holds two dispatch slots, then a pass that
         //   must still reach its own handler
         "{$live}/1.8-skip-multi.Test.php" => $spec(
            "   skip: true,\n"
               . "   requests: [\n"
               . "      fn (): string => \"GET /x HTTP/1.1\\r\\nHost: localhost\\r\\n\\r\\n\",\n"
               . "      fn (): string => \"GET /x HTTP/1.1\\r\\nHost: localhost\\r\\n\\r\\n\",\n"
               . "   ],\n"
               . $answer('X')
               . "   test: fn (array \$responses): bool => true,\n"
         ),
         "{$live}/1.9-after-skip.Test.php" => $spec($get('/y') . $answer('Y') . $expect('Y')),
         // # A server that stops answering: every request busy-waits past the
         //   read timeout, so after three consecutive timeouts the harness
         //   stops instead of making each later case wait out its own
         "{$stuck}/autoboot.php" => str_replace(
            ["port: {$port}", "suiteName: 'Live'", "tests: ['1.1-pass', '1.2-assert', '1.3-throw', '1.4-multi', '1.5-slow', '1.6-skip', '1.7-after', '1.8-skip-multi', '1.9-after-skip']"],
            ["port: {$hung}", "suiteName: 'Hung'", "tests: ['3.1-hung', '3.2-hung', '3.3-hung', '3.4-hung', '3.5-hung']"],
            $liveAutoboot
         ),
         "{$stuck}/3.1-hung.Test.php" => $spec($get('/h') . $answer('H', $wait) . $expect('H')),
         "{$stuck}/3.2-hung.Test.php" => $spec($get('/h') . $answer('H', $wait) . $expect('H')),
         "{$stuck}/3.3-hung.Test.php" => $spec($get('/h') . $answer('H', $wait) . $expect('H')),
         "{$stuck}/3.4-hung.Test.php" => $spec($get('/h') . $answer('H', $wait) . $expect('H')),
         "{$stuck}/3.5-hung.Test.php" => $spec($get('/h') . $answer('H', $wait) . $expect('H')),
         // # Requests the server holds open on purpose (a body that never
         //   comes) while it keeps serving others: three timeouts in a row
         //   (a throw in between does not reset the count — the server was
         //   never asked), then the liveness probe answers and the run goes
         //   on to the last case
         "{$held}/autoboot.php" => str_replace(
            ["port: {$port}", "suiteName: 'Live'", "tests: ['1.1-pass', '1.2-assert', '1.3-throw', '1.4-multi', '1.5-slow', '1.6-skip', '1.7-after', '1.8-skip-multi', '1.9-after-skip']"],
            ["port: {$still}", "suiteName: 'Stall'", "tests: ['4.1-stall', '4.2-stall', '4.3-throw', '4.4-stall', '4.5-pass']"],
            $liveAutoboot
         ),
         "{$held}/4.1-stall.Test.php" => $spec($stall . $answer('W') . $expect('W')),
         "{$held}/4.2-stall.Test.php" => $spec($stall . $answer('W') . $expect('W')),
         "{$held}/4.3-throw.Test.php" => $spec(
            "   request: function (): string { throw new \\RuntimeException('stall probe throw'); },\n"
               . $answer('T') . $expect('T')
         ),
         "{$held}/4.4-stall.Test.php" => $spec($stall . $answer('W') . $expect('W')),
         "{$held}/4.5-pass.Test.php" => $spec($get('/p') . $answer('P') . $expect('P')),
         "{$gone}/2.1-first.Test.php" => $spec($get('/d1') . $answer('D1') . $expect('D1')),
         "{$gone}/2.2-second.Test.php" => $spec($get('/d2') . $answer('D2') . $expect('D2')),
      ];

      try {
         mkdir("{$app}/tests", 0o700, true);
         mkdir($live, 0o700, true);
         mkdir($gone, 0o700, true);
         mkdir($stuck, 0o700, true);
         mkdir($held, 0o700, true);
         foreach ($files as $file => $contents) {
            file_put_contents($file, $contents);
         }

         // @ Run the consumer tree as an agent
         $Process = proc_open(
            [PHP_BINARY, '-d', 'opcache.jit=0', $entry, 'test'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $app,
            $environment
         );
         $output = '';
         $status = -1;
         if (is_resource($Process)) {
            /** @var array<int,resource> $pipes */
            $output = (string) stream_get_contents($pipes[1]);
            stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $status = proc_close($Process);
         }

         // ! The report is the last JSON document on stdout
         $position = strrpos($output, "{\"result\"");
         $document = json_decode(trim($position === false ? $output : substr($output, $position)), true);
         $report = is_array($document) ? $document : [];
         $failures = [];
         $down = [];
         $stalled = [];
         $held = [];
         foreach (is_array($report['failures'] ?? null) ? $report['failures'] : [] as $failure) {
            if (is_array($failure) === false) {
               continue;
            }
            $record = [(string) ($failure['file'] ?? ''), (string) ($failure['message'] ?? '')];
            $case = (int) ($failure['case'] ?? -1);
            match ($failure['suite'] ?? null) {
               'Dead' => $down[$case] = $record,
               'Hung' => $stalled[$case] = $record,
               'Stall' => $held[$case] = $record,
               default => $failures[$case] = $record,
            };
         }

         // ! Live: 9 cases — 3 passed, 4 failed, 2 declared skips; Dead: the
         //   never-connected failure (case 0) plus its 2 cases not reached;
         //   Hung: 3 timed-out cases, then 2 not reached; Stall: 3 timeouts
         //   and a throw, then the last case still runs and passes
         yield assert(
            assertion: ($report['cases'] ?? null) === ['total' => 22, 'failed' => 12, 'skipped' => 6, 'passed' => 4],
            description: 'Every registered case is run and recorded, and a harness that never connects still accounts for its cases'
         );

         yield assert(
            assertion: array_keys($down) === [0]
               && str_contains($down[0][1], 'RuntimeException: E2E harness never connected'),
            description: 'A harness that never connected fails loudly as a suite-level failure (case 0)'
         );

         yield assert(
            assertion: array_keys($stalled) === [1, 2, 3]
               && str_contains($stalled[1][1], 'the connection expired before a complete response')
               && str_contains($stalled[3][1], 'the server stopped answering (3 consecutive timeouts and an unanswered liveness probe)'),
            description: 'A server that stops answering: each timeout is named, and the harness stops after three and an unanswered probe'
         );

         yield assert(
            assertion: array_keys($held) === [1, 2, 3, 4]
               && str_contains($held[4][1], 'the connection expired before a complete response')
               && str_contains($held[4][1], 'stopped answering') === false,
            description: 'Requests held open by a live server are timeouts, not a stop: the probe answers and every case runs'
         );

         yield assert(
            assertion: array_keys($failures) === [2, 3, 4, 5]
               && $failures[2][0] === '1.2-assert'
               && $failures[3][0] === '1.3-throw'
               && $failures[4][0] === '1.4-multi'
               && $failures[5][0] === '1.5-slow',
            description: 'Each failure is attributed to its own case and file'
         );

         yield assert(
            assertion: str_contains($failures[2][1] ?? '', 'probe expected C')
               && str_contains($failures[3][1] ?? '', 'RuntimeException: probe request throw')
               && str_contains($failures[4][1] ?? '', 'request #2: RuntimeException: probe multi throw'),
            description: 'A failure names its cause: the assertion, or the Throwable a request closure raised'
         );

         yield assert(
            assertion: $status === 1 && ($report['result'] ?? null) === 'failed',
            description: 'The run still fails (exit 1, result failed)'
         );
      }
      finally {
         // @ Tear the tree down
         $paths = glob("{$directory}/{,*/,*/*/,*/*/*/,*/*/*/*/,*/*/*/*/*/}*", GLOB_BRACE) ?: [];
         rsort($paths);
         foreach ($paths as $path) {
            is_dir($path) && is_link($path) === false ? @rmdir($path) : @unlink($path);
         }
         @rmdir($directory);
      }
   }
);
