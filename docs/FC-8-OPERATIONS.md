# FC-8 — Exploitation, maintenance et limites

Ce document décrit l'exploitation de FC-8 : PDF d'OF, preuve de libération qualité, expéditions, colisage, validation, BL/liste de colisage, départ, annulation et workflow documentaire P7.

Il complète les documents existants et ne les duplique pas :

- [DOMAIN.md](DOMAIN.md) — affaires, OF, documents techniques, nomenclature ;
- [QUALITY.md](QUALITY.md) — contrôles, non-conformités, dérogations et clôture FC-7 ;
- [WORKSHOP.md](WORKSHOP.md) — gammes, opérations, exécution et chronométrage ;
- [DEVELOPMENT.md](DEVELOPMENT.md) — bootstrap local et conventions ;
- [FC-8-TECHNICAL-DESIGN.md](FC-8-TECHNICAL-DESIGN.md) — conception et décisions ;
- [FC-8-DELIVERY-CHECKLIST.md](FC-8-DELIVERY-CHECKLIST.md) — checklist de mise en production.

---

## 1. Architecture fonctionnelle

### 1.1 Services principaux

| Service | Responsabilité |
|---|---|
| `WorkOrderPdfGenerationService` | Construit les données, rend le PDF et l'archive |
| `WorkOrderPrintService` | Assemble les visas, révisions, quantités et opérations du PDF d'OF |
| `WorkOrderQualityReleaseService` | Écrit et relit la preuve `work_order_quality_releases` |
| `ShipmentService` | Création, en-tête, lignes, préparation, réouverture, départ, annulation |
| `ShipmentPackagingService` | Colis, répartitions, retraits et contrôle de colisage |
| `ShipmentQuantityGuard` | Cumuls préparés, validés, expédiés, disponibles et restants |
| `ShipmentValidationService` | Validation atomique, contrôles, réservation, génération BL/liste |
| `ShipmentQualityGateService` | Contrôle de la preuve qualité avant validation |
| `ShipmentDocumentService` | Détection P7, verrouillage documentaire, décisions et historique |
| `ShipmentPrintService` | Données et rendu du BL et de la liste de colisage |
| `GeneratedDocumentStorageService` | Écriture des PDF sur disque privé, taille et SHA-256 |
| `GeneratedDocumentArchiveService` | Versionnement, supersession et cohérence fichier/base |
| `ShipmentValidationRefusalRecorder` | Audit durable des refus de validation (JSONL + miroir SQL) |
| `ShipmentAuditService` | Journal append-only des expéditions |

### 1.2 Modèles et relations

- `WorkOrder` → `qualityReleases`, `shipments`, `generatedDocuments`, `documentSnapshots` ;
- `WorkOrderQualityRelease` → preuve append-only de libération qualité ;
- `Shipment` → `workOrder`, `lines`, `packages`, `generatedDocuments`, `documentReviews`, `auditLogs` ;
- `ShipmentLine` → `shipment`, `workOrderItem`, `packageLines` ;
- `ShipmentPackage` → `shipment`, `packageLines` ;
- `ShipmentPackageLine` → colis + ligne, avec contrôle d'appartenance à la même expédition ;
- `GeneratedDocument` → PDF d'OF, BL ou liste de colisage archivé ;
- `ShipmentDocumentReview` → décision P7 append-only ;
- `ShipmentAuditLog` → journal append-only de l'expédition.

### 1.3 Policies

| Policy | Règle principale |
|---|---|
| `WorkOrderPolicy::generatePdf` | Administrateur uniquement |
| `WorkOrderPolicy::viewPdf` | Admin, atelier et qualité selon l'accès D-6 |
| `WorkOrderPolicy::releaseQuality` | Admin ou responsable qualité |
| `WorkOrderPolicy::decideDocumentReview` | Responsable qualité uniquement |
| `ShipmentPolicy` | Admin : validation, départ, annulation ; admin/atelier : préparation ; qualité : consultation |
| `GeneratedDocumentPolicy` | Admin, atelier et qualité selon l'accès à l'OF ; opérateur refusé |
| `DocumentVersionPolicy` | Admin ; responsable qualité pour les documents applicables aux OF consultables |
| `ShipmentDocumentReviewPolicy` | Consultation admin/atelier/qualité selon l'accès à l'OF |

La décision D-6 est conservée : l'atelier et la qualité ont un accès global par rôle aux PDF et aux expéditions. Aucune affectation individuelle par OF n'est introduite.

