<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Resources\Storage\tests\fixtures;


use const ENT_QUOTES;
use const ENT_XML1;
use const FILE_APPEND;
use const FILE_IGNORE_NEW_LINES;
use const FILE_SKIP_EMPTY_LINES;
use const LIBXML_NONET;
use const LOCK_EX;
use const LOCK_UN;
use const SIG_DFL;
use const SIG_IGN;
use const SIGINT;
use const SIGKILL;
use const SIGPIPE;
use const SIGTERM;
use const SORT_STRING;
use const STREAM_SERVER_BIND;
use const STREAM_SERVER_LISTEN;
use const WNOHANG;
use function abs;
use function array_filter;
use function array_key_exists;
use function array_keys;
use function array_map;
use function array_pad;
use function array_slice;
use function array_values;
use function base64_decode;
use function base64_encode;
use function basename;
use function bin2hex;
use function count;
use function dirname;
use function explode;
use function fclose;
use function feof;
use function file;
use function file_get_contents;
use function file_put_contents;
use function filemtime;
use function filesize;
use function flock;
use function fopen;
use function fread;
use function ftruncate;
use function fwrite;
use function getmypid;
use function glob;
use function gmdate;
use function gmmktime;
use function hash;
use function hash_equals;
use function hash_hmac;
use function hex2bin;
use function htmlspecialchars;
use function implode;
use function in_array;
use function ini_set;
use function is_array;
use function is_dir;
use function is_file;
use function is_string;
use function json_decode;
use function json_encode;
use function libxml_clear_errors;
use function libxml_use_internal_errors;
use function ltrim;
use function max;
use function md5;
use function md5_file;
use function microtime;
use function min;
use function mkdir;
use function pcntl_fork;
use function pcntl_signal;
use function pcntl_waitpid;
use function posix_getppid;
use function posix_kill;
use function preg_match;
use function preg_replace;
use function random_bytes;
use function rawurldecode;
use function rawurlencode;
use function rename;
use function rewind;
use function rmdir;
use function rtrim;
use function scandir;
use function sha1;
use function simplexml_load_string;
use function sort;
use function str_contains;
use function str_repeat;
use function str_starts_with;
use function strcmp;
use function stream_copy_to_stream;
use function stream_get_contents;
use function stream_select;
use function stream_socket_accept;
use function stream_socket_get_name;
use function stream_socket_server;
use function strlen;
use function strpos;
use function strrpos;
use function strtolower;
use function strtr;
use function substr;
use function time;
use function touch;
use function trim;
use function unlink;
use function usleep;
use function usort;
use RuntimeException;
use Throwable;


/**
 * An in-test fake of the S3 REST API on 127.0.0.1 (path-style, plain HTTP).
 *
 * The listening socket is bound to an ephemeral port here, then a forked child serves it
 * (several connections at once, `stream_select`) until `stop()` — or until the process that
 * started it is gone. State lives in a directory both processes share: objects, open
 * multipart uploads, a request log and scripted faults.
 *
 * It answers PutObject (and CopyObject through `x-amz-copy-source`), HeadObject, GetObject
 * (with `Range` → 206 + `Content-Range`, or 416), DeleteObject, ListObjectsV2 (prefix,
 * delimiter, continuation tokens), CreateMultipartUpload, UploadPart, ListParts,
 * CompleteMultipartUpload and AbortMultipartUpload, with S3 XML errors.
 *
 * Every request must be signed with AWS Signature Version 4 — header auth or presigned
 * query auth. The signature is recomputed here from the request as it arrived, with a
 * verifier written from the specification and independent of the framework's signer, so a
 * canonicalization defect in the code under test shows up as a 403. As on AWS: every
 * `x-amz-*` header sent must be signed, the body must match `x-amz-content-sha256`, the
 * credential's day is the day of the request, an expired presigned URL and a clock skew
 * over 15 minutes answer 403, and an upload that declares a `Content-MD5` must match it.
 *
 * `inject()` scripts failures for the next calls of one operation: an S3 `<Error>` under any
 * status (200 keeps the status: "200 with an Error body"), a dropped connection (status 0),
 * or a raw response sent verbatim (truncated bodies, malformed XML, a lying `Content-Range`).
 */
final class S3
{
   public const string KEY = 'FAKEACCESSKEYEXAMPLE';
   public const string SECRET = 'fake/secret+key/EXAMPLE0123456789abcdefgh';
   public const string REGION = 'us-east-1';
   private const int SKEW = 900;

   // * Config
   public private(set) int $port;
   public private(set) string $bucket;
   /** Keys per ListObjectsV2 page. */
   public private(set) int $page;
   /** Parts per ListParts page. */
   public private(set) int $parts;
   /** Whether every part but the last must have the same size (the Cloudflare R2 rule). */
   public private(set) bool $equal;

   // * Metadata
   /** State directory, shared with the forked server. */
   private string $directory;
   private int $PID = 0;
   /** The process that started the server — the only one that stops it. */
   private int $owner;


   private function __construct (string $directory, string $bucket, int $page, int $parts, bool $equal)
   {
      // * Config
      $this->port = 0;
      $this->bucket = $bucket;
      $this->page = $page;
      $this->parts = $parts;
      $this->equal = $equal;
      // * Metadata
      $this->directory = rtrim($directory, '/') . '/';
      $this->owner = (int) getmypid();
   }

