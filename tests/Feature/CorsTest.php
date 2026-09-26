<?php

namespace Tests\Feature;

use Tests\TestCase;

class CorsTest extends TestCase
{
    public function test_production_origin_is_allowed_and_other_origins_are_not(): void
    {
        $this->options('/api/sync', [], [
            'Origin' => 'https://fit.qla.dev', 'Access-Control-Request-Method' => 'POST',
            'Access-Control-Request-Headers' => 'authorization,content-type',
        ])->assertSuccessful()->assertHeader('Access-Control-Allow-Origin', 'https://fit.qla.dev');
        $this->options('/api/sync', [], [
            'Origin' => 'https://unrelated.example', 'Access-Control-Request-Method' => 'POST',
        ])->assertHeaderMissing('Access-Control-Allow-Origin');
    }
}
