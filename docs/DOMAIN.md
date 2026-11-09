# Affaires et ordres de fabrication — socle fonctionnel

Ce document décrit le socle Affaires / Ordres de fabrication et le module Documents techniques : modèle de données, numérotation, statuts, règles métier, stockage et traçabilité.

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

## Module Documents techniques

### Modèle

- **`documents`** : document métier (affaire et/ou OF), `category`, `title`, `reference`, `description`, `status` (`active` / `archived`), `current_version_id`, `created_by`.
- **`document_versions`** : un dépôt = une ligne immuable. L'indice métier (`revision`) est distinct de `version_number`, entier monotone par document. Unicité `(document_id, revision)` et `(document_id, version_number)`.
- **`work_order_document_snapshots`** : instantané des versions approuvées applicables, avec motif `release` ou `re_review`, validateur/date de validation, auteur et date du figeage. L'historique n'est jamais écrasé : une remise en revue ajoute des lignes.
- **`document_audit_logs`** : journal des actions sensibles (document, version, OF, utilisateur, date, commentaire, métadonnées).

### Rattachement

Un document doit être rattaché à une affaire, à un OF, ou aux deux. Si les deux sont renseignés, l'OF doit appartenir à l'affaire. Si seul l'OF est renseigné, l'affaire est retrouvée par la relation de l'OF. L'association ne peut plus être modifiée dès qu'une version existe.

### Indices et version courante

- Le premier indice proposé est `A`, puis `B`, `C`… ; les indices numériques (`01`, `02`) et composés (`A01`, `A02`) sont acceptés.
- Un indice ne peut pas être réutilisé pour un même document.
- `documents.current_version_id` pointe vers la **dernière version téléversée**, quel que soit son statut (brouillon, en attente, refusée…).
- La **version applicable** à un OF est la dernière version `approved` (`latestApprovedVersion`), jamais déduite d'un tri alphabétique.

### Statuts de version et transitions

| Statut | Libellé |
| --- | --- |
| `draft` | Brouillon |
| `pending_validation` | En attente de validation |
| `approved` | Validée |
| `rejected` | Refusée |
| `superseded` | Remplacée |

Transitions :

| Depuis | Vers |
| --- | --- |
| draft | pending_validation |
| pending_validation | approved, rejected |
| approved | superseded (automatique lors de l'approbation d'une version plus récente) |
| rejected | — |
| superseded | — |

Un dépôt n'est jamais approuvé automatiquement. L'approbation enregistre le validateur, la date et le commentaire. Une version approuvée ne peut pas être modifiée en place : il faut une nouvelle version. Une version plus ancienne ne peut pas être approuvée si une version plus récente l'est déjà.

### Application à un OF

Sont applicables à un OF :

1. les documents rattachés directement à cet OF ;
2. les documents rattachés à l'affaire sans OF.

Les documents rattachés à un autre OF de la même affaire sont exclus. Les documents archivés sont exclus.

À la libération d'un OF (`WorkOrderService::transitionTo(..., Released)`), les dernières versions approuvées applicables sont figées dans `work_order_document_snapshots` dans la même transaction que la libération. Les versions en attente ou refusées ne sont jamais utilisées. Un OF libéré sans document reste libérable (comportement historique conservé) ; l'obligation de documents obligatoires reste à définir métier.

Une nouvelle version approuvée après libération :

- ne modifie aucune référence figée ;
- est signalée dans la fiche document (OF libérés utilisant une révision antérieure) et par une notification à l'approbation ;
- ne change jamais automatiquement la référence d'un OF.

La remise en revue est une action explicite et autorisée sur un OF `released`, `in_progress` ou `quality_check` : elle ajoute des snapshots `re_review` avec motif, sans supprimer les snapshots `release`.

### Stockage et sécurité

- Disque privé `documents` : `storage/app/private/documents`, jamais servi directement depuis `public/`.
- Nom de fichier généré (`UUID.extension`) ; le nom d'origine n'est qu'une métadonnée.
- Limite configurable `DOCUMENTS_MAX_SIZE_KB` (50 Mo par défaut).
- Extensions et MIME contrôlés ; le MIME est détecté sur le contenu.
- SHA-256 calculé après stockage.
- Téléchargement par route authentifiée + policy ; un utilisateur non administrateur reçoit `403`.
- Les fichiers ne sont jamais écrasés ; les anciennes versions restent téléchargeables.
- Si la transaction de base échoue après l'écriture du fichier, le nouveau fichier est supprimé. Les anciens fichiers ne sont jamais supprimés par un dépôt.
- Commande de maintenance `documents:find-orphans [--delete]` pour les fichiers présents sur le disque mais non référencés.

### Traçabilité

`document_audit_logs` couvre : création/modification/archivage du document, dépôt, soumission, approbation, refus, remplacement, figeage à la libération, remise en revue et téléchargement. Chaque ligne conserve l'utilisateur, la date, la version, l'OF et le commentaire éventuel.

