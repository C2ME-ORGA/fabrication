<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Génération des références qualité annuelles (NC-AAAA-NNNN, DER-AAAA-NNNN).
 *
 * Le compteur est verrouillé dans la transaction appelante, comme le
 * générateur de numéros d'OF. Un rollback ne consomme donc pas de numéro.
 */
class QualityReferenceGenerator
{
    public const MAX_NUMBER_PER_YEAR = 9999;

    /**
     * @var array<string, string>
     */
    protected const PREFIXES = [
        'non_conformity' => 'NC',
        'deviation' => 'DER',
    ];

    public function nextNonConformityReference(?int $year = null): string
    {
        return $this->next('non_conformity', $year);
    }

    public function next(string $type, ?int $year = null): string
    {
        if (! isset(self::PREFIXES[$type])) {
            throw new RuntimeException("Type de référence qualité inconnu : {$type}.");
        }

        $year ??= $this->currentYear();

        if (DB::transactionLevel() === 0) {
            return DB::transaction(fn (): string => $this->generate($type, $year));
        }

        return $this->generate($type, $year);
    }

    protected function generate(string $type, int $year): string
    {
        $this->assertValidYear($year);

        $now = now();

        DB::table('quality_sequences')->insertOrIgnore([
            'type' => $type,
            'year' => $year,
            'last_number' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $sequence = DB::table('quality_sequences')
            ->where('type', $type)
            ->where('year', $year)
            ->lockForUpdate()
            ->first();

        if ($sequence === null) {
            throw new RuntimeException("Impossible de verrouiller le compteur qualité {$type} pour l'année {$year}.");
        }

        $next = (int) $sequence->last_number + 1;

        if ($next > self::MAX_NUMBER_PER_YEAR) {
            throw new RuntimeException(
                "Le compteur qualité {$type} de l'année {$year} a atteint sa limite de ".self::MAX_NUMBER_PER_YEAR.'.',
            );
        }

        DB::table('quality_sequences')
            ->where('id', $sequence->id)
            ->update([
                'last_number' => $next,
                'updated_at' => $now,
            ]);

        return $this->format($type, $year, $next);
    }

    public function format(string $type, int $year, int $number): string
    {
        $prefix = self::PREFIXES[$type] ?? throw new RuntimeException("Type de référence qualité inconnu : {$type}.");

        return sprintf('%s-%04d-%04d', $prefix, $year, $number);
    }

    public function currentYear(): int
    {
        return (int) Carbon::now(config('app.timezone'))->format('Y');
    }

    protected function assertValidYear(int $year): void
    {
        if ($year < 2000 || $year > 9999) {
            throw new RuntimeException("Année invalide pour une référence qualité : {$year}.");
        }
    }
}