### 1.4 Interfaces Filament

- `ShipmentResource` : liste, création, consultation, lignes, colis, revue P7, documents générés, audit ;
- `ViewShipment` : préparation, réouverture, validation, départ, annulation ;
- `ViewWorkOrder` : génération PDF, libération qualité historique ;
- `ViewWorkshopOperation` : consultation du PDF d'OF en lecture seule ;
- panneau atelier : expéditions, contrôles qualité, NC, dérogations, PDF.

Les actions sensibles utilisent une confirmation ou un formulaire modal avec commentaire obligatoire. Les documents annulés restent consultables et téléchargeables.

---

## 2. Cycle de vie métier

### 2.1 Ordre de fabrication

1. Création de l'OF et de ses pièces/opérations.
2. Approbation BE et libération.
3. Exécution atelier et contrôles qualité.
4. Clôture : contrôle d'éligibilité FC-7, écriture de la preuve qualité, passage à `completed`.
5. Génération du PDF d'OF : les visas proviennent des preuves persistées, jamais d'un événement d'audit seul.

### 2.2 Preuve de libération qualité

- Table `work_order_quality_releases`, append-only.
- Écrite dans la transaction de clôture ou par une revue historique explicite.
- Contient l'instantané d'éligibilité, son hash, la règle appliquée, l'auteur et la date.
- Une preuve ne peut être ni modifiée ni supprimée.
- Le visa qualité du PDF n'est « effectué » que si la dernière preuve est éligible.

### 2.3 Préparation d'expédition

- Création d'une expédition brouillon pour un OF préparable.
- Ajout de lignes depuis les pièces de l'OF.
- Création de colis et répartition des quantités.
- La préparation **ne réserve aucune quantité** et ne clôture pas l'OF.
- Une quantité colisée ne dépasse jamais la quantité préparée de la ligne.
- Une expédition préparée peut être rouverte en brouillon.

### 2.4 Validation

La validation est atomique :

1. verrouillage OF → pièces → expédition → lignes/colis ;
2. contrôle qualité de phase `shipment` ;
3. contrôle du colisage complet ;
4. contrôle des cumuls validés + expédiés ;
5. contrôle P7 : toutes les révisions applicables doivent avoir une décision valide ;
6. verrouillage des documents applicables et relecture des versions ;
7. génération et archivage du BL et de la liste de colisage ;
8. passage à `validated` et réservation des quantités.

Une expédition validée ne peut plus être modifiée. Elle peut être annulée dans les cas autorisés.

### 2.5 Départ

- Réservé à l'administrateur.
- Uniquement depuis `validated`.
- Recopie les quantités préparées vers `quantity_shipped`.
- Accepte une observation facultative de 10 000 caractères maximum, conservée dans l'audit.
- Le contrôle P7 est refait : une nouvelle révision approuvée après validation bloque le départ.

### 2.6 Annulation

- Autorisée pour `draft`, `prepared` et `validated`.
- Motif obligatoire ; au moins 20 caractères pour une expédition validée.
- Les documents générés passent à `cancelled` mais restent sur disque.
- Les quantités réservées sont libérées.
- Une expédition `shipped` ne peut pas être annulée.

### 2.7 Workflow documentaire P7

- Une décision porte sur **OF + document + version**.
- `shipment_id` est un contexte de traçabilité, pas un périmètre indépendant.
- Décisions : `no_impact`, `reviewed`, `blocked`.
- `no_impact` et `reviewed` autorisent la poursuite ; `blocked` l'interdit.
- Une nouvelle version approuvée impose une nouvelle décision.
- L'historique est append-only ; une décision ultérieure peut remplacer fonctionnellement la précédente.
- Seul le responsable qualité décide.
- La validation et le départ verrouillent les documents applicables avant de relire les versions, ce qui empêche une approbation concurrente entre le contrôle et le commit.

---

## 3. Stockage et confidentialité

### 3.1 Disques privés

| Disque | Racine | Contenu |
|---|---|---|
| `documents` | `storage/app/private/documents` | Documents techniques et versions |
| `generated_documents` | `storage/app/private/generated-documents` | PDF d'OF, BL et listes de colisage |
| `shipment_audit` | `storage/app/private/shipment-audit` | Journal JSONL des refus de validation |

Aucun de ces disques n'est servi depuis `public/`. Les téléchargements passent par des routes authentifiées et des Policies.

### 3.2 Generated documents

