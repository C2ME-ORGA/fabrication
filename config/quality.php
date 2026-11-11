<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Politique de gravité des non-conformités
    |--------------------------------------------------------------------------
    |
    | Cette matrice est copiée dans la non-conformité au moment de sa création
    | (severity_policy_snapshot). Une évolution de la configuration ne modifie
    | donc jamais une NC existante. Le Lot 4 consomme cette section ; elle est
    | déclarée ici avec le reste de la configuration qualité.
    |
    */

    'severities' => [
        'minor' => [
            'blocking' => false,
            'verification' => false,
            'counter_control' => false,
            'due_days' => 30,
        ],
        'major' => [
            'blocking' => true,
            'verification' => true,
            'counter_control' => false,
            'due_days' => 15,
        ],
        'critical' => [
            'blocking' => true,
            'verification' => true,
            'counter_control' => true,
            'due_days' => 5,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Contrôles et validation
    |--------------------------------------------------------------------------
    */

    'controls' => [
        'sampling_modes' => ['all', 'every_n', 'fixed'],
        'required_validations_default' => 1,
        'required_validations_critical' => 2,
        'segregation_on_critical' => true,
        'reopen_on_item_added_after_pass' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Verrou de clôture des ordres de fabrication
    |--------------------------------------------------------------------------
    |
    | Le verrou n'est appliqué que si l'OF possède au moins une exigence
    | obligatoire, afin de préserver le comportement historique (FC-6).
    |
    */

    'closure' => [
        'enforce_only_when_requirements_exist' => true,
        'block_on_pending_verification' => true,
        'block_on_non_conforming_mandatory' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Version des règles de libération qualité
    |--------------------------------------------------------------------------
    |
    | Copiée dans chaque preuve work_order_quality_releases afin de pouvoir
    | interpréter ultérieurement l'instantané d'éligibilité.
    |
    */

    'rules_version' => env('QUALITY_RULES_VERSION', '1'),

    /*
    |--------------------------------------------------------------------------
    | Dérogations qualité
    |--------------------------------------------------------------------------
    */

    'deviations' => [
        'reason_min_length' => 20,
        'require_second_approver_for_critical' => true,
        'allowed_on_missing_control' => false,
        'forbid_on_final_documentary' => true,
        'forbid_on_critical_non_conformity' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Preuves et pièces jointes
    |--------------------------------------------------------------------------
    |
    | Les fichiers sont stockés hors du répertoire public, sous un chemin
    | généré côté serveur. Le module documents n'est pas détourné.
    |
    */

    'evidence' => [
        'disk' => env('QUALITY_EVIDENCE_DISK', 'documents'),
        'directory' => 'quality',
        'max_size_kb' => 25600,
        'allowed_extensions' => ['pdf', 'png', 'jpg', 'jpeg', 'webp', 'heic'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Affichage des dates
    |--------------------------------------------------------------------------
    |
    | Le stockage reste en UTC ; seul l'affichage est converti.
    |
    */

    'display_timezone' => env('APP_DISPLAY_TIMEZONE', 'Europe/Paris'),

];