   /**
    * Binds an ephemeral port on 127.0.0.1, forks the server and returns once it listens.
    *
    * @param string $directory An existing, empty directory for the fake's state (the caller removes it).
    */
   public static function start (
      string $directory,
      string $bucket = 'test-bucket',
      int $page = 1000,
      int $parts = 1000,
      bool $equal = false
   ): self
   {
      $S3 = new self($directory, $bucket, $page, $parts, $equal);

      // ! Shared state
      foreach (['objects', 'uploads'] as $folder) {
         if (@mkdir("{$S3->directory}{$folder}", 0700, true) === false && is_dir("{$S3->directory}{$folder}") === false) {
            throw new RuntimeException("S3 fixture: cannot create {$S3->directory}{$folder}");
         }
      }
      touch("{$S3->directory}requests.log");
      file_put_contents("{$S3->directory}faults.json", '[]');

      // ! Bound before the fork: when start() returns, connections queue up
      $Server = @stream_socket_server('tcp://127.0.0.1:0', $errno, $error, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN);
      if ($Server === false) {
         throw new RuntimeException("S3 fixture: cannot listen on 127.0.0.1 ({$error})");
      }
      $name = (string) stream_socket_get_name($Server, false);
      $S3->port = (int) substr($name, (int) strrpos($name, ':') + 1);

      $parent = (int) getmypid();
      $PID = pcntl_fork();
      // ? A failed fork must never hand back -1: stop() would signal every process we own
      if ($PID < 0) {
         fclose($Server);

         throw new RuntimeException('S3 fixture: fork failed');
      }

      // @ Child: serve, then die without running the parent's shutdown functions or
      //   destructors (the test report, inherited output buffers)
      if ($PID === 0) {
         pcntl_signal(SIGTERM, SIG_DFL);
         pcntl_signal(SIGINT, SIG_DFL);
         pcntl_signal(SIGPIPE, SIG_IGN);
         ini_set('memory_limit', '-1');
         try {
            $S3->serving($Server, $parent);
         }
         catch (Throwable) {
            // ! A crashed server just goes away: the case sees connection errors
         }
         posix_kill((int) getmypid(), SIGKILL);
         exit(0);
      }

      fclose($Server);
      $S3->PID = $PID;

      // :
      return $S3;
   }

   /**
    * Kills and reaps the server. Idempotent; a forked case never stops its parent's server.
    */
   public function stop (): void
   {
      // ?
      if ($this->PID <= 0 || getmypid() !== $this->owner) {
         return;
      }

      posix_kill($this->PID, SIGTERM);
      $deadline = microtime(true) + 2.0;
      // @@ Reap; SIGKILL after 2 s
      while (pcntl_waitpid($this->PID, $status, WNOHANG) === 0) {
         if (microtime(true) > $deadline) {
            posix_kill($this->PID, SIGKILL);
            pcntl_waitpid($this->PID, $status);

            break;
         }
         usleep(10_000);
      }
      $this->PID = 0;
   }

   public function __destruct ()
   {
      $this->stop();
   }

   /**
    * The driver options of a disk pointed at this fake (plain HTTP, so `insecure`).
    *
    * @param array<string,mixed> $options Overrides (`token`, `secret`, ...).
    *
    * @return array<string,mixed>
    */
   public function configure (array $options = []): array
   {
      // :
      return $options + [
         'bucket' => $this->bucket,
         'region' => self::REGION,
         'key' => self::KEY,
         'secret' => self::SECRET,
         'endpoint' => "http://127.0.0.1:{$this->port}",
         'insecure' => true,
      ];
   }

   /**
    * Puts an object straight into the store (no request, not logged).
    */
   public function seed (string $key, string $bytes, string $type = 'binary/octet-stream'): void
   {
      $this->save($key, $bytes, $type, '"' . md5($bytes) . '"', []);
   }

   /**
    * The stored bytes of an object, or null.
    */
   public function fetch (string $key): null|string
   {
      $file = $this->locate($key);
      $bytes = is_file("{$file}.bin") === true ? file_get_contents("{$file}.bin") : false;

      // :
      return $bytes === false ? null : $bytes;
   }

   /**
    * The stored description of an object (`key`, `type`, `etag`, `meta`), or null.
    *
    * @return null|array{key:string,type:string,etag:string,meta:array<string,string>}
    */
   public function describe (string $key): null|array
   {
      $file = $this->locate($key);
      $meta = is_file("{$file}.json") === true ? json_decode((string) file_get_contents("{$file}.json"), associative: true) : null;

      // :
      return is_array($meta) === true ? $meta : null;
   }

