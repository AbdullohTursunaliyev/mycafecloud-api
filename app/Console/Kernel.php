<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // $schedule->command('inspire')->hourly();
        $schedule->command('pcs:heartbeat-check')->everyMinute();
        $schedule->command('bookings:expire')->everyMinute()->withoutOverlapping();
        $schedule->command('billing:sessions-tick')
            ->everyMinute()
            ->withoutOverlapping();
        $schedule->command('shifts:auto-tick')
            ->everyMinute()
            ->withoutOverlapping();

    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
