## Multi-Tenancy for Laravel and Laravel-Doctrine

This library provides the necessary infra-structure for a complex multi-tenant application.
Multi-tenancy allows an application to be silo'd into protected areas by some form of tenant
identifier. This could be by sub-domain, URL parameter or some other scheme.

### Terminology

#### Tenant

Identifies the currently loaded account that the user belongs to. There are two components:

 * Tenant Owner: tenant_owner_id
 * Tenant Creator: tenant_creator_id

The tenant owner is the root account that actual "owns" the tenant-aware data.

The tenant creator is the instance that is adding or manipulating data that belongs to the tenant owner.

The tenant owner and creator may be the same entity.

The Tenant is its own object, registered in the container as: auth.tenant.

#### Tenant Participant

A tenant participant identifies the entity that is actually providing the tenancy reference.
This must be defined for this library to work and there can only be a single entity.

Typically this will be an Account class or User or (from laravel-doctrine/acl), an organization.

The tenant participant may be a polymorphic entity e.g.: one that uses single table inheritance.

#### Tenant Participant Mapping

Provides an alias to the tenant participant for easier referencing. Note: this is not a container
alias but used internally for tagging routes. e.g.:

the participant class is \App\Entity\SomeType\TheActualInstanceClass and in the routes we want
to restrict to this type. Instead of using the whole class name, it can be aliased to "short_name".

#### Tenant Aware

An entity that implements the TenantAware contract (interface). This allows the data to be portioned
by the tenant owner / creator.

A tenant aware entity requires:

 * get/set TenantOwnerId
 * get/set TenantCreatorId
 * importTenancyFrom

#### Tenant Aware Repository

A specific repository that will enforce the tenant requirements ensuring that any fetch request
will be correctly bound with the tenant owner and creator, depending on the security scheme
that has been implemented on the tenant owners data.

A tenant aware repository usually wraps the standard entities repository class. This may be the
standard Doctrine EntityRepository.

#### Security Model

Defines how data is shared within a tenant owners account. In many situations this will be just
the tenant owner and creator only, however this library allows a hierarchy and a user to have
multiple tenants associated with them. In this instance the security level will determine what
information is available to the user depending on their current creator instance.

The provided security models are:

 * shared - all data within the tenant owner is shared to all tenant creators
 * user - the user can access all data they are allowed access to within the tenant owner
 * closed - only the current creator within the owner is permitted
 * inherit - defer to the parent to get the security model.

Additional models can be implemented. The default configuration is closed, with no sharing.

### Requirements

 * PHP 5.5+
 * laravel 5.1+
 * laravel-doctrine/orm
 * somnambulist/laravel-doctrine-behaviours

### Installation

Install using composer, or checkout / pull the files from github.com.

 * composer install somnambulist/laravel-doctrine-tenancy

### Setup / Getting Started

 * add \Somnambulist\Tenancy\TenancyServiceProvider::class to your config/app.php
 * add \Somnambulist\Tenancy\TenantImporterEventSubscriber::class to config/doctrine.php subscribers
 * create or import the config/tenancy.php file
 * create your TenantParticipant entity / repository and add to the config file
 * create your participant mappings in the config file (at least class => class)
 * create your User with tenancy support
 * create an App\Http\Controller\TenantController to handle the various tenant redirects
 * add the basic routes
 * add AuthenticateTenant as auth.tenant to HttpKernel route middlewares
 * if wanted, add EnsureTenantType as auth.tenant.type to HttpKernel route middlewares

#### Doctrine Event Subscriber

An event subscriber is provided that will automatically set the tenancy information on any
tenant aware entity upon persist. Note that this only occurs on prePersist and once created
should not be modified. If this information is subsequently removed, then records may simply
disappear when accessing the tenant aware repositories.

#### Example User

The following is an example of a tenant aware user that has a single tenant:

    <?php
    namespace App\Entity;

    use Somnambulist\Tenancy\Contracts\BelongsToTenant as BelongsToTenantContract;
    use Somnambulist\Tenancy\Contracts\BelongsToTenantParticipants;
    use Somnambulist\Tenancy\Contracts\TenantParticipant;
    use Somnambulist\Tenancy\Traits\BelongsToTenant;
    class User implements AuthenticatableContract, AuthorizableContract,
           CanResetPasswordContract, BelongsToTenantContract, BelongsToTenantParticipant
    {
        use BelongsToTenant;

        protected $tenant;

        public function getTenantParticipant()
        {
            return $this->tenant;
        }

        public function setTenantParticipant(TenantParticipant $tenant)
        {
            $this->tenant = $tenant;
        }
    }

#### Example Tenant Participant

