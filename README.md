# Fabrication C2ME

Application interne de gestion des ordres de fabrication de charpente métallique.

## Stack
Laravel + Livewire + Filament (versions exactes à fixer au bootstrap et à verrouiller dans composer.lock).

## Démarrage
Le dépôt est initialement vierge. Suivre [la spécification fonctionnelle et technique](docs/SPECIFICATION.md) avant de générer le squelette Laravel.

## Périmètre MVP
Affaires, documents de référence avec gestion des indices, ordres de fabrication, pièces/repères, opérations atelier, contrôles qualité, non-conformités et expéditions.

## Règle importante
Une liste d'approvisionnement n'est pas un plan de débit détaillé. Les documents et révisions doivent être validés avant la libération d'un OF.
