# Qualité — FC-7

Ce document décrit le module qualité livré avec FC-7 : référentiel des modèles de contrôle, génération des contrôles dans les OF, saisie des résultats, non-conformités, dérogations, verrou de clôture et interfaces atelier.

Il complète [DOMAIN.md](DOMAIN.md) pour le socle Affaires / OF et [WORKSHOP.md](WORKSHOP.md) pour le module atelier FC-6.

## 1. Principes

- Aucune règle métier n'est portée par Filament : les écrans appellent les services.
- Les résultats, validations et journaux d'audit sont append-only.
- Une dérogation ne modifie jamais un résultat ni une validation : elle est prise en compte dynamiquement dans le calcul d'éligibilité.
- Le verrou de clôture ne s'applique que si l'OF possède des exigences qualité obligatoires, afin de préserver les OF historiques.
- Les contrôles sont des copies indépendantes des modèles et exigences au moment de leur génération.
- L'unicité d'un contrôle par exigence et par OF est garantie par un index unique portable, les ajouts manuels restant libres.
- Les preuves sont stockées sur un disque privé, avec chemin UUID et SHA-256.

## 2. Habilitations

| Profil | Droits principaux |
| --- | --- |
| Opérateur | Saisir un résultat, déclarer une NC, consulter les contrôles des OF actifs |
| Responsable atelier | Saisir, valider, rejeter, seconde validation critique, superviser |
| Responsable qualité | Gérer le référentiel, les NC, les dérogations, valider les contrôles |
| Administrateur | Accès complet, sans contournement de la séparation des tâches |

Règles fortes :

- le contrôleur ne peut jamais valider son propre résultat ;
- un point critique exige **deux validateurs distincts**, hors contrôleur ;
- l'administrateur ne contourne ni la ségrégation ni la double validation ;
- les drapeaux d'habilitation (`is_admin`, `is_workshop_supervisor`, `is_quality_manager`) ne sont pas mass-assignables.

## 3. Référentiel des contrôles

Un modèle (`quality_control_templates`) possède des versions (`quality_control_template_versions`) et des points (`quality_control_points`).

- Une version est créée en `draft`, puis activée après validation.
- Une seule version peut être active par modèle.
- Une version active est immuable : toute évolution passe par une nouvelle version.
- Un modèle utilisé par une gamme ou copié dans un OF ne peut pas être supprimé ; il peut être désactivé.

### Types de résultat

| Type | Saisie | Conformité |
| --- | --- | --- |
| `conformity` | Conforme / non conforme | directement portée par la valeur |
| `numeric` | Mesure et unité | calculée par rapport aux bornes incluses |
| `text` | Texte constaté + indicateur explicite | l'indicateur est obligatoire |
| `choice` | Option de liste | portée par l'option `conformant` |

Une mesure numérique sans borne est informative : `is_conform = null`.

### Échantillonnage

| Mode | Portée | Calcul |
| --- | --- | --- |
| `all` | repère/pièce | un résultat par repère |
| `every_n` | repère/pièce uniquement | sélection déterministe des repères |
| `fixed` | repère/pièce | nombre de résultats demandé |

En portée opération ou OF, les modes `all` et `fixed` sont utilisés ; `every_n` est refusé.

L'ajout d'un repère recalcule `required_results` de manière auditée. Un repère portant déjà un résultat ne peut être supprimé ou fusionné.

## 4. Génération dans les OF

`QualityControlPlanGenerationService` copie les exigences de gamme dans l'OF :

- un contrôle par exigence ;
- copie des caractéristiques du modèle, de la version et des points ;
- calcul de l'échantillonnage ;
- audit `control_generated`.

La copie est indépendante : modifier ensuite le modèle ou la gamme ne change pas les contrôles déjà générés.

Un contrôle manuel peut être ajouté avant libération de l'OF. Il ne peut être supprimé que s'il est manuel, sans résultat et avant libération.

## 5. Exécution et validation

### Cycle de vie d'un contrôle

