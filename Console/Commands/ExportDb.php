<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Illuminate\Support\Facades\Storage;
use AWS;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Input\InputOption;
use App\User;

class ExportDb extends Command
{
  /**
  * Exports and save db dump via api
  *
  * @var string
  */
  protected $signature = 'command:exportdb {email}';

  /**
  * The console command description.
  *
  * @var string
  */
  protected $description = 'Back up database';
  protected $process;
  protected $file_name;
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

  }

  //Upload latest file to s3 bucket
  public function processBackup($email){

    try {

      $search_str = array("@", ".");
      $replace_str = array("_", "_");
      $str = $email;
      $this->file_name = str_replace($search_str, $replace_str, $str).'-'.time().'.sql';

      $this->process = new Process(sprintf('mysqldump -u %s -p\'%s\' %s > %s',
      config('database.connections.mysql.username'),
      config('database.connections.mysql.password'),
      config('database.connections.mysql.database'),
      $this->dir.$this->file_name
      ));
      $this->process->run();

      $s3 = AWS::createClient('s3');
      $s3->putObject(array(
        'Bucket'     => $this->bucket,
        'Key'        => 'dbbackup/'.$this->file_name,
        'SourceFile' => $this->dir.$this->file_name
      ));

      unlink($this->dir.$this->file_name);

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

      $email = $this->argument('email');
      $user = User::where('email', '=', $email)->where('user_type', '=', 'admin')->first();
      if($user){
        $this->processBackup($email);
        $this->line('Backup successful');
        $this->line('File location: '.$this->file_name);
      }else{
        $this->line('Error: either email not found or user is not admin');
      }

      Log::info('The backup has been processed successfully.');
    } catch (ProcessFailedException $exception) {
      Log::info('The backup process has failed. '.$exception);
    }
  }
}
