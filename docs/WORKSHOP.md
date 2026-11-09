# Atelier — gammes, opérations, exécution et chronométrage

Ce document décrit le fonctionnement du module atelier livré avec FC-6 : gammes modèles, génération des opérations d'un ordre de fabrication (OF), moteur d'exécution, chronométrage, supervision et règles de sécurité. Il complète [DOMAIN.md](DOMAIN.md), qui couvre le socle Affaires / OF et les documents techniques.

## 1. Documentation fonctionnelle

### 1.1 Gammes atelier

Une gamme atelier (`workshop_routings`) est un modèle réutilisable. Elle contient des opérations ordonnées (`workshop_routing_operations`) et un graphe de dépendances (`workshop_routing_operation_dependencies`).

- **Création** : une gamme est créée **inactive**, avec un code unique normalisé en majuscules. Elle peut être créée avec ses opérations dans la même transaction.
- **Modification** : le code, le nom, la description et l'état d'activation peuvent être modifiés. Les opérations peuvent être ajoutées, modifiées, réordonnées ou supprimées.
- **Activation** : réservée aux responsables atelier et aux administrateurs. Elle exige :
  - au moins une opération ;
  - au moins une opération obligatoire ;
  - des codes d'opération uniques dans la gamme ;
  - des positions strictement positives et uniques ;
  - des durées estimées positives ou nulles ;
  - un graphe de dépendances sans cycle, sans auto-dépendance et limité aux opérations de la gamme.
- **Désactivation** : toujours possible, y compris pour une gamme déjà utilisée. Une gamme inactive reste consultable et conservée pour l'historique ; elle n'est plus proposée pour de nouveaux OF.
- **Suppression** : réservée aux gammes jamais référencées par un OF et n'ayant jamais généré d'opération. Une gamme historique doit être désactivée, pas supprimée.
- **Positions** : elles servent à l'affichage et sont normalisées par pas de 10 (10, 20, 30…). Les dépendances restent la seule règle d'enchaînement.

### 1.2 Génération des opérations dans un OF

L'affectation d'une gamme à un OF est faite par `WorkOrderRoutingService` :

1. l'OF doit être modifiable (statut `draft` ou `ready`) ;
2. l'acteur doit être **administrateur** ; le responsable atelier gère le catalogue des gammes mais ne peut pas affecter une gamme à un OF ;
3. la gamme doit être active et valide ;
4. les opérations de la gamme sont **copiées** dans `work_order_operations` : code, nom, consignes, position, durée estimée, caractère obligatoire ;
5. les dépendances sont **remappées** vers les opérations générées ;
6. une opération sans dépendance est créée `ready`, sinon `pending` ;
7. chaque copie et chaque dépendance sont auditées.

La copie est **indépendante** : modifier ou supprimer la gamme modèle ensuite ne change pas les opérations déjà générées.

Avant la libération de l'OF, un administrateur peut modifier, réordonner ou supprimer les opérations générées (si aucun historique) et ajuster leurs dépendances. Après libération, ces actions sont refusées. Un remplacement de gamme n'est possible que si les opérations actuelles n'ont ni session de temps ni historique d'exécution métier.

### 1.3 Statuts et transitions des opérations

| Statut | Libellé | Transitions autorisées |
| --- | --- | --- |
| `pending` | En attente | `ready` (quand toutes les dépendances sont terminées) |
| `ready` | Prête | `in_progress`, `cancelled` |
| `in_progress` | En cours | `paused`, `blocked`, `completed`, `cancelled` |
| `paused` | En pause | `in_progress`, `blocked`, `cancelled` |
| `blocked` | Bloquée | `ready`, `pending`, `in_progress`, `cancelled` |
| `completed` | Terminée | aucune |
| `cancelled` | Annulée | aucune |

Règles complémentaires :

