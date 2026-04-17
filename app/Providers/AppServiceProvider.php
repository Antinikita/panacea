<?php

namespace App\Providers;

use Illuminate\Foundation\Console\ServeCommand;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Windows: Laravel's artisan serve strips env vars not in its passthrough list,
        // but the list is case-sensitive and Windows exposes vars like "SystemRoot" in mixed case.
        // Without them the child PHP built-in server fails to initialize Winsock and dies with
        // "Failed to listen on 127.0.0.1:8000 (reason: ?)". Extend the list to cover both cases
        // plus other vars the Win32 runtime expects (TEMP/APPDATA/USERPROFILE/etc).
        if (PHP_OS_FAMILY === 'Windows') {
            ServeCommand::$passthroughVariables = array_values(array_unique(array_merge(
                ServeCommand::$passthroughVariables,
                [
                    'SystemRoot', 'SYSTEMROOT',
                    'SystemDrive', 'SYSTEMDRIVE',
                    'windir', 'WINDIR',
                    'TEMP', 'TMP',
                    'APPDATA', 'LOCALAPPDATA',
                    'USERPROFILE', 'HOMEDRIVE', 'HOMEPATH',
                    'COMSPEC', 'PATHEXT',
                    'ProgramData', 'PROGRAMDATA',
                    'ProgramFiles', 'PROGRAMFILES',
                    'ProgramFiles(x86)',
                    'NUMBER_OF_PROCESSORS', 'PROCESSOR_ARCHITECTURE',
                ]
            )));
        }
    }
}
