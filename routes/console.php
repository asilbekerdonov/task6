<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('circuit:sweep-presence')->everyFiveSeconds();

Artisan::command('circuit:test-concurrency {--users=8}', function () {
    $totalUsers = (int) $this->option('users');
    $this->info("Setting up test circuit for {$totalUsers} concurrent join requests...");

    $circuit = \App\Models\Circuit::create([
        'name' => 'Concurrency Lab ' . now()->toIso8601String(),
        'grid_size' => 20,
        'canvas_width' => 1200,
        'canvas_height' => 720,
    ]);

    $this->info("Circuit created with ID: {$circuit->id}. Launching parallel join attempts...");

    $pool = \Illuminate\Support\Facades\Process::pool(function ($pool) use ($circuit, $totalUsers) {
        for ($i = 1; $i <= $totalUsers; $i++) {
            $uuid = (string) \Illuminate\Support\Str::uuid();
            $cmd = sprintf(
                'php -r "require \'vendor/autoload.php\'; \$app = require \'bootstrap/app.php\'; \$kernel = \$app->make(Illuminate\Contracts\Console\Kernel::class); \$kernel->bootstrap(); try { \$res = App\Models\SessionUser::joinCircuit(App\Models\Circuit::find(%d), \'Collaborator\', \'%s\'); echo json_encode([\'status\' => 200, \'name\' => \$res[\'display_name\']]); } catch (Symfony\Component\HttpKernel\Exception\HttpException \$e) { echo json_encode([\'status\' => \$e->getStatusCode(), \'error\' => \$e->getMessage()]); }"',
                $circuit->id,
                $uuid
            );
            $pool->path(base_path())->command($cmd);
        }
    })->start()->wait();

    $statuses = [];
    $errors = [];
    foreach ($pool as $result) {
        $data = json_decode(trim($result->output()), true);
        if ($data) {
            $statuses[] = $data['status'];
            if (!in_array($data['status'], [200, 403])) {
                $errors[] = $data;
            }
        } else {
            $statuses[] = 500;
            $errors[] = ['error' => $result->errorOutput() ?: $result->output()];
        }
    }

    $successCount = count(array_filter($statuses, fn ($s) => $s === 200));
    $rejectedCount = count(array_filter($statuses, fn ($s) => $s === 403));
    $activeInDb = \App\Models\SessionUser::where('circuit_id', $circuit->id)->whereNull('left_at')->count();

    $this->table(['Metric', 'Expected', 'Actual', 'Pass?'], [
        ['HTTP 200 (Admitted)', '5', $successCount, $successCount === 5 ? '✅ YES' : '❌ NO'],
        ['HTTP 403 (Rejected)', (string) ($totalUsers - 5), $rejectedCount, $rejectedCount === ($totalUsers - 5) ? '✅ YES' : '❌ NO'],
        ['DB Active Sessions', '5', $activeInDb, $activeInDb === 5 ? '✅ YES' : '❌ NO'],
    ]);

    if (!empty($errors)) {
        $this->warn("Non-200/403 errors encountered: " . json_encode($errors, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    return ($successCount === 5 && $activeInDb === 5) ? 0 : 1;
})->purpose('Run concurrent join stress test against the database (useful for PostgreSQL validation)');
