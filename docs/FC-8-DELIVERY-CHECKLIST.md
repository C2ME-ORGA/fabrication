# FC-8 — Checklist de livraison

Cette checklist prépare une revue finale et une décision de livraison. Elle ne déclenche aucun déploiement, aucune migration de production, aucun commit, aucun tag et aucune action Jira.

Référence d'exploitation : [FC-8-OPERATIONS.md](FC-8-OPERATIONS.md).

---

## 1. Préparation du dépôt

- [ ] Branche attendue : `feature/FC-8-technical-design`.
- [ ] Commit de base relevé : `b3e3b32764690d1305bd9e59452c84e3ce5b050f`.
- [ ] `git status --porcelain -uall` relevé et conservé.
- [ ] Aucun fichier non suivi des Lots 2 à 5 supprimé ou écrasé.
- [ ] `git diff --check` sans erreur.
- [ ] Pint passé sur les fichiers modifiés.
- [ ] Aucun commit, push, merge, tag ou déploiement effectué.
- [ ] `develop` non modifiée.

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

## 4. Fichiers privés

- [ ] Sauvegarde de `storage/app/private/documents`.
- [ ] Sauvegarde de `storage/app/private/generated-documents`.
- [ ] Sauvegarde de `storage/app/private/shipment-audit`.
- [ ] Permissions de lecture/écriture pour l'utilisateur applicatif.
- [ ] Aucun accès direct depuis `public/`.
- [ ] Espace disque suffisant pour les PDF et le journal JSONL.
- [ ] Test de restauration sur un environnement isolé.

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

## 9. Smoke tests après déploiement

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

## 10. Revue finale et décision

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

## 11. Limites à rappeler lors de la revue

- Tests MySQL de verrouillage ≠ tests multiprocessus réels.
- PHP 8.4.27 testé ≠ toutes les versions 8.4.
- Journal JSONL local ≠ stockage distribué.
- Absence de `shipment_documents` ≠ contrôle de présence de documents obligatoires.
- Décision P7 au niveau OF ≠ décision indépendante par expédition.
- Accès global D-6 ≠ affectation individuelle par OF.