The following is an example of a tenant participant:

    <?php
    namespace App\Entity;

    use Somnambulist\Doctrine\Traits\Identifiable;
    use Somnambulist\Doctrine\Traits\Nameable;
    use Somnambulist\Tenancy\Contracts\TenantParticipant as TenantParticipantContract;
    use Somnambulist\Tenancy\Traits\TenantParticipant;

    class Account implements TenantParticipantContract
    {
        use Identifiable;
        use Nameable;
        use TenantParticipant;
    }

#### Basic Routes

The two authentication middlewares expect the following routes to be defined and available:

    // tenant selection and error routes
    Route::group(['prefix' => 'tenant', 'as' => 'tenant.', 'middleware' => ['auth']], function () {
        Route::get('select',          ['as' => 'select_tenant',             'uses' => 'TenantController@selectTenantAction']);
        Route::get('no-tenants',      ['as' => 'no_tenants',                'uses' => 'TenantController@noTenantsAvailableAction']);
        Route::get('no-access',       ['as' => 'access_denied',             'uses' => 'TenantController@accessDeniedAction']);
        Route::get('not-supported',   ['as' => 'tenant_type_not_supported', 'uses' => 'TenantController@tenantTypeNotSupportedAction']);
        Route::get('invalid-request', ['as' => 'invalid_tenant_hierarchy',  'uses' => 'TenantController@invalidHierarchyAction']);
    });

As a separate block (or within the previous section) add the areas of the application that
require tenancy support / enforcement. These routes should be prefixed with at least:
{tenant_creator_id}. {tenant_owner_id} can be used (first) which will force the auth.tenant
middleware to validate that the creator belongs to the owner as well as the current user
having access to the creator.

Note: the user does not need access to the tenant owner, access to the tenant creator implies
permission to access a sub-set of the data.

    // Tenant Aware Routes
    Route::group(['prefix' => 'account/{tenant_creator_id}', 'as' => 'tenant.', 'namespace' => 'Tenant', 'middleware' => ['auth', 'auth.tenant']], function () {
        Route::get('/', ['as' => 'index', 'uses' => 'DashboardController@indexAction']);

        // routes that should be limited to certain ParticipantTypes
        Route::group(['prefix' => 'customer', 'as' => 'customer.', 'namespace' => 'Customer', 'middleware' => ['auth.tenant.type:crm']], function () {
            Route::get('/', ['as' => 'index', 'uses' => 'CustomerController@indexAction']);
        });
    });

#### Tenant Aware Entity

Finally you need something that is actually tenant aware! So lets create a really basic
customer:

    <?php
    namespace App\Entity;

    use Somnambulist\Doctrine\Contracts\GloballyTrackable as GloballyTrackableContract;
    use Somnambulist\Doctrine\Traits\GloballyTrackable;
    use Somnambulist\Tenancy\Contracts\TenantAware as TenantAwareContract;
    use Somnambulist\Tenancy\Traits\TenantAware;

    class Customer implements GloballyTrackableContract, TenantAwareContract
    {
        use GloballyTrackable;
        use TenantAware;
    }

This creates a Customer entity that will track the tenant information. To save typing
this uses the built-in trait. A corresponding repository will need to be created along with
the Doctrine mapping file. Here is an example yaml file:

    App\Entity\Customer:
        type: entity
        table: customers
        repositoryClass: App\Repository\CustomerRepository

        uniqueConstraints:
            uniq_users_uuid:
                columns: [ uuid ]

        id:
            id:
                type: bigint
                generator:
                    strategy: auto

        fields:
            uuid:
                type: guid

            tenantOwnerId:
                type: integer

            tenantCreatorId:
                type: integer

            name:
                type: string
                length: 255

            createdBy:
                type: string
                length: 36

            updatedBy:
                type: string
                length: 36

            createdAt:
                type: datetime

            updatedAt:
                type: datetime

#### Tenant Aware Repositories

Tenant aware repositories simply wrap an existing entity repository with the standard
repository interface. They should be defined and created as we actually want to be
able to inject these as dependency and set them up in the container.

First you will need to create an App level TenantAwareRepository that extends:

 * Somnambulist\Tenancy\TenantAwareRepository

For example:

    <?php
    namespace App\Repository;

    use Somnambulist\Tenancy\TenantAwareRepository;

    class AppTenantAwareRepository extends TenantAwareRepository
    {

    }

Provided you don't have a custom security model, this should be good to extend again
as a namespaced "tenant" repository for our customer:

    <?php
    namespace App\Repository\TenantAware;

    use App\Repository\AppTenantAwareRepository;

    class CustomerRepository extends AppTenantAwareRepository
    {

    }

