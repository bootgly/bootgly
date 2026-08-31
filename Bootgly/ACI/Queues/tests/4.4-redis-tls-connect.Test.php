<?php

use Bootgly\ACI\Tests\Suite\Test;


// ! The driver prefers ext-redis whenever it is loaded, which makes the native RESP path
//   unreachable in-process. Clearing the ini scan directory usually drops the extension;
//   probe a child to find out whether this machine can reach the path at all. The child
//   is the environment that decides, and it is not this one — every capability the run
//   needs (no ext-redis, pcntl, openssl for the handshake, sockets for the tuning block
//   the entry lives in) is probed THERE, so a host that cannot deliver is skipped by this
//   guard instead of being reported by the body, where a skip is indistinguishable from
//   a pass.
$PHP = PHP_BINARY;
$probe = @shell_exec(
   'PHP_INI_SCAN_DIR= ' . escapeshellarg($PHP)
      . ' -r ' . escapeshellarg(
         'echo extension_loaded("redis") ? "1" : "0",'
            . ' function_exists("pcntl_fork") ? "1" : "0",'
            . ' extension_loaded("openssl") ? "1" : "0",'
            . ' extension_loaded("sockets") ? "1" : "0";'
      ) . ' 2>/dev/null'
);
$native = trim((string) $probe) === '0111';

return new Test(
   description: 'Queues(Redis): a TLS connect on the native lane survives the socket tuning',
   skip: DIRECTORY_SEPARATOR === '\\'
      || function_exists('shell_exec') === false
      || extension_loaded('openssl') === false
      || $native === false,
   test: function () use ($PHP) {
      // ! A throwaway certificate for 127.0.0.1, minted here because `openssl.cafile`
      //   is INI_PERDIR — trust must be handed to the child on its command line, so the
      //   files have to exist before the child starts
      $dir = sys_get_temp_dir() . '/bootgly-redis-tls-' . bin2hex(random_bytes(4));
      $cnf = "{$dir}/openssl.cnf";
      $certificate = "{$dir}/cert.pem";
      $key = "{$dir}/key.pem";

      try {
         @mkdir($dir, 0700);
         @file_put_contents(
            $cnf,
            "[req]\ndistinguished_name = dn\nx509_extensions = v3\n[dn]\n"
               . "[v3]\nsubjectAltName = IP:127.0.0.1\n"
         );

         $options = ['config' => $cnf, 'x509_extensions' => 'v3', 'digest_alg' => 'sha256'];
         $Key = @openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'config' => $cnf,
         ]);
         $Request = $Key === false
            ? false
            : @openssl_csr_new(['commonName' => '127.0.0.1'], $Key, $options);
         $Certificate = $Request === false
            ? false
            : @openssl_csr_sign($Request, null, $Key, 2, $options);
         $minted = $Certificate !== false
            && @openssl_x509_export_to_file($Certificate, $certificate) === true
            && @openssl_pkey_export_to_file($Key, $key, null, ['config' => $cnf]) === true;

         yield assert(
            assertion: $minted === true,
            description: 'ext-openssl is loaded but could not mint a certificate: '
               . (string) @openssl_error_string()
         );

         if ($minted === false) {
            return;
         }

         // ! The stub and the driver run in a child without ext-redis, with the minted
         //   certificate as that process's whole CA bundle
         $script = __DIR__ . '/redis-tls.php';
         $output = @shell_exec(
            'PHP_INI_SCAN_DIR= ' . escapeshellarg($PHP)
               . ' -d ' . escapeshellarg("openssl.cafile={$certificate}")
               . ' -r ' . escapeshellarg('require $_SERVER["argv"][1] ?? "";')
               . ' ' . escapeshellarg($script)
               . ' ' . escapeshellarg($certificate)
               . ' ' . escapeshellarg($key) . ' 2>/dev/null'
         );
         $observed = json_decode(trim((string) $output), true);

         // ? The child refused a run the guard admitted, so the two disagree about the
         //   same environment. This must fail: a body-side skip is reported as a pass,
         //   and the case would then be green while measuring nothing at all.
         if (is_array($observed) === true && isset($observed['skip']) === true) {
            yield assert(
               assertion: false,
               description: 'The child refused a run this case was admitted for: '
                  . (string) $observed['skip']
            );

            return;
         }

         yield assert(
            assertion: is_array($observed) && isset($observed['tls'], $observed['tcp']),
            description: 'The stub-server probe produced no readable result: '
               . substr((string) $output, 0, 400)
         );

         if (is_array($observed) === false) {
            return;
         }

         $TLS = is_array($observed['tls'] ?? null) ? $observed['tls'] : [];
         $TCP = is_array($observed['tcp'] ?? null) ? $observed['tcp'] : [];

         // # A TLS connect survives the socket tuning and serves its first command
         //   QUEUE-13: socket_import_stream() cannot represent a TLS stream — it warns,
         //   and the framework escalates the warning into an ErrorException that used to
         //   escape connect() before AUTH, SELECT or any command reached the wire.
         yield assert(
            assertion: ($TLS['outcome'] ?? null) === 'served'
               && ($TLS['value'] ?? null) === 1,
            description: 'A native-lane TLS connect must serve, found: ' . json_encode($TLS)
         );

         // # …and the command actually crossed the TLS wire
         //   Pinned on the stub's transcript, so the section cannot pass by never
         //   reaching the handshake at all.
         $lines = is_array($TLS['transcript'] ?? null) ? $TLS['transcript'] : [];
         $crossed = false;

         foreach ($lines as $line) {
            if (str_starts_with((string) $line, '1|ZCARD tlsqueue:probe')) {
               $crossed = true;
            }
         }

         yield assert(
            assertion: $crossed === true,
            description: 'The command must cross the TLS wire, found: ' . json_encode($lines)
         );

         // # Control that must not move: the plain-TCP lane still tunes and serves
         $lines = is_array($TCP['transcript'] ?? null) ? $TCP['transcript'] : [];
         $crossed = false;

         foreach ($lines as $line) {
            if (str_starts_with((string) $line, '1|ZCARD tcpqueue:probe')) {
               $crossed = true;
            }
         }

         yield assert(
            assertion: ($TCP['outcome'] ?? null) === 'served'
               && ($TCP['value'] ?? null) === 1
               && $crossed === true,
            description: 'The plain-TCP control must be untouched, found: ' . json_encode($TCP)
         );
      }
      finally {
         @unlink($cnf);
         @unlink($certificate);
         @unlink($key);
         @rmdir($dir);
      }
   }
);
