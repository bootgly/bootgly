<?php

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Session\Handlers\Cache;


return new Specification(
   description: 'Session Cache key paths preserve safe directories and reject replacement surfaces',
   skip: function_exists('openssl_encrypt') === false,

   test: function () {
      $suffix = bin2hex(random_bytes(12));
      $directory = BOOTGLY_STORAGE_DIR . "sessions/.cache-path-{$suffix}";
      $unsafeDirectory = BOOTGLY_STORAGE_DIR . "sessions/.cache-unsafe-{$suffix}";
      $path = "{$directory}/key";
      $link = "{$directory}/link";
      $unsafePath = "{$unsafeDirectory}/key";
      $symlinkMessage = '';
      $unsafeMessage = '';
      $emptyMessage = '';
      $roundtrip = false;

      try {
         if (@mkdir($directory, 0755) === false || @chmod($directory, 0755) === false) {
            throw new RuntimeException('Could not create the safe key-path fixture.');
         }
         if (
            @mkdir($unsafeDirectory, 0700) === false
            || @chmod($unsafeDirectory, 0777) === false
         ) {
            throw new RuntimeException('Could not create the unsafe key-path fixture.');
         }

         $Handler = new Cache([
            'driver' => 'memory',
            'secret_path' => $path,
         ]);
         $ID = bin2hex(random_bytes(16));
         $payload = serialize(['path' => 'preserved']);
         $roundtrip = $Handler->write($ID, $payload)
            && $Handler->read($ID) === $payload;

         $directoryState = @lstat($directory);
         $keyState = @lstat($path);

         if (@symlink($path, $link) === false) {
            throw new RuntimeException('Could not create the key symlink fixture.');
         }
         try {
            new Cache([
               'driver' => 'memory',
               'secret_path' => $link,
            ]);
         }
         catch (RuntimeException $Exception) {
            $symlinkMessage = $Exception->getMessage();
         }

         try {
            new Cache([
               'driver' => 'memory',
               'secret_path' => $unsafePath,
            ]);
         }
         catch (RuntimeException $Exception) {
            $unsafeMessage = $Exception->getMessage();
         }

         try {
            new Cache([
               'driver' => 'memory',
               'secret_path' => '',
            ]);
         }
         catch (InvalidArgumentException $Exception) {
            $emptyMessage = $Exception->getMessage();
         }
      }
      finally {
         @unlink($link);
         @unlink($path);
         @unlink($unsafePath);
         @chmod($unsafeDirectory, 0700);
         @rmdir($unsafeDirectory);
         @rmdir($directory);
         $cleaned = is_dir($directory) === false
            && is_dir($unsafeDirectory) === false;
      }

      yield assert(
         assertion: $roundtrip
            && is_array($directoryState ?? null)
            && ((int) $directoryState['mode'] & 0777) === 0755
            && is_array($keyState ?? null)
            && ((int) $keyState['mode'] & 0777) === 0600,
         description: 'A caller-owned safe directory keeps its mode while its key is owner-only'
      );
      yield assert(
         assertion: str_contains($symlinkMessage, 'must not be a symbolic link'),
         description: 'A symbolic-link key path is rejected: ' . $symlinkMessage
      );
      yield assert(
         assertion: str_contains($unsafeMessage, 'directory has unsafe metadata'),
         description: 'A group/world-writable key directory is rejected: ' . $unsafeMessage
      );
      yield assert(
         assertion: str_contains($emptyMessage, 'must be non-empty'),
         description: 'An empty key path is rejected before filesystem access: ' . $emptyMessage
      );
      yield assert(
         assertion: $cleaned,
         description: 'The key-path test removes all of its fixtures'
      );
   }
);
