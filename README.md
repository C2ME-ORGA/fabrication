# Fabrication C2ME

Application interne de gestion des ordres de fabrication de charpente métallique.

## Stack de référence
- Laravel 13
- Livewire 4
- FilamentPHP 5
- MySQL / MariaDB
- Pest pour les tests

## Initialisation locale (Windows / PowerShell)
Depuis la racine du dépôt, exécuter :

```powershell
Set-ExecutionPolicy -Scope Process Bypass
.\scripts\bootstrap.ps1
```

Le script crée un squelette Laravel 13 dans un répertoire temporaire, copie les fichiers applicatifs sans toucher à `docs/`, `scripts/`, `.git` ni au présent README, installe Livewire 4 et FilamentPHP 5, puis lance l'installation du panneau Filament.

Prérequis : PHP supporté par Laravel 13, Composer, Node.js/npm et Git. Le script s'arrête si un projet Composer/Laravel existe déjà afin d'éviter tout écrasement. Vérifier les messages Composer : les versions exactes et leur compatibilité sont matérialisées dans `composer.lock` après installation.

## Documentation
- [Spécification fonctionnelle et technique](docs/SPECIFICATION.md)
- [Socle technique fixé](docs/STACK.md)
- [Guide de développement](docs/DEVELOPMENT.md)

## Périmètre MVP
Affaires, documents techniques versionnés, ordres de fabrication, pièces/repères, opérations atelier, contrôles qualité, non-conformités et expéditions.

## Règle métier importante
Une liste d'approvisionnement ne remplace pas un plan de débit détaillé. Les références et indices des documents doivent être validés avant la libération d'un OF.