   /**
    * The request log, oldest first.
    *
    * @return array<int,array{method:string,target:string,headers:array<string,string>,auth:string,operation:string,status:int,length:int,body:string}>
    */
   public function log (): array
   {
      $requests = [];
      foreach (file("{$this->directory}requests.log", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
         $request = json_decode($line, associative: true);
         if (is_array($request) === true) {
            $requests[] = $request;
         }
      }

      // :
      return $requests;
   }

   /**
    * The open multipart uploads (not completed nor aborted).
    *
    * @return array<int,array{id:string,key:string,type:string,meta:array<string,string>,parts:array<int,array{number:int,size:int,etag:string}>}>
    */
   public function list (): array
   {
      $uploads = [];
      foreach (glob("{$this->directory}uploads/*/meta.json") ?: [] as $file) {
         $meta = json_decode((string) file_get_contents($file), associative: true);
         if (is_array($meta) === false) {
            continue;
         }
         $meta['parts'] = self::enumerate(dirname($file) . '/');
         $uploads[] = $meta;
      }

      // :
      return $uploads;
   }

   /**
    * Scripts a failure for the next `$times` calls of `$operation` (`PutObject`, `GetObject`,
    * `UploadPart`, `ListParts`, ...): an S3 `<Error>` under `$status` — 200 keeps the status,
    * 0 drops the connection with no answer — or, when `$raw` is given, that response verbatim,
    * followed by `$pad` bytes of white space (an oversized answer).
    */
   public function inject (
      string $operation,
      int $status,
      string $code = 'InternalError',
      string $message = 'We encountered an internal error. Please try again.',
      int $times = 1,
      null|string $raw = null,
      int $pad = 0
   ): void
   {
      $Handle = fopen("{$this->directory}faults.json", 'c+');
      if ($Handle === false) {
         throw new RuntimeException('S3 fixture: cannot open the faults file');
      }
      flock($Handle, LOCK_EX);
      $faults = json_decode((string) stream_get_contents($Handle), associative: true);
      $faults = is_array($faults) === true ? $faults : [];
      $faults[] = [
         'operation' => $operation,
         'status' => $status,
         'code' => $code,
         'message' => $message,
         'times' => $times,
         'raw' => $raw !== null ? base64_encode($raw) : null,
         'pad' => $pad,
      ];
      ftruncate($Handle, 0);
      rewind($Handle);
      fwrite($Handle, (string) json_encode($faults));
      flock($Handle, LOCK_UN);
      fclose($Handle);
   }

   // # Server (the forked child)
   /**
    * @param resource $Server
    */
   private function serving ($Server, int $parent): void
   {
      /** @var array<int,resource> $Peers */
      $Peers = [];
      /** @var array<int,string> $buffers */
      $buffers = [];

      // @@ Until SIGTERM, or until the test process is gone
      while (posix_getppid() === $parent) {
         $read = [$Server, ...$Peers];
         $write = null;
         $except = null;
         if (@stream_select($read, $write, $except, 1) === false || $read === []) {
            continue;
         }

         foreach ($read as $Socket) {
            // @ A new connection
            if ($Socket === $Server) {
               $Peer = @stream_socket_accept($Server, 0);
               if ($Peer !== false) {
                  $Peers[(int) $Peer] = $Peer;
                  $buffers[(int) $Peer] = '';
               }

               continue;
            }

            $id = (int) $Socket;
            $chunk = @fread($Socket, 1_048_576);
            if ($chunk === false || $chunk === '') {
               if ($chunk === false || feof($Socket) === true) {
                  @fclose($Socket);
                  unset($Peers[$id], $buffers[$id]);
               }

               continue;
            }
            $buffers[$id] .= $chunk;

            // @@ Every complete request in the buffer
            while (true) {
               $end = strpos($buffers[$id], "\r\n\r\n");
               if ($end === false) {
                  break;
               }
               $request = self::parse(substr($buffers[$id], 0, $end));
               $length = (int) ($request['headers']['content-length'] ?? 0);
               if (strlen($buffers[$id]) < $end + 4 + $length) {
                  break;
               }
               $request['body'] = substr($buffers[$id], $end + 4, $length);
               $buffers[$id] = substr($buffers[$id], $end + 4 + $length);

               try {
                  $response = $this->handle($request);
               }
               catch (Throwable $Throwable) {
                  $response = self::fail(500, 'InternalError', "Fixture error: {$Throwable->getMessage()}");
               }

               $close = $response === null
                  || strtolower($request['headers']['connection'] ?? '') === 'close'
                  || $request['protocol'] === 'HTTP/1.0';
               if ($response !== null) {
                  $close = self::write($Socket, $response) === false || $close;
               }
               if ($close === true) {
                  @fclose($Socket);
                  unset($Peers[$id], $buffers[$id]);

                  break;
               }
            }
         }
      }
   }

   /**
    * One request → the raw response, or null to drop the connection.
    *
    * @param array{method:string,target:string,protocol:string,headers:array<string,string>,body:string} $request
    */
   private function handle (array $request): null|string
   {
      $method = $request['method'];
      $head = $method === 'HEAD';
      [$path, $raw] = array_pad(explode('?', $request['target'], 2), 2, '');
      $pairs = self::decode($raw);
      $query = [];
      foreach ($pairs as [$name, $value]) {
         $query[$name] = $value;
      }

      // # Route: /{bucket} or /{bucket}/{key}
      $segments = explode('/', ltrim($path, '/'), 2);
      $bucket = rawurldecode($segments[0]);
      $key = rawurldecode($segments[1] ?? '');
      $operation = 'Unknown';
      if ($key === '') {
         if ($method === 'GET' && ($query['list-type'] ?? '') === '2') {
            $operation = 'ListObjectsV2';
         }
      }
      else {
         $operation = match (true) {
            $method === 'POST' && array_key_exists('uploads', $query) => 'CreateMultipartUpload',
            $method === 'PUT' && isset($query['uploadId'], $query['partNumber']) => 'UploadPart',
            $method === 'GET' && isset($query['uploadId']) => 'ListParts',
            $method === 'POST' && isset($query['uploadId']) => 'CompleteMultipartUpload',
            $method === 'DELETE' && isset($query['uploadId']) => 'AbortMultipartUpload',
            $method === 'PUT' && isset($request['headers']['x-amz-copy-source']) => 'CopyObject',
            $method === 'PUT' => 'PutObject',
            $method === 'HEAD' => 'HeadObject',
            $method === 'GET' => 'GetObject',
            $method === 'DELETE' => 'DeleteObject',
            default => 'Unknown'
         };
      }

      // # Auth, then the operation
      [$auth, $problem] = $this->verify($request, $path, $pairs, $query);
      $response = match (true) {
         $problem !== null => self::fail($problem[0], $problem[1], $problem[2], $head),
         $bucket !== $this->bucket => self::fail(404, 'NoSuchBucket', 'The specified bucket does not exist.', $head),
         $operation === 'Unknown' => self::fail(501, 'NotImplemented', 'The fake S3 does not implement this request.', $head),
         default => $this->operate($operation, $request, $key, $query, $head)
      };

      $this->record($request, $auth, $operation, $response);

      // :
      return $response;
   }

   /**
    * A scripted fault for the operation, or the operation itself.
    *
    * @param array{method:string,target:string,protocol:string,headers:array<string,string>,body:string} $request
    * @param array<string,string> $query
    */
   private function operate (string $operation, array $request, string $key, array $query, bool $head): null|string
   {
      // # Scripted faults
      $fault = $this->consume($operation);
      if ($fault !== null) {
         // ?: A verbatim response
         if (is_string($fault['raw']) === true) {
            return (string) base64_decode($fault['raw']) . str_repeat(' ', (int) ($fault['pad'] ?? 0));
         }
         // ?: Drop the connection, no answer
         if ($fault['status'] === 0) {
            return null;
         }

         return self::fail($fault['status'], $fault['code'], $fault['message'], $head);
      }

      $headers = $request['headers'];
      // ? An upload that declares its MD5 must match it
      if (
         ($operation === 'PutObject' || $operation === 'UploadPart')
         && isset($headers['content-md5']) === true
         && $headers['content-md5'] !== base64_encode(md5($request['body'], true))
      ) {
         return self::fail(400, 'BadDigest', 'The Content-MD5 you specified did not match what we received.');
      }
      $type = $headers['content-type'] ?? 'binary/octet-stream';
      $meta = [];
      foreach ($headers as $name => $value) {
         if (str_starts_with($name, 'x-amz-meta-') === true) {
            $meta[substr($name, 11)] = $value;
         }
      }

      // :
      return match ($operation) {
         'ListObjectsV2' => $this->browse($query),
         'CreateMultipartUpload' => $this->initiate($key, $type, $meta),
         'UploadPart' => $this->upload($key, $query['uploadId'], $query['partNumber'], $request['body']),
         'ListParts' => $this->page($key, $query),
         'CompleteMultipartUpload' => $this->join($key, $query['uploadId'], $request['body']),
         'AbortMultipartUpload' => $this->cancel($key, $query['uploadId']),
         'CopyObject' => $this->copy($key, $headers['x-amz-copy-source']),
         'PutObject' => $this->put($key, $request['body'], $type, $meta),
         'HeadObject', 'GetObject' => $this->get($key, $headers['range'] ?? null, $head),
         default => $this->erase($key)
      };
   }

   /**
    * Recomputes the request's SigV4 signature → [auth kind, null|[status, code, message]].
    *
    * @param array{method:string,target:string,protocol:string,headers:array<string,string>,body:string} $request
    * @param array<int,array{0:string,1:string}> $pairs The decoded query pairs, in wire order.
    * @param array<string,string> $query
    *
    * @return array{0:string,1:null|array{0:int,1:string,2:string}}
    */
   private function verify (array $request, string $path, array $pairs, array $query): array
   {
      $headers = $request['headers'];
      $denied = [403, 'SignatureDoesNotMatch', 'The request signature we calculated does not match the signature you provided.'];
      $unsigned = [403, 'AccessDenied', 'There were headers present in the request which were not signed'];
      // ! S3's canonical URI: each segment of the decoded path encoded once
      $URI = implode('/', array_map(
         static fn (string $segment): string => rawurlencode(rawurldecode($segment)),
         explode('/', $path)
      ));

      // # Query auth (a presigned URL)
      if (isset($query['X-Amz-Signature']) === true) {
         $credential = explode('/', $query['X-Amz-Credential'] ?? '');
         $time = self::convert($query['X-Amz-Date'] ?? '');
         $expires = $query['X-Amz-Expires'] ?? '';
         $names = explode(';', $query['X-Amz-SignedHeaders'] ?? '');
         if (count($credential) !== 5 || $credential[0] !== self::KEY) {
            return ['query', [403, 'InvalidAccessKeyId', 'The access key ID you provided does not exist in our records.']];
         }
         if (
            $time === null
            || ($query['X-Amz-Algorithm'] ?? '') !== 'AWS4-HMAC-SHA256'
            || in_array('host', $names, true) === false
            || preg_match('/^[1-9]\d{0,5}$/', $expires) !== 1
            || (int) $expires > 604800
         ) {
            return ['query', [400, 'AuthorizationQueryParametersError', 'Malformed query authentication parameters.']];
         }
         if ($credential[1] !== substr($query['X-Amz-Date'] ?? '', 0, 8)) {
            return ['query', [403, 'AuthorizationQueryParametersError', 'Invalid credential date. Date is not the same as X-Amz-Date.']];
         }
         if ($time + (int) $expires < time()) {
            return ['query', [403, 'AccessDenied', 'Request has expired']];
         }
         if ($time - time() > self::SKEW) {
            return ['query', [403, 'AccessDenied', 'Request is not yet valid']];
         }
         foreach ($headers as $name => $value) {
            if (str_starts_with($name, 'x-amz-') === true && in_array($name, $names, true) === false) {
               return ['query', $unsigned];
            }
         }

         $signed = array_values(array_filter($pairs, static fn (array $pair): bool => $pair[0] !== 'X-Amz-Signature'));
         $expected = self::sign(
            $request['method'],
            $URI,
            $signed,
            $headers,
            $names,
            'UNSIGNED-PAYLOAD',
            $query['X-Amz-Date'],
            $credential
         );

         // :
         return ['query', $expected !== null && hash_equals($expected, $query['X-Amz-Signature']) === true ? null : $denied];
      }

      // # Header auth
      $authorization = $headers['authorization'] ?? '';
      if (str_starts_with($authorization, 'AWS4-HMAC-SHA256 ') === false) {
         return ['none', [403, 'AccessDenied', 'Access Denied']];
      }
      if (preg_match('/Credential=([^,\s]+)\s*,\s*SignedHeaders=([^,\s]+)\s*,\s*Signature=([0-9a-f]{64})$/', $authorization, $matches) !== 1) {
         return ['header', [400, 'AuthorizationHeaderMalformed', 'The authorization header is malformed.']];
      }
      $credential = explode('/', $matches[1]);
      if (count($credential) !== 5 || $credential[0] !== self::KEY) {
         return ['header', [403, 'InvalidAccessKeyId', 'The access key ID you provided does not exist in our records.']];
      }
      $time = self::convert($headers['x-amz-date'] ?? '');
      if ($time === null || abs(time() - $time) > self::SKEW) {
         return ['header', [403, 'RequestTimeTooSkewed', 'The difference between the request time and the current time is too large.']];
      }
      if ($credential[1] !== substr($headers['x-amz-date'] ?? '', 0, 8)) {
         return ['header', [400, 'AuthorizationHeaderMalformed', 'Invalid credential date. Date is not the same as x-amz-date.']];
      }
      $payload = $headers['x-amz-content-sha256'] ?? '';
      if ($payload !== 'UNSIGNED-PAYLOAD' && hash_equals(hash('sha256', $request['body']), $payload) === false) {
         return ['header', [400, 'XAmzContentSHA256Mismatch', 'The provided x-amz-content-sha256 header does not match what was computed.']];
      }

      $names = explode(';', $matches[2]);
      foreach (['host', 'x-amz-content-sha256', 'x-amz-date'] as $required) {
         if (in_array($required, $names, true) === false) {
            return ['header', [403, 'AccessDenied', "SignedHeaders must include {$required}."]];
         }
      }
      foreach ($headers as $name => $value) {
         if (str_starts_with($name, 'x-amz-') === true && in_array($name, $names, true) === false) {
            return ['header', $unsigned];
         }
      }

      $expected = self::sign($request['method'], $URI, $pairs, $headers, $names, $payload, $headers['x-amz-date'], $credential);

      // :
      return ['header', $expected !== null && hash_equals($expected, $matches[3]) === true ? null : $denied];
   }

   /**
    * The SigV4 signature of a request, straight from the specification; null when a signed
    * header is missing from the request.
    *
    * @param array<int,array{0:string,1:string}> $pairs Decoded query pairs.
    * @param array<string,string> $headers Request headers, lowercased names.
    * @param array<int,string> $names The signed header names, as the client listed them.
    * @param array<int,string> $credential `[key, day, region, service, 'aws4_request']`.
    */
   private static function sign (
      string $method,
      string $URI,
      array $pairs,
      array $headers,
      array $names,
      string $payload,
      string $date,
      array $credential
   ): null|string
   {
      // ! Canonical query: encoded pairs sorted by name, then value
      $encoded = [];
      foreach ($pairs as [$name, $value]) {
         $encoded[] = [rawurlencode($name), rawurlencode($value)];
      }
      usort($encoded, static fn (array $A, array $B): int => strcmp($A[0], $B[0]) ?: strcmp($A[1], $B[1]));
      $canonical = implode('&', array_map(static fn (array $pair): string => "{$pair[0]}={$pair[1]}", $encoded));

      // ! Canonical headers: the listed names (which must be sorted), trimmed and folded values
      $sorted = $names;
      sort($sorted);
      if ($sorted !== $names) {
         return null;
      }
      $lines = '';
      foreach ($names as $name) {
         if (isset($headers[$name]) === false) {
            return null;
         }
         $lines .= "{$name}:" . preg_replace('/\s+/', ' ', trim($headers[$name])) . "\n";
      }

      $request = "{$method}\n{$URI}\n{$canonical}\n{$lines}\n" . implode(';', $names) . "\n{$payload}";
      $scope = "{$credential[1]}/{$credential[2]}/{$credential[3]}/aws4_request";
      $string = "AWS4-HMAC-SHA256\n{$date}\n{$scope}\n" . hash('sha256', $request);

      // @ HMAC chain
      $key = hash_hmac('sha256', $credential[1], 'AWS4' . self::SECRET, true);
      $key = hash_hmac('sha256', $credential[2], $key, true);
      $key = hash_hmac('sha256', $credential[3], $key, true);
      $key = hash_hmac('sha256', 'aws4_request', $key, true);

      // :
      return hash_hmac('sha256', $string, $key);
   }

   // # Operations
   /**
    * @param array<string,string> $query
    */
   private function browse (array $query): string
   {
      $prefix = $query['prefix'] ?? '';
      $delimiter = $query['delimiter'] ?? '';
      $after = isset($query['continuation-token']) === true ? (string) base64_decode($query['continuation-token']) : '';
      $maximum = max(1, min($this->page, (int) ($query['max-keys'] ?? $this->page)));

      // ! Every stored key under the prefix, in byte order
      $entries = [];
      foreach (glob("{$this->directory}objects/*.json") ?: [] as $file) {
         $meta = json_decode((string) file_get_contents($file), associative: true);
         if (is_array($meta) === true && str_starts_with((string) $meta['key'], $prefix) === true) {
            $entries[(string) $meta['key']] = (int) filesize(substr($file, 0, -5) . '.bin');
         }
      }
      $keys = array_map('strval', array_keys($entries));
      sort($keys, SORT_STRING);

      // @ One page: keys after the token; a key with the delimiter past the prefix rolls
      //   up into a common prefix
      $contents = '';
      $prefixes = [];
      $count = 0;
      $last = '';
      $truncated = false;
      foreach ($keys as $key) {
         if ($after !== '' && strcmp($key, $after) <= 0) {
            continue;
         }
         if ($count >= $maximum) {
            $truncated = true;

            break;
         }
         $rest = substr($key, strlen($prefix));
         $cut = $delimiter !== '' ? strpos($rest, $delimiter) : false;
         if ($cut !== false) {
            $common = $prefix . substr($rest, 0, $cut + strlen($delimiter));
            if (isset($prefixes[$common]) === false) {
               $prefixes[$common] = true;
               $count++;
            }
            $last = $key;

            continue;
         }
         $contents .= '<Contents><Key>' . self::escape($key) . "</Key><Size>{$entries[$key]}</Size></Contents>";
         $count++;
         $last = $key;
      }
      foreach ($prefixes as $common => $present) {
         $contents .= '<CommonPrefixes><Prefix>' . self::escape((string) $common) . '</Prefix></CommonPrefixes>';
      }

      $fields = [
         'Name' => $this->bucket,
         'Prefix' => $prefix,
         'KeyCount' => (string) $count,
         'MaxKeys' => (string) $maximum,
         'IsTruncated' => $truncated === true ? 'true' : 'false',
      ];
      if ($truncated === true) {
         $fields['NextContinuationToken'] = base64_encode($last);
      }

      // :
      return self::answer(200, ['Content-Type' => 'application/xml'], self::render('ListBucketResult', $fields, $contents));
   }

   /**
    * @param array<string,string> $meta
    */
   private function initiate (string $key, string $type, array $meta): string
   {
      $id = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=') . '.~1';
      $folder = $this->folder($id);
      mkdir($folder, 0700);
      file_put_contents("{$folder}meta.json", json_encode(['id' => $id, 'key' => $key, 'type' => $type, 'meta' => $meta]));

      // :
      return self::answer(200, ['Content-Type' => 'application/xml'], self::render('InitiateMultipartUploadResult', [
         'Bucket' => $this->bucket,
         'Key' => $key,
         'UploadId' => $id,
      ]));
   }

   private function upload (string $key, string $id, string $number, string $body): string
   {
      $folder = $this->open($key, $id);
      // ?
      if ($folder === null) {
         return self::fail(404, 'NoSuchUpload', 'The specified multipart upload does not exist.');
      }
      $part = (int) $number;
      if ((string) $part !== $number || $part < 1 || $part > 10_000) {
         return self::fail(400, 'InvalidArgument', 'Part number must be an integer between 1 and 10000, inclusive.');
      }

      file_put_contents("{$folder}{$part}.part", $body);

      // :
      return self::answer(200, ['ETag' => '"' . md5($body) . '"'], '');
   }

   /**
    * @param array<string,string> $query
    */
   private function page (string $key, array $query): string
   {
      $folder = $this->open($key, $query['uploadId']);
      // ?
      if ($folder === null) {
         return self::fail(404, 'NoSuchUpload', 'The specified multipart upload does not exist.');
      }

      $marker = (int) ($query['part-number-marker'] ?? 0);
      $maximum = max(1, min($this->parts, (int) ($query['max-parts'] ?? $this->parts)));
      $parts = array_values(array_filter(self::enumerate($folder), static fn (array $part): bool => $part['number'] > $marker));
      $page = array_slice($parts, 0, $maximum);
      $truncated = count($parts) > $maximum;

      $XML = '';
      foreach ($page as $part) {
         $XML .= "<Part><PartNumber>{$part['number']}</PartNumber><ETag>" . self::escape($part['etag']) . '</ETag>'
            . "<Size>{$part['size']}</Size></Part>";
      }
      $last = $page === [] ? $marker : $page[count($page) - 1]['number'];

      // :
      return self::answer(200, ['Content-Type' => 'application/xml'], self::render('ListPartsResult', [
         'Bucket' => $this->bucket,
         'Key' => $key,
         'UploadId' => $query['uploadId'],
         'PartNumberMarker' => (string) $marker,
         'NextPartNumberMarker' => (string) $last,
         'MaxParts' => (string) $maximum,
         'IsTruncated' => $truncated === true ? 'true' : 'false',
      ], $XML));
   }

   private function join (string $key, string $id, string $body): string
   {
      $folder = $this->open($key, $id);
      // ?
      if ($folder === null) {
         return self::fail(404, 'NoSuchUpload', 'The specified multipart upload does not exist.');
      }
      $previous = libxml_use_internal_errors(true);
      $XML = simplexml_load_string($body, options: LIBXML_NONET);
      libxml_clear_errors();
      libxml_use_internal_errors($previous);
      if ($XML === false || $XML->getName() !== 'CompleteMultipartUpload' || count($XML->Part) === 0) {
         return self::fail(400, 'MalformedXML', 'The XML you provided was not well-formed or did not validate against our published schema.');
      }

      $stored = [];
      foreach (self::enumerate($folder) as $part) {
         $stored[$part['number']] = $part;
      }

      // @ Ascending, every part uploaded with that ETag (and equal sizes but the last, on demand)
      $numbers = [];
      $hashes = '';
      $count = count($XML->Part);
      $index = 0;
      $size = null;
      foreach ($XML->Part as $Part) {
         $index++;
         $number = (int) $Part->PartNumber;
         $ETag = trim((string) $Part->ETag, "\" \t");
         if ($numbers !== [] && $number <= $numbers[count($numbers) - 1]) {
            return self::fail(400, 'InvalidPartOrder', 'The list of parts was not in ascending order.');
         }
         if (isset($stored[$number]) === false || trim($stored[$number]['etag'], '"') !== $ETag) {
            return self::fail(400, 'InvalidPart', 'One or more of the specified parts could not be found.');
         }
         if ($this->equal === true && $index < $count) {
            $size ??= $stored[$number]['size'];
            if ($stored[$number]['size'] !== $size) {
               return self::fail(400, 'InvalidPart', 'All non-trailing parts must have the same length.');
            }
         }
         $numbers[] = $number;
         $hashes .= (string) hex2bin($ETag);
      }

      // @ Concatenate (file to file)
      $file = $this->locate($key);
      $Target = fopen("{$file}.tmp", 'wb');
      if ($Target === false) {
         return self::fail(500, 'InternalError', 'Fixture error: cannot write the object.');
      }
      foreach ($numbers as $number) {
         $Source = fopen("{$folder}{$number}.part", 'rb');
         if ($Source !== false) {
            stream_copy_to_stream($Source, $Target);
            fclose($Source);
         }
      }
      fclose($Target);
      rename("{$file}.tmp", "{$file}.bin");
      $meta = json_decode((string) file_get_contents("{$folder}meta.json"), associative: true);
      $meta = is_array($meta) === true ? $meta : [];
      $ETag = '"' . md5($hashes) . '-' . count($numbers) . '"';
      file_put_contents("{$file}.json", json_encode([
         'key' => $key,
         'type' => $meta['type'] ?? 'binary/octet-stream',
         'etag' => $ETag,
         'meta' => $meta['meta'] ?? [],
      ]));
      self::remove($folder);

      // :
      return self::answer(200, ['Content-Type' => 'application/xml'], self::render('CompleteMultipartUploadResult', [
         'Location' => "http://127.0.0.1:{$this->port}/{$this->bucket}/{$key}",
         'Bucket' => $this->bucket,
         'Key' => $key,
         'ETag' => $ETag,
      ]));
   }

   private function cancel (string $key, string $id): string
   {
      $folder = $this->open($key, $id);
      // ?
      if ($folder === null) {
         return self::fail(404, 'NoSuchUpload', 'The specified multipart upload does not exist.');
      }

      self::remove($folder);

      // :
      return self::answer(204, [], '');
   }

   private function copy (string $key, string $source): string
   {
      // ! `x-amz-copy-source` is `/{bucket}/{encoded key}`
      $segments = explode('/', ltrim($source, '/'), 2);
      $from = implode('/', array_map('rawurldecode', explode('/', $segments[1] ?? '')));
      $bytes = rawurldecode($segments[0]) === $this->bucket ? $this->fetch($from) : null;
      $meta = $this->describe($from);
      // ?
      if ($bytes === null || $meta === null) {
         return self::fail(404, 'NoSuchKey', 'The specified key does not exist.');
      }

      $this->save($key, $bytes, $meta['type'], $meta['etag'], $meta['meta']);

      // :
      return self::answer(200, ['Content-Type' => 'application/xml'], self::render('CopyObjectResult', ['ETag' => $meta['etag']]));
   }

   /**
    * @param array<string,string> $meta
    */
   private function put (string $key, string $body, string $type, array $meta): string
   {
      $ETag = '"' . md5($body) . '"';
      $this->save($key, $body, $type, $ETag, $meta);

      // :
      return self::answer(200, ['ETag' => $ETag], '');
   }

   private function get (string $key, null|string $range, bool $head): string
   {
      $file = $this->locate($key);
      $meta = $this->describe($key);
      // ?
      if (is_file("{$file}.bin") === false || $meta === null) {
         return self::fail(404, 'NoSuchKey', 'The specified key does not exist.', $head);
      }
      $size = (int) filesize("{$file}.bin");
      $headers = [
         'Content-Type' => $meta['type'],
         'ETag' => $meta['etag'],
         'Accept-Ranges' => 'bytes',
         'Last-Modified' => gmdate('D, d M Y H:i:s', (int) filemtime("{$file}.bin")) . ' GMT',
      ];
      foreach ($meta['meta'] as $name => $value) {
         $headers["x-amz-meta-{$name}"] = $value;
      }

      // # Range: bytes=a-b | a- | -n
      if ($range !== null && $head === false) {
         $unsatisfiable = self::fail(416, 'InvalidRange', 'The requested range is not satisfiable', extra: ['Content-Range' => "bytes */{$size}"]);
         if (preg_match('/^bytes=(\d*)-(\d*)$/', trim($range), $matches) !== 1 || ($matches[1] === '' && $matches[2] === '')) {
            return $unsatisfiable;
         }
         if ($matches[1] === '') {
            $from = max(0, $size - (int) $matches[2]);
            $to = $size - 1;
         }
         else {
            $from = (int) $matches[1];
            $to = $matches[2] === '' ? $size - 1 : min((int) $matches[2], $size - 1);
         }
         if ($from >= $size || $to < $from) {
            return $unsatisfiable;
         }

         $headers['Content-Range'] = "bytes {$from}-{$to}/{$size}";

         // :
         return self::answer(206, $headers, (string) file_get_contents("{$file}.bin", offset: $from, length: $to - $from + 1));
      }

      // :
      return $head === true
         ? self::answer(200, $headers, '', length: $size)
         : self::answer(200, $headers, (string) file_get_contents("{$file}.bin"));
   }

   private function erase (string $key): string
   {
      $file = $this->locate($key);
      @unlink("{$file}.bin");
      @unlink("{$file}.json");

      // :
      return self::answer(204, [], '');
   }

   // # State
   /**
    * @param array<string,string> $meta
    */
   private function save (string $key, string $bytes, string $type, string $ETag, array $meta): void
   {
      $file = $this->locate($key);
      file_put_contents("{$file}.tmp", $bytes);
      rename("{$file}.tmp", "{$file}.bin");
      file_put_contents("{$file}.json", json_encode(['key' => $key, 'type' => $type, 'etag' => $ETag, 'meta' => $meta]));
   }

   private function locate (string $key): string
   {
      return "{$this->directory}objects/" . sha1($key);
   }

   private function folder (string $id): string
   {
      return "{$this->directory}uploads/" . sha1($id) . '/';
   }

   /**
    * The upload's folder when it exists for this key.
    */
   private function open (string $key, string $id): null|string
   {
      $folder = $this->folder($id);
      $meta = is_file("{$folder}meta.json") === true ? json_decode((string) file_get_contents("{$folder}meta.json"), associative: true) : null;

      // :
      return is_array($meta) === true && ($meta['key'] ?? null) === $key ? $folder : null;
   }

   /**
    * @return array<int,array{number:int,size:int,etag:string}>
    */
   private static function enumerate (string $folder): array
   {
      $parts = [];
      foreach (glob("{$folder}*.part") ?: [] as $file) {
         $parts[] = [
            'number' => (int) basename($file, '.part'),
            'size' => (int) filesize($file),
            'etag' => '"' . md5_file($file) . '"',
         ];
      }
      usort($parts, static fn (array $A, array $B): int => $A['number'] <=> $B['number']);

      // :
      return $parts;
   }

   /**
    * Takes one use of the first fault scripted for this operation.
    *
    * @return null|array{operation:string,status:int,code:string,message:string,times:int,raw:null|string}
    */
   private function consume (string $operation): null|array
   {
      $Handle = fopen("{$this->directory}faults.json", 'c+');
      if ($Handle === false) {
         return null;
      }
      flock($Handle, LOCK_EX);
      $faults = json_decode((string) stream_get_contents($Handle), associative: true);
      $faults = is_array($faults) === true ? $faults : [];

      $taken = null;
      foreach ($faults as $index => $fault) {
         if (($fault['operation'] ?? '') === $operation && ($fault['times'] ?? 0) > 0) {
            $taken = $fault;
            $faults[$index]['times']--;

            break;
         }
      }
      if ($taken !== null) {
         ftruncate($Handle, 0);
         rewind($Handle);
         fwrite($Handle, (string) json_encode(array_values(array_filter($faults, static fn (array $fault): bool => $fault['times'] > 0))));
      }
      flock($Handle, LOCK_UN);
      fclose($Handle);

      // :
      return $taken;
   }

   /**
    * @param array{method:string,target:string,protocol:string,headers:array<string,string>,body:string} $request
    */
   private function record (array $request, string $auth, string $operation, null|string $response): void
   {
      $status = $response !== null && preg_match('#^HTTP/\S+ (\d{3})#', $response, $matches) === 1 ? (int) $matches[1] : 0;

      file_put_contents("{$this->directory}requests.log", json_encode([
         'method' => $request['method'],
         'target' => $request['target'],
         'headers' => $request['headers'],
         'auth' => $auth,
         'operation' => $operation,
         'status' => $status,
         'length' => strlen($request['body']),
         // ! Small bodies only (the CompleteMultipartUpload XML), never a part
         'body' => strlen($request['body']) <= 8192 && str_contains($request['body'], "\0") === false ? $request['body'] : '',
      ]) . "\n", FILE_APPEND | LOCK_EX);
   }

   // # Wire
   /**
    * @return array{method:string,target:string,protocol:string,headers:array<string,string>,body:string}
    */
   private static function parse (string $head): array
   {
      $lines = explode("\r\n", $head);
      $line = explode(' ', $lines[0], 3);
      $headers = [];
      foreach (array_slice($lines, 1) as $field) {
         $colon = strpos($field, ':');
         if ($colon !== false) {
            $headers[strtolower(trim(substr($field, 0, $colon)))] = trim(substr($field, $colon + 1));
         }
      }

      // :
      return [
         'method' => $line[0],
         'target' => $line[1] ?? '/',
         'protocol' => $line[2] ?? 'HTTP/1.1',
         'headers' => $headers,
         'body' => '',
      ];
   }

   /**
    * A raw query string → its decoded pairs, in wire order (a bare `name` reads as `name=`).
    *
    * @return array<int,array{0:string,1:string}>
    */
   private static function decode (string $raw): array
   {
      $pairs = [];
      foreach ($raw === '' ? [] : explode('&', $raw) as $pair) {
         [$name, $value] = array_pad(explode('=', $pair, 2), 2, '');
         $pairs[] = [rawurldecode($name), rawurldecode($value)];
      }

      // :
      return $pairs;
   }

   /**
    * An `x-amz-date` (`20261108T123000Z`) → a Unix time, or null.
    */
   private static function convert (string $date): null|int
   {
      // ?
      if (preg_match('/^(\d{4})(\d{2})(\d{2})T(\d{2})(\d{2})(\d{2})Z$/', $date, $m) !== 1) {
         return null;
      }

      // :
      return (int) gmmktime((int) $m[4], (int) $m[5], (int) $m[6], (int) $m[2], (int) $m[3], (int) $m[1]);
   }

   /**
    * @param array<string,string> $fields
    */
   private static function render (string $root, array $fields, string $inner = ''): string
   {
      $XML = '';
      foreach ($fields as $name => $value) {
         $XML .= "<{$name}>" . self::escape($value) . "</{$name}>";
      }

      // :
      return "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<{$root} xmlns=\"http://s3.amazonaws.com/doc/2006-03-01/\">{$XML}{$inner}</{$root}>";
   }

   private static function escape (string $value): string
   {
      return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES);
   }

