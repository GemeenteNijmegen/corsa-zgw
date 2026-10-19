<?php

use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\RelationManagers\TokensRelationManager;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Telescope\Telescope;
use Livewire\Livewire;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

test('a guest is redirected from the admin panel to the login page', function () {
    $this->get('/admin')
        ->assertRedirect('/admin/login');
});

test('a guest cannot open the queue dashboard', function () {
    $this->get('/horizon')
        ->assertForbidden();
});

test('a guest is not authorized for the debug dashboard', function () {
    // The debug dashboard routes are not registered while it is disabled in the
    // test environment, so this checks its authorization callback directly.
    expect(Telescope::check(Request::create('/telescope')))->toBeFalse();
});

test('a logged-in user sees the list of users', function () {
    $users = User::factory()->count(3)->create();
    $this->actingAs($users->first());

    Livewire::test(ListUsers::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords($users);
});

test('a logged-in user can open the edit page of a user with its tokens', function () {
    $admin = User::factory()->create();
    $apiClient = User::factory()->create(['name' => 'API client']);
    $apiClient->createToken('existing-token');
    $this->actingAs($admin);

    Livewire::test(EditUser::class, ['record' => $apiClient->getRouteKey()])
        ->assertSuccessful()
        ->assertSchemaStateSet(['name' => 'API client']);

    Livewire::test(TokensRelationManager::class, [
        'ownerRecord' => $apiClient,
        'pageClass' => EditUser::class,
    ])
        ->assertSuccessful()
        ->assertCanSeeTableRecords($apiClient->tokens()->get());
});

test('creating a token through the relation manager shows the plain text token once', function () {
    $admin = User::factory()->create();
    $apiClient = User::factory()->create();
    $this->actingAs($admin);

    // In the panel the session middleware attaches the session to the request.
    // Livewire component tests skip middleware on their update requests, so
    // attach the session to every request that is bound during this test.
    $this->app->rebinding('request', fn ($app, $request) => $request->setLaravelSession($app['session.store']));

    $component = Livewire::test(TokensRelationManager::class, [
        'ownerRecord' => $apiClient,
        'pageClass' => EditUser::class,
    ])
        ->callAction(TestAction::make('create')->table(), data: ['name' => 'notifications-client'])
        ->assertHasNoActionErrors();

    $token = $apiClient->tokens()->sole();
    expect($token->name)->toBe('notifications-client');

    // The re-rendered relation manager shows the plain text token...
    preg_match('/\b'.$token->id.'\|[A-Za-z0-9]{40,}/', $component->html(), $matches);
    expect($matches)->toHaveCount(1)
        ->and(PersonalAccessToken::findToken($matches[0])?->is($token))->toBeTrue();

    // ...and the flashed value is cleared once that response is sent, so it is not shown again.
    expect(session()->has('sanctum-token'))->toBeFalse();
    $component->call('$refresh')->assertDontSee($matches[0]);
});
