<?php

namespace Tests\Unit;

use App\Services\Learning\ExerciseGeneration;
use App\Services\Learning\GenerationInterruptedException;
use App\Services\Llm\LlmTimeoutException;
use App\Services\Redis\ArrayRedis;
use App\Support\Clock;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ExerciseGenerationTest extends TestCase
{
    protected function tearDown(): void
    {
        Clock::reset();
        parent::tearDown();
    }

    public function test_lease_is_exclusive_until_it_expires(): void
    {
        Clock::freeze(Carbon::parse('2026-10-13 00:00:00', 'UTC'));
        $service = new ExerciseGeneration(new ArrayRedis);
        $lease = $service->acquire('gen:reading');
        $this->assertNotNull($lease);
        $this->assertNull($service->acquire('gen:reading'));
        $this->assertSame('generating', $service->state('gen:reading')['generation_status']);
        Clock::advance(ExerciseGeneration::LEASE_SECONDS + 1);
        $this->assertSame('failed', $service->state('gen:reading')['generation_status']);
        $this->assertSame('interrupted', $service->state('gen:reading')['generation_error']);
        $replacement = $service->acquire('gen:reading');
        $this->assertNotNull($replacement);
        $this->assertNotSame($lease->owner, $replacement->owner);
        $this->assertNull($service->state('gen:reading')['generation_error']);
    }

    public function test_expired_owner_cannot_clear_the_replacement(): void
    {
        Clock::freeze(Carbon::parse('2026-10-13 00:00:00', 'UTC'));
        $service = new ExerciseGeneration(new ArrayRedis);
        $old = $service->acquire('gen:listening');
        Clock::advance(ExerciseGeneration::LEASE_SECONDS + 1);
        $new = $service->acquire('gen:listening');
        $service->finish($old, null);
        $this->assertSame('generating', $service->state('gen:listening')['generation_status']);
        $service->finish($new, null);
        $this->assertSame('idle', $service->state('gen:listening')['generation_status']);
    }

    public function test_run_records_timeout_and_generation_failure(): void
    {
        $service = new ExerciseGeneration(new ArrayRedis);
        $timeout = $service->acquire('gen:a');
        $timeout->run(function () {
            throw new LlmTimeoutException('slow');
        });
        $this->assertSame('failed', $service->state('gen:a')['generation_status']);
        $this->assertSame('timeout', $service->state('gen:a')['generation_error']);
        $this->assertNotNull($service->acquire('gen:a'));

        $failed = $service->acquire('gen:b');
        $failed->run(function () {
            throw new \RuntimeException('TTS unavailable');
        });
        $this->assertSame('generation_failed', $service->state('gen:b')['generation_error']);

        $lost = $service->acquire('gen:c');
        $lost->run(function () {
            throw new GenerationInterruptedException('lost');
        });
        $this->assertSame('interrupted', $service->state('gen:c')['generation_error']);
    }

    public function test_leases_are_isolated_by_key(): void
    {
        $service = new ExerciseGeneration(new ArrayRedis);
        $keys = ['reading:A1:en-GB', 'reading:A2:en-GB', 'listening:A1:en-GB', 'listening:A1:es-ES'];
        $held = 0;
        foreach ($keys as $key) {
            if ($service->acquire($key) !== null) {
                $held++;
            }
        }
        $this->assertSame(4, $held);
    }
}
