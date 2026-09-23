<?php

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ACI\Tests\Temporaries;
use Bootgly\API\Environment\Agent;


return new Test(
   description: 'The client E2E harness honors skip: on both cursors, records a throwing request as a failed case, and reports the cases after it as not reached',
   test: function () {
      // ? proc_open unavailable — nothing to spawn
      if (function_exists('proc_open') === false) {
         yield assert(assertion: true, description: 'Skipped: proc_open is unavailable');
         return;
      }
      // ? Nested probe guard — the child must never re-run this very suite
      if (getenv('BOOTGLY_TEST_CLIENT_HARNESS_PROBE') === '1') {
         yield assert(assertion: true, description: 'Skipped: nested client harness probe');
         return;
      }

      // ! A free loopback port for the child's mock server
      $Listener = stream_socket_server('tcp://127.0.0.1:0');
      if ($Listener === false) {
         yield assert(assertion: false, description: 'No free loopback port');
         return;
      }
      $name = (string) stream_socket_get_name($Listener, false);
      $port = (int) substr($name, strrpos($name, ':') + 1);
      fclose($Listener);

      // ! Agent environment — the child prints the JSON report
      $environment = getenv();
      foreach ([...array_merge(...array_values(Agent::MARKERS)), 'AI_AGENT', 'BOOTGLY_AGENT_STDOUT_REDIRECTED', 'BOOTGLY_TTY'] as $variable) {
         unset($environment[$variable]);
      }
      $environment['BOOTGLY_TEST_CLIENT_HARNESS_PROBE'] = '1';
      $environment['AI_AGENT'] = '1';

      // ! A consumer tree (exactly how a kit boots) holding one client E2E
      //   suite driven by the real harness — HTTP_Client_CLI::test() and its
      //   forked lock-step mock: a pass, a declared skip, a pass that must get
      //   ITS response (the mock stepped over the skip too), a throwing
      //   request, and a case the stop leaves unreached
      $directory = Temporaries::reserve('client-harness-lock-step');
      $entry = "{$directory}/bootgly";
      $app = "{$directory}/projects/App";
      $suite = "{$app}/Client/tests";
      $root = BOOTGLY_ROOT_DIR;
      $spec = static fn (string $body, string $request = ''): string => "<?php\n\n"
         . "use Bootgly\\WPI\\Nodes\\HTTP_Client_CLI;\n"
         . "use Bootgly\\WPI\\Nodes\\HTTP_Client_CLI\\Request\\Response;\n"
         . "use Bootgly\\WPI\\Nodes\\HTTP_Client_CLI\\Tests\\Suite\\Test;\n\n"
         . "return new Test(\n"
         . ($request === '' ? '' : "   skip: {$request},\n")
         . "   response: fn (): string => \"HTTP/1.1 200 OK\\r\\nContent-Length: " . strlen($body) . "\\r\\nConnection: close\\r\\n\\r\\n{$body}\",\n"
         . "   request: fn (HTTP_Client_CLI \$Client): Response => \$Client->request(method: 'GET', URI: '/{$body}'),\n"
         . "   test: fn (Response \$Response): bool|string => \$Response->Body->raw === '{$body}' ?: 'client probe expected {$body}',\n"
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
            . "return new Suites(directories: ['Client/']);\n",
         "{$suite}/autoboot.php" => "<?php\n\n"
            . "use Bootgly\\ACI\\Tests\\Suite;\n"
            . "use Bootgly\\WPI\\Nodes\\HTTP_Client_CLI;\n\n"
            . "return new Suite(\n"
            . "   autoBoot: function (Suite \$Suite): true {\n"
            . "      HTTP_Client_CLI::pretest(\$Suite, specs: __DIR__);\n"
            . "      HTTP_Client_CLI::test({$port});\n"
            . "      return true;\n"
            . "   },\n"
            . "   suiteName: 'Client',\n"
            . "   tests: ['1.1-pass', '1.2-skip', '1.3-after-skip', '1.4-throw', '1.5-unreached']\n"
            . ");\n",
         "{$suite}/1.1-pass.Test.php" => $spec('A'),
         "{$suite}/1.2-skip.Test.php" => $spec('S', 'true'),
         "{$suite}/1.3-after-skip.Test.php" => $spec('B'),
         "{$suite}/1.4-throw.Test.php" => "<?php\n\n"
            . "use Bootgly\\WPI\\Nodes\\HTTP_Client_CLI\\Tests\\Suite\\Test;\n\n"
            . "return new Test(\n"
            . "   response: fn (): string => \"HTTP/1.1 200 OK\\r\\nContent-Length: 1\\r\\nConnection: close\\r\\n\\r\\nT\",\n"
            . "   request: function (): never { throw new \\RuntimeException('client probe throw'); },\n"
            . "   test: fn (): bool => true,\n"
            . ");\n",
         "{$suite}/1.5-unreached.Test.php" => $spec('U'),
      ];

      try {
         mkdir("{$app}/tests", 0o700, true);
         mkdir($suite, 0o700, true);
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
         if (is_resource($Process)) {
            /** @var array<int,resource> $pipes */
            $output = (string) stream_get_contents($pipes[1]);
            stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($Process);
         }

         // ! The report is the last JSON document on stdout
         $position = strrpos($output, "{\"result\"");
         $document = json_decode(trim($position === false ? $output : substr($output, $position)), true);
         $report = is_array($document) ? $document : [];
         $failures = is_array($report['failures'] ?? null) ? $report['failures'] : [];
         $failure = is_array($failures[0] ?? null) ? $failures[0] : [];

         yield assert(
            assertion: ($report['cases'] ?? null) === ['total' => 5, 'failed' => 1, 'skipped' => 2, 'passed' => 2],
            description: 'Every registered case is accounted for: 2 passed, the throw failed, the declared skip and the unreached case skipped'
         );

         yield assert(
            assertion: count($failures) === 1
               && ($failure['case'] ?? null) === 4
               && ($failure['file'] ?? null) === '1.4-throw'
               && str_contains((string) ($failure['message'] ?? ''), 'request: RuntimeException: client probe throw'),
            description: 'A throwing request is the failed case, with its cause — and the case after the skip got its own response'
         );
      }
      finally {
         // @ Tear the tree down
         $paths = glob("{$directory}/{,*/,*/*/,*/*/*/,*/*/*/*/}*", GLOB_BRACE) ?: [];
         rsort($paths);
         foreach ($paths as $path) {
            is_dir($path) && is_link($path) === false ? @rmdir($path) : @unlink($path);
         }
         @rmdir($directory);
      }
   }
);
