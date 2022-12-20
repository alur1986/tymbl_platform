<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Illuminate\Support\Facades\Storage;
use AWS;
use Illuminate\Support\Facades\Log;

class BackupDb extends Command
{
  /**
  * The name and signature of the console command.
  *
  * @var string
  */
  protected $signature = 'command:dbbackup';

  /**
  * The console command description.
  *
  * @var string
  */
  protected $description = 'Back up database';
  protected $process;
  protected $db_name = 'larabid_dump';
  protected $bucket = 'tymbl-landing-users';
  protected $dir = '/var/www/html/db_backups/';

  /**
  * Create a new command instance.
  *
  * @return void
  */
  public function __construct()
  {
    parent::__construct();

    $dbname =  $this->db_name.'_'.time().'.sql';

    $this->process = new Process(sprintf('mysqldump -u %s -p\'%s\' %s > %s',
    config('database.connections.mysql.username'),
    config('database.connections.mysql.password'),
    config('database.connections.mysql.database'),
    $this->dir.$dbname
    ));
  }

  //Upload latest file to s3 bucket
  public function s3Backup(){

    try {

      $files = scandir($this->dir, SCANDIR_SORT_DESCENDING);
      $new_dumpfile = $files[0];

      //$this->laravel($new_dumpfile.' The backup has been proceed successfully.');
      Log::info('prepared'.$new_dumpfile);
        $s3 = AWS::createClient('s3');
        $s3->putObject(array(
          'Bucket'     => $this->bucket,
          'Key'        => 'dbbackup/'.$new_dumpfile,
          'SourceFile' => $this->dir.$new_dumpfile
        ));
        Log::info('uploaded'.$new_dumpfile);

    }
    catch (\Exception $e) {
      Log::info('uploaded'.$e->getMessage());
      return $e->getMessage();
    }
  }

  /**
  * Execute the console command.
  *
  * @return mixed
  */
  public function handle()
  {
    try {
      $this->process->run();
      sleep(5);
      $this->s3Backup();
      Log::info('The backup has been proceed successfully.');
    } catch (ProcessFailedException $exception) {
      Log::info('The backup process has failed. '.$exception);
    }
  }
}
