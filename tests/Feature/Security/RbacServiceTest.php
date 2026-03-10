<?php

namespace Tests\Feature\Security;

use App\Services\Security\RbacService;
use Tests\TestCase;

class RbacServiceTest extends TestCase
{
    public function test_can_returns_true_when_permission_exists(): void
    {
        $service = new RbacService();

        $this->assertTrue($service->can(['sales.read', 'sales.write'], 'sales.write'));
    }

    public function test_can_returns_false_when_permission_missing(): void
    {
        $service = new RbacService();

        $this->assertFalse($service->can(['sales.read'], 'sales.approve'));
    }
}
