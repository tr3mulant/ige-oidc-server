<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Spatie\Backup\Notifications\Notifications\BackupHasFailedNotification;
use Spatie\Backup\Notifications\Notifications\BackupWasSuccessfulNotification;
use Spatie\Backup\Notifications\Notifications\UnhealthyBackupWasFoundNotification;

test('the signing keys are in the backup', function () {
    expect(config('backup.backup.source.files.include'))
        ->toContain(storage_path('oauth-private.key'))
        ->toContain(storage_path('oauth-public.key'));
})->note('The keys are files on a volume, not rows. A restore that recovers Postgres without them brings back every account and no ability to sign anything.');

test('the env file is not in the backup', function () {
    expect(config('backup.backup.source.files.include'))->not->toContain(base_path('.env'));
})->note('APP_KEY is configuration, managed separately. It is also excluded from the image, so an include here would silently match nothing.');

test('the database is backed up on the connection the application uses', function () {
    expect(config('backup.backup.source.databases'))->toContain(config('database.default'));
});

test('the archive is verified after it is written', function () {
    expect(config('backup.backup.verify_backup'))->toBeTrue();
})->note('A truncated archive that looks finished is indistinguishable from a good one until the day it is needed.');

test('backup failures raise a notification and successes stay quiet', function () {
    $notifications = config('backup.notifications.notifications');

    expect($notifications[BackupHasFailedNotification::class])->toContain('mail')
        ->and($notifications[UnhealthyBackupWasFoundNotification::class])->toContain('mail')
        ->and($notifications[BackupWasSuccessfulNotification::class])->toBeEmpty();
})->note('A nightly success mail trains people to filter the address the failure will arrive at.');

test('the backup commands are scheduled', function () {
    app(Kernel::class)->all();

    $scheduled = collect(app(Schedule::class)->events())
        ->map(fn ($event) => $event->command)
        ->filter()
        ->implode(' ');

    expect($scheduled)
        ->toContain('backup:run')
        ->toContain('backup:clean')
        ->toContain('backup:monitor');
})->note('Without the schedule the package is installed and inert: nothing ever runs between deploys, which is the gap it was added to close.');
