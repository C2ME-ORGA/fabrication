# Développement local

## Stack imposée
- Laravel 13
- Livewire 4
- FilamentPHP 5
- PHP supporté par Laravel 13 et les dépendances sélectionnées
- Composer, Node.js/npm, Git
- MySQL/MariaDB (SQLite peut être utilisé pour des tests isolés si compatible avec les migrations)

## Bootstrap
Depuis la racine du dépôt sous Windows PowerShell :

```powershell
Set-ExecutionPolicy -Scope Process Bypass
.\scripts\bootstrap.ps1
```

Le script échoue volontairement si `artisan` ou `composer.json` existe déjà. Il ne doit pas être relancé sur un projet initialisé. Après installation, vérifier `composer.json` et `composer.lock`, configurer `.env`, puis exécuter :

```powershell
php artisan migrate
php artisan test
npm install
npm run build
```

## Conventions
- Toute mutation métier importante passe par une action/service testable.
- Les transitions d'OF sont contrôlées côté serveur et couvertes par des tests.
- Les documents utilisent un stockage privé.
- Ne pas remplacer un indice de plan associé à un OF libéré ; créer une révision et consigner l'impact.
- Toute modification du schéma est versionnée par migration.
- Filament gère les écrans administratifs ; les règles métier ne doivent pas exister uniquement dans les formulaires.
- Aucun secret, fichier `.env` ou donnée client réelle ne doit être commité.
