<?php

namespace Bootgly\ACI\Mail\tests\E2E;


use const OPENSSL_KEYTYPE_RSA;
use const SIGKILL;
use const STREAM_CRYPTO_METHOD_TLS_SERVER;
use const WNOHANG;
use function base64_encode;
use function count;
use function extension_loaded;
use function fclose;
use function file_put_contents;
use function fread;
use function function_exists;
use function fwrite;
use function in_array;
use function openssl_csr_new;
use function openssl_csr_sign;
use function openssl_pkey_export;
use function openssl_pkey_new;
use function openssl_x509_export;
use function pcntl_fork;
use function pcntl_waitpid;
use function posix_getpid;
use function posix_getppid;
use function posix_kill;
use function rtrim;
use function sha1;
use function sleep;
use function str_ends_with;
use function str_starts_with;
use function stream_context_create;
use function stream_select;
use function stream_socket_accept;
use function stream_socket_client;
use function stream_socket_enable_crypto;
use function stream_socket_server;
use function strpos;
use function strtoupper;
use function substr;
use function sys_get_temp_dir;
use function trim;
use function unlink;
use function usleep;
use RuntimeException;

use Bootgly\ACI\Logs\Data\Display;
use Bootgly\ACI\Tests\Suite;


return new Suite(
   // * Config
   autoBoot: function (Suite|null $Suite = null): true {
      Display::show(Display::NONE);

      // ? The suite forks a TLS-capable mock server — a missing extension
      //   must surface as skipped cases, never as a green empty suite
      $missing = match (true) {
         extension_loaded('openssl') === false => 'ext-openssl',
         function_exists('pcntl_fork') === false => 'ext-pcntl',
         function_exists('posix_getpid') === false => 'ext-posix',
         default => ''
      };
      if ($missing !== '') {
         // @@ Record every spec as skipped
         foreach ($Suite->tests as $test) {
            $Suite->skip("(missing {$missing})");
         }
         $Suite->summarize();

         return true;
      }

      // ! Self-signed certificate generated at boot (never committed)
      $Key = openssl_pkey_new([
         'private_key_bits' => 2048,
         'private_key_type' => OPENSSL_KEYTYPE_RSA
      ]);
      $CSR = $Key !== false
         ? openssl_csr_new(['commonName' => 'localhost'], $Key, ['digest_alg' => 'sha256'])
         : false;
      $X509 = ($CSR !== false && $CSR !== true)
         ? openssl_csr_sign($CSR, null, $Key, 1, ['digest_alg' => 'sha256'])
         : false;
      $certificate = '';
      $private = '';
      if (
         $Key === false || $X509 === false
         || openssl_x509_export($X509, $certificate) === false
         || openssl_pkey_export($Key, $private) === false
      ) {
         throw new RuntimeException('Mail E2E could not generate a self-signed certificate.');
      }

      $pem = sys_get_temp_dir() . '/bootgly-mail-e2e.pem';
      file_put_contents($pem, "{$certificate}{$private}");

      // @ Fork a scripted mock SMTP server:
      //   9998 = plain (scenario selected by the EHLO argument)
      //   9997 = implicit TLS · 9996 = multiline greeting
      //   9995 = 421 busy greeting · 9994 = greeting then silence (timeout)
      $parent = posix_getpid();
      $pid = pcntl_fork();
      if ($pid === 0) {
         $context = stream_context_create(['ssl' => [
            'local_cert' => $pem,
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true
         ]]);

         $listeners = [];
         foreach ([9998, 9997, 9996, 9995, 9994] as $port) {
            $scheme = $port === 9997 ? 'tls' : 'tcp';
            $listener = @stream_socket_server(
               "{$scheme}://127.0.0.1:{$port}", $errno, $error, context: $context
            );
            if ($listener === false) {
               exit(1);
            }
            $listeners[$port] = $listener;
         }

         // # Expected credential blobs
         $CREDENTIALS = [
            'plain' => base64_encode("\0user@example.com\0secret"),
            'username' => base64_encode('user@example.com'),
            'password' => base64_encode('secret'),
            'oauth' => base64_encode("user=user@example.com\x01auth=Bearer good-token\x01\x01")
         ];

         // # One scripted SMTP conversation (serial — the specs run serially)
         $serve = function ($conn, int $port) use ($CREDENTIALS): void {
            $buffer = '';

            $reply = function (string $line) use ($conn): void {
               @fwrite($conn, "{$line}\r\n");
            };
            $readline = function () use ($conn, &$buffer): null|string {
               while (($position = strpos($buffer, "\n")) === false) {
                  $bytes = @fread($conn, 4096);
                  if ($bytes === '' || $bytes === false) {
                     return null;
                  }
                  $buffer .= $bytes;
               }
               $line = substr($buffer, 0, $position);
               $buffer = substr($buffer, $position + 1);

               return rtrim($line, "\r");
            };

            // # Greeting (per port)
            if ($port === 9995) {
               $reply('421 4.3.2 mock too busy, try again later');
               fclose($conn);
               return;
            }
            if ($port === 9994) {
               $reply('220 mock.bootgly.test ESMTP slow');
               // @ Never answer the next command — the client must time out
               sleep(2);
               fclose($conn);
               return;
            }
            if ($port === 9996) {
               $reply('220-mock.bootgly.test welcomes you');
               $reply('220 mock.bootgly.test ESMTP ready');
            }
            else {
               $reply('220 mock.bootgly.test ESMTP MockSMTP ready');
            }

            $scenario = 'happy';
            $encrypted = $port === 9997;

            // @@ Command loop
            while (($line = $readline()) !== null) {
               $upper = strtoupper($line);

               if (str_starts_with($upper, 'EHLO')) {
                  $argument = trim(substr($line, 4));
                  if ($port === 9998 && $argument !== '') {
                     $scenario = $argument;
                  }

                  $capabilities = match (true) {
                     $scenario === 'starttls' && $encrypted === false => ['STARTTLS'],
                     $scenario === 'starttls' => ['SIZE 10485760', '8BITMIME', 'HELP'],
                     $scenario === 'no-starttls' => ['HELP'],
                     $scenario === 'auth-plain' => ['AUTH PLAIN LOGIN', '8BITMIME'],
                     $scenario === 'auth-login' => ['AUTH LOGIN', '8BITMIME'],
                     $scenario === 'auth-oauth' => ['AUTH PLAIN XOAUTH2', '8BITMIME'],
                     $scenario === 'auth-fail' => ['AUTH PLAIN LOGIN'],
                     $scenario === 'size' => ['SIZE 1024', '8BITMIME'],
                     $scenario === 'utf8' => ['SIZE 10485760', 'SMTPUTF8', '8BITMIME'],
                     default => ['SIZE 10485760', 'AUTH PLAIN LOGIN', '8BITMIME', 'ENHANCEDSTATUSCODES', 'HELP']
                  };

                  $reply('250-mock.bootgly.test greets you');
                  $count = count($capabilities);
                  foreach ($capabilities as $index => $capability) {
                     $separator = $index === $count - 1 ? ' ' : '-';
                     $reply("250{$separator}{$capability}");
                  }
               }
               elseif ($upper === 'STARTTLS') {
                  if ($encrypted === true || $scenario !== 'starttls') {
                     $reply('502 5.5.1 STARTTLS not available');
                     continue;
                  }
                  $reply('220 2.0.0 ready to start TLS');
                  $buffer = '';
                  $upgraded = @stream_socket_enable_crypto(
                     $conn, true, STREAM_CRYPTO_METHOD_TLS_SERVER
                  );
                  if ($upgraded !== true) {
                     fclose($conn);
                     return;
                  }
                  $encrypted = true;
               }
               elseif (str_starts_with($upper, 'AUTH ')) {
                  if ($scenario === 'auth-fail') {
                     $reply('535 5.7.8 authentication failed');
                  }
                  elseif (str_starts_with($upper, 'AUTH PLAIN ')) {
                     $blob = substr($line, 11);
                     $reply($blob === $CREDENTIALS['plain']
                        ? '235 2.7.0 authentication successful'
                        : '535 5.7.8 authentication credentials invalid');
                  }
                  elseif ($upper === 'AUTH LOGIN') {
                     $reply('334 VXNlcm5hbWU6');
                     $username = $readline();
                     $reply('334 UGFzc3dvcmQ6');
                     $password = $readline();
                     $reply($username === $CREDENTIALS['username'] && $password === $CREDENTIALS['password']
                        ? '235 2.7.0 authentication successful'
                        : '535 5.7.8 authentication credentials invalid');
                  }
                  elseif (str_starts_with($upper, 'AUTH XOAUTH2 ')) {
                     $blob = substr($line, 13);
                     if ($blob === $CREDENTIALS['oauth']) {
                        $reply('235 2.7.0 authentication successful');
                     }
                     else {
                        $challenge = base64_encode('{"status":"401","schemes":"Bearer"}');
                        $reply("334 {$challenge}");
                        $readline();
                        $reply('535 5.7.8 invalid bearer token');
                     }
                  }
                  else {
                     $reply('504 5.5.4 mechanism not supported');
                  }
               }
               elseif (str_starts_with($upper, 'MAIL')) {
                  $reply('250 2.1.0 sender ok');
               }
               elseif (str_starts_with($upper, 'RCPT')) {
                  if ($scenario === 'transient') {
                     $reply('450 4.2.0 mailbox busy');
                  }
                  elseif ($scenario === 'permanent') {
                     $reply('550 5.1.1 user unknown');
                  }
                  else {
                     $reply('250 2.1.5 recipient ok');
                  }
               }
               elseif ($upper === 'DATA') {
                  $reply('354 end data with <CRLF>.<CRLF>');
                  // @ Capture the raw wire payload until the terminator and
                  //   prove it back via its sha1 (dot-stuffing wire assertion)
                  $wire = $buffer;
                  $buffer = '';
                  while (str_ends_with($wire, "\r\n.\r\n") === false) {
                     $bytes = @fread($conn, 8192);
                     if ($bytes === '' || $bytes === false) {
                        fclose($conn);
                        return;
                     }
                     $wire .= $bytes;
                  }
                  $payload = substr($wire, 0, -3);   // strip `.\r\n`, keep the final CRLF
                  $hash = sha1($payload);
                  $reply("250 2.0.0 OK sha1={$hash}");
               }
               elseif ($upper === 'RSET') {
                  $reply('250 2.0.0 flushed');
               }
               elseif ($upper === 'NOOP') {
                  $reply('250 2.0.0 ok');
               }
               elseif ($upper === 'QUIT') {
                  $reply('221 2.0.0 bye');
                  break;
               }
               else {
                  $reply('500 5.5.2 command unrecognized');
               }
            }

            fclose($conn);
         };

         // @@ Accept loop (conversations handled inline — specs are serial)
         while (true) {
            $reads = [];
            foreach ($listeners as $listener) {
               $reads[] = $listener;
            }
            $writes = null;
            $excepts = null;
            $ready = @stream_select($reads, $writes, $excepts, 1);

            // ? Self-reap when the suite master is gone (orphaned accept loop
            //   would hold the CI step's pipes open)
            if (posix_getppid() !== $parent) {
               exit(0);
            }
            if ($ready === false || $ready === 0) {
               continue;
            }

            foreach ($listeners as $port => $listener) {
               if (in_array($listener, $reads, true) === false) {
                  continue;
               }
               // ! A TLS accept (9997) handshakes here; a probe/aborted
               //   handshake yields false — just keep accepting
               $conn = @stream_socket_accept($listener, 0);
               if ($conn === false) {
                  continue;
               }
               $serve($conn, $port);
            }
         }
         exit(0);
      }

      // @ Readiness probe (plain TCP on the 9998 listener)
      for ($i = 0; $i < 200; $i++) {
         $probe = @stream_socket_client('tcp://127.0.0.1:9998', $errno, $error, 0.05);
         if ($probe !== false) {
            fclose($probe);
            break;
         }
         usleep(25000);
      }

      // ? Fail loudly if the mock died (e.g. a port was not bindable) —
      //   otherwise the specs would run against whatever answered the probe
      if (pcntl_waitpid($pid, $status, WNOHANG) === $pid) {
         throw new RuntimeException(
            'Mail E2E mock SMTP server exited before the specs ran (ports 9994-9998 not bindable?).'
         );
      }

      try {
         $Suite->autoboot(__DIR__);
         $Suite->autoinstance(true);
         $Suite->summarize();
      }
      finally {
         posix_kill($pid, SIGKILL);
         pcntl_waitpid($pid, $status);
         @unlink($pem);
      }

      return true;
   },
   autoReport: true,
   suiteName: __NAMESPACE__,
   exitOnFailure: false,
   // * Data
   tests: [
      '1.1-send',
      '1.2-greeting',
      '2.1-starttls',
      '2.2-starttls-required',
      '2.3-tls',
      '2.4-verify',
      '3.1-auth-plain',
      '3.2-auth-login',
      '3.3-auth-xoauth2',
      '3.4-auth-failure',
      '3.5-auth-insecure',
      '4.1-transient',
      '4.2-permanent',
      '4.3-size',
      '4.4-encoding',
      '5.1-timeout',
      '6.1-message'
   ]
);