- Chemin généré côté serveur, nom UUID, extension PDF.
- SHA-256 calculé au moment de l'archivage.
- Version par portée et par type ; une nouvelle génération remplace fonctionnellement l'ancienne sans écraser le fichier.
- Un document archivé ne peut pas être modifié ni supprimé.
- Le statut peut évoluer vers `superseded` ou `cancelled`, jamais le contenu.

### 3.3 Journal JSONL des refus

Fichier :

```text
storage/app/private/shipment-audit/validation-refused/refusals.jsonl
```

Caractéristiques :

- écriture append-only, `flock(LOCK_EX)`, `fflush`, `fsync` ;
- une ligne JSON par tentative refusée ;
- `attempt_id` UUID pour corréler le fichier et le miroir SQL ;
- métadonnées limitées : expédition, OF, utilisateur, statut, clés d'erreur, classe d'exception ;
- indépendant de la transaction métier : un rollback, y compris externe, ne l'annule pas ;
- miroir SQL `validation_refused` en meilleur effort, dans une transaction séparée ;
- si le disque d'audit échoue : `report()` + `Log::critical()`, puis miroir SQL.

**Limite :** le fichier est local au serveur et ne garantit pas une panne disque ou un arrêt brutal avant `fsync`. En multi-serveurs, utiliser un volume partagé durable ou une centralisation externe.

### 3.4 Segment historique

Un fichier `validation-refused.jsonl` peut subsister à l'ancien chemin, à côté du répertoire `validation-refused/refusals.jsonl`. Il contient des refus antérieurs au changement de chemin et ne doit pas être supprimé sans revue.

Règles :

- sauvegarder tout le répertoire `storage/app/private/shipment-audit`, pas seulement le sous-dossier courant ;
- inclure l'ancien segment dans la politique de rotation et de rétention ;
- ne jamais fusionner par simple concaténation, car l'ordre chronologique et l'append-only seraient faussés ;
- une consolidation éventuelle doit être une copie vérifiée par SHA-256, avec conservation de l'original.

### 3.5 Politique de rotation proposée

À valider avec les règles de conservation qualité/audit avant implémentation :

1. rotation quotidienne ou à seuil de taille ;
2. nommage horodaté et manifeste SHA-256 ;
3. compression des fichiers clôturés ;
4. conservation selon la durée légale/qualité définie ;
5. suppression uniquement après validation explicite et sauvegarde ;
6. restauration testée avec la base et les PDF ;
7. jamais de rotation destructive sans copie de sauvegarde.

Aucune rotation automatique n'est implémentée dans le Lot 5.

---

## 4. Sauvegarde et restauration

### 4.1 À sauvegarder ensemble

- base de données ;
- `storage/app/private/documents` ;
- `storage/app/private/generated-documents` ;
- `storage/app/private/shipment-audit` ;
- configuration `.env` ou secrets équivalents, hors dépôt.

La base contient les métadonnées, les hash et les références ; les disques contiennent les fichiers. Une restauration partielle peut rendre un document référencé introuvable ou un fichier orphelin.

### 4.2 Procédure de sauvegarde

1. vérifier l'espace disque ;
2. sauvegarder la base avec un dump cohérent ;
3. copier les trois disques privés ;
4. conserver les hash/checksums de la sauvegarde ;
5. tester périodiquement une restauration sur un environnement isolé.

### 4.3 Procédure de restauration

1. restaurer la base ;
2. restaurer les trois disques privés ;
3. vérifier les références `generated_documents.path` et l'existence des fichiers ;
4. exécuter `php artisan generated-documents:find-orphans` ;
5. exécuter `php artisan documents:find-orphans` ;
6. contrôler quelques SHA-256 et l'accès aux PDF par rôle ;
7. ne jamais restaurer uniquement la base ou uniquement les fichiers.

---

## 5. Commandes d'exploitation

### 5.1 Orphelins PDF

```powershell
php artisan generated-documents:find-orphans
php artisan generated-documents:find-orphans --delete
```

- sans option : liste les fichiers sans enregistrement et signale les enregistrements sans fichier ;
- `--delete` : supprime uniquement les fichiers orphelins du disque configuré ;
- ne parcourt pas les autres disques ;
- retourne un échec si un enregistrement référence un fichier absent.

### 5.2 Orphelins documents techniques

```powershell
php artisan documents:find-orphans
php artisan documents:find-orphans --delete
```

### 5.3 Contrôles avant déploiement

