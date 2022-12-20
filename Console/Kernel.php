<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * The Artisan commands provided by your application.
     *
     * @var array
     */
    protected $commands = [
      Commands\BackupDb::class,
      Commands\CleanUp::class,
    ];

    /**
     * Define the application's command schedule.
     *
     * @param  \Illuminate\Console\Scheduling\Schedule  $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {

      //listen to new listing notification
      $schedule->call('\App\Http\Controllers\NotificationTaskController@notificationTask')->cron('0 */5 * * *');

      //start approval and payment transaction tasks
      $schedule->call('\App\Http\Controllers\TitleCompanyController@sendApprovalNotification')->everyMinute();

      //new registration listener
      $schedule->call('\App\Http\Controllers\NotificationTaskController@sendRegistrationActivation')->everyMinute();


      //checks if broker verified user
      //$schedule->call('\App\Http\Controllers\NotificationTaskController@checkBrokerVerifiedUser')->hourly();

      //notify user for saved seach
      //$schedule->call('\App\Http\Controllers\NotificationTaskController@notifyUserSavedSearch')->hourly();

      //perform database backup
      //$schedule->command('command:dbbackup')->cron('0 */4 * * *');

      //clean local dump at midnight
      //$schedule->command('command:cleanup')->daily();

      //remind buy about Incomplete Title Company

      $schedule->call('\App\Http\Controllers\ContractSigned@sendTitleCompanyReminder')->daily();

      $schedule->command('awsbackup')->daily();

      //$schedule->call('\App\Http\Controllers\ContractSigned@sendTitleCompanyReminder')->daily();

      //check user incomplete broker after 3 days
      //$schedule->call('\App\Http\Controllers\NotificationTaskController@followUpBrokerAfterThreeDays')->daily();

    }

    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands()
    {
        $this->load(__DIR__.'/Commands');
        require base_path('routes/console.php');
    }
}
