<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;

Route::get('/upgrade-to-v1-2-0', function () {
    if (! config('app.upgrade_mode')) {
        abort(403, 'Upgrade mode is disabled.');
    }

    Artisan::call(
        'migrate',
        [
            '--force' => true,
        ]
    );
});

// upgrade all database

Route::get('/upgrade/database', function () {
    if (config('app.upgrade_mode')) {
        Artisan::call('migrate', ['--force' => true]);

        echo "✅ Upgrade Database has been executed successfully.<br>";
        echo "👉 To prevent accidental reruns, set `<b>UPGRADE_MODE=false</b>` in your <b>.env</b> file.<br>";
    } else {
        echo "🚫 Upgrade Database cannot be executed because `<b>UPGRADE_MODE</b>` is set to false.<br>";
        echo "🔧 If you want to run the database, temporarily set `<b>UPGRADE_MODE=true</b>` in your <b>.env</b> file.";
    }
});

// run store seeder version 1.2
// Route::get('/store/seeder', function () {
//     if (config('app.upgrade_mode')) {
//         Artisan::call('db:seed', [
//             '--class' => 'StoreSeeder',
//             '--force' => true,
//         ]);

//         echo "✅ StoreSeeder has been executed successfully.<br>";
//         echo "👉 To prevent accidental reruns, set `<b>UPGRADE_MODE=false</b>` in your <b>.env</b> file.<br>";
//     } else {
//         echo "🚫 StoreSeeder cannot be executed because `<b>UPGRADE_MODE</b>` is set to false.<br>";
//         echo "🔧 If you want to run the seeder, temporarily set `<b>UPGRADE_MODE=true</b>` in your <b>.env</b> file.";
//     }
// });