   /**
    * An S3 error: the XML body (none for HEAD).
    *
    * @param array<string,string> $extra
    */
   private static function fail (int $status, string $code, string $message, bool $head = false, array $extra = []): string
   {
      $body = $head === true ? '' : "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<Error><Code>" . self::escape($code)
         . '</Code><Message>' . self::escape($message) . '</Message><RequestId>' . bin2hex(random_bytes(8)) . '</RequestId></Error>';

      // :
      return self::answer($status, ['Content-Type' => 'application/xml'] + $extra, $body);
   }

   /**
    * @param array<string,string> $headers
    */
   private static function answer (int $status, array $headers, string $body, null|int $length = null): string
   {
      $reason = match ($status) {
         200 => 'OK',
         204 => 'No Content',
         206 => 'Partial Content',
         400 => 'Bad Request',
         403 => 'Forbidden',
         404 => 'Not Found',
         416 => 'Range Not Satisfiable',
         500 => 'Internal Server Error',
         501 => 'Not Implemented',
         503 => 'Service Unavailable',
         default => 'Status'
      };

      $raw = "HTTP/1.1 {$status} {$reason}\r\n";
      $headers['Date'] = gmdate('D, d M Y H:i:s') . ' GMT';
      $headers['x-amz-request-id'] = bin2hex(random_bytes(8));
      foreach ($headers as $name => $value) {
         $raw .= "{$name}: {$value}\r\n";
      }
      if ($status !== 204) {
         $raw .= 'Content-Length: ' . ($length ?? strlen($body)) . "\r\n";
      }

      // :
      return "{$raw}\r\n{$body}";
   }

   /**
    * @param resource $Socket
    */
   private static function write ($Socket, string $data): bool
   {
      $total = strlen($data);
      $sent = 0;
      // @@ Partial writes
      while ($sent < $total) {
         $written = @fwrite($Socket, substr($data, $sent, 1_048_576));
         if ($written === false || $written === 0) {
            return false;
         }
         $sent += $written;
      }

      // :
      return true;
   }

   private static function remove (string $path): void
   {
      if (is_dir($path) === false) {
         @unlink($path);

         return;
      }
      foreach (scandir($path) ?: [] as $entry) {
         if ($entry !== '.' && $entry !== '..') {
            self::remove(rtrim($path, '/') . "/{$entry}");
         }
      }
      @rmdir($path);
   }
}
