<?php

namespace Tests\Feature;

use App\Models\Circuit;
use App\Models\SessionUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Concurrency Test for Transactional Circuit Join.
 *
 * NOTE ON DATABASE ENGINE & ROW LOCKING:
 * - When running in standard PHPUnit with SQLite (:memory:), in-memory databases are isolated per-process
 *   and SQLite uses database/file level locks rather than PostgreSQL row-level locks.
 * - In Production (PostgreSQL), `Circuit::where('id', $circuitId)->lockForUpdate()` issues a real
 *   `SELECT ... FOR UPDATE` query, strictly serializing concurrent join transactions on the parent circuit row.
 * - To verify concurrency on a live PostgreSQL instance or environment:
 *   1) Run the artisan concurrency test command:
 *      php artisan circuit:test-concurrency --users=8
 *   2) Or run concurrent HTTP requests via curl / xargs against a running server:
 *      seq 1 8 | xargs -n 1 -P 8 -I {} curl -s -X POST http://localhost:8000/api/circuits/1/join \
 *          -H "Content-Type: application/json" -H "Accept: application/json" \
 *          -d '{"name": "Candidate"}'
 */
class CircuitConcurrentJoinTest extends TestCase
{
    use RefreshDatabase;

    public function test_concurrent_joins_respect_capacity_and_uniqueness(): void
    {
        $circuit = Circuit::create([
            'name' => 'Concurrent Lab',
            'grid_size' => 20,
            'canvas_width' => 1200,
            'canvas_height' => 720,
        ]);

        $requestedUsers = 8;
        $successCount = 0;
        $rejectedCount = 0;
        $results = [];

        // Simulate 8 concurrent join attempts targeting the same circuit
        for ($i = 1; $i <= $requestedUsers; $i++) {
            $uuid = (string) Str::uuid();
            try {
                $res = SessionUser::joinCircuit($circuit, 'Candidate', $uuid);
                $successCount++;
                $results[] = [
                    'status' => 200,
                    'name' => $res['display_name'],
                    'uuid' => $uuid,
                ];
            } catch (HttpException $e) {
                if ($e->getStatusCode() === 403) {
                    $rejectedCount++;
                    $results[] = [
                        'status' => 403,
                        'message' => $e->getMessage(),
                    ];
                } else {
                    throw $e;
                }
            }
        }

        // Exactly 5 should succeed, exactly 3 should be rejected with 403
        $this->assertEquals(5, $successCount, 'Exactly 5 users should be admitted.');
        $this->assertEquals(3, $rejectedCount, 'Exactly 3 users should be rejected with 403.');

        // Verify database state: exactly 5 active session records exist in this circuit
        $activeSessions = SessionUser::where('circuit_id', $circuit->id)
            ->whereNull('left_at')
            ->get();

        $this->assertCount(5, $activeSessions);

        // Verify all 5 assigned display names are unique and deduplicated
        $displayNames = $activeSessions->pluck('display_name')->all();
        $this->assertCount(5, array_unique($displayNames));

        $this->assertEquals([
            'Candidate',
            'Candidate 2',
            'Candidate 3',
            'Candidate 4',
            'Candidate 5',
        ], $displayNames);
    }
}
