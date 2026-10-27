# Fabrication C2ME — spécification de démarrage

## Objectif
Application interne de pilotage de la fabrication d'ossatures métalliques : préparer un ordre de fabrication (OF), rattacher les documents du bureau d'études, lister les pièces à produire, suivre la gamme atelier et libérer les lots avant expédition.

## Socle retenu
- Laravel (version stable compatible avec PHP installé au moment de l'initialisation).
- Livewire pour les interactions des pages.
- Filament pour le back-office et les ressources CRUD.
- MySQL/MariaDB en production ; SQLite possible en tests si la configuration du projet le prévoit.
- Authentification Laravel et permissions par rôle ; version exacte de Filament/Livewire à fixer au bootstrap et verrouiller dans composer.lock.

Le dépôt était vide lors de la rédaction. Ce fichier décrit donc l'architecture et la feuille de route, il ne prétend pas être un code applicatif déjà exécuté.

## Modèle de données MVP

### Affaires (jobs)
- id, code_affaire unique, client, nom/projet, adresse chantier (facultatif)
- devis_reference, date_devis (facultatif)
- longueur_mm, largeur_mm, surface_m2 (facultatifs)
- responsable_id, notes, status (draft / active / on_hold / closed)
- timestamps

### Ordres de fabrication (work_orders)
- id, number unique généré côté serveur
- job_id, title, description, status (draft / ready / released / in_progress / quality_check / completed / cancelled)
- priority, planned_start_at, planned_end_at, actual_start_at, actual_end_at
- drawing_revision, approval fields (approved_by / approved_at)
- created_by, released_by, released_at, notes
- timestamps

### Documents (documents)
- id, job_id, work_order_id nullable, category (quote / assembly_plan / detail_plan / supply_list / cutting_plan / welding / quality / shipping / other)
- original_name, storage_path, mime_type, size_bytes, revision, uploaded_by
- status (current / superseded / rejected), notes, timestamps
- Politique : conserver l'historique des indices ; ne jamais remplacer silencieusement un plan déjà utilisé dans un OF libéré.

### Pièces / repères (work_order_items)
- id, work_order_id, mark (repère unique au sein d'un OF), designation, profile, steel_grade
- length_mm, quantity_planned, quantity_produced, quantity_rejected
- unit_weight_kg nullable, total_weight_kg nullable, operation_group, status, notes
- Les calculs de poids sont indicatifs uniquement s'ils ne proviennent pas de la nomenclature validée.

### Opérations atelier (work_order_operations)
- id, work_order_item_id nullable, work_order_id
- sequence, operation_type (cutting / preparation / fitting / welding / inspection / coating / marking / packing / other)
- instructions, status (pending / in_progress / blocked / completed / skipped)
- planned_minutes nullable, actual_minutes nullable, operator_id nullable, started_at, completed_at, remarks
- Contrôles / signatures selon permissions.

### Contrôles qualité et non-conformités
- quality_checks : OF/pièce/opération, contrôle, résultat, mesure, contrôleur, date.
- non_conformities : référence, description, containment_action, corrective_action, status, closed_by/closed_at.

### Expédition
- shipments : OF, date prévue/réelle, transporteur, référence BL, notes.
- shipment_items : pièces/lots, quantités expédiées et colis repérés.

### Sécurité et traçabilité
- utilisateurs ; rôles/permis ; journal d'événements pour libération d'OF, changement d'indice, clôture qualité et expédition.
- Fichiers servis par contrôleur autorisé, pas par URL publique prévisible.
- Valider les fichiers (taille/MIME/extension), stocker hors public si possible et journaliser les actions sensibles.

## Règles métier
1. Numéro d'OF généré côté serveur et unique, ex. OF-2026-0001 ; pas dérivé d'une saisie libre.
2. Un OF ne peut être libéré que si l'affaire est active, les documents obligatoires sont présents et les indices sont cohérents/validés.
3. Un changement de plan après libération doit créer une nouvelle révision et déclencher une revue d'impact ; aucun remplacement silencieux.
4. Une liste d'approvisionnement n'est pas automatiquement un plan de débit : les repères/longueurs de fabrication doivent être liés aux plans de détails ou validés par le BE.
5. Quantités produites/rebutées ne peuvent pas être négatives ; une opération ne peut être clôturée sans contrôle requis.
6. Clôture d'un OF uniquement quand les opérations requises et contrôles sont terminés, ou qu'une dérogation explicite est enregistrée.
7. Références incohérentes entre devis, liste appro et plan d'ensemble doivent être signalées, jamais corrigées automatiquement.
8. L'app est un outil de suivi atelier et ne remplace pas la validation technique du bureau d'études.

## Ressources Filament proposées
- Dashboard : OF par statut, OF en retard, opérations bloquées, taux d'avancement.
- Affaires : liste/recherche, fiche affaire et documents.
- Ordres de fabrication : création, duplication maîtrisée, lancement, suivi, contrôle final, clôture.
- Pièces / repères : table éditable en tenant compte des droits et de l'état OF.
- Gamme atelier : actions démarrer / bloquer / terminer, saisie temps.
- Documents : téléversement, indice, état, téléchargement authentifié.
- Non-conformités : création, assignation, actions correctives, clôture.
- Expéditions : préparation du colisage et état de départ.
- Paramètres : statuts, types d'opérations, rôles.

## Tests minimum
- Numéro d'OF unique, génération concurrente sûre.
- Interdiction de libérer un OF incomplet ou avec documents/indices non validés.
- Les fichiers ne sont accessibles qu'aux utilisateurs autorisés.
- Quantités non négatives et transitions de statut valides.
- Clôture bloquée si les opérations ou contrôles requis sont incomplets.
- Tests de policies pour chaque action de transition.
- Smoke test du panneau Filament et vérification du schéma à partir d'une base fraîche.

## Feuille de route
1. Bootstrap Laravel + Filament/Livewire, auth, configuration, CI basique, conventions.
2. Modèle Affaires / Documents et upload privé.
3. Ordres de fabrication, numéro automatique, états et règles de lancement.
4. Pièces/repères et gamme atelier avec saisie de temps.
5. Contrôles, non-conformités, clôture et expédition.
6. Impression PDF de l'OF et fiche de suivi, exports.
7. Revue sécurité, tests d'autorisation, sauvegardes et guide de déploiement.

## Exemple de référence métier
Affaire LE PEUTREC : devis DE1241, hangar monopente 30 × 15 m, surface annoncée d'environ 450 m², 6 travées de 5 m, 7 portiques ; hauteurs indicatives de 5,50 m et 8,50 m. La liste d'appro indique 20 788,6 kg et 763,0 m². Les documents portent des références différentes (26-001 sur la liste d'appro et 01100-2026 sur le plan) : la correspondance et les indices doivent être validés avant lancement. Les lisses/pannes bois, la couverture, le bardage, les portes et le montage sont exclus du devis DE1241 sauf ligne contraire.