Now, the config/tenancy.php can be updated to add a repository config definition so this class
will be automatically available in the container. Note: this step presumes the standard
repository is already mapped to the container using the repository class as the key.

    [
        'repository' => \App\Repository\TenantAware\CustomerRepository::class,
        'base'       => \App\Repository\CustomerRepository::class,
        //'alias'      => 'app.repository.tenant_aware_customer', // optionally alias
        //'tags'       => ['repository', 'tenant_aware'], // optionally tag
    ],

### Security Models

The security model defines how data within a tenant owner should be shared. The default is no
sharing at all. In fact the security model only applies when the User implements the
BelongsToTenantParticipants and there can be multiple tenants on one user.

#### Shared

In this situation, the tenant owner may decide that any data can be shared by all child tenants
of the owner. This model is called "shared" and means that all data in the tenant owner is
available to all tenant creators at any time.

To set the security model, simply save the TenantParticipant instance with the security model
set to: TenantSecurityModel::SHARED()

Behind the scenes, when the TenantAwareRepository is queried, the current Tenant information is
extracted and the query builder instance modified to set the tenant owner and/or creator. For
shared data, only the owner is set.

The other pre-built models are:

 * user
 * closed
 * inherit

#### User

The User model restricts the queries to the current tenant owner and any mapped tenant. So if
a User has 4 child tenants, they will be able to access the data created only by those 4
child tenants. All other data will be excluded.

#### Closed

If the security model is set to closed, then all queries are created with the tenant owner and
current creator only. The user in this scheme, even with multiple tenant creators, will only
ever see data that was created by the current creator.

#### Inherit

Inherit allows the security model to be adopted from a parent tenant. If the parent model
is inherit, or there is no parent then the model will be set to closed automatically. This
library attempts to favour least access whenever possible.

#### Applying / Adding Security Models

The security model rules are applied by methods within the TenantAwareRepository. The model
name is capitalised, prefixed with "appy" and suffixed with SecurityModel so "shared" becomes
"applySharedSecurityModel".

This is why an App level repository is strongly suggested as you can then implement your own
security models simply by extending the TenantSecurityModel, defining some new constants and
then adding the appropriate method in your App repository.

For example: say you want to have a "global" policy where all unowned data is shared all over
but you also have your own data that is private to your tenant, you could add this as a new
method:

    class AppTenantAwareRepository extends TenantAwareRepository
    {

        protected function applyGlobalSecurityModel(QueryBuilder $qb, $alias)
        {
            $qb
                ->where("({$alias}.tenantOwnerId IS NULL OR {$alias}.tenantOwnerId = :tenantOwnerId)")
                ->setParameters([
                    ':tenantOwnerId' => $this->tenant->getTenantOwnerId(),
                ])
            ;
        }
    }

Additional schemes can be added as needed.

Note: while in theory you can mix security models within a tenant e.g.: some children are
closed, others shared, some user; this may result in strange results or inconsistencies.
It may lead to a large increase in duplicate records. It is up to you to manage this
accordingly.

## Twig Extension

A Twig extension will be automatically loaded if Twig is detected in the container which will
provide the following template functions:

 * current_tenant_owner_id
 * current_tenant_creator_id
 * current_tenant_owner
 * current_tenant_creator
 * get_entity_tenant_owner
 * get_entity_tenant_creator

This allows access to the current, active Tenant instance and to query for the owner/creator
on TenantAware entities.

## Views

The bundle TenantController expects to find views under:

 * /resources/views/tenant
 * /resources/views/tenant/error

These are not included as they require application implementation. The TenantController class
has information about file names and route mappings.

## Potential Issues

Working with multi-tenancy can be very complex. This library works on a shared database, not
individual databases, however you could setup specific databases based on the tenant if
necessary (if you are comfortable with multiple connections / definitions in Doctrine).

When creating repositories always ensure that tenant aware / non-tenant aware are clearly
marked to avoid using the wrong type in the wrong context. Best case: you don't see anything,
worst case - you see everything unfiltered.

You will note that in this system there are no magic SQL filters pre-applied through Doctrines
DQL filters: this is deliberate. You should be able to switch the tenancy easily at any point
and this can be done by simply updating the Tenant instance, or using the non-tenant aware
repository.

Additionally: none of the tenant ids are references to other objects. Again this is very
deliberate. It allows e.g. customer data to be in a separate database to your users and makes
it a lot more portable.

Using tenancy will add an amount of overhead to your application. How much will depend on
how much data you have and the security model you apply.

Always test and have functional tests to ensure that the tenancy is applied correctly and
whenever in doubt: **always** deny rather than grant access.

## Links

 * [Laravel Doctrine](http://laraveldoctrine.org)
 * [Laravel](http://laravel.com)
 * [Doctrine](http://doctrine-project.org)