`pending` → `in_progress` → `passed` / `failed`, avec `cancelled` possible avant clôture.  
Le statut `waived` existe dans l'énumération mais n'est pas matérialisé par le calcul actuel : la dérogation reste dynamique.

### Statuts de résultat

- Un résultat est retenu après le nombre de validations requis.
- Un résultat rejeté est conservé mais n'est plus retenu.
- Une correction crée un nouveau résultat avec `supersedes_result_id` ; l'ancien n'est jamais modifié.
- Un contrôle non conforme peut être recontrôlé par de nouveaux résultats.

### Validation requise

- point normal : 1 validation ;
- point critique : 2 validations distinctes ;
- aucune auto-validation ;
- décision de rejet motivée obligatoire.

Les paramètres sont dans `config/quality.php` (`required_validations_default`, `required_validations_critical`).

## 6. Non-conformités

Une NC peut être créée :

- depuis un résultat non conforme ;
- depuis un contrôle en échec ;
- manuellement sur un OF.

Une seule NC active peut être créée depuis un résultat donné.

La politique de gravité est figée à la création (`severity_policy_snapshot`) :

| Gravité | Blocage | Vérification | Contre-contrôle |
| --- | --- | --- | --- |
| mineure | non | non | non |
| majeure | oui | oui | non |
| critique | oui | oui | oui |

Changer `config/quality.php` après coup ne modifie pas une NC existante.

Cycle de vie : `open` → `in_progress` → `pending_verification` → `closed`, avec annulation possible et réouverture motivée depuis `closed`.

La clôture d'une NC majeure ou critique est réservée au responsable qualité ou à l'administrateur.

## 7. Dérogations

Une dérogation est sélective : elle cible précisément un contrôle, un point ou une NC.

- motif d'au moins 20 caractères ;
- aucune dérogation sur un contrôle manquant ou un contrôle final documentaire ;
- aucune dérogation sur une NC critique ;
- point `allow_deviation = false` → refus ;
- dérogation critique : double approbation par deux utilisateurs distincts ;
- révocation motivée et tracée ; suppression interdite ;
- expiration prise en compte par date.

Le calcul d'éligibilité vérifie que la cible correspond exactement et que la dérogation est active et non expirée.

## 8. Éligibilité et clôture d'OF

`QualityClosureEligibilityService` produit un diagnostic :

- éligible ou bloqué ;
- blocages typés (`ClosureBlockerCode`) ;
- avertissements ;
- contrôles, NC et dérogations concernés.

La phase `release` contrôle l'intégrité du plan. La phase `closure` contrôle en plus :

- les résultats validés ;
- les points critiques ;
- les NC bloquantes ou en attente de vérification ;
- les dérogations actives.

Le verrou est appliqué dans `WorkOrderService::transitionTo()`, pas seulement dans Filament. Un OF annulé ou verrouillé reste consultable sans blocage qualité.

## 9. Audit et immuabilité

Les événements sont écrits dans `quality_audit_logs` :

- création et cycle de vie du référentiel ;
- génération et recalcul d'échantillonnage ;
- saisie, validation, rejet, remplacement de résultat ;
- création, affectation, transition, clôture et réouverture de NC ;
- actions correctives et vérifications ;
- approbation, refus, révocation et expiration de dérogation ;
- évaluation et refus de clôture.

Les entrées d'audit, résultats et validations refusent `update` et `delete` via Eloquent. Les journaux conservent les références lisibles même après suppression d'une cible.

## 10. Preuves et sécurité

- Disque privé `documents` par défaut (`QUALITY_EVIDENCE_DISK`).
- Répertoire `quality/...`, nom UUID, extension contrôlée.
- MIME réel vérifié, taille maximale 25 Mo par défaut.
- SHA-256 calculé après écriture.
- Téléchargement uniquement via des routes authentifiées du panneau atelier.
- Une preuve hérite des droits de son parent : contrôle, NC ou dérogation.
- Nettoyage du fichier si l'écriture métier échoue.

Routes de téléchargement :

- `/workshop/quality/result-attachments/{attachment}/download` ;
- `/workshop/quality/non-conformity-attachments/{attachment}/download` ;
- `/workshop/quality/deviation-attachments/{attachment}/download`.

