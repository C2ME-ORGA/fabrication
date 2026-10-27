<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Disque de stockage des documents techniques
    |--------------------------------------------------------------------------
    |
    | Les fichiers sont stockes hors du repertoire public. Aucun nom de
    | fichier fourni par l'utilisateur ne sert de chemin de stockage.
    |
    */

    'disk' => env('DOCUMENTS_DISK', 'documents'),

    /*
    |--------------------------------------------------------------------------
    | Limite de taille
    |--------------------------------------------------------------------------
    |
    | Valeur en kilobytes (Laravel) et en octets (controle applicatif).
    | 51 200 Ko = 50 Mo, valeur adaptee aux plans techniques.
    |
    */

    'max_size_kb' => (int) env('DOCUMENTS_MAX_SIZE_KB', 51200),

    /*
    |--------------------------------------------------------------------------
    | Extensions et MIME autorises
    |--------------------------------------------------------------------------
    |
    | Le MIME est detecte a partir du contenu par fileinfo. Pour les formats
    | CAO, les navigateurs et fileinfo renvoient souvent application/octet-stream
    | ou text/plain : ces valeurs sont autorisees uniquement pour ces formats.
    |
    */

    'allowed_extensions' => [
        'pdf',
        'dxf',
        'dwg',
        'xlsx',
        'csv',
        'docx',
        'png',
        'jpg',
        'jpeg',
        'tif',
        'tiff',
    ],

    'mime_types' => [
        'pdf' => ['application/pdf'],
        'dxf' => ['application/dxf', 'application/x-dxf', 'image/vnd.dxf', 'text/plain', 'application/octet-stream'],
        'dwg' => ['application/acad', 'application/x-acad', 'image/vnd.dwg', 'application/dwg', 'application/octet-stream'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream'],
        'csv' => ['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream'],
        'png' => ['image/png'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'tif' => ['image/tiff'],
        'tiff' => ['image/tiff'],
    ],

    'revision_pattern' => '/^[A-Za-z0-9][A-Za-z0-9._-]{0,31}$/',

];
