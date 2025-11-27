<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder {
  public function run(): void {

    $adminEmail = env('ADMIN_EMAIL');
    $adminPassword = env('ADMIN_PASSWORD');

    if(!User::where('email', $adminEmail)->first()){
      User::factory()->create([
        'name' => 'Admin Admin',
        'email' => $adminEmail,
        'password' => bcrypt($adminPassword),
      ]);
    }
  }
}