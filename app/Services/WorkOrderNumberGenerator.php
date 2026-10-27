<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Generates yearly sequential work order numbers (OF-AAAA-NNNN).
 *
 * The counter lives in `work_order_sequences`, one row per year. The row is
 * read with `SELECT ... FOR UPDATE` inside a database transaction, so two
 * concurrent requests cannot read the same value on MySQL/MariaDB.
 *
 * The counter is incremented inside the same transaction that creates the
 * work order: if the creation fails and rolls back, the number is not
 * consumed. A cancelled work order keeps its number, which is never reused.
 */
class WorkOrderNumberGenerator
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

        // Create the row if it does not exist yet. Concurrent first calls are
        // safe: one insert wins, the others are ignored, then all of them lock
        // the same row below.
        DB::table('work_order_sequences')->insertOrIgnore([
            'year' => $year,
            'last_number' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $sequence = DB::table('work_order_sequences')
            ->where('year', $year)
            ->lockForUpdate()
            ->first();

        if ($sequence === null) {
            throw new RuntimeException("Impossible de verrouiller le compteur d'OF pour l'année {$year}.");
        }

        $next = (int) $sequence->last_number + 1;

        if ($next > self::MAX_NUMBER_PER_YEAR) {
            throw new RuntimeException(
                "Le compteur d'OF de l'année {$year} a atteint sa limite de ".self::MAX_NUMBER_PER_YEAR.'.',
            );
        }

        DB::table('work_order_sequences')
            ->where('year', $year)
            ->update([
                'last_number' => $next,
                'updated_at' => $now,
            ]);

        return $this->format($year, $next);
    }

    public function format(int $year, int $number): string
    {
        return sprintf('OF-%04d-%04d', $year, $number);
    }

    /**
     * The year is derived from the application timezone (config/app.php).
     */
    public function currentYear(): int
    {
        return (int) Carbon::now(config('app.timezone'))->format('Y');
    }

    protected function assertValidYear(int $year): void
    {
        if ($year < 2000 || $year > 9999) {
            throw new RuntimeException("Année invalide pour un numéro d'OF : {$year}.");
        }
    }
}
