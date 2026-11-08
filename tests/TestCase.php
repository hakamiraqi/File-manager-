<?php

namespace Tests;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Schema;

abstract class TestCase extends BaseTestCase
{
    /**
     * Every non-admin action is now gated by the role permission matrix, so
     * tests need the same permissive defaults a fresh install ships with.
     * Guarded by a table check so a test that never migrates (e.g. a plain
     * unit test) doesn't blow up trying to seed a table that doesn't exist.
     */
    protected function setUp(): void
    {
        parent::setUp();

        if (Schema::hasTable('role_permissions')) {
            $this->seed(RolePermissionSeeder::class);
        }
    }
}
