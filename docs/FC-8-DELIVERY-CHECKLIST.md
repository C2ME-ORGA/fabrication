# FC-8 — Checklist de livraison

Cette checklist prépare une revue finale et une décision de livraison. Elle ne déclenche aucun déploiement, aucune migration de production, aucun commit, aucun tag et aucune action Jira.

Référence d'exploitation : [FC-8-OPERATIONS.md](FC-8-OPERATIONS.md).

---

## 1. Préparation du dépôt et de la PR

- [ ] Branche attendue : `feature/FC-8-technical-design`.
- [ ] PR #6 ouverte vers `develop`, non fusionnée.
- [ ] Commit de base relevé : `b3e3b32764690d1305bd9e59452c84e3ce5b050f`.
- [ ] `git status --porcelain -uall` relevé et conservé.
- [ ] Aucun fichier non suivi des Lots 2 à 5 supprimé ou écrasé.
- [ ] `git diff --check` sans erreur.
- [ ] Pint passé sur les fichiers modifiés.
- [ ] Workflow GitHub Actions vert :
  - [ ] job SQLite (`test`) : PHPUnit, Pint, build Vite ;
  - [ ] job MySQL 8.4 (`mysql`) : migrations et sélection critique FC-8.
- [ ] Aucun commit, push, merge, tag ou déploiement effectué.
- [ ] `develop` non modifiée.

Le job MySQL peut être rejoué localement sur une base dédiée :

```powershell
vendor/bin/phpunit -c phpunit.mysql.xml tests/Feature/ShipmentConcurrencyTest.php
vendor/bin/phpunit -c phpunit.mysql.xml tests/Feature/WorkOrderPdfConcurrencyTest.php
```

## 2. Dépendances et compatibilité

- [ ] `composer validate` OK.
- [ ] `composer check-platform-reqs` OK sous PHP 8.4.
- [ ] Version PHP de production ≥ **8.4.1**.
- [ ] Extensions présentes : `dom`, `mbstring`, `gd`, `fileinfo`, `pdo_mysql`, `pdo_sqlite`, `zip`, `intl`.
- [ ] Dépendances PDF installées : `barryvdh/laravel-dompdf`, `dompdf/dompdf`, plugin viewer.
- [ ] Filament 5 et Livewire 4 installés dans les versions verrouillées.
- [ ] PHPUnit 12 installé.
- [ ] Tests exécutés sous PHP 8.4.27 (ou version 8.4.x ≥ 8.4.1).

## 3. Base de données

- [ ] Sauvegarde de la base effectuée et vérifiée.
- [ ] `php artisan migrate:status` consulté.
- [ ] Migrations FC-8 présentes :
  - [ ] `generated_documents` ;
  - [ ] `work_order_quality_releases` ;
  - [ ] `shipment_sequences` ;
  - [ ] `shipments` ;
  - [ ] `shipment_packages` ;
  - [ ] `shipment_lines` ;
  - [ ] `shipment_package_lines` ;
  - [ ] `shipment_audit_logs` ;
  - [ ] FK différée `generated_documents.shipment_id` ;
  - [ ] `shipment_document_reviews`.
- [ ] Aucune migration exécutée sur une base de production depuis ce dépôt.
- [ ] Procédure de rollback/restauration documentée.

### 3.1 Procédure exacte pour la migration P7 pending

La migration `2026_11_11_000040_create_shipment_document_reviews_table` crée uniquement une nouvelle table. Elle ne modifie pas les tables existantes.

1. Sauvegarder la base et vérifier la sauvegarde.
2. Sauvegarder les trois disques privés.
3. Activer le mode maintenance si la politique d'exploitation l'exige :

```powershell
php artisan down
```

4. Vérifier l'état :

```powershell
php artisan migrate:status
```

5. Vérifier le SQL sans l'exécuter :

```powershell
php artisan migrate --pretend
```

6. Appliquer uniquement les migrations en attente :

```powershell
php artisan migrate --force
```

7. Vérifier la table, les index et les clés étrangères :

```powershell
php artisan db:table shipment_document_reviews
```

8. Vérifier à nouveau :

```powershell
php artisan migrate:status
```

9. Effectuer les smoke tests P7 : consultation d'une expédition, décision `no_impact`, absence de décision bloquante.
10. Désactiver le mode maintenance :

```powershell
php artisan up
```

**Rollback :** la migration `down()` supprime la table. Ne jamais l'exécuter si des décisions P7 existent. En cas d'incident après des décisions, restaurer la sauvegarde base + fichiers, ou conserver la table et corriger l'application. Ne pas utiliser `migrate:fresh`.

### 3.2 Vérification du schéma sur MySQL 8.4

