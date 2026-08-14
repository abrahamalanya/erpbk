<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Spatie\Permission\PermissionRegistrar;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // RefreshDatabase resets the database per test, but Spatie's permission
        // cache lives outside of it for the lifetime of the test process — without
        // this, role/permission changes made by an earlier test leak into the next.
        $this->app->make(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