## Pièces, repères et nomenclature (BOM)

### Modèle

- **`work_order_items`** : pièces d'un OF. Unicité `(work_order_id, mark)`. Le repère est normalisé (trim, espaces réduits, accents translittérés, majuscules) avant stockage afin d'obtenir le même comportement sous MySQL et SQLite.
- **`work_order_bom_imports`** : historique des imports réussis. Unicité `(work_order_id, sha256)`. La suppression d'un OF possédant un import est restreinte ; l'historique n'est jamais supprimé silencieusement.
- Un OF ne peut pas être supprimé si l'une de ses pièces possède une quantité fabriquée ou rebutée : l'historique de production est conservé.

Champs principaux : `designation`, `profile`, `steel_grade`, `length_mm`, `quantity_planned`, `quantity_produced`, `quantity_rejected`, `unit_weight_kg`, `total_weight_kg`, `operation_group`, `status`, `notes`.

### Quantités et poids

- `quantity_produced` représente le nombre total de pièces produites, rebuts compris.
- `quantity_accepted = quantity_produced - quantity_rejected` (calculé, non stocké).
- `quantity_remaining = max(quantity_planned - quantity_accepted, 0)` : les rebuts doivent être refabriqués.
- Contraintes : quantités entières, non négatives, `quantity_rejected <= quantity_produced`.
- `total_weight_kg = unit_weight_kg × quantity_planned`, calculé côté serveur et recalculé à chaque sauvegarde. Aucune valeur de poids total n'est acceptée depuis un formulaire ou un CSV.

### Statuts

| Statut | Libellé |
| --- | --- |
| `todo` | À fabriquer |
| `in_progress` | En fabrication |
| `partially_produced` | Partiellement fabriquée |
| `produced` | Fabriquée |
| `closed` | Clôturée |
| `cancelled` | Annulée |

La quantité acceptée détermine l'état de production. `closed` et `cancelled` sont des états finaux et nécessitent une action explicite. Les transitions sont contrôlées par `WorkOrderItemStatus` et `WorkOrderItemService`, jamais par un formulaire.

### Fusion des repères

Identité technique : `designation`, `profile`, `steel_grade`, `length_mm` normalisés.

- Même repère + caractéristiques identiques : fusion, addition de `quantity_planned` uniquement.
- Production, rebuts, statut et informations existantes conservés.
- Caractéristiques différentes : refus explicite, aucune écriture partielle.
- Un repère peut exister dans plusieurs OF différents.

### Import CSV

Format strict, transactionnel et idempotent :

- UTF-8 obligatoire, BOM UTF-8 toléré, UTF-16 et encodages invalides rejetés.
- Séparateur détecté : virgule, point-virgule ou tabulation.
- Colonnes obligatoires : `mark`, `designation`, `quantity_planned`.
- Colonnes optionnelles : `profile`, `steel_grade`, `length_mm`, `unit_weight_kg`, `operation_group`, `notes`.
- Colonnes interdites : `quantity_produced`, `quantity_rejected`, `status`, `total_weight_kg`.
- Taille maximale configurable : 5 Mo par défaut (`BOM_IMPORTS_MAX_SIZE_KB`).
- Toutes les lignes sont validées avant écriture. Une seule ligne invalide rejette l'import complet.
- Les erreurs sont retournées avec numéro de ligne, colonne, valeur et motif.
- Les doublons internes et les doublons avec les pièces existantes sont détectés avant confirmation.
- La prévisualisation ne crée aucune pièce, aucun historique et ne stocke aucun fichier.
- La confirmation relit et revalide le fichier côté serveur, verrouille l'OF et les pièces concernées, recalcule les fusions puis exécute l'import dans une transaction unique.
- Les colonnes supplémentaires sont ignorées et enregistrées dans le mapping.

### Stockage et historique

- Disque privé `bom_imports` : `storage/app/private/bom_imports`, jamais dans `public/`.
- Chemin généré côté serveur : `work-orders/{work_order_id}/{uuid}.csv`. Le nom d'origine est une métadonnée.
- SHA-256 calculé sur le fichier brut. Un même hash ne peut être importé qu'une fois sur un même OF.
- Seuls les imports confirmés et réussis sont historisés (`completed`). Un aperçu ou un import rejeté ne crée aucun fichier, aucune ligne et ne bloque pas une nouvelle tentative.
- Si la transaction échoue après stockage, le nouveau fichier est supprimé.
- Téléchargement par route authentifiée et policy `WorkOrderBomImportPolicy` ; aucun chemin physique n'est exposé.

### Limites connues

- SQLite ne permet pas de démontrer le verrouillage concurrent ; les tests MySQL dédiés valident le verrou OF et la contrainte unique de hash.
- L'import ne modifie jamais les quantités fabriquées ou rebutées.
- Les fichiers CSV sont limités à 5 Mo et 5 000 lignes par défaut, configurables.
- L'import ne gère pas encore les colonnes de production, les opérations atelier détaillées ou les contrôles qualité.

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
