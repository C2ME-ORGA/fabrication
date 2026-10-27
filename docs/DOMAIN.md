# Affaires et ordres de fabrication — socle fonctionnel

Ce document décrit le premier socle : affaires (`jobs`), ordres de fabrication (`work_orders`), numérotation, statuts et règles métier.

## Modèle de données

### `jobs` (affaires)

| Colonne | Type | Règle |
| --- | --- | --- |
| `id` | bigint | clé primaire |
| `code` | varchar(255) | obligatoire, unique (ex. `AFF-2026-001`) |
| `client_name` | varchar(255) | obligatoire ; champ simple en attendant le module Clients |
| `name` | varchar(255) | désignation obligatoire |
| `site_address` | varchar(255) | nullable |
| `status` | varchar(20) | `draft` par défaut, indexé |
| `notes` | text | nullable |
| `created_at`, `updated_at` | timestamps | |

Statuts : `draft` (brouillon), `active` (active), `on_hold` (suspendue), `closed` (clôturée).

### `work_orders` (ordres de fabrication)

| Colonne | Type | Règle |
| --- | --- | --- |
| `id` | bigint | clé primaire |
| `job_id` | bigint | obligatoire, FK `jobs.id`, `ON DELETE RESTRICT` |
| `number` | varchar(255) | unique, généré côté serveur (`OF-AAAA-NNNN`) |
| `title` | varchar(255) | obligatoire |
| `description` | text | nullable |
| `status` | varchar(20) | `draft` par défaut |
| `priority` | varchar(20) | `normal` par défaut |
| `planned_start_at`, `planned_end_at` | timestamp | nullable |
| `actual_start_at`, `actual_end_at` | timestamp | nullable, pilotés par le service |
| `notes` | text | nullable |
| `created_by` | bigint | nullable, FK `users.id`, `ON DELETE SET NULL` |
| `released_by` | bigint | nullable, FK `users.id`, `ON DELETE SET NULL` |
| `released_at` | timestamp | nullable |
| `created_at`, `updated_at` | timestamps | |

Index : `status + priority`, `job_id + status`, `planned_end_at`. La clé étrangère `job_id` est indexée par MySQL ; `number` est couvert par son index unique.

Une affaire peut contenir plusieurs OF ; un OF appartient à une seule affaire. `Job::workOrders()` et `WorkOrder::job()` exposent la relation. Supprimer une affaire avec des OF est interdit à trois niveaux : action Filament masquée par la policy, contrôle dans `JobPolicy::delete()`, et contrainte SQL `RESTRICT`.

### `work_order_sequences` (compteurs annuels)

| Colonne | Type |
| --- | --- |
| `year` | smallint, clé primaire |
| `last_number` | int non signé, défaut 0 |
| `created_at`, `updated_at` | timestamps |

## Numérotation des OF

Format : `OF-AAAA-NNNN`, par exemple `OF-2026-0001`, avec un compteur remis à zéro chaque année.

`App\Services\WorkOrderNumberGenerator` :

1. ouvre (ou rejoint) une transaction ;
2. insère la ligne de l'année si elle n'existe pas (`insertOrIgnore`) ;
3. lit la ligne avec `SELECT ... FOR UPDATE` (`lockForUpdate()`) ;
4. incrémente `last_number` et formate le numéro.

Le numéro est généré dans la même transaction que l'insertion de l'OF (`WorkOrderService::create()`). Si la création échoue, la transaction est annulée et le numéro n'est pas consommé. Un OF annulé conserve son numéro, qui n'est jamais réutilisé. La contrainte `unique` sur `work_orders.number` sert de dernière barrière.

L'année provient de `now()` dans le fuseau configuré par `config('app.timezone')`.

## Statuts et transitions des OF

| Statut | Libellé |
| --- | --- |
| `draft` | Brouillon |
| `ready` | Prêt |
| `released` | Libéré |
| `in_progress` | En cours |
| `quality_check` | Contrôle qualité |
| `completed` | Terminé |
| `cancelled` | Annulé |

Matrice (`WorkOrderStatus::allowedTransitions()`) :

| Depuis | Vers |
| --- | --- |
| draft | ready, cancelled |
| ready | released, cancelled |
| released | in_progress, cancelled |
| in_progress | quality_check, cancelled |
| quality_check | in_progress, completed, cancelled |
| completed | — (figé) |
| cancelled | — (figé) |

Règles complémentaires :

- la libération (`released`) exige que l'affaire soit au statut `active` ;
- le passage en `in_progress` renseigne `actual_start_at` s'il est vide ;
- le passage en `completed` renseigne `actual_end_at` ;
- un OF `completed` ou `cancelled` ne peut plus être modifié ;
- seul un OF `draft` peut être supprimé ; une annulation conserve l'enregistrement.

## Règles métier appliquées côté serveur

Toutes les mutations passent par `App\Services\WorkOrderService` :

- création impossible sans affaire, pour une affaire clôturée ou suspendue ;
- `job_id`, `number`, `status`, `created_by`, `released_by`, `released_at`, `actual_start_at`, `actual_end_at` ne sont pas modifiables via un payload de formulaire ;
- l'affaire d'un OF ne peut pas être changée ;
- `planned_end_at` ne peut pas précéder `planned_start_at` ;
- les transitions invalides et les modifications d'un OF figé lèvent une `ValidationException` ;
- l'affaire est verrouillée (`lockForUpdate`) pendant la création pour éviter qu'elle soit clôturée concurremment.

## Administration Filament

Ressources disponibles dans le panneau `/admin` :

- `/admin/jobs` — affaires (liste, recherche code/client/désignation, filtre statut, fiche avec OF liés) ;
- `/admin/work-orders` — ordres de fabrication (recherche numéro/désignation/affaire, filtres statut/priorité, actions de transition explicites).

L'accès au panneau exige `users.is_admin = true`. Les nouveaux utilisateurs n'ont pas cet accès. Pour autoriser un utilisateur existant :

```powershell
php artisan make:filament-user   # création ponctuelle d'un compte (mot de passe choisi, non versionné)
php artisan admin:promote utilisateur@example.com
```

Aucun compte administrateur avec mot de passe fixe n'est fourni.

## Tests

```powershell
php artisan test
```

La suite par défaut utilise SQLite en mémoire. Le verrouillage concurrent MySQL/MariaDB est couvert par `test_the_sequence_row_is_locked_for_update_on_mysql`, qui est ignoré sur SQLite. Pour le vérifier réellement, créer une base de test dédiée puis lancer la configuration MySQL fournie :

```sql
CREATE DATABASE fab_c2me_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

```powershell
vendor/bin/phpunit -c phpunit.mysql.xml
```

`phpunit.mysql.xml` force `DB_CONNECTION=mysql` et `DB_DATABASE=fab_c2me_test` (et non les valeurs du `.env`). Ne jamais l'exécuter contre la base de développement.

Les factories génèrent des numéros de test à partir de `9000` et ne mettent pas à jour `work_order_sequences` : elles ne doivent pas être utilisées pour un seeding de production. Utiliser `WorkOrderService::create()` pour créer des OF réels.
