<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Illuminate\Support\Facades\Storage;
use AWS;

class CleanUp extends Command
{
  /**
  * The name and signature of the console command.
  *
  * @var string
  */
  protected $signature = 'command:cleanup';
  protected $dir = '/var/www/html/db_backups/';

  /**
  * The console command description.
  *
  * @var string
  */
  protected $description = 'Clean up old files';
  protected $process;
  protected $db_name = 'larabid_dump';
  protected $bucket = 'tymbl-landing-users';

  /**
  * Create a new command instance.
  *
  * @return void
  */
  public function __construct()
  {
    parent::__construct();

    $dbname =  $this->db_name.'_'.time().'.sql';

    $this->process = new Process(
      $this->localCleanUp()
    );
  }

  public function localCleanUp(){

    $num_files = count(glob($this->dir . "*"));

    if($num_files > 50){

      $files = scandir($this->dir, SCANDIR_SORT_DESCENDING);
      $s3 = AWS::createClient('s3');

      foreach($files as $k=>$v){
        $dfile =$this->dir.$files[$k];

        if ($k > 50  && is_file($dfile)) {
          //Log::info($files[$k]);
          $s3->deleteObject(array(
            'Bucket' =>  $this->bucket,
            'Key'    => 'dbbackup/'.$files[$k],
          ));
          unlink($dfile);
        }
      }
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
      $this->process->mustRun();
      $this->info('The backup has been proceed successfully.');
    } catch (ProcessFailedException $exception) {
      $this->error('The backup process has been failed.');
    }
  }
}
