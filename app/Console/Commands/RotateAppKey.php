<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Re-encrypt every encrypted column under a NEW APP_KEY using the OLD
 * APP_KEY for one-time decryption. Run this AFTER moving the project
 * off OneDrive (so the new key never touches synced storage).
 *
 * Usage:
 *   1. Move project folder off OneDrive (or exclude it from sync).
 *   2. Save your CURRENT APP_KEY:
 *        cp .env .env.backup-rotation
 *   3. Generate a fresh key WITHOUT writing it to .env yet:
 *        php artisan key:generate --show
 *      (copy the printed value)
 *   4. Run this command, passing both keys:
 *        php artisan app:rotate-key \
 *          --old="$(grep ^APP_KEY= .env.backup-rotation | cut -d= -f2-)" \
 *          --new="<the value from step 3>"
 *   5. After it succeeds, replace APP_KEY in .env with the new key:
 *        php artisan key:set "<new key>" (or edit .env directly)
 *      Then `php artisan config:clear`.
 *   6. Test: log in to confirm reads still work.
 *   7. Delete .env.backup-rotation. Securely wipe any OneDrive history
 *      copies of the old .env you can reach.
 *
 * Atomic per-row, idempotent on retry: if a row's column doesn't decrypt
 * with the old key but DOES with the new key, it's already rotated and
 * gets skipped.
 */
class RotateAppKey extends Command
{
    protected $signature = 'app:rotate-key {--old= : The current APP_KEY (with or without base64: prefix)} {--new= : The replacement APP_KEY}';

    protected $description = 'Re-encrypt every encrypted column under a new APP_KEY (run after moving project off OneDrive).';

    private array $userCols = ['name', 'email', 'sex'];

    private array $anamnesisTextCols = [
        'chief_complaint',
        'history_present_illness',
        'past_medical_history',
        'family_history',
        'social_history',
        'allergies',
        'medications',
        'review_of_systems',
    ];

    private array $anamnesisJsonCols = ['health_context', 'ai_raw_response'];

    public function handle(): int
    {
        $oldKey = $this->parseKey($this->option('old'));
        $newKey = $this->parseKey($this->option('new'));

        if (! $oldKey || ! $newKey) {
            $this->error('Both --old and --new keys are required.');
            return self::FAILURE;
        }
        if (hash_equals($oldKey, $newKey)) {
            $this->error('Old and new keys are identical; nothing to rotate.');
            return self::FAILURE;
        }

        $cipher = config('app.cipher', 'AES-256-CBC');
        $oldEnc = new Encrypter($oldKey, $cipher);
        $newEnc = new Encrypter($newKey, $cipher);

        $this->info('Rotating users...');
        $usersTouched = $this->rotateTable('users', $this->userCols, $oldEnc, $newEnc);
        $this->info("  {$usersTouched['rotated']} rotated, {$usersTouched['skipped']} already-rotated, {$usersTouched['failed']} failed");

        $this->info('Rotating chat_messages.message + metadata...');
        $chatTouched = $this->rotateTable('chat_messages', ['message', 'metadata'], $oldEnc, $newEnc);
        $this->info("  {$chatTouched['rotated']} rotated, {$chatTouched['skipped']} already-rotated, {$chatTouched['failed']} failed");

        $this->info('Rotating anamneses...');
        $anaTouched = $this->rotateTable(
            'anamneses',
            array_merge($this->anamnesisTextCols, $this->anamnesisJsonCols),
            $oldEnc,
            $newEnc,
        );
        $this->info("  {$anaTouched['rotated']} rotated, {$anaTouched['skipped']} already-rotated, {$anaTouched['failed']} failed");

        $this->info('Rotating health_metrics.value...');
        $hmTouched = $this->rotateTable('health_metrics', ['value'], $oldEnc, $newEnc);
        $this->info("  {$hmTouched['rotated']} rotated, {$hmTouched['skipped']} already-rotated, {$hmTouched['failed']} failed");

        $this->info('Flushing app cache (idempotency entries were encrypted under the old key).');
        Cache::flush();

        $totalFailed = $usersTouched['failed'] + $chatTouched['failed'] + $anaTouched['failed'] + $hmTouched['failed'];
        if ($totalFailed > 0) {
            $this->warn("Some rows failed to rotate ({$totalFailed} total). Inspect the log and re-run with the same --old/--new keys; the command is idempotent.");
            return self::FAILURE;
        }

        $this->info('All encrypted columns rotated. Update APP_KEY in .env to the new value, then `php artisan config:clear`.');
        return self::SUCCESS;
    }

    private function parseKey(?string $value): ?string
    {
        if (! $value) return null;
        if (str_starts_with($value, 'base64:')) {
            return base64_decode(substr($value, 7));
        }
        return $value;
    }

    /**
     * Walk every row of $table and re-encrypt each cell of $cols.
     *
     * For each cell:
     *  - decrypt with the OLD encrypter; if that succeeds, encrypt with NEW.
     *  - if old decrypt fails but new decrypt succeeds, the cell is already
     *    rotated (re-run after partial run) — skip.
     *  - otherwise, count as failure.
     *
     * @return array{rotated:int, skipped:int, failed:int}
     */
    private function rotateTable(string $table, array $cols, Encrypter $old, Encrypter $new): array
    {
        $rotated = 0; $skipped = 0; $failed = 0;

        DB::table($table)->orderBy('id')->each(function ($row) use ($table, $cols, $old, $new, &$rotated, &$skipped, &$failed) {
            $updates = [];
            foreach ($cols as $col) {
                $val = $row->{$col} ?? null;
                if ($val === null || $val === '') continue;
                try {
                    // 'encrypted' / 'encrypted:array' casts use encryptString
                    // (no serialize). decryptString matches that envelope —
                    // do NOT use decrypt(..., true) here, that adds an
                    // unserialize step the original payload never had.
                    $plain = $old->decryptString($val);
                    $updates[$col] = $new->encryptString($plain);
                    $rotated++;
                } catch (\Throwable) {
                    try {
                        $new->decryptString($val);
                        $skipped++;
                    } catch (\Throwable) {
                        $this->error("  {$table}.{$col} id={$row->id}: neither key decrypts — leaving alone");
                        $failed++;
                    }
                }
            }
            if ($updates) {
                DB::table($table)->where('id', $row->id)->update($updates);
            }
        });

        return ['rotated' => $rotated, 'skipped' => $skipped, 'failed' => $failed];
    }
}
