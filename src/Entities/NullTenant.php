<?php

namespace Somnambulist\Tenancy\Entities;

use Somnambulist\Tenancy\Contracts\DomainAwareTenantParticipant as DomainAwareTenantParticipantContract;
use Somnambulist\Tenancy\Traits\TenantParticipant;

/**
 * Class NullTenant
 *
 * @package    Somnambulist\Tenancy\Entities
 * @subpackage Somnambulist\Tenancy\Entities\NullTenant
 */
class NullTenant implements DomainAwareTenantParticipantContract
{

    use TenantParticipant;

    /**
     * @return integer
     */
    public function getId()
    {
        return null;
    }

    /**
     * @return string
     */
    public function getName()
    {
        return 'Null Tenant';
    }

    /**
     * @return string
     */
    public function getDomain()
    {
        return null;
    }
}
