<?php

namespace Tests\Feature\Security;

use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    public function test_usuario_no_puede_ver_datos_de_otro_tenant(): void
    {
        $this->markTestIncomplete('Implementar aislamiento por tenant_id.');
    }

    public function test_query_de_negocio_requiere_contexto_tenant(): void
    {
        $this->markTestIncomplete('Implementar validaciÃ³n de tenant context.');
    }
}
