<?php

namespace App\Services;

use App\Enums\ShipmentAuditEvent;
use App\Enums\ShipmentStatus;
use App\Models\Shipment;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * Audit durable des refus de validation d'expédition.
 *
 * Le journal de référence est un fichier JSONL append-only sur le disque
 * privé `shipment_audit`. Il est écrit hors de la transaction métier : un
 * rollback, y compris le rollback d'une transaction externe qui enveloppe
 * l'appel à ShipmentValidationService::validate(), ne peut pas l'annuler.
 *
 * Une ligne d'audit SQL est également écrite en meilleur effort pour
 * l'affichage applicatif. Dans une transaction externe, cette ligne peut être
 * annulée par le rollback externe : le fichier reste alors la preuve durable.
 * Le champ `attempt_id` permet de corréler les deux supports et d'identifier
 * une tentative unique.
 *
 * Limite assumée : une panne du disque ou un arrêt brutal du processus entre
 * l'écriture et le fsync n'est pas couvert. Le répertoire doit être sauvegardé
 * avec le stockage privé de l'application et n'est jamais servi publiquement.
 */
class ShipmentValidationRefusalRecorder
{
    public const DISK = 'shipment_audit';

    public const DIRECTORY = 'validation-refused';

    public const PATH = self::DIRECTORY.'/refusals.jsonl';

    protected const MAX_REASON_LENGTH = 2000;

    public function __construct(
        protected ShipmentAuditService $audit,
    ) {}

    /**
     * Enregistre un refus. Cette méthode ne propage pas les erreurs d'audit :
     * l'exception métier d'origine doit rester l'erreur visible pour
     * l'appelant.
     */
    public function record(
        Shipment $shipment,
        ?User $actor,
        Throwable $exception,
        string $attemptId,
    ): void {
        $entry = $this->entry($shipment, $actor, $exception, $attemptId);

        try {
            $this->append($entry);
        } catch (Throwable $fileException) {
            report($fileException);

            // Repli secondaire : au moins une trace structurée dans le journal
            // applicatif si le disque d'audit est indisponible.
            Log::critical('Refus de validation non journalisé sur le disque d’audit.', $entry);
        }

        $this->mirrorToDatabase($shipment, $actor, $exception, $entry);
    }

    /**
     * @return array<string, mixed>
     */
    protected function entry(
        Shipment $shipment,
        ?User $actor,
        Throwable $exception,
        string $attemptId,
    ): array {
        $reason = $exception instanceof ValidationException
            ? (string) collect($exception->errors())->flatten()->implode(' ')
            : 'La validation de l’expédition a échoué.';

        return [
            'attempt_id' => $attemptId,
            'event' => ShipmentAuditEvent::ValidationRefused->value,
            'shipment_id' => (int) $shipment->getKey(),
            'work_order_id' => $shipment->work_order_id === null ? null : (int) $shipment->work_order_id,
            'shipment_number' => $shipment->number,
            'user_id' => $actor?->getKey() ?? Auth::id(),
            'status_before' => $shipment->status?->value,
            'target_status' => ShipmentStatus::Validated->value,
            'reason' => mb_substr($reason, 0, self::MAX_REASON_LENGTH),
            'error_keys' => $exception instanceof ValidationException
                ? array_keys($exception->errors())
                : [],
            'exception' => class_basename($exception),
            'recorded_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    protected function append(array $entry): void
    {
        $disk = Storage::disk(self::DISK);
        $disk->makeDirectory(self::DIRECTORY);

        $stream = fopen($disk->path(self::PATH), 'ab');

        if ($stream === false) {
            throw new RuntimeException('Impossible d’ouvrir le journal des refus de validation.');
        }

        try {
            if (! flock($stream, LOCK_EX)) {
                throw new RuntimeException('Impossible de verrouiller le journal des refus de validation.');
            }

            $line = json_encode(
                $entry,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ).PHP_EOL;

            // En cas d'écriture partielle, tronquer la ligne incomplète pour
            // ne jamais laisser un JSON invalide dans un journal append-only.
            $position = ftell($stream);
            $written = fwrite($stream, $line);

            if ($position === false || $written === false || $written !== strlen($line)) {
                if ($position !== false) {
                    ftruncate($stream, $position);
                }

                throw new RuntimeException('Impossible d’écrire une ligne complète dans le journal des refus de validation.');
            }

            fflush($stream);

            if (function_exists('fsync')) {
                fsync($stream);
            }

            flock($stream, LOCK_UN);
        } finally {
            fclose($stream);
        }
    }

    /**
     * Miroir applicatif en base, en meilleur effort et dans une transaction
     * distincte. Il peut être annulé par un rollback externe : le fichier
     * append-only reste la source durable.
     *
     * @param  array<string, mixed>  $entry
     */
    protected function mirrorToDatabase(
        Shipment $shipment,
        ?User $actor,
        Throwable $exception,
        array $entry,
    ): void {
        try {
            $fresh = Shipment::query()->find($shipment->getKey());

            if ($fresh === null) {
                return;
            }

            DB::transaction(function () use ($fresh, $actor, $entry): void {
                $this->audit->record(
                    $fresh,
                    ShipmentAuditEvent::ValidationRefused,
                    $actor,
                    $entry['status_before'],
                    ShipmentStatus::Validated->value,
                    $entry['reason'],
                    [
                        'attempt_id' => $entry['attempt_id'],
                        'error_keys' => $entry['error_keys'],
                        'exception' => $entry['exception'],
                    ],
                );
            }, attempts: 1);
        } catch (Throwable $auditException) {
            report($auditException);
        }
    }
}
