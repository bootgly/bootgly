<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Resources\Storage\Drivers\S3;


/**
 * The S3 operations the driver sends, by their S3 names: what `Reader::judge()` needs to know
 * which answers are a success.
 */
enum Operations
{
   case PutObject;
   case GetObject;
   case HeadObject;
   case DeleteObject;
   case CopyObject;
   case ListObjectsV2;
   case CreateMultipartUpload;
   case UploadPart;
   case ListParts;
   case CompleteMultipartUpload;
   case AbortMultipartUpload;
}
