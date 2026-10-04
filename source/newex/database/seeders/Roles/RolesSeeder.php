<?php

namespace Database\Seeders\Roles;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class RolesSeeder extends Seeder
{
    const ROLES = [
        [
            'name' => 'user',
        ],
        [
            'name' => 'admin',
        ],
        [
            'name' => 'content_editor',
        ],
        [
            'name' => 'p2p_appeal_judge',
        ],
        [
            'name' => 'user_editor',
        ],
        [
            'name' => 'assets_editor',
        ],
        [
            'name' => 'finance_manager',
        ],
        [
            'name' => 'superadmin',
        ],
        [
            'name' => 'user_leader',
        ],
        [
            'name' => 'salesman',
        ]
    ];

    /**
     * Seed the application's database.
     *
     * @return void
     */
    public function run()
    {
        foreach (self::ROLES as $role) {
            if(!Role::where('name', $role['name'])->first()) {
                Role::create($role);
            }
        }
    }
}
