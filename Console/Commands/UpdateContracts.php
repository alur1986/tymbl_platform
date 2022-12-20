<?php

namespace App\Console\Commands;

use App\Contract;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Tjphippen\Docusign\Facades\Docusign;

class UpdateContracts extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'larabid:docusign';
    
    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Update statuses of envelopes';
    
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
        //track execution time of the scripts
        $timeStart = $this->startedAt();
        //Log::info('CHECK STATUSES FOR ENVELOPES cron job is running');
        //dd(Docusign::getEns()['envelopes'] );
        //https://demo.docusign.net/restapi/v2/accounts/221765/envelopes?from_date=07%2F20%2F2013&status=created,sent,delivered,signed,completed
        try {
            
            collect(Docusign::getEns())
                ->each(
                    function ($envelope) {
                        //dd('closure', $envelope);
                        if (! ($status = config('larabid.statuses')[$envelope['status']])){
                            throw new \Exception("No status found for this one: {$envelope['status']}");
                        }
                        Contract::whereEnvelopeId($envelope['envelopeId'])->update(['status' => $status]);
                    }
                );
        } catch (\Exception $e) {
            dd('ERROR:', $e);
        }
        
        $this->endedAt($timeStart);
        //file_put_contents(storage_path('app/test.txt'), date('Y-m-d H:i:s')."\n", FILE_APPEND);
    }
    
    /**
     * @param $timeStart
     */
    protected function endedAt($timeStart)
    {
        $diff    = microtime(true) - $timeStart;
        $min     = $diff / 60;
        $endedAt = Carbon::now();
        $this->info("Ended at:  {$endedAt} and execution took {$diff} seconds ({$min} minutes)");
        //info('GizmoSurvey CheckStatuses command is running and finish at:' . $endedAt);
    }
    
    /**
     * @return array
     */
    protected function startedAt()
    {
        $timeStart = microtime(true);
        $startedAt = Carbon::now();
        $this->info('Started at:' . $startedAt);
        
        return $timeStart;
    }
}
