<?php

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Validators\MIME;


/**
 * The MIME rule decides by the uploaded content, never by the client's
 * declared `type`, and only inspects a regular file the HTTP server wrote in
 * `BOOTGLY_UPLOADS_DIR` — a hand-built record cannot point it anywhere else.
 */

return new Test(
   description: 'It should allow an upload by its sniffed content, only from the uploads directory',
   test: new Assertions(Case: function (): Generator {
      if (is_dir(BOOTGLY_UPLOADS_DIR) === false) {
         mkdir(BOOTGLY_UPLOADS_DIR, 0700, true);
      }
      $PNG = (string) base64_decode(
         'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='
      );
      $prefix = BOOTGLY_UPLOADS_DIR . 'validators-1.4-' . bin2hex(random_bytes(6));
      $outside = sys_get_temp_dir() . '/validators-1.4-' . bin2hex(random_bytes(6));
      $sibling = rtrim(BOOTGLY_UPLOADS_DIR, '/') . '-' . bin2hex(random_bytes(6)) . '/';
      $paths = [
         'png' => "{$prefix}-png",
         'php' => "{$prefix}-php",
         'link' => "{$prefix}-link",
         'dir' => "{$prefix}-dir",
         'locked' => "{$prefix}-locked",
         'fifo' => "{$prefix}-fifo",
         'outside' => $outside,
         'sibling' => "{$sibling}png",
      ];
      $record = static fn (null|string $path, string $type = 'image/png', int $error = 0): array => [
         'name' => 'a.png',
         'size' => 67,
         'type' => $type,
         'error' => $error,
      ] + ($path === null ? [] : ['tmp_name' => $path]);

      try {
         file_put_contents($paths['png'], $PNG);
         file_put_contents($paths['php'], "<?php system(\$_GET['c']);\n");
         file_put_contents($paths['outside'], "plain text\n");
         symlink($paths['png'], $paths['link']);
         mkdir($paths['dir']);
         mkdir($sibling);
         file_put_contents($paths['sibling'], $PNG);
         file_put_contents($paths['locked'], $PNG);
         chmod($paths['locked'], 0000);

         $Image = new MIME(['image/png', 'image/jpeg']);
         $Text = new MIME('text/plain');

         // @ The content decides — never the declared type
         yield new Assertion(description: 'PHP bytes declared image/png are refused')
            ->expect($Image->validate('avatar', $record($paths['php']), []))
            ->to->be(false)
            ->assert();

         yield new Assertion(description: 'A real PNG declared text/plain is accepted')
            ->expect($Image->validate('avatar', $record($paths['png'], 'text/plain'), []))
            ->to->be(true)
            ->assert();

         // @ Only a file the server wrote in the uploads directory is inspected
         yield new Assertion(description: 'A record without tmp_name, or with an empty one, is refused')
            ->expect([$Image->validate('avatar', $record(null), []), $Image->validate('avatar', $record(''), [])])
            ->to->be([false, false])
            ->assert();

         yield new Assertion(description: 'A text file outside the uploads directory is refused')
            ->expect($Text->validate('avatar', $record($paths['outside'], 'text/plain'), []))
            ->to->be(false)
            ->assert();

         yield new Assertion(description: 'A traversal out of the uploads directory is refused')
            ->expect($Text->validate('avatar', $record(BOOTGLY_UPLOADS_DIR . '../../../../../../../../..' . $paths['outside'], 'text/plain'), []))
            ->to->be(false)
            ->assert();

         yield new Assertion(description: '/etc/passwd is refused')
            ->expect($Text->validate('avatar', $record('/etc/passwd', 'text/plain'), []))
            ->to->be(false)
            ->assert();

         yield new Assertion(description: 'A sibling directory sharing the prefix is refused')
            ->expect($Image->validate('avatar', $record($paths['sibling']), []))
            ->to->be(false)
            ->assert();

         yield new Assertion(description: 'A symlink inside the uploads directory is refused')
            ->expect($Image->validate('avatar', $record($paths['link']), []))
            ->to->be(false)
            ->assert();

         yield new Assertion(description: 'A directory inside the uploads directory is refused')
            ->expect(new MIME('directory')->validate('avatar', $record($paths['dir']), []))
            ->to->be(false)
            ->assert();

         yield new Assertion(description: 'A NUL byte in tmp_name is refused without an exception')
            ->expect($Image->validate('avatar', $record("{$paths['png']}\0.png"), []))
            ->to->be(false)
            ->assert();

         // ? Root reads anything: the unreadable leg only means something for other users
         if (posix_geteuid() !== 0) {
            yield new Assertion(description: 'An unreadable file (empty sniff) is refused')
               ->expect(new MIME(['image/png', ''])->validate('avatar', $record($paths['locked']), []))
               ->to->be(false)
               ->assert();
         }

         yield new Assertion(description: 'Control: an upload error is refused')
            ->expect($Image->validate('avatar', $record($paths['png'], 'image/png', 1), []))
            ->to->be(false)
            ->assert();

         // @ Only the integer UPLOAD_ERR_OK counts as an upload
         yield new Assertion(description: 'A string error `0` is refused')
            ->expect($Image->validate('avatar', ['error' => '0', 'tmp_name' => $paths['png']], []))
            ->to->be(false)
            ->assert();

         yield new Assertion(description: 'A record without `error` is refused')
            ->expect($Image->validate('avatar', ['tmp_name' => $paths['png']], []))
            ->to->be(false)
            ->assert();

         // @ The allowlist is normalized: case-insensitive, parameters ignored
         $Loose = new MIME('IMAGE/PNG; charset=binary');

         yield new Assertion(description: 'The allowlist is normalized and still matches')
            ->expect([$Loose->types, $Loose->validate('avatar', $record($paths['png']), [])])
            ->to->be([['image/png'], true])
            ->assert();

         // ! Run a PHP snippet in a child process, bounded: a sniff that blocks
         //   fails the case instead of hanging the suite
         $root = BOOTGLY_ROOT_DIR;
         $run = static function (string $code, array $options = [], array $environment = []) use ($root): string {
            $command = [PHP_BINARY];
            foreach ($options as $option) {
               $command[] = '-d';
               $command[] = $option;
            }
            $command[] = '-r';
            $command[] = $code;

            $Process = proc_open(
               $command,
               [1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']],
               $Pipes,
               BOOTGLY_ROOT_BASE,
               $environment + ['V14_AUTOBOOT' => "{$root}autoboot.php"] + (array) getenv()
            );
            if (is_resource($Process) === false) {
               return 'proc_open failed';
            }

            stream_set_blocking($Pipes[1], false);
            $output = '';
            $deadline = hrtime(true) + 5_000_000_000;
            // @@ Drain until the child exits or the deadline passes
            while (true) {
               $output .= (string) stream_get_contents($Pipes[1]);
               if (proc_get_status($Process)['running'] === false) {
                  break;
               }
               if (hrtime(true) > $deadline) {
                  proc_terminate($Process, 9);
                  $output .= 'timeout';
                  break;
               }
               usleep(10_000);
            }
            $output .= (string) stream_get_contents($Pipes[1]);
            fclose($Pipes[1]);
            proc_close($Process);

            return $output;
         };

         // @ A special file never reaches the sniffer — opening a FIFO blocks
         posix_mkfifo($paths['fifo'], 0600);
         $output = $run(
            'require getenv("V14_AUTOBOOT");'
            . 'var_export(new Bootgly\ADI\Validators\MIME("image/png")'
            . '->validate("avatar", ["error" => 0, "tmp_name" => getenv("V14_FIFO")], []));',
            environment: ['V14_FIFO' => $paths['fifo']]
         );

         yield new Assertion(description: "A FIFO inside the uploads directory is refused at once: {$output}")
            ->expect($output)
            ->to->be('false')
            ->assert();

         // @ Without fileinfo the rule is never built — it cannot sniff anything
         $output = $run(
            'require getenv("V14_AUTOBOOT");'
            . 'try { new Bootgly\ADI\Validators\MIME("image/png"); echo "built"; }'
            . 'catch (RuntimeException $E) { echo $E->getMessage(); }',
            ['disable_functions=mime_content_type']
         );

         yield new Assertion(description: "Without fileinfo the constructor throws: {$output}")
            ->expect($output)
            ->to->be('The MIME validator requires the fileinfo extension.')
            ->assert();

         // @ The uploads directory cannot be redirected before boot: the server
         //   deletes the files in it
         $output = $run(
            'define("BOOTGLY_UPLOADS_DIR", sys_get_temp_dir() . "/");'
            . 'try { require getenv("V14_AUTOBOOT"); echo "booted"; }'
            . 'catch (LogicException $E) { echo $E->getMessage(); }'
         );

         yield new Assertion(description: "A pre-defined BOOTGLY_UPLOADS_DIR stops the boot: {$output}")
            ->expect($output)
            ->to->be('BOOTGLY_UPLOADS_DIR cannot be pre-defined: it derives from BOOTGLY_STORAGE_DIR, and the server deletes the files in it.')
            ->assert();
      }
      finally {
         @chmod($paths['locked'], 0600);
         foreach ($paths as $name => $path) {
            $name === 'dir' ? @rmdir($path) : @unlink($path);
         }
         @rmdir($sibling);
      }
   })
);
