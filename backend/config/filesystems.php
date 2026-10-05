<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            /*
             * 'serve' => false ON PURPOSE.
             *
             * When true, Laravel registers a `GET storage/{path}` route that
             * streams files straight off this disk. That route sits OUTSIDE
             * the `api` middleware group, so it would be the one endpoint on
             * the host with no security headers, no envelope — and, far worse,
             * no authorization: backend/ serves uploads through an explicit
             * controller precisely so a student cannot fetch another course's
             * material by guessing a path. Stage 1 ports that controller;
             * until then nothing serves files at all.
             */
            'serve' => false,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => env('APP_URL').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        /*
        |----------------------------------------------------------------------
        | STAGE 9 — THE UPLOADS DISK.
        |----------------------------------------------------------------------
        |
        | ╔══════════════════════════════════════════════════════════════════╗
        | ║ **NEVER RUN `php artisan storage:link`.**                        ║
        | ║                                                                  ║
        | ║ The ENTIRE threat model for private files rests on one fact:     ║
        | ║ storage/uploads sits OUTSIDE the web-server document root        ║
        | ║ (public/ is the root), so Apache/Nginx has no URL that maps to   ║
        | ║ it and cannot serve an uploaded byte at all. Every read is       ║
        | ║ forced back through PHP — App\Http\Controllers\FileController —  ║
        | ║ which is what makes an authorization decision ALWAYS precede the ║
        | ║ read.                                                            ║
        | ║                                                                  ║
        | ║ `storage:link` creates public/storage -> storage/app/public.     ║
        | ║ That link alone does not expose THIS disk, but it establishes    ║
        | ║ the pattern and the `links` map below is the place someone       ║
        | ║ would "helpfully" add storage/uploads next — at which point      ║
        | ║ every note attachment in the system is a static file, fetchable  ║
        | ║ by anyone who has ever seen a `path` value, with no subscription ║
        | ║ check anywhere in the request.                                   ║
        | ║                                                                  ║
        | ║ Regression-proofed by StorageIsolationTest, which asserts        ║
        | ║ public_path('storage') DOES NOT EXIST and that `links` names no  ║
        | ║ uploads target.                                                  ║
        | ╚══════════════════════════════════════════════════════════════════╝
        |
        | The root is spelled with the SAME expression as
        | config/upload.php's `storage_path` key, not a second literal:
        | App\Support\StoredUploadPath resolves and containment-checks against
        | config('upload.storage_path'), while this disk is what writes the
        | files. If the two ever pointed at different directories, every
        | freshly uploaded file would fail its own containment check and
        | download as a 404. Asserted identical by StorageIsolationTest.
        |
        | 'visibility' => 'private' sets 0600/0700 modes on POSIX — belt to
        | the braces of being outside the document root. 'throw' => false
        | keeps a failed write a `false` return that StoreUpload turns into a
        | logged `move_failed` + 422, rather than an unlogged 500.
        */
        'uploads' => [
            'driver' => 'local',
            'root' => env('UPLOAD_STORAGE_PATH') ?: storage_path('uploads'),
            'visibility' => 'private',
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    | ┌──────────────────────────────────────────────────────────────────────┐
    | │ **DO NOT ADD storage/uploads HERE, AND DO NOT RUN storage:link.**    │
    | │ See the `uploads` disk above for the full argument. A symlink into   │
    | │ storage/uploads makes every private note attachment a static file    │
    | │ served by the web server with no authorization check at all.         │
    | │                                                                      │
    | │ The Laravel-default entry below is left as shipped and is UNUSED —   │
    | │ nothing writes to the `public` disk. StorageIsolationTest asserts     │
    | │ public_path('storage') does not exist on disk, so the command has     │
    | │ demonstrably never been run against this checkout.                    │
    | └──────────────────────────────────────────────────────────────────────┘
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
