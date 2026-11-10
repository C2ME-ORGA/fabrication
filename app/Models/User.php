<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
            'is_workshop_supervisor' => 'boolean',
            'is_quality_manager' => 'boolean',
        ];
    }

    /**
     * L'accès au panneau d'administration reste réservé aux administrateurs.
     * L'interface atelier est un panneau distinct, accessible aux utilisateurs
     * authentifiés sans leur donner les droits d'administration.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        if ($panel->getId() === 'workshop') {
            return true;
        }

        return $this->isAdmin();
    }

    public function isAdmin(): bool
    {
        return (bool) $this->is_admin;
    }

    /**
     * Responsable atelier : peut gérer les gammes, débloquer des opérations,
     * saisir et corriger des temps. Ce rôle n'accorde aucun accès admin.
     * Le champ `is_workshop_supervisor` n'est pas mass assignable.
     */
    public function isWorkshopSupervisor(): bool
    {
        return (bool) $this->is_workshop_supervisor;
    }

    public function canManageWorkshopRoutings(): bool
    {
        return $this->isAdmin() || $this->isWorkshopSupervisor();
    }

    public function canUnblockWorkshopOperations(): bool
    {
        return $this->isAdmin() || $this->isWorkshopSupervisor();
    }

    public function canManageOperationTimes(): bool
    {
        return $this->isAdmin() || $this->isWorkshopSupervisor();
    }

    /**
     * Responsable qualité : gère le référentiel, valide les contrôles et
     * accorde les dérogations. Ce drapeau n'accorde aucun accès admin.
     * Il n'est pas mass assignable : il doit être positionné explicitement.
     */
    public function isQualityManager(): bool
    {
        return (bool) $this->is_quality_manager;
    }

    public function canManageQualityTemplates(): bool
    {
        return $this->isAdmin() || $this->isQualityManager();
    }

    /**
     * Les exigences qualité d'une gamme sont gérées par le responsable
     * atelier (catalogue), le responsable qualité et l'administrateur.
     */
    public function canManageQualityRequirements(): bool
    {
        return $this->isAdmin() || $this->isWorkshopSupervisor() || $this->isQualityManager();
    }

    /**
     * Tout utilisateur authentifié peut réaliser un contrôle sur un OF en
     * exécution ou en phase qualité. Le service vérifie ensuite l'état de
     * l'OF et du contrôle.
     */
    public function canRecordQualityControls(): bool
    {
        return true;
    }

    /**
     * Validation des résultats : responsable atelier, responsable qualité et
     * administrateur. La ségrégation (contrôleur, rang, criticité) est
     * appliquée dans le service, pas dans cette méthode.
     */
    public function canValidateQualityControls(): bool
    {
        return $this->isAdmin() || $this->isWorkshopSupervisor() || $this->isQualityManager();
    }

    public function canValidateCriticalQualityControls(): bool
    {
        return $this->canValidateQualityControls();
    }

    /**
     * Consultation de l'historique qualité d'un OF clôturé ou annulé.
     */
    public function canViewQualityHistory(): bool
    {
        return $this->isAdmin() || $this->isWorkshopSupervisor() || $this->isQualityManager();
    }

    public function canReportNonConformity(): bool
    {
        return true;
    }

    public function canManageNonConformities(): bool
    {
        return $this->isAdmin() || $this->isWorkshopSupervisor() || $this->isQualityManager();
    }

    public function canCloseMinorNonConformities(): bool
    {
        return $this->isAdmin() || $this->isWorkshopSupervisor() || $this->isQualityManager();
    }

    public function canCloseMajorNonConformities(): bool
    {
        return $this->isAdmin() || $this->isQualityManager();
    }

    public function canReopenNonConformities(): bool
    {
        return $this->isAdmin() || $this->isQualityManager();
    }

    public function canVerifyNonConformities(): bool
    {
        return $this->isAdmin() || $this->isQualityManager();
    }

    public function canGrantQualityDeviations(): bool
    {
        return $this->isAdmin() || $this->isQualityManager();
    }

    public function canApproveQualityDeviations(): bool
    {
        return $this->isAdmin() || $this->isQualityManager();
    }

    public function canRevokeQualityDeviations(): bool
    {
        return $this->isAdmin() || $this->isQualityManager();
    }

    public function canViewQualityEligibility(): bool
    {
        return $this->isAdmin() || $this->isWorkshopSupervisor() || $this->isQualityManager();
    }
}
