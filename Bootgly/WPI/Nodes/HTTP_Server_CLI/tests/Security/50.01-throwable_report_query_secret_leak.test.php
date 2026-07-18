<?php

use Bootgly\ABI\Debugging\Data\Throwables;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


/**
 * Security PoC L1 — request query secrets must not enter throwable reporter
 * context.
 *
 * The request traverses the native HTTP test server and deliberately throws
 * from its real handler. A reporter installed in that worker records the
 * context received from Catcher::respond() through Throwables::notify(). The
 * exact throwable marker and the generated 500 response are controls against
 * mistaking a harness or reporter failure for disclosure.
 */
$secret = 'l1-reset-token-7f41c9e2';
$target = '/reset?token=' . rawurlencode($secret) . '&flow=magic-link';
$marker = 'L1 throwable reporter path probe';
$evidencePath = sys_get_temp_dir()
   . '/bootgly-security-l1-' . getmypid() . '-' . bin2hex(random_bytes(6)) . '.json';

return new Specification(
   description: 'Throwable reporter context must omit request query secrets',

   request: static function () use ($evidencePath, $target): string {
      @unlink($evidencePath);

      return "GET {$target} HTTP/1.1\r\n"
         . "Host: localhost\r\nConnection: close\r\n\r\n";
   },

   response: static function (Request $Request, Response $Response) use (
      $evidencePath,
      $marker,
   ): Response {
      Throwables::$reporters[] = static function (
         Throwable $Throwable,
         array $context,
      ) use ($evidencePath, $marker): void {
         if (
            $Throwable instanceof RuntimeException === false
            || $Throwable->getMessage() !== $marker
         ) {
            return;
         }

         $encoded = json_encode([
            'class' => $Throwable::class,
            'message' => $Throwable->getMessage(),
            'context' => $context,
         ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
         file_put_contents($evidencePath, $encoded, LOCK_EX);
      };

      throw new RuntimeException($marker);
   },

   test: static function (string $response) use (
      $evidencePath,
      $marker,
      $secret,
      $target,
   ): bool|string {
      $raw = @file_get_contents($evidencePath);
      @unlink($evidencePath);

      if (str_contains($response, 'HTTP/1.1 500 Internal Server Error') === false) {
         return 'L1 fixture did not traverse the real handler/Catcher 500 path.';
      }
      if ($raw === false || $raw === '') {
         return 'L1 fixture reporter did not capture the finding-specific throwable.';
      }

      try {
         $evidence = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
      }
      catch (JsonException $Exception) {
         return 'L1 fixture reporter emitted invalid evidence: ' . $Exception->getMessage();
      }

      $context = is_array($evidence['context'] ?? null)
         ? $evidence['context']
         : [];
      if (
         ($evidence['class'] ?? null) !== RuntimeException::class
         || ($evidence['message'] ?? null) !== $marker
         || ($context['method'] ?? null) !== 'GET'
         || ! is_string($context['peer'] ?? null)
         || $context['peer'] === ''
      ) {
         return 'L1 fixture did not prove reporter delivery of the expected request throwable context.';
      }

      if (($context['URI'] ?? null) === $target && str_contains($raw, $secret)) {
         return 'CONFIRMED L1: throwable reporter context contains the complete request target and reset token.';
      }

      if (str_contains($raw, $secret)) {
         return 'L1 reporter context still contains the reset token through an unexpected field.';
      }
      if (($context['URI'] ?? null) !== '/reset') {
         return 'L1 reporter context did not preserve the query-free request path.';
      }

      return true;
   },
);
