# Socle technique — Fabrication C2ME

## Versions de référence
- Laravel Framework 13
- Livewire 4
- FilamentPHP 5
- PHP : version supportée par Laravel 13 et toutes les dépendances retenues, à vérifier au bootstrap
- Base de données : MySQL / MariaDB
- Tests : Pest

## Règles de dépendances
- Vérifier la compatibilité de chaque paquet avec Laravel 13, Livewire 4 et Filament 5 avant installation.
- Fixer les contraintes de versions dans composer.json et commiter composer.lock.
- Ne pas installer de paquet Filament/Livewire conçu pour une version majeure différente.
- Documenter les commandes exactes d'installation dans le README après validation sur un environnement propre.

## Initialisation
1. Créer le projet Laravel 13 dans le dépôt.
2. Installer Livewire 4 et FilamentPHP 5 dans des versions compatibles.
3. Configurer le panneau Filament et l'authentification.
4. Configurer MySQL/MariaDB et .env.example sans secret.
5. Ajouter les premières migrations, policies, ressources Filament et tests.
6. Vérifier l'installation depuis une base vide et lancer toute la suite de tests.

## Décision d'architecture
Filament sert aux écrans de gestion internes. Livewire sert aux interactions réactives spécifiques. Les règles métier importantes restent dans des services/actions testables et des policies Laravel, pas uniquement dans les formulaires Filament.
