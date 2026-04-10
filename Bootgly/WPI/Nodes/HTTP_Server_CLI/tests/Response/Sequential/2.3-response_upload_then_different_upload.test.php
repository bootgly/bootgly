<?php

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


return new Specification(
   requests: [
      function () {
         return "GET /download/text HTTP/1.1\r\nHost: localhost\r\n\r\n";
      },
      function () {
         return "GET /download/image HTTP/1.1\r\nHost: localhost\r\n\r\n";
      },
   ],
   responseLengths: [342, 82928],
   response: function (Request $Request, Response $Response, Router $Router)
   {
      yield $Router->route('/download/text', function ($Request, $Response) {
         return $Response->upload('HTTP_Server_CLI/statics/alphanumeric.txt', close: false);
      }, GET);

      yield $Router->route('/download/image', function ($Request, $Response) {
         return $Response->upload('HTTP_Server_CLI/statics/image1.jpg', close: false);
      }, GET);
   },

   test: function (array $responses) {
      // @ Assert Response 1 — text file upload
      if (strpos($responses[0], 'filename="alphanumeric.txt"') === false) {
         Vars::$labels = ['Response 1:'];
         dump(json_encode($responses[0]));
         return 'First upload: wrong filename in Content-Disposition';
      }
      if (strpos($responses[0], 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789') === false) {
         Vars::$labels = ['Response 1:'];
         dump(json_encode($responses[0]));
         return 'First upload: text file content missing';
      }

      // @ Assert Response 2 — image file upload
      if (strpos($responses[1], 'filename="image1.jpg"') === false) {
         Vars::$labels = ['Response 2:'];
         dump(json_encode($responses[1]));
         return 'Second upload: wrong filename — files array leaked from first upload';
      }
      // @ Assert correct file size (image1.jpg = 82928 bytes total response)
      $responseLength = strlen($responses[1]);
      if ($responseLength !== 82928) {
         Vars::$labels = ['Response 2 length:', 'Expected:'];
         dump($responseLength, 82928);
         return 'Second upload: response length mismatch — file state leaked';
      }

      return true;
   }
);
