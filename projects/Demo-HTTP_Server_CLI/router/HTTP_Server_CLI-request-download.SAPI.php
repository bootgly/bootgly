<?php
/*
 * --------------------------------------------------------------------------
 * HTTP Server CLI - Upload Handler (Streaming Decoder)
 * --------------------------------------------------------------------------
 *
 * This handler demonstrates the streaming multipart decoder.
 * It accepts file uploads via POST and returns metadata about uploaded files.
 *
 * Usage:
 *   1. In HTTP_Server_CLI.project.php, change the on line to:
 *      $Server->on(request: require __DIR__ . '/router/HTTP_Server_CLI-download.SAPI.php');
 *
 *   2. Start the server:
*      bootgly project Demo-HTTP_Server_CLI start
 *
 *   3. Upload files with curl:
 *      # Single file
 *      curl -X POST http://localhost:8082/request/download \
 *        -F "file=@/path/to/file.txt"
 *
 *      # Multiple files + fields
 *      curl -X POST http://localhost:8082/request/download \
 *        -F "file1=@/path/to/photo.jpg" \
 *        -F "file2=@/path/to/document.pdf" \
 *        -F "description=My upload"
 *
 *      # Large file (streaming decoder writes directly to disk)
 *      curl -X POST http://localhost:8082/request/download \
 *        -F "bigfile=@/path/to/large-video.mp4"
 *
 *      # Generate a large test file for upload testing:
 *      dd if=/dev/urandom of=/tmp/testfile_100mb.bin bs=1M count=100
 *      curl -X POST http://localhost:8082/request/download \
 *        -F "bigfile=@/tmp/testfile_100mb.bin"
 *
 *   4. Adjust max file size if needed (default: 500MB):
 *      Bootgly\WPI\Nodes\HTTP_Server_CLI\Request::$maxFileSize = 500 * 1024 * 1024;
 */

namespace projects\Bootgly\WPI;


use function array_map;
use function count;
use function filesize;
use function is_file;
use function json_encode;
use function memory_get_peak_usage;
use function number_format;
use function round;

use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;


return static function
(Request $Request, Response $Response, Router $Router)
{
   // @ Home: simple info page
   yield $Router->route('/', function (Request $Request, Response $Response) {
      $maxMB = round(Request::$maxFileSize / 1024 / 1024);

      $html = <<<HTML
      <!DOCTYPE html>
      <html>
      <head><title>Bootgly Upload Test</title></head>
      <body>
         <h1>Bootgly Streaming Upload Test</h1>
         <p>Max upload size: {$maxMB} MB</p>
         <p>Streaming decoder: files are written directly to disk (low RAM usage).</p>

         <h2>Upload via Form</h2>
         <form action="/request/download" method="POST" enctype="multipart/form-data">
            <input type="file" name="file" /><br/><br/>
            <label>Description: <input type="text" name="description" /></label><br/><br/>
            <button type="submit">Upload</button>
         </form>

         <h2>Upload via curl</h2>
         <pre>curl -X POST http://localhost:8082/request/download -F "file=@/path/to/file"</pre>

         <h2>Large file test</h2>
         <pre>dd if=/dev/urandom of=/tmp/testfile.bin bs=1M count=50
      curl -X POST http://localhost:8082/request/download -F "bigfile=@/tmp/testfile.bin"</pre>
      </body>
      </html>
      HTML;

      return $Response(body: $html);
   }, GET);

   // @ Upload endpoint
   yield $Router->route('/request/download', function (Request $Request, Response $Response) {
      // @ Process the upload (streaming decoder already wrote files to disk)
      $Request->download();

      // @ Build response with file metadata
      $files = [];
      foreach ($_FILES as $key => $file) {
         $tmpExists = is_file($file['tmp_name'] ?? '');

         $files[$key] = [
            'name'      => $file['name'],
            'size'      => $file['size'],
            'size_human' => formatBytes($file['size']),
            'type'      => $file['type'],
            'error'     => $file['error'],
            'tmp_name'  => $file['tmp_name'],
            'tmp_exists' => $tmpExists,
            // @ Verify the file on disk matches reported size
            'disk_size' => $tmpExists ? filesize($file['tmp_name']) : null,
         ];
      }

      $result = [
         'success'     => true,
         'streaming'   => $Request->Body->streaming,
         'files_count' => count($files),
         'files'       => $files,
         'fields'      => $_POST,
         'memory_peak' => formatBytes(memory_get_peak_usage(true)),
      ];

      return $Response->Json->send($result);
   }, POST);

   // @ 404
   return yield $Response(code: 404, body: '404 Not Found');
};

function formatBytes (int|string $bytes, int $precision = 2): string
{
   $bytes = (int) $bytes;
   if ($bytes <= 0) return '0 B';

   $units = ['B', 'KB', 'MB', 'GB'];
   $pow = min((int) log($bytes, 1024), count($units) - 1);

   return round($bytes / (1024 ** $pow), $precision) . ' ' . $units[$pow];
}
