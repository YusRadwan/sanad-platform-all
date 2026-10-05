<?php

declare(strict_types=1);

/**
 * Upload storage configuration.
 *
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ INTRODUCED IN STAGE 6 / SESSION B, WHICH NEEDS EXACTLY **ONE** THING    ║
 * ║ FROM IT: the root under which App\Support\StoredUploadPath checks that  ║
 * ║ an `uploads/<category>/<uuid>.<ext>` reference names bytes that really  ║
 * ║ exist, before that string is written to                                 ║
 * ║ `assignment_submissions.file_path`.                                     ║
 * ║                                                                          ║
 * ║ STAGE 9 OWNS THE REST — the upload endpoint, the per-category size caps, ║
 * ║ the extension↔MIME agreement rules and the file-serving route. It should ║
 * ║ EXTEND this file rather than start a second one, and it should reuse     ║
 * ║ StoredUploadPath rather than re-derive the path-safety rules. That       ║
 * ║ forward dependency is recorded as open decision 6.10.                    ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 *
 * backend/config/upload.php's `max_size_mb` and `allowed_mime` keys are NOT
 * ported: that file's own comment records that they are read by nothing, the
 * real caps living in UploadService::KIND_RULES. Porting dead configuration
 * would only make it look load-bearing.
 *
 * The directory sits OUTSIDE the web-server document root (public/), which is
 * what makes PHP-mediated serving the only possible read path — the same
 * property backend/ relies on.
 */

return [

    'storage_path' => env('UPLOAD_STORAGE_PATH') ?: storage_path('uploads'),

];