```powershell
php artisan migrate:status
php artisan route:list
php artisan about
php artisan generated-documents:find-orphans
vendor/bin/pint --test app tests config
git diff --check
```

---

## 6. Maintenance et compatibilité

### 6.1 PHP

- Production cible : **PHP 8.4**, minimum réel **8.4.1** (contrainte des paquets Symfony 8.1 installés).
- Version validée localement : PHP 8.4.27.
- PHP 8.5.12 est également utilisé pour le développement local.
- Exécuter les tests sous la version cible avant livraison :

```powershell
& 'C:\Users\maxim\.config\herd\bin\php84\php.exe' artisan test <suites ciblées>
& 'C:\Users\maxim\.config\herd\bin\php84\php.exe' vendor/bin/phpunit -c phpunit.mysql.xml <suites MySQL>
```

### 6.2 Dépendances PDF

- `barryvdh/laravel-dompdf` ;
- `dompdf/dompdf` ;
- plugin `joaopaulolndev/filament-pdf-viewer`.

Le rendu désactive les ressources distantes, le PHP embarqué et JavaScript. Les vues Blade sont contrôlées et les polices restent locales.

### 6.3 Base de données

- SQLite en mémoire pour la suite rapide ;
- MySQL/MariaDB pour les tests de verrouillage et les contrôles de concurrence ;
- base de test dédiée `fab_c2me_test`, jamais la base de développement ou de production.

```powershell
vendor/bin/phpunit -c phpunit.mysql.xml
```

Les tests MySQL vérifient des verrous de ligne avec une seconde connexion `FOR UPDATE NOWAIT`. **Ils ne constituent pas un test multiprocessus réel.**

### 6.4 Migrations

- Les migrations FC-8 sont versionnées et doivent être appliquées dans l'ordre.
- Sauvegarder la base et les fichiers privés avant toute migration.
- Ne jamais exécuter de migration de production depuis ce dépôt de travail.
- Vérifier `php artisan migrate:status` avant et après.

### 6.5 Tests ciblés

```powershell
php artisan test tests/Feature/Fc8IntegrationTest.php tests/Feature/Fc8SecurityTest.php
php artisan test tests/Feature/WorkOrderPdfGenerationTest.php tests/Feature/WorkOrderPdfFilamentTest.php
php artisan test tests/Feature/WorkOrderQualityReleaseTest.php tests/Feature/WorkOrderQualityReleaseFilamentTest.php
php artisan test tests/Feature/ShipmentPreparationTest.php tests/Feature/ShipmentValidationTest.php tests/Feature/ShipmentShippingTest.php
php artisan test tests/Feature/ShipmentDocumentReviewTest.php tests/Feature/ShipmentFilamentTest.php
php artisan test tests/Feature/FilamentAuthorizationTest.php tests/Feature/QualitySecurityTest.php
```

---

## 7. Limites connues

| Limite | Impact | Statut |
|---|---|---|
| Tests MySQL de verrouillage ≠ multiprocessus | La concurrence réelle n'est pas prouvée bout en bout | Risque opérationnel modéré |
| PHP 8.4.27 testé, pas toutes les 8.4.x | Le minimum 8.4.1 est requis | Risque opérationnel faible |
| Journal JSONL local | Pas de centralisation multi-serveurs | Risque opérationnel modéré |
| Pas de table `shipment_documents` | Pas de contrôle « document obligatoire présent » | Limite fonctionnelle assumée |
| Décision P7 au niveau OF | Pas de décision indépendante par expédition | Limite métier assumée |
| Accès global D-6 | Pas de restriction par affectation individuelle | Décision métier validée |
| Rotation JSONL non automatique | Croissance du fichier | À définir avec la conservation |
| Vérification de hash des PDF non outillée | Contrôle manuel | Amélioration future |

---

## 8. Renvois

- Checklist de livraison : [FC-8-DELIVERY-CHECKLIST.md](FC-8-DELIVERY-CHECKLIST.md)
- Conception : [FC-8-TECHNICAL-DESIGN.md](FC-8-TECHNICAL-DESIGN.md)
- Plan : [FC-8-IMPLEMENTATION-PLAN.md](FC-8-IMPLEMENTATION-PLAN.md)
- Qualité : [QUALITY.md](QUALITY.md)
- Domaine : [DOMAIN.md](DOMAIN.md)
- Atelier : [WORKSHOP.md](WORKSHOP.md)
- Développement : [DEVELOPMENT.md](DEVELOPMENT.md)
