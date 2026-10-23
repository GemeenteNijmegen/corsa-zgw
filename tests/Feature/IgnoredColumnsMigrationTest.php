<?php

use Illuminate\Support\Facades\Schema;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

/**
 * @return list<list<string>>
 */
function notificationIndexColumns(): array
{
    return collect(Schema::getIndexes('notifications'))
        ->map(fn (array $index): array => $index['columns'])
        ->values()
        ->all();
}

test('the ignored columns migration adds an index on ignored_at and ignored_reason', function () {
    expect(notificationIndexColumns())
        ->toContain(['ignored_at'])
        ->toContain(['ignored_reason']);
});

test('rolling back the ignored columns migration removes both indexes and columns', function () {
    $migration = require database_path('migrations/2026_10_23_090000_add_ignored_columns_to_notifications_table.php');

    $migration->down();

    expect(notificationIndexColumns())
        ->not->toContain(['ignored_at'])
        ->not->toContain(['ignored_reason'])
        ->and(Schema::hasColumns('notifications', ['ignored_at']))->toBeFalse()
        ->and(Schema::hasColumns('notifications', ['ignored_reason']))->toBeFalse();

    $migration->up();

    expect(notificationIndexColumns())->toContain(['ignored_at']);
});
