<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Stockage des fichiers de nomenclature importés
    |--------------------------------------------------------------------------
    */

    'disk' => env('BOM_IMPORTS_DISK', 'bom_imports'),

    'max_size_kb' => (int) env('BOM_IMPORTS_MAX_SIZE_KB', 5120),

    'max_rows' => (int) env('BOM_IMPORTS_MAX_ROWS', 5000),

    'allowed_extensions' => ['csv'],

    'mime_types' => [
        'csv' => [
            'text/csv',
            'text/plain',
            'application/csv',
            'application/vnd.ms-excel',
        ],
    ],

    'delimiters' => [',', ';', "\t"],

    'required_columns' => [
        'mark',
        'designation',
        'quantity_planned',
    ],

    'forbidden_columns' => [
        'quantity_produced',
        'quantity_rejected',
        'status',
        'total_weight_kg',
    ],

    /*
    |--------------------------------------------------------------------------
    | En-têtes CSV
    |--------------------------------------------------------------------------
    |
    | Les en-têtes sont normalisés (minuscules, accents retirés, séparateurs
    | remplacés par des underscores) avant d'être comparés à ces alias.
    |
    */

    'column_aliases' => [
        'repere' => 'mark',
        'reference' => 'mark',
        'designation' => 'designation',
        'profil' => 'profile',
        'nuance' => 'steel_grade',
        'nuance_acier' => 'steel_grade',
        'longueur' => 'length_mm',
        'longueur_mm' => 'length_mm',
        'quantite_prevue' => 'quantity_planned',
        'qte_prevue' => 'quantity_planned',
        'quantite' => 'quantity_planned',
        'poids_unitaire' => 'unit_weight_kg',
        'poids_unitaire_kg' => 'unit_weight_kg',
        'groupe_operations' => 'operation_group',
        'groupe_d_operations' => 'operation_group',
        'observations' => 'notes',
        'observation' => 'notes',
        'quantite_fabriquee' => 'quantity_produced',
        'qte_fabriquee' => 'quantity_produced',
        'quantite_rejetee' => 'quantity_rejected',
        'quantite_rebutee' => 'quantity_rejected',
        'rebuts' => 'quantity_rejected',
        'statut' => 'status',
        'etat' => 'status',
        'poids_total' => 'total_weight_kg',
        'poids_total_kg' => 'total_weight_kg',
    ],

];
