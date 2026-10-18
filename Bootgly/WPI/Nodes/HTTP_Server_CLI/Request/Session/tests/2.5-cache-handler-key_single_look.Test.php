<?php

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Session\Handlers\Cache;


/**
 * WPI BUGS SESS-3 — the Session Cache key path is classified with one look.
 *
 * `Cache::load()` asked `is_file()` and then `file_exists()`: a worker that saw
 * no file, then saw the key a concurrent worker had just published, refused
 * it with "Session Cache key path must be a regular file." (CI on the 2.3
 * race; 1 in 4,800 loads with 16 workers on one core). The path is now one
 * `lstat()`: missing creates, a regular file imports, a symbolic link or any
 * other type is refused. Pinned:
 *
 * - on the tokens of `load()`: one `lstat()`, no `is_file()` / `file_exists()`
 *   (the window is microseconds wide — a structural pin is the deterministic
 *   one);
 * - by behaviour: a missing key is created owner-only and reused, a directory
 *   in its place is refused as not a regular file, a symbolic link as a link.
 */
return new Test(
   description: 'Session Cache key paths are classified with one look, so a key published mid-way is imported',
   skip: function_exists('openssl_encrypt') === false,

   test: function () {
      // @ The calls `load()` makes on the path
      $Method = new ReflectionMethod(Cache::class, 'load');
      $lines = (array) file((string) $Method->getFileName());
      $source = implode('', array_slice(
         $lines,
         $Method->getStartLine() - 1,
         $Method->getEndLine() - $Method->getStartLine() + 1
      ));
      $calls = [];
      $Tokens = token_get_all("<?php {$source}");
      foreach ($Tokens as $index => $Token) {
         if (is_array($Token) === false || $Token[0] !== T_STRING) {
            continue;
         }
         $next = $Tokens[$index + 1] ?? null;
         if ($next !== '(' || in_array($Token[1], ['lstat', 'is_file', 'file_exists', 'is_link'], true) === false) {
            continue;
         }
         $calls[$Token[1]] = ($calls[$Token[1]] ?? 0) + 1;
      }
      ksort($calls);
      $evidence = json_encode($calls);

      yield assert(
         assertion: $calls === ['lstat' => 1],
         description: "load() classifies the key path with one lstat() — no is_file()/file_exists() pair a concurrent publication can split: {$evidence}"
      );

      // @ What one look decides
      $suffix = bin2hex(random_bytes(12));
      $directory = BOOTGLY_STORAGE_DIR . "sessions/.cache-look-{$suffix}";
      $path = "{$directory}/key";
      $folder = "{$directory}/folder";
      $link = "{$directory}/link";
      $rows = [];

      try {
         $Load = new ReflectionMethod(Cache::class, 'load');
         $first = $Load->invoke(null, ['secret_path' => $path]);
         $state = @lstat($path);
         $rows['missing'] = [
            'created' => is_array($state) && ((int) $state['mode'] & 0777) === 0600,
            'reused' => $Load->invoke(null, ['secret_path' => $path]) === $first,
         ];

         @mkdir($folder, 0700);
         try {
            $Load->invoke(null, ['secret_path' => $folder]);
            $rows['directory'] = 'accepted';
         }
         catch (RuntimeException $Exception) {
            $rows['directory'] = $Exception->getMessage();
         }

         @symlink($path, $link);
         try {
            $Load->invoke(null, ['secret_path' => $link]);
            $rows['link'] = 'accepted';
         }
         catch (RuntimeException $Exception) {
            $rows['link'] = $Exception->getMessage();
         }
      }
      finally {
         @unlink($link);
         @rmdir($folder);
         @unlink($path);
         @rmdir($directory);
      }
      $evidence = json_encode($rows);

      yield assert(
         assertion: $rows === [
            'missing' => ['created' => true, 'reused' => true],
            'directory' => 'Session Cache key path must be a regular file.',
            'link' => 'Session Cache key path must not be a symbolic link.',
         ],
         description: "A missing key is created owner-only and reused; a directory is refused as not a regular file, a symbolic link as a link: {$evidence}"
      );
   }
);
