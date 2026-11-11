<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Génère les numéros d'expédition annuels (EXP-AAAA-NNNN).
 *
 * Le compteur est verrouillé par `lockForUpdate()` dans la transaction de
 * création, sur le modèle de WorkOrderNumberGenerator.
 */
class ShipmentNumberGenerator
{
    public const MAX_NUMBER_PER_YEAR = 9999;

    public function nextNumber(?int $year = null): string
    {
        $year ??= $this->currentYear();

        if (DB::transactionLevel() === 0) {
            return DB::transaction(fn (): string => $this->nextNumber($year));
        }

        return $this->generate($year);
    }

    protected function generate(int $year): string
    {
        $this->assertValidYear($year);

        $now = now();

        DB::table('shipment_sequences')->insertOrIgnore([
            'year' => $year,
            'last_number' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $sequence = DB::table('shipment_sequences')
            ->where('year', $year)
            ->lockForUpdate()
            ->first();

        if ($sequence === null) {
            throw new RuntimeException("Impossible de verrouiller le compteur d'expédition pour l'année {$year}.");
        }

        $next = (int) $sequence->last_number + 1;

        if ($next > self::MAX_NUMBER_PER_YEAR) {
            throw new RuntimeException(
                "Le compteur d'expédition de l'année {$year} a atteint sa limite de ".self::MAX_NUMBER_PER_YEAR.'.',
            );
        }

        DB::table('shipment_sequences')
            ->where('year', $year)
            ->update([
                'last_number' => $next,
                'updated_at' => $now,
            ]);

        return $this->format($year, $next);
    }

    public function format(int $year, int $number): string
    {
        return sprintf('EXP-%04d-%04d', $year, $number);
    }

    public function currentYear(): int
    {
        return (int) Carbon::now(config('app.timezone'))->format('Y');
    }

    protected function assertValidYear(int $year): void
    {
        if ($year < 2000 || $year > 9999) {
            throw new RuntimeException("Année invalide pour un numéro d'expédition : {$year}.");
        }
    }
}