À exécuter sur une base de test vide, jamais sur la base de production :

```powershell
php artisan migrate:fresh --force
php artisan migrate:status
```

Contrôler ensuite les tables FC-8, les index et les clés étrangères :

```powershell
php artisan db:table shipment_document_reviews
php artisan db:table generated_documents
php artisan db:table shipment_package_lines
```

Le job CI `mysql` exécute cette séquence sur un service MySQL 8.4 LTS. La migration P7 `2026_11_11_000040_create_shipment_document_reviews_table` est couverte.

## 4. Sauvegarde (base, fichiers privés, journal)

Sauvegarder **ensemble** la base et les trois disques privés : la base contient
les métadonnées, les chemins et les SHA-256 ; les disques contiennent les
fichiers. Une sauvegarde partielle rend la restauration incohérente.

```powershell
# 1. Base MySQL : dump cohérent (adapter hôte, base et utilisateur)
mysqldump --single-transaction --routines --triggers `
  -h 127.0.0.1 -u <utilisateur> -p <base> > backup-db-<horodatage>.sql

# 2. Fichiers privés
Compress-Archive -Path storage/app/private/documents `
  -DestinationPath backup-documents-<horodatage>.zip

Compress-Archive -Path storage/app/private/generated-documents `
  -DestinationPath backup-generated-<horodatage>.zip

# 3. Journal JSONL complet, y compris l'ancien segment
Compress-Archive -Path storage/app/private/shipment-audit `
  -DestinationPath backup-shipment-audit-<horodatage>.zip

# 4. Empreintes de la sauvegarde
Get-FileHash backup-*.zip, backup-db-*.sql -Algorithm SHA256
```

- [ ] Dump MySQL terminé sans erreur et relu (`SHOW TABLES`, `migrate:status` sur une copie).
- [ ] Sauvegarde de `storage/app/private/documents`.
- [ ] Sauvegarde de `storage/app/private/generated-documents`.
- [ ] Sauvegarde de `storage/app/private/shipment-audit` (tout le répertoire, pas seulement `validation-refused/`).
- [ ] Empreintes de sauvegarde conservées hors du serveur.
- [ ] Permissions de lecture/écriture pour l'utilisateur applicatif.
- [ ] Aucun accès direct depuis `public/`.
- [ ] Espace disque suffisant pour les PDF et le journal JSONL.
- [ ] Test de restauration sur un environnement isolé.
- [ ] Vérification de quelques SHA-256 PDF après restauration.

## 5. Configuration

- [ ] `APP_URL` correct.
- [ ] Connexion MySQL/MariaDB de production correcte.
- [ ] `PDF_DISK=generated_documents`.
- [ ] `PDF_COMPANY_NAME` défini.
- [ ] `PDF_MAX_SIZE_KB` adapté.
- [ ] Disques `documents`, `generated_documents`, `shipment_audit` privés.
- [ ] Cache de configuration régénéré après déploiement.
- [ ] Sessions, files d'attente et logs configurés.
- [ ] Aucun secret dans le dépôt.

## 6. Routes et Policies

- [ ] `php artisan route:list` ne montre aucune route privée non authentifiée.
- [ ] `documents.versions.download` protégée.
- [ ] `generated-documents.view` et `generated-documents.download` protégées.
- [ ] Route qualité du panneau atelier protégée par Policy.
- [ ] `WorkOrderPolicy::decideDocumentReview` réservée au responsable qualité.
- [ ] `ShipmentPolicy` : validation, départ et annulation réservés à l'administrateur.
- [ ] `GeneratedDocumentPolicy` refuse les opérateurs.
- [ ] Aucune élévation de privilège introduite par le Lot 5.

## 7. Journal JSONL

- [ ] Répertoire `storage/app/private/shipment-audit/validation-refused` créable par l'application.
- [ ] Ancien segment `storage/app/private/shipment-audit/validation-refused.jsonl` inclus dans la sauvegarde s'il existe.
- [ ] Sauvegarde de tout le répertoire `shipment-audit`, pas seulement du sous-dossier courant.
- [ ] Fichier `refusals.jsonl` accessible uniquement à l'utilisateur applicatif.
- [ ] Écriture append-only et verrouillage vérifiés.
- [ ] Repli `report()` + `Log::critical()` vérifié.
- [ ] Sauvegarde du journal incluse avec la base et les PDF.
- [ ] Politique de rotation/retention validée avant activation.
- [ ] En multi-serveurs : stockage partagé durable ou centralisation prévue.

## 8. Tests automatisés

- [ ] Tests transversaux FC-8 :
  - [ ] `Fc8IntegrationTest` ;
  - [ ] `Fc8SecurityTest`.
