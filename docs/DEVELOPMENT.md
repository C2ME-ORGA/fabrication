# Développement local

## Pré-requis
- PHP dans une version supportée par la version Laravel choisie.
- Composer.
- Node.js/npm pour les assets.
- MySQL/MariaDB ou SQLite pour tests.
- Git.

## Initialisation
1. Créer le projet Laravel dans ce dépôt avec la version choisie après vérification de compatibilité PHP.
2. Installer Filament et Livewire dans les versions compatibles avec cette version de Laravel.
3. Créer le panneau Filament, l'authentification et les policies.
4. Configurer .env sans jamais commiter de secrets.
5. Créer les migrations, modèles, policies, ressources Filament et tests en suivant docs/SPECIFICATION.md.
6. Exécuter les tests sur une base fraîche et le lint avant toute PR.

## Conventions
- Toute mutation métier importante passe par une action/service testable.
- Les transitions d'OF sont contrôlées côté serveur et couvertes par des tests.
- Les documents utilisent un stockage privé.
- Ne pas remplacer un indice de plan associé à un OF libéré ; créer une révision et consigner l'impact.
- Toute modification du schéma doit être versionnée via migration.
