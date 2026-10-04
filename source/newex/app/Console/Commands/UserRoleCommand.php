<?php

namespace App\Console\Commands;

use App\Models\Market\Market;
use App\Models\User\User;
use Database\Seeders\Roles\RolesSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class UserRoleCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'role:manage {user=0} {role=0} {set=0}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $action = $this->argument('set');
        $param = $this->argument('user');
        $role = $this->argument('role');

        if($action !== "set" && $action !== "remove") {
            $this->info('Wrong action type, it must be "set" or "remove"');
            return;
        }

        $user = User::where('id', (int)$param)->orWhere('email', $param)->first();

        if(!$user) {
            $this->info('Wrong User Id or Email');
            return;
        }

        $roles = array_column(RolesSeeder::ROLES, 'name');

        if(!in_array($role, $roles)) {
            $this->info('Wrong User Role');
            return;
        }

        if($action == "set") {
            $user->assignRole($role);
            $this->info('Role ' . $role . ' was successfully ASSIGNED for ' . $user->email);
        } else {
            $user->removeRole($role);
            $this->info('Role ' . $role . ' was successfully REMOVED from ' . $user->email);
        }

    }
}
