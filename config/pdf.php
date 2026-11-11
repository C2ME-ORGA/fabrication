<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Disque d'archivage des PDF générés
    |--------------------------------------------------------------------------
    |
    | Les PDF générés sont conservés hors du répertoire public. Aucun chemin
    | fourni par l'utilisateur ne sert de chemin de stockage.
    |
    */

    'disk' => env('PDF_DISK', 'generated_documents'),

    /*
    |--------------------------------------------------------------------------
    | Identité affichée dans les documents
    |--------------------------------------------------------------------------
    */

    'company_name' => env('PDF_COMPANY_NAME', 'C2ME'),

    'template_version' => '1',

    'paper' => 'a4',

    'orientation' => 'portrait',

    /*
    |--------------------------------------------------------------------------
    | Limite de taille d'un PDF généré (kilooctets)
    |--------------------------------------------------------------------------
    */

    'max_size_kb' => (int) env('PDF_MAX_SIZE_KB', 25600),

    /*
    |--------------------------------------------------------------------------
    | Options Dompdf
    |--------------------------------------------------------------------------
    |
    | Les ressources distantes et le PHP embarqué restent désactivés. Le
    | rendu doit se limiter aux vues Blade contrôlées et aux polices locales.
    |
    */

    'dompdf' => [
        'isRemoteEnabled' => false,
        'isPhpEnabled' => false,
        'isJavascriptEnabled' => false,
        'defaultFont' => 'DejaVu Sans',
        'dpi' => 120,
    ],

];