- le démarrage exige une opération `ready`, un OF libéré et toutes les dépendances terminées ;
- la pause et le blocage arrêtent automatiquement les chronomètres actifs ;
- le blocage exige un motif non vide (2 000 caractères maximum) ;
- la reprise d'une opération bloquée est réservée aux responsables atelier et aux administrateurs ;
- le déblocage recalcule le statut : `ready` si les dépendances sont satisfaites, sinon `pending` ;
- la terminaison exige une opération `in_progress`, des dépendances terminées et aucun chronomètre actif ;
- l'annulation est possible depuis `ready`, `in_progress`, `paused` ou `blocked`, avec motif obligatoire et sans chronomètre actif ;
- une opération terminée ou annulée est définitive pour les actions standard.

Clôture ou annulation d'un OF :

- elle est refusée si un chronomètre est encore actif sur l'OF ;
- elle est refusée si une opération est encore `in_progress` ;
- chaque opération `in_progress` doit être annulée explicitement via le moteur d'exécution, avec son motif obligatoire ;
- `WorkOrderService` n'annule jamais les opérations automatiquement et ne modifie jamais les sessions ou durées historiques ;
- une opération `completed` n'est jamais annulée implicitement.

### 1.4 Dépendances entre opérations

Une arête « A dépend de B » signifie que B doit être terminée avant que A puisse démarrer. Les cycles directs et indirects sont refusés avant écriture. Quand une opération est terminée, seules les opérations qui en dépendent directement sont recalculées ; une opération reste `pending` tant qu'au moins une de ses dépendances n'est pas terminée.

Une opération bloquée n'est jamais rétrogradée automatiquement par la propagation : elle conserve son statut jusqu'à un déblocage explicite.

### 1.5 Chronométrage

- Une **session active** est une ligne `operation_time_entries` dont `ended_at` est nul.
- Un opérateur ne peut avoir qu'**une seule session active**, tous OF confondus. La contrainte est garantie par un index unique SQL et vérifiée dans la transaction.
- **Plusieurs opérateurs** peuvent chronométrer la même opération en même temps.
- Le démarrage et l'arrêt sont explicites, depuis l'interface atelier.
- La durée est calculée **côté serveur** à partir de `started_at` et de l'heure serveur d'arrêt : aucune durée n'est fournie par le navigateur.
- Le démarrage n'est possible que sur une opération `in_progress`, d'un OF libéré, avec dépendances satisfaites.
- L'arrêt d'une session appartient à son opérateur : un opérateur ne peut pas arrêter la session d'un autre.
- Une pause ou un blocage d'opération arrête automatiquement toutes les sessions actives de cette opération et trace un événement `timer_auto_stopped`.
- Les sessions actives ne sont **pas comptées** dans les agrégats tant qu'elles ne sont pas arrêtées.

### 1.6 Saisie manuelle et corrections

- La **saisie manuelle** est réservée aux responsables atelier et aux administrateurs. Elle exige un opérateur existant, une durée strictement positive (maximum 31 536 000 secondes) et un OF en exécution. L'opérateur saisi peut différer de l'auteur de la saisie.
- La **correction** est réservée aux responsables atelier et aux administrateurs. Elle ne modifie jamais l'entrée d'origine : elle crée une nouvelle entrée `adjustment` reliée par `original_entry_id`.
- Une correction exige un motif non vide (2 000 caractères maximum), une durée corrigée positive ou nulle, et une session déjà clôturée. Une correction ne peut pas corriger une autre correction.
- Le journal d'audit conserve l'entrée d'origine, la correction, l'auteur, le motif et les durées avant/après.

### 1.7 Règles de calcul des agrégats

`OperationTimeAggregationService` calcule les temps réels à la demande, sans stockage redondant :

