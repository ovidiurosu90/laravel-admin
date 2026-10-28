<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Throwaway users for the security tests, so they never act as (or change) a real account. The rows are inserted with
 * the query builder (a User model is never saved: its virtual attributes break save()), roles and permissions go
 * through the laravel-roles pivots, and DatabaseTransactions rolls everything back.
 *
 * The test class needs `use DatabaseTransactions;`.
 */
trait CreatesSyntheticUsers
{
    /**
     * @param list<string> $roleSlugs       e.g. ['admin'], ['financeadmin'], [] for no role at all
     * @param list<string> $permissionSlugs direct user permissions, on top of what the roles give
     */
    protected function syntheticUser(array $roleSlugs = [], array $permissionSlugs = []): User
    {
        $tag = 'tst-sec-' . Str::lower(Str::random(10));
        $now = now();
        $id  = DB::table('users')->insertGetId([
            'name'       => $tag,
            'email'      => $tag . '@example.test',
            'password'   => bcrypt('secret'),
            'token'      => Str::random(64),
            'activated'  => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('profiles')->insert([
            'user_id'    => $id,
            'theme_id'   => DB::table('themes')->value('id'),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $user = User::findOrFail($id);
        foreach ($roleSlugs as $slug) {
            $user->attachRole($this->_roleOrSkip($slug));
        }
        foreach ($permissionSlugs as $slug) {
            $user->attachPermission($this->_permissionOrSkip($slug));
        }

        return User::findOrFail($id);
    }

    private function _roleOrSkip(string $slug): Role
    {
        $role = Role::where('slug', $slug)->first();
        if ($role === null) {
            $this->markTestSkipped("Requires the '{$slug}' role");
        }

        return $role;
    }

    private function _permissionOrSkip(string $slug): Permission
    {
        $permission = Permission::where('slug', $slug)->first();
        if ($permission === null) {
            $this->markTestSkipped("Requires the '{$slug}' permission");
        }

        return $permission;
    }
}