- [ ] Régressions OF :
  - [ ] `WorkOrderPdfGenerationTest` ;
  - [ ] `WorkOrderPdfFilamentTest` ;
  - [ ] `WorkOrderTest`.
- [ ] Qualité et clôture :
  - [ ] `WorkOrderQualityReleaseTest` ;
  - [ ] `WorkOrderQualityLifecycleTest` ;
  - [ ] `WorkOrderClosureTest`.
- [ ] Expéditions :
  - [ ] `ShipmentPreparationTest` ;
  - [ ] `ShipmentPackagingAvailabilityTest` ;
  - [ ] `ShipmentValidationTest` ;
  - [ ] `ShipmentShippingTest` ;
  - [ ] `ShipmentCancelledWorkOrderTest`.
- [ ] P7 :
  - [ ] `ShipmentDocumentReviewTest` ;
  - [ ] `ShipmentFilamentTest`.
- [ ] Documents :
  - [ ] `DocumentDownloadTest` ;
  - [ ] `DocumentVersionTest` ;
  - [ ] `DocumentSnapshotTest`.
- [ ] Sécurité et Policies :
  - [ ] `FilamentAuthorizationTest` ;
  - [ ] `QualitySecurityTest` ;
  - [ ] `DatabaseSchemaTest`.
- [ ] MySQL ciblé :
  - [ ] `ShipmentConcurrencyTest` ;
  - [ ] `ShipmentValidationTest` + `ShipmentShippingTest` ;
  - [ ] `ShipmentDocumentReviewTest` ;
  - [ ] `WorkOrderPdfConcurrencyTest`.

## 9. Déploiement

1. Vérifier que la sauvegarde de la section 4 est terminée et relue.
2. Vérifier l'espace disque disponible pour les PDF et le journal.
3. Installer les dépendances selon la procédure du projet :

```powershell
composer install --no-dev --optimize-autoloader
npm ci
npm run build
```

4. Activer la maintenance si la politique d'exploitation l'exige :

```powershell
php artisan down
```

5. Appliquer les migrations :

```powershell
php artisan migrate --force
php artisan migrate:status
```

6. Régénérer les caches :

```powershell
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan filament:upgrade
```

7. Vérifier les permissions des trois disques privés. L'utilisateur applicatif
   doit pouvoir créer et écrire les PDF et les lignes JSONL, sans accès public.
8. Vérifier la génération PDF et l'écriture du journal :

```powershell
php artisan generated-documents:find-orphans
php artisan documents:find-orphans
```

9. Désactiver la maintenance :

```powershell
php artisan up
```

## 10. Recette fonctionnelle FC-8

À exécuter sur un environnement de recette avec un administrateur, un
responsable atelier et un responsable qualité.

### 10.1 Documents d'ordre de fabrication

- [ ] Générer le PDF d'un OF libéré.
- [ ] Vérifier que le fichier est archivé sur `generated_documents` et absent de `public/`.
- [ ] Relever le SHA-256 en base et le comparer au fichier.
- [ ] Générer une nouvelle version : l'ancienne passe `superseded`, la nouvelle est `archived`, les deux fichiers restent sur disque.
- [ ] Vérifier qu'une tentative de modification du contenu archivé est refusée.
- [ ] Télécharger le PDF avec un rôle autorisé et vérifier le refus pour un opérateur.

### 10.2 Qualité et P7

- [ ] Réaliser une validation qualité et vérifier la preuve de libération.
- [ ] Vérifier que le visa qualité du PDF ne s'appuie que sur une preuve éligible.
- [ ] Approuver une nouvelle révision documentaire applicable.
- [ ] Vérifier que la validation d'expédition exige une décision P7 (`no_impact`, `reviewed` ou `blocked`).
- [ ] Vérifier que `blocked` empêche la validation.
- [ ] Vérifier l'historique append-only des décisions P7.

### 10.3 Expédition, colisage et quantités

- [ ] Préparer une expédition pour un OF éligible.
- [ ] Ajouter plusieurs lignes et plusieurs colis.
- [ ] Répartir une ligne sur plusieurs colis.
- [ ] Vérifier que le sélecteur ne propose plus une ligne entièrement colisée.
- [ ] Vérifier qu'une ligne partiellement colisée reste proposée.
- [ ] Modifier un colis existant et vérifier que sa propre ligne reste modifiable.
- [ ] Vérifier qu'un dépassement est refusé par le service même en contournant l'interface.
- [ ] Vérifier que « Reste à coliser » devient nul lorsque le colisage est complet.

### 10.4 Validation, BL et départ

