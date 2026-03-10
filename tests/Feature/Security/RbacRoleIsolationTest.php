<?php

namespace Tests\Feature\Security;

use Tests\TestCase;

class RbacRoleIsolationTest extends TestCase
{
    public function test_cajero_no_puede_aprobar_operacion_critica(): void
    {
        $this->markTestIncomplete('Implementar autorizaciÃƒÆ’Ã‚Â³n por rol.');
    }

    public function test_admin_puede_gestionar_permisos_no_criticos(): void
    {
        $this->markTestIncomplete('Implementar regla de autorizaciÃƒÆ’Ã‚Â³n ADMIN.');
    }
}