<?php

use Bootgly\ABI\Debugging\Data\Throwables;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ACI\Tests\Temporaries;


/**
 * M9 — a throwable's text reaches the operator's terminal when it is rendered (the CLI debug
 * screen, a WebSocket handler error). Its message may echo a client's bytes, an anonymous
 * class name carries a NUL byte, and a trace frame names identifiers whose bytes PHP does
 * not escape: none of them may drive the terminal.
 */
return new Test(
   description: 'Throwables::render() shows every control character a throwable carries as visible text',
   test: function () {
      $verbosity = Throwables::$verbosity;
      $directory = Temporaries::reserve('throwables-controls');
      $file = "{$directory}/probe\xC2\x9B.php";

      // ! Anything a terminal acts on beyond SGR, TAB and LF
      $live = static fn (string $output): bool => preg_match('/\e(?!\[[0-9;]*m)|\xC2[\x80-\x9F]|[\x00-\x08\x0B-\x1A\x1C-\x1F\x7F]/', $output) === 1;

      try {
         // # The message
         Throwables::$verbosity = 1;
         $output = Throwables::render(
            new RuntimeException("m\e]52;c;UFdO\x07\xC2\x9By\x7F\nnext\tline"),
            Throwables::TARGET_CLI
         );
         yield assert(
            assertion: $live($output) === false
               && str_contains($output, 'm\u001b]52;c;UFdO\u0007\u009by\u007f' . "\nnext\tline"),
            description: 'the message is escaped in place and keeps its line feeds and tabs'
         );

         // # An anonymous class
         $output = Throwables::render(new class ('anonymous') extends Exception {}, Throwables::TARGET_CLI);
         yield assert(
            assertion: $live($output) === false && str_contains($output, ' Exception@anonymous '),
            description: 'an anonymous throwable is named without the NUL byte get_class() embeds'
         );

         // # A trace frame
         if (function_exists("probe\xC2\x9B") === false) {
            eval("function probe\xC2\x9B (): never { throw new LogicException('frame probe'); }");
         }
         try {
            ("probe\xC2\x9B")();
         }
         catch (LogicException $Exception) {
            Throwables::$verbosity = 3;
            $output = Throwables::render($Exception, Throwables::TARGET_CLI);
         }
         yield assert(
            assertion: $live($output) === false && str_contains($output, 'probe\u009b()'),
            description: 'a trace frame naming a function with a C1 byte is escaped'
         );

         // # A file path — the throw site and a trace frame
         file_put_contents($file, "<?php\nreturn static function (): never {\n   (static function (): never { throw new LogicException('file probe'); })();\n};\n");
         try {
            (require $file)();
         }
         catch (LogicException $Exception) {
            Throwables::$verbosity = 3;
            $output = Throwables::render($Exception, Throwables::TARGET_CLI);
         }
         yield assert(
            assertion: $live($output) === false && substr_count($output, 'probe\u009b.php') >= 2,
            description: 'the throw site and the trace frames name a file with a C1 byte escaped'
         );

         // # A message that is not UTF-8
         Throwables::$verbosity = 1;
         $output = Throwables::render(new RuntimeException("a\x9B2Jb\xFF"), Throwables::TARGET_CLI);
         yield assert(
            assertion: $live($output) === false && str_contains($output, ' a?2Jb? ') && mb_check_encoding($output, 'UTF-8'),
            description: 'a message that is not UTF-8 keeps printable ASCII only (a raw 0x9B is a CSI on a non-UTF-8 terminal)'
         );
      }
      finally {
         Throwables::$verbosity = $verbosity;
         @unlink($file);
         @rmdir($directory);
      }
   }
);
