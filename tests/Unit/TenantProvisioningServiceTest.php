<?php

namespace Tests\Unit;

use App\Services\TenantProvisioningService;
use PHPUnit\Framework\TestCase;

class TenantProvisioningServiceTest extends TestCase
{
    /**
     * 28-sep-2026: con `kernia_provisioner` (acotado a `bridge\_siv`,
     * `svi\_%`...) el GRANT sobre `bridge_siv` falló con 1044 -- el `_` sin
     * escapar es comodín y pedía un patrón más amplio que el del otorgante.
     */
    public function test_patron_grant_escapa_comodines(): void
    {
        $this->assertSame('bridge\_siv', TenantProvisioningService::patronGrantExacto('bridge_siv'));
        $this->assertSame('svi\_pruebasvi', TenantProvisioningService::patronGrantExacto('svi_pruebasvi'));
        $this->assertSame('kernialandlord', TenantProvisioningService::patronGrantExacto('kernialandlord'));
    }
}
