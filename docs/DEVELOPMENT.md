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

## Après une modification du socle Affaires / OF

```powershell
php artisan migrate
php artisan test
vendor/bin/pint app database tests config
php artisan route:list --path=admin
php artisan migrate:status
```

La file d'attente `database` utilise la table `queue_jobs` : la table `jobs` est réservée aux affaires. Ce choix est appliqué par la migration `relocate_queue_jobs_table` et `config/queue.php`.

## Déploiement et file d'attente

- Arrêter les workers (`php artisan queue:restart` puis attendre la fin des jobs en cours) avant de lancer la migration qui renomme `jobs` en `queue_jobs`.
- Ne pas définir `DB_QUEUE_TABLE=jobs` dans un environnement : laisser la valeur par défaut `queue_jobs`.
- La migration est idempotente : si `queue_jobs` existe déjà, le renommage est ignoré. Le rollback ne renomme `queue_jobs` en `jobs` que si la table métier `jobs` n'existe plus.

Le détail du modèle de données, de la numérotation et des transitions d'OF est documenté dans [DOMAIN.md](DOMAIN.md).

## Conventions
- Toute mutation métier importante passe par une action/service testable.
- Les transitions d'OF sont contrôlées côté serveur et couvertes par des tests.
- Les documents utilisent un stockage privé.
- Ne pas remplacer un indice de plan associé à un OF libéré ; créer une révision et consigner l'impact.
- Toute modification du schéma est versionnée par migration.
- Filament gère les écrans administratifs ; les règles métier ne doivent pas exister uniquement dans les formulaires.
- Aucun secret, fichier `.env` ou donnée client réelle ne doit être commité.
