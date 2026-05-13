<?php

use App\Models\User;

it('redirects a Technicien to /machines after login', function () {
    $user = User::factory()->create([
        'email' => 'tech@example.com',
        'password' => bcrypt('secret123'),
    ]);

    $this->post('/login', [
        'email' => 'tech@example.com',
        'password' => 'secret123',
    ])->assertRedirect(route('machines.index'));
});

it('redirects a Personnel Navigant to /personnel-navigant after login', function () {
    $user = User::factory()->create([
        'email' => 'pn@example.com',
        'password' => bcrypt('secret123'),
        'is_personnel_navigant' => true,
    ]);

    $this->post('/login', [
        'email' => 'pn@example.com',
        'password' => 'secret123',
    ])->assertRedirect(route('personnel-navigant.index'));
});

it('redirects an admin (non-PN) to /machines after login', function () {
    $user = User::factory()->create([
        'email' => 'admin@example.com',
        'password' => bcrypt('secret123'),
        'is_admin' => true,
    ]);

    $this->post('/login', [
        'email' => 'admin@example.com',
        'password' => 'secret123',
    ])->assertRedirect(route('machines.index'));
});

it('redirects an admin who is also PN to /personnel-navigant', function () {
    $user = User::factory()->create([
        'email' => 'adminpn@example.com',
        'password' => bcrypt('secret123'),
        'is_admin' => true,
        'is_personnel_navigant' => true,
    ]);

    $this->post('/login', [
        'email' => 'adminpn@example.com',
        'password' => 'secret123',
    ])->assertRedirect(route('personnel-navigant.index'));
});
