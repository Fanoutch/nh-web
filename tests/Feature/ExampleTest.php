<?php

it('redirects root to login when unauthenticated', function () {
    $response = $this->get('/');
    $response->assertRedirect(route('login'));
});

it('redirects root to machines when authenticated', function () {
    $user = \App\Models\User::factory()->create();
    $response = $this->actingAs($user)->get('/');
    $response->assertRedirect(route('machines.index'));
});