Les commentaires et descriptions sont affichés échappés par Blade/Filament ; aucun HTML utilisateur n'est rendu brut.

## 11. Interfaces

### Panneau atelier

- Tableau de bord : taux de conformité, contrôles en retard, NC ouvertes par gravité, dérogations actives.
- Supervision : contrôles à réaliser, contrôles en retard, validations en attente, NC en retard, dérogations expirant sous 7 jours.
- Contrôles qualité : liste filtrable par statut, OF, priorité, retard, échec, à effectuer.
- Fiche contrôle : points, avancement, résultats, validations, preuves, éligibilité et actions.
- Non-conformités : ressource atelier existante, filtrée par Policy.

### Panneau administration

- Référentiel des modèles et versions.
- Non-conformités, actions correctives, vérifications et preuves.
- Dérogations et preuves.
- Fiche OF : contrôles, NC et dérogations associés.

## 12. Modèle de données

| Table | Rôle |
| --- | --- |
| `quality_control_templates` | modèles |
| `quality_control_template_versions` | versions |
| `quality_control_points` | points de modèle |
| `workshop_routing_operation_quality_requirements` | exigences de gamme |
| `work_order_quality_controls` | contrôles générés dans les OF |
| `work_order_quality_control_points` | points figés dans l'OF |
| `quality_control_results` | résultats append-only |
| `quality_control_result_validations` | validations append-only |
| `quality_control_result_attachments` | preuves de résultat |
| `non_conformities` | non-conformités |
| `non_conformity_status_histories` | historique de statut |
| `non_conformity_corrective_actions` | actions correctives |
| `non_conformity_verifications` | vérifications et contre-contrôles |
| `non_conformity_attachments` | preuves de NC |
| `quality_deviations` | dérogations |
| `quality_deviation_targets` | cibles précises |
| `quality_deviation_attachments` | preuves de dérogation |
| `quality_audit_logs` | journal qualité immuable |
| `quality_sequences` | références NC/DER |

## 13. Tests

Tests ciblés SQLite :

```powershell
php artisan test --filter=Quality
php artisan test --filter=NonConformity
php artisan test --filter=WorkshopQuality
```

Suite complète SQLite :

```powershell
php artisan test
```

Tests de concurrence MySQL (base `fab_c2me_test`) :

```powershell
vendor/bin/phpunit -c phpunit.mysql.xml tests/Feature/OperationTimerConcurrencyTest.php
vendor/bin/phpunit -c phpunit.mysql.xml tests/Feature/QualityControlConcurrencyTest.php
vendor/bin/phpunit -c phpunit.mysql.xml tests/Feature/NonConformityConcurrencyTest.php
vendor/bin/phpunit -c phpunit.mysql.xml tests/Feature/QualityDeviationConcurrencyTest.php
```

Ces tests sont ignorés sous SQLite. Ne jamais lancer `phpunit.mysql.xml` contre la base de développement.

## 14. Jeu de démonstration

```powershell
php artisan db:seed --class=QualityDemoSeeder
```

Le seeder crée ou réutilise :

- le modèle `QUAL-DEMO-01`, avec quatre points couvrant conformité, mesure, texte et choix ;
- la gamme `GAM-QUAL-DEMO`, ses deux opérations et une exigence qualité obligatoire.

Il est idempotent.

## 15. Limites connues

- Les métriques du tableau de bord sont calculées en mémoire à partir des contrôles actifs ; un volume très important pourra justifier des agrégats SQL dédiés.
- Le statut `waived` n'est pas matérialisé : l'éligibilité reste calculée à la demande.
- La seconde validation critique reste dépendante de la disponibilité d'un second utilisateur habilité distinct.
- Les preuves ne sont pas versionnées : une nouvelle preuve est ajoutée, jamais substituée.
- MySQL 8 n'autorise pas une colonne générée qui référence une colonne portant une clé étrangère. L'unicité de la version active et de la NC active par résultat est donc maintenue par les services sous verrou, protégée par des index uniques.
