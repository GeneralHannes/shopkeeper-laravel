<?php
namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

// Apply database/sql/*.sql once each, tracked in schema_migrations — the same raw
// SQL migrations the Python/Bun ports use (they contain their own BEGIN..COMMIT,
// so we run them outside Laravel's own migration transactions). Run: php artisan shop:migrate
class ShopkeeperMigrate extends Command
{
    protected $signature = 'shop:migrate';
    protected $description = 'Apply the raw shopkeeper SQL migrations (database/sql/*.sql)';

    public function handle(): int
    {
        DB::statement('CREATE TABLE IF NOT EXISTS schema_migrations (filename text PRIMARY KEY, applied_at timestamptz NOT NULL DEFAULT now())');
        $files = glob(database_path('sql/*.sql'));
        sort($files);
        foreach ($files as $path) {
            $name = basename($path);
            $done = DB::selectOne('SELECT 1 AS x FROM schema_migrations WHERE filename = ?', [$name]);
            if ($done) { $this->line("skip  $name"); continue; }
            DB::unprepared(file_get_contents($path));
            DB::insert('INSERT INTO schema_migrations (filename) VALUES (?)', [$name]);
            $this->info("apply $name");
        }
        $this->info('migrations up to date.');
        return self::SUCCESS;
    }
}