- [ ] Valider une expédition avec colisage complet : BL et liste de colisage générés et archivés.
- [ ] Vérifier la réservation des quantités validées.
- [ ] Vérifier qu'une seconde validation ne peut pas dépasser la quantité acceptée.
- [ ] Enregistrer le départ et vérifier les quantités expédiées.
- [ ] Vérifier qu'une nouvelle révision approuvée après validation bloque le départ.
- [ ] Annuler une expédition validée : quantités libérées, documents `cancelled` conservés.
- [ ] Vérifier qu'une expédition `shipped` ne peut pas être annulée.

### 10.5 Journal des refus

- [ ] Provoquer un refus de validation (colisage incomplet ou document P7 manquant).
- [ ] Vérifier une ligne JSON valide dans `storage/app/private/shipment-audit/validation-refused/refusals.jsonl`.
- [ ] Vérifier la présence de `attempt_id`, `recorded_at`, `shipment_id`, `error_keys` et l'absence de secret.
- [ ] Vérifier le miroir SQL `validation_refused` lorsqu'il est disponible.
- [ ] Vérifier que le journal survit à un rollback de la transaction métier.

## 11. Retour arrière

Un `migrate:rollback` ne suffit pas : il ne restaure ni les fichiers PDF, ni le
journal JSONL, ni les décisions P7 déjà créées.

Conditions à réunir avant tout rollback :

- [ ] Sauvegarde base + trois disques privés disponible et vérifiée.
- [ ] Décision explicite du responsable habilité.
- [ ] Analyse de l'impact sur les données FC-8 déjà créées.

Procédure :

1. Activer la maintenance.
2. Arrêter les workers de file d'attente.
3. Restaurer la base depuis le dump cohérent.
4. Restaurer `documents`, `generated-documents` et `shipment-audit` depuis la
   même sauvegarde.
5. Vérifier les références `generated_documents.path` et l'existence des fichiers.
6. Lancer `php artisan generated-documents:find-orphans` et
   `php artisan documents:find-orphans`.
7. Contrôler plusieurs SHA-256 et l'accès aux PDF par rôle.
8. Désactiver la maintenance et exécuter les smoke tests.

Limites :

- Un rollback de code sans restauration de données peut laisser des colonnes,
  tables ou statuts inconnus de l'ancienne version.
- Une restauration de la base seule rend les PDF introuvables ; une restauration
  des fichiers seule crée des orphelins.
- Les documents déjà archivés ne doivent jamais être régénérés ou écrasés pour
  « réparer » un incident.

## 12. Smoke tests après déploiement

- [ ] Un administrateur ouvre `/admin`.
- [ ] Un administrateur consulte un OF et génère un PDF.
- [ ] Le PDF est archivé et consultable sans régénération.
- [ ] Un responsable atelier consulte une expédition et un PDF en lecture seule.
- [ ] Un responsable qualité consulte une expédition et une révision documentaire.
- [ ] Un opérateur ne peut pas ouvrir une expédition ni un document privé.
- [ ] Une validation génère BL et liste de colisage.
- [ ] Un départ enregistre les quantités expédiées.
- [ ] Une annulation autorisée libère les réservations et conserve les fichiers.
- [ ] `php artisan generated-documents:find-orphans` ne signale aucune incohérence.

## 13. Revue finale et décision

- [ ] Parcours A à E validés.
- [ ] Interfaces cohérentes avec les autorisations.
- [ ] Accès documentaires sécurisés.
- [ ] Tests transversaux passés.
- [ ] Régressions Lots 2 à 4 écartées.
- [ ] Documentation technique et opérationnelle prête.
- [ ] Limites restantes documentées.
- [ ] Aucun travail antérieur perdu.
- [ ] Décision de livraison prise par le responsable habilité.

---

## 14. Limites à rappeler lors de la revue

- Tests MySQL de verrouillage ≠ tests multiprocessus réels.
- PHP 8.4.27 testé ≠ toutes les versions 8.4.
- Journal JSONL local ≠ stockage distribué.
- Absence de `shipment_documents` ≠ contrôle de présence de documents obligatoires.
- Décision P7 au niveau OF ≠ décision indépendante par expédition.
- Accès global D-6 ≠ affectation individuelle par OF.
- La suite PHPUnit complète n'est pas exécutée sous MySQL : deux tests FC-7
  préexistants ne sont pas portables (comparaison d'identifiants entre tables
  et ordre des clés JSON). Le job MySQL exécute la sélection critique FC-8 ; la
  correction de ces deux tests relève d'une PR FC-7.
- L'immuabilité des PDF est applicative (Eloquent/services), pas filesystem.
- Le SHA-256 n'est pas revérifié automatiquement à chaque téléchargement.