- seules les entrées de type `timer` et `manual` **clôturées** sont prises en compte ;
- pour chaque entrée d'origine, seule la **dernière correction** (`adjustment`, identifiant le plus élevé) remplace sa durée ; les corrections ne sont jamais additionnées à l'original ;
- les sessions actives sont exclues ;
- `realSeconds` est la somme des durées effectives (heures-homme) ;
- `calendarSeconds` est l'amplitude entre le premier début et la dernière fin des entrées prises en compte (utile pour distinguer le temps calendaire du temps homme) ;
- `estimatedSeconds` provient de `estimated_duration_minutes × 60` ;
- `varianceSeconds = realSeconds − estimatedSeconds` ;
- `consumptionPercent = realSeconds / estimatedSeconds × 100`, ou `null` si l'estimé est nul ;
- les ventilations `byOperator` et `byOperation` utilisent les durées effectives ;
- `entryCount` compte les entrées d'origine prises en compte, pas les corrections.

Le temps réel d'un OF est la somme des temps réels de ses opérations. Aucun double comptage n'est possible entre une entrée et sa correction.

## 2. Guide opérateur

1. **Accéder au panneau atelier** : ouvrir `/workshop` et se connecter avec son compte.
2. **Rechercher un OF ou une opération** : utiliser la liste « Opérations atelier » et la recherche (numéro d'OF, code d'affaire, code ou nom d'opération).
3. **Consulter les consignes** : ouvrir la fiche de l'opération pour lire les consignes, les dépendances et l'historique.
4. **Démarrer une opération** : sur une opération « Prête », utiliser l'action « Démarrer ».
5. **Démarrer son chronomètre** : sur une opération « En cours », utiliser « Démarrer le chrono ». Un seul chrono actif est autorisé par opérateur.
6. **Mettre en pause** : utiliser « Mettre en pause ». Les chronomètres actifs de l'opération sont arrêtés automatiquement.
7. **Signaler un blocage** : utiliser « Bloquer » et saisir un motif obligatoire.
8. **Reprendre le travail** : après déblocage par un responsable, utiliser « Reprendre », puis redémarrer le chrono.
9. **Arrêter son chronomètre** : utiliser « Arrêter le chrono ». La durée est calculée par le serveur.
10. **Terminer une opération** : lorsque le travail est fini et le chrono arrêté, utiliser « Terminer ».

Le tableau de bord et le bandeau « Chronomètres actifs » s'actualisent automatiquement toutes les 5 secondes. Un opérateur ne voit dans ce bandeau que ses propres sessions actives.

## 3. Guide responsable atelier

Un responsable atelier possède `users.is_workshop_supervisor = true` et n'a pas besoin d'être administrateur. Il accède au panneau `/workshop`, à la gestion du catalogue des gammes et à la supervision. Il ne peut pas accéder au panneau `/admin` et ne peut pas affecter une gamme à un OF : cette action est réservée aux administrateurs.

- **Gestion des gammes** : créer, compléter, activer ou désactiver une gamme ; ajouter des opérations, les réordonner, gérer leurs dépendances. Une gamme ne peut être activée que si le graphe et les positions sont valides. L'affectation d'une gamme à un OF reste dans `/admin`.
- **Supervision des opérations** : la page `/workshop/supervision` présente les opérations bloquées, les dépassements de temps estimé, les OF avec opérations en cours, les opérations sans activité récente (24 h) et les temps consommés par OF.
- **Suivi des temps** : consulter, sur la fiche d'une opération, le temps estimé, le temps réel, l'écart, le pourcentage de consommation, la ventilation par opérateur et l'historique des sessions.
- **Consultation historique** : les opérations des OF clôturés ou annulés restent consultables par les responsables et les administrateurs, avec leurs sessions de chronométrage, saisies manuelles et corrections.
- **Saisie manuelle** : depuis la fiche d'une opération, saisir un temps pour un opérateur avec une durée en secondes et un commentaire facultatif. La saisie manuelle reste limitée aux OF en exécution.
- **Correction des temps** : sélectionner une session clôturée, saisir la durée corrigée et un motif obligatoire. L'entrée d'origine reste consultable ; la correction est ajoutée à l'historique. La correction reste possible après clôture ou annulation de l'OF.
- **Traitement des blocages** : analyser le motif, débloquer l'opération (le statut redevient `ready` ou `pending` selon les dépendances) ou la reprendre directement si les dépendances sont satisfaites.
- **Analyse des écarts** : comparer `estimated_duration_minutes` et le temps réel dans la fiche, le tableau de bord et la supervision. Un écart positif signale un dépassement ; le pourcentage de consommation permet de comparer les opérations entre elles.

## 4. Documentation technique

### 4.1 Modèles et relations

| Modèle | Rôle | Relations principales |
| --- | --- | --- |
| `WorkshopRouting` | Gamme modèle | `operations()`, `operationDependencies()`, `workOrders()` |
| `WorkshopRoutingOperation` | Opération d'une gamme | `routing()`, `dependencies()`, `dependents()` |
| `WorkshopRoutingOperationDependency` | Arête du graphe modèle | `operation()`, `dependsOnOperation()` |
| `WorkOrderOperation` | Opération copiée dans un OF | `workOrder()`, `sourceRoutingOperation()`, `dependencies()`, `dependents()`, `timeEntries()`, `activeTimeEntries()`, `auditLogs()`, `lastStatusAudit()` |
| `OperationTimeEntry` | Session de temps | `operation()`, `user()`, `creator()`, `corrector()`, `originalEntry()`, `corrections()` |
| `WorkOrderOperationAuditLog` | Journal immuable de l'atelier | `operation()`, `workOrder()`, `user()` |
| `User` | Rôles | `isAdmin()`, `isWorkshopSupervisor()`, `canManageWorkshopRoutings()`, `canUnblockWorkshopOperations()`, `canManageOperationTimes()` |

`WorkOrder` expose `workshopRouting()`, `operations()` et `operationAuditLogs()`. Le champ `workshop_routing_id` est protégé contre l'assignation de masse et écrit uniquement par le service d'affectation.

### 4.2 Tables principales

| Table | Contenu |
| --- | --- |
| `workshop_routings` | Gammes : code unique, nom, description, `is_active` |
| `workshop_routing_operations` | Opérations de gamme : code, nom, consignes, position, durée estimée, obligation |
| `workshop_routing_operation_dependencies` | Dépendances entre opérations d'une gamme (unique par paire) |
| `work_order_operations` | Copies indépendantes dans l'OF : statut, blocage, début, fin, source |
| `work_order_operation_dependencies` | Dépendances remappées dans l'OF (unique par paire) |
| `operation_time_entries` | Sessions `timer`, `manual`, `adjustment`, durées et traçabilité |
| `work_order_operation_audit_logs` | Journal immuable des actions atelier |

Points de schéma importants :

- `operation_time_entries.active_user_id` est une colonne générée (`CASE WHEN ended_at IS NULL THEN user_id ELSE NULL END`) avec un index unique : un opérateur ne peut avoir qu'une session active, sur SQLite comme sur MySQL.
- `work_order_operations` a une contrainte unique `(work_order_id, code)` et des index sur `(work_order_id, position)` et `(work_order_id, status)`.
- Les dépendances ont une contrainte unique par paire et des clés étrangères en cascade.
- Les journaux d'audit conservent le numéro d'OF et le code d'opération même si l'opération ou l'OF est supprimé (`nullOnDelete` sur les clés, colonnes dénormalisées conservées).

### 4.3 Services métier

| Service | Responsabilité |
| --- | --- |
| `WorkshopRoutingService` | Création, modification, activation, désactivation et suppression des gammes |
| `WorkshopRoutingOperationService` | CRUD et réorganisation des opérations de gamme, normalisation des positions |
| `OperationDependencyService` | Graphe modèle et graphe d'exécution : ajout/suppression/synchronisation, détection de cycles, propagation `pending` → `ready` |
| `WorkshopRoutingValidationService` | Validation métier d'une gamme avant activation ou affectation |
| `WorkOrderRoutingService` | Affectation/remplacement de gamme, copie des opérations, remappage des dépendances, modifications avant libération |
| `WorkOrderOperationService` | Moteur d'exécution : démarrer, pause, reprise, blocage, déblocage, terminaison, annulation |
| `OperationTimerService` | Démarrage/arrêt des chronos, unicité de session, arrêt automatique sur pause/blocage |
| `OperationTimeEntryService` | Saisie manuelle et corrections append-only |
| `OperationTimeAggregationService` | Agrégats par opération, par OF et par lot d'OF |
| `WorkOrderOperationAuditService` | Écriture du journal d'audit atelier |
| `WorkshopDashboardService` | Indicateurs du tableau de bord et de la supervision |
| `WorkOrderService` | Cycle de vie de l'OF ; refuse une clôture/annulation si un chrono est actif ou si une opération est encore `in_progress` |

### 4.4 Policies et autorisations

| Policy | Règles |
| --- | --- |
| `WorkshopRoutingPolicy` | `viewAny`, `view`, `create`, `update` : responsable atelier ou administrateur ; `delete` : en plus, gamme jamais utilisée ; `activate`/`deactivate` : état cohérent |
| `WorkOrderOperationPolicy` | `view` : OF en exécution pour tous ; en plus, OF clôturé ou annulé pour les responsables/administrateurs ; `update`/`delete`/`manageDependencies` : administrateur uniquement, avant libération et hors statut final ; `start`/`pause`/`block`/`complete` : OF en exécution ; `resume` d'une opération bloquée, `unblock` et `cancel` : en plus, responsable atelier ou administrateur ; `startTimer` : OF en exécution ; `stopTimer` : service vérifiant la propriété de la session ; `recordManualTime` : responsable/administrateur, OF en exécution, opération non annulée ; `correctTime` : responsable/administrateur, y compris après clôture/annulation |
| `WorkOrderPolicy::manageRouting` | Administrateur uniquement, OF modifiable |

L'accès au panneau `/admin` reste réservé à `users.is_admin = true`. Le panneau `/workshop` est accessible à tout utilisateur authentifié, mais chaque ressource et page applique sa policy. Le drapeau `is_workshop_supervisor` n'est pas mass assignable ; il doit être positionné explicitement en base. Aucune commande d'administration dédiée à ce rôle n'est fournie à ce jour.

### 4.5 Événements d'audit

`WorkshopOperationAuditEvent` : `created`, `updated`, `deleted`, `ready`, `dependencies_modified`, `routing_applied`, `routing_changed`, `routing_operations_deleted`, `routing_override_applied`, `started`, `paused`, `resumed`, `blocked`, `unblocked`, `completed`, `cancelled`, `timer_started`, `timer_stopped`, `timer_auto_stopped`, `manual_time_recorded`, `time_corrected`.

Chaque événement est écrit dans la même transaction que la mutation métier. `WorkOrderOperationAuditLog` refuse toute mise à jour et toute suppression via Eloquent.

### 4.6 Concurrence et intégrité

- **Ordre de verrouillage** : OF puis opération puis sessions de temps pour l'exécution ; gamme puis opérations pour les mutations de gamme. Cet ordre est constant pour éviter les interblocages.
- **MySQL** : les services utilisent `lockForUpdate()` ; les tests MySQL vérifient le verrouillage des lignes OF, opération et session.
- **SQLite** : aucun verrouillage de ligne concurrent ; l'unicité de la session active reste garantie par l'index unique sur la colonne générée.
- **Unicité** : un opérateur ne peut avoir qu'une session active, même en cas de démarrages simultanés ; le service intercepte la violation de contrainte et renvoie une erreur métier.
- **Transactions** : toutes les mutations critiques utilisent `DB::transaction(..., attempts: 3)` ; l'audit est rollbacké avec la mutation.
- **Clôture/annulation d'OF** : refusée tant qu'une session de chronométrage est active, pour éviter une session orpheline et un opérateur bloqué par la contrainte d'unicité ; refusée aussi tant qu'une opération est encore `in_progress`, afin que l'annulation reste explicite et auditée.

### 4.7 Points d'entrée Filament

Panneau `/admin` (administrateurs) :

- `/admin/work-orders` : liste des OF, transitions, approbation BE, relation manager « Opérations de fabrication » (affectation de gamme réservée aux administrateurs, édition avant libération, dépendances, exécution, chronométrage, saisie manuelle, correction) ;
- `/admin/workshop-routings` : gestion des gammes (également accessible aux responsables via le panneau atelier).

Panneau `/workshop` :

- `/workshop/dashboard` : tableau de bord ;
- `/workshop/operations` : liste des opérations des OF en exécution ; pour les responsables et administrateurs, inclut aussi l'historique des OF clôturés et annulés ; filtres, recherche, actions opérateur ;
- `/workshop/operations/{record}` : fiche d'opération (consignes, dépendances, sessions, historique, actions) ;
- `/workshop/supervision` : supervision, réservée aux responsables atelier et administrateurs ;
- `/workshop/workshop-routings` : gestion des gammes, réservée aux responsables atelier et administrateurs.

Le composant Livewire `App\Livewire\Workshop\ActiveTimers` affiche les chronomètres actifs et s'actualise toutes les 5 secondes. Les opérateurs n'y voient que leurs sessions ; les responsables voient tout l'atelier. La fiche d'une opération affiche l'historique complet des sessions de cette opération, y compris celles des autres opérateurs, pour la coordination et la traçabilité.

### 4.8 Tests

Les tests ciblés FC-6 couvrent les gammes, les opérations d'OF, le moteur d'exécution, les dépendances, les chronomètres, les corrections, les agrégats, les permissions et les interfaces Filament.

```powershell
php artisan test --filter=Workshop
php artisan test --filter=Operation
php artisan test --filter=Timing
```

Les tests de concurrence MySQL sont ignorés sur SQLite (`markTestSkipped`). Pour les exécuter réellement sur une base dédiée :

```sql
CREATE DATABASE fab_c2me_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

```powershell
vendor/bin/phpunit -c phpunit.mysql.xml tests/Feature/OperationTimerConcurrencyTest.php
vendor/bin/phpunit -c phpunit.mysql.xml tests/Feature/WorkOrderOperationConcurrencyTest.php
```

`phpunit.mysql.xml` force `DB_CONNECTION=mysql` et `DB_DATABASE=fab_c2me_test`. Ne jamais l'exécuter contre la base de développement.

### 4.9 Limites connues et périmètre

- La concurrence MySQL est vérifiée par des tests de verrouillage et de contrainte unique ; les tests PHPUnit ne lancent pas deux threads réellement simultanés. La garantie finale repose sur l'index unique et les verrous de ligne.
- Les sessions actives sont exclues des agrégats et du calcul de dépassement de la supervision tant qu'elles ne sont pas arrêtées. Leur durée en direct est visible dans le bandeau des chronomètres actifs.
- Une gamme active peut être modifiée et devenir invalide pour une affectation ; `WorkOrderRoutingService` refuse alors l'affectation. Une désactivation/réactivation permet de repasser par la validation complète.
- L'affectation d'une gamme à un OF et l'édition des opérations générées sont réservées aux administrateurs dans `/admin`. Un responsable atelier non administrateur gère le catalogue des gammes et supervise l'exécution, mais ne peut pas affecter une gamme à un OF.
- La consultation et la correction historique sont disponibles pour les responsables et administrateurs sur les OF clôturés ou annulés. Les opérateurs standard ne voient pas ces OF dans la liste atelier et ne peuvent jamais corriger un temps.
- Le garde-fou de clôture/annulation d'OF contrôle les opérations encore `in_progress`. Les opérations `paused` ou `blocked` ne bloquent pas la transition d'OF : la décision métier porte explicitement sur `in_progress`.
- Les actions « Démarrer le chrono » et « Arrêter le chrono » de la liste interrogent l'état de session de l'utilisateur pour chaque ligne affichée. Avec la pagination par défaut de 25 lignes, cela reste borné ; une optimisation par mémoïsation ou préchargement sera à prévoir si la liste s'allonge.
