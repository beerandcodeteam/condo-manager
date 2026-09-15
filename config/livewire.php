<?php

/*
|--------------------------------------------------------------------------
| Livewire
|--------------------------------------------------------------------------
|
| Only the keys overridden by the application; every other option keeps the
| package default (merged by the Livewire service provider).
|
*/

return [

    /*
    |---------------------------------------------------------------------------
    | Temporary File Uploads
    |---------------------------------------------------------------------------
    |
    | No size rule on temporary uploads: rule document PDFs have no size limit in
    | the application (PHP caps uploads at 100M). Each component validates its own
    | files, e.g. ticket photos keep their 10MB limit.
    |
    */

    'temporary_file_upload' => [
        'disk' => env('LIVEWIRE_TEMPORARY_FILE_UPLOAD_DISK'),
        'rules' => ['required', 'file'],
        'directory' => null,
        'middleware' => null,
        'preview_mimes' => [
            'png', 'gif', 'bmp', 'svg', 'wav', 'mp4',
            'mov', 'avi', 'wmv', 'mp3', 'm4a',
            'jpg', 'jpeg', 'mpga', 'webp', 'wma',
        ],
        'max_upload_time' => 30,
        'cleanup' => true,
    ],

];
