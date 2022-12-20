<?php

namespace App\Console\Commands;

use Storage;
use Carbon\Carbon;
use App;
use Log;

use Illuminate\Console\Command;

class AWS3Backup extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'command:awsbackup';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Make AWS3 Backup';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
    
    if (!App::isLocal()) {
            $local = Storage::disk('local');
            $images = $local->allFiles('uploads/images');
            $cloud = Storage::disk('s3');
            $path = 'tymbl-photos' . DIRECTORY_SEPARATOR;
            foreach ($images as $file) {
                $contents = $local->get($file);
                $cloud_path = $path . $file;
                $cloud->put($cloud_path, $contents);
            
            }
        }
        else {
            Log::info('AWS3 Back Up error');
        }
    }



}
