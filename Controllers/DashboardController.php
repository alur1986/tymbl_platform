<?php

namespace App\Http\Controllers;

use App\Ad;
use App\ContactQuery;
use App\Payment;
use App\Report_ad;
use App\User;
use Illuminate\Http\Request;
use App\City;
use App\Comment;
use App\Country;
use App\State;
use App\Notification;
use App\Http\Requests;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Yajra\Datatables\Datatables;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Facades\Image;
use ZipArchive;
use App\UserTitleCompanyInfo;
use App\Media;
use App\ReferralContactInfo;
use App\Category;
use Illuminate\Filesystem\Filesystem;
use Session;
use App\NotificationsUsers;
use App\ListingContracts;
use App\TransactionReports;
use App\TitleCompanyInfoLTB;
use LaravelEsignatureWrapper;
use Illuminate\Support\Facades\Mail;
use Carbon\Carbon;
use App\Broker;
use App\Jobs\SendContractToBroker;
use Aws\S3\S3Client;
use League\Flysystem\AwsS3v3\AwsS3Adapter;



class DashboardController extends Controller
{
  public function dashboard(){
    $user = Auth::user();
    $user_id = $user->id;
    $user_notification_optin = $user->notification_optin;

    $total_users = 0;
    $total_reports = 0;
    $total_payments = 0;
    $ten_contact_messages = 0;
    $reports = 0;
    $total_payments_amount = 0;
    $comments = 0;
    $notif = $this->getUserNotifications($user->id, '10');
    $notifications_users = $notif[0];
    $user_notification_count = $notif[1];

    if ($user->is_admin()){
      $approved_ads = Ad::whereStatus('1')->count();
      //$pending_ads = Ad::whereStatus('0')->count();
      $blocked_ads = Ad::whereStatus('3')->count();

      $total_users = User::where('active_status', '=', '1')->count();
      $total_users_all = User::count();
      $total_reports = Report_ad::count();
      $total_payments = Payment::whereStatus('success')->count();
      $total_payments_amount = Payment::whereStatus('success')->sum('amount');
      $ten_contact_messages = ContactQuery::take(10)->orderBy('id', 'desc')->get();
      $reports = Report_ad::orderBy('id', 'desc')->with('ad')->take(10)->get();

    }else{
      $approved_ads = Ad::whereStatus('1')->whereUserId($user_id)->count();
      $pending_ads = Ad::whereStatus('0')->whereUserId($user_id)->count();
      $blocked_ads = Ad::whereStatus('3')->whereUserId($user_id)->count();
      $comments = Comment::whereStatus('0')->whereUserId($user_id)->take(5)->orderBy('id', 'desc')->get();
      $comment_counts = Comment::whereStatus('0')->whereUserId($user_id)->count();
      //$notifications_users = NotificationsUsers::where('recipient', '=', $user_id)->limit(10)->get();

      //$user_notification_count = NotificationsUsers::where('recipient', '=', $user_id)->count();

      $num_reserved_leads = DB::table('ads')->join('listing_contracts', 'ads.id', '=', 'listing_contracts.listing_id')->select('ads.*', 'listing_contracts.*')->where('listing_contracts.buyer_id', $user_id)->where('listing_contracts.list_status', '!=', '3')->orderBy('ads.id', 'desc')->get();

      $num_listed_leads = DB::table('ads')->join('listing_contracts', 'ads.id', '=', 'listing_contracts.listing_id')->select('ads.*', 'listing_contracts.*')->where('ads.user_id', $user_id)->where('listing_contracts.list_status', '!=', '3')->orderBy('ads.id', 'desc')->get();

      //dd($notifications_users);
    }

    //start new registration remind me pop up

    $countries  = Country::all();

    if(old('country') == ''){
      $previous_states = State::where('country_id', '231')->get();
      $previous_cities = City::where('state_id', old('state'))->get();
    }else{
      $previous_states = State::where('country_id', old('country'))->get();
      $previous_cities = City::where('state_id', old('state'))->get();
    }

    if($user->user_type == 'admin') {
      $dashboard_template = 'tymbl.dashboard.index';
    }else{
      $dashboard_template = 'tymbl.dashboard.index_user';
    }

    //end new registraton popup
    return view($dashboard_template, compact('approved_ads', 'blocked_ads', 'total_users', 'total_reports', 'total_payments', 'total_payments_amount', 'ten_contact_messages', 'reports', 'countries', 'previous_states', 'previous_cities', 'user_notification_optin', 'comments', 'comment_counts', 'num_reserved_leads', 'num_listed_leads', 'total_users_all', 'notifications_users', 'user_notification_count'));
  }

  public function logout(){
    if (Auth::check()){
      Auth::logout();
    }
    return redirect(route('login'));
  }



  public function AWS3Backup() {

  $title = 'AWS3Backup Area';
 

$url = 'https://s3.' . env('AWS_DEFAULT_REGION') . '.amazonaws.com/' . env('AWS_BUCKET1') . '/';
       $profiles = [];
       $files = Storage::disk('s3')->files('tymbl-profiles');
           foreach ($files as $file) {
               $profiles[] = [
                   'name' => str_replace('tymbl-profiles', '', $file),
                   'src' => $url . $file
               ];
           }
     

       $url = 'https://s3.' . env('AWS_DEFAULT_REGION') . '.amazonaws.com/' . env('AWS_BUCKET2') . '/';
       $photos = [];
       $files = Storage::disk('s3')->files('tymbl_photos');
           foreach ($files as $file) {
               $photos[] = [
                   'name' => str_replace('tymbl_photos/', '', $file),
                   'src' => $url . $file
               ];
           }
     

    return view('tymbl.dashboard.aws3_backup', compact('title','profiles','photos'));



  }




public function uploadPhoto(Request $request)
{
 
    $file = $request->file('image');
 
    $request->validate([
    'file' => 'required|mimes:pdf,jpeg,jpg,png,gif',
    ]);

    $saved = Storage::disk('s3')->put('tymbl-photos', $file,'public');
 
    return back()->with('success', 'All files moved to the AWS3 server ok');
 
}


public function uploadProfile(Request $request)
{
 
    $file = $request->file('image');
 
    $request->validate([
    'file' => 'required|mimes:pdf,jpeg,jpg,png,gif',
    ]);
 
    $saved = Storage::disk('s3')->put('tymbl-profiles', $file,'public');
  
    return back()->with('success', 'All files moved to the AWS3 server ok');
 
 
}





  public function allLeads(){
    $title = 'Leads';
    return view('tymbl.dashboard.all_leads', compact('title'));
  }

  public function leadsData(){
    $ads = Ad::select('id', 'title', 'description', 'status', 'seller_name', 'address', 'country_id', 'state_id', 'city_id', 'zipcode', 'user_id', 'escrow_amount', 'created_at', 'slug')->where('status', '=', '1')->orWhere('status', '=', '4')->orWhere('status', '=', '5')->get();

    return  Datatables::of($ads)

    ->editColumn('title', function($ads){
      $html = '<a href="/listing/'.$ads->id.'/'.$ads->slug.'">'.safe_output($ads->title).'</a>';
      return $html;
    })

    ->editColumn('seller_name', function($ads){
      $user = User::whereId($ads->user_id)->first();
      $html = '<a href="'.route('user_info', $user->id).'">'.safe_output($user->name).'</a>';
      return $html;
    })

    ->editColumn('country_id', function($ads){
      $country = Country::whereId($ads->country_id)->first();
      return $country->country_name;
    })

    ->editColumn('status', function($ads){
      $status = '';
      $status_color = '';
      switch ($ads->status) {
        case '1':
        $status = 'Active';
        $status_color = 'active-status';
        break;
        case '2':
        $status = 'Paused';
        $status_color = 'pending-status';
        break;
        case '3':
        $status = 'Block';
        $status_color = 'block-status';
        break;
        case '4':
        $status = 'Pending for Approval';
        $status_color = 'sale-pending';
        break;
        case '5':
        $status = 'Reserved';
        $status_color = 'sale-approved';
        break;
      }
      $html = '<span class="m-list-search__result-item-icon"><i class="fas fa-circle '.$status_color.'"></i></span>
      <span class="m-list-search__result-item-text">'.$status.'</span>';
      return $html;
    })
    ->removeColumn('user_id')
    ->removeColumn('slug')
    ->addColumn('', function($ads){
      $html = '';
      if($ads->status == '5'){
        $html = '<a href="#" title="view transaction"><i class="fas fa-file-invoice"></i></a>';
      }
      return $html;
    })
    ->make();
  }


  public function leadsBlockedData(){
    $ads = Ad::select('id', 'title', 'description', 'status', 'seller_name', 'address', 'country_id', 'state_id', 'city_id', 'zipcode', 'user_id', 'escrow_amount', 'created_at', 'list-status')->where('status', '=', '3')->orderBy('updated_at', 'desc')->get();

    return  Datatables::of($ads)

    ->editColumn('title', function($ads){
      $html = '<a href="/listing/'.$ads->id.'/'.$ads->slug.'">'.safe_output($ads->title).'</a>';
      return $html;
    })

    ->editColumn('seller_name', function($ads){
      $user = User::whereId($ads->user_id)->first();
      $html = '<a href="'.route('user_info', $user->id).'">'.safe_output($user->name).'</a>';
      return $html;
    })

    ->editColumn('country_id', function($ads){
      $country = Country::whereId($ads->country_id)->first();
      return $country->country_name;
    })

    ->editColumn('status', function($ads){
      $status = '';
      $status_color = '';
      switch ($ads->status) {
        case '1':
        $status = 'Active';
        $status_color = 'active-status';
        break;
        case '2':
        $status = 'Paused';
        $status_color = 'pending-status';
        break;
        case '3':
        $status = 'Block';
        $status_color = 'block-status';
        break;
        case '4':
        $status = 'Pending for Approval';
        $status_color = 'sale-pending';
        break;
        case '5':
        $status = 'Reserved';
        $status_color = 'sale-approved';
        break;
      }
      $html = '<span class="m-list-search__result-item-icon"><i class="fas fa-circle '.$status_color.'"></i></span>
      <span class="m-list-search__result-item-text">'.$status.'</span>';
      return $html;
    })
    ->removeColumn('user_id')
    ->addColumn('', function($ads){
      $html = '';
      if($ads->status == '5'){
        $html = '<a href="#" title="view transaction"><i class="fas fa-file-invoice"></i></a>';
      }
      return $html;
    })
    ->make();
  }

  public function importLeads(){
    $title = 'Import Leads';

    $records = [];
    $path = storage_path('csv_uploads');

    foreach (glob($path.'/*.csv') as $file) {
      $file = new \SplFileObject($file, 'r');
      //$file->seek(PHP_INT_MAX);
      //$records[] = $file->value();
      if($file){
        $file->setFlags(\SplFileObject::READ_CSV);
        foreach ($file as $num=>$row){

          $records[] = $row;

        }
      }
    }

    return view('tymbl.dashboard.import_leads', compact('title', 'records'));
  }

  public function importCsv(){

    request()->validate([
      'csv_file' => 'required|mimes:csv,txt'
    ]);

    //get file from upload
    $path = request()->file('csv_file')->getRealPath();

    $file = file($path);

    //remove header
    $data = array_slice($file, 1);

    $parts = (array_chunk($data, 100));
    $i = 1;
    foreach($parts as $line) {
      $filename = storage_path('csv_uploads/'.date('y-m-d-H-i-s').$i.'.csv');
      file_put_contents($filename, $line);
      $i++;
    }

    return redirect(route('import-leads'))->with('success', 'File upload success. Parsing files...');
  }

  public function importImages(){
    request()->validate([
      'zip_file' => 'required|mimes:zip,rar'
    ]);

    $path = request()->file('zip_file')->getRealPath();
    $file = Storage::putFile('temp_uploads', request()->file('zip_file'));

    $zip = new ZipArchive();
    //$zip->open($path);
    //$zip->extractTo(public_path('uploads').'/'.'images');

    if ($zip->open($path) === true) {
      for($i = 0; $i < $zip->numFiles; $i++) {
        $zip->extractTo(public_path('uploads').'/images', array($zip->getNameIndex($i)));

        try {
          $image = $zip->getNameIndex($i);
          $imageFileName  = public_path('uploads').'/images/'.$image;
          $imageThumbName = public_path('uploads').'/images/thumbs/'.$image;

          $resized = Image::make($imageFileName)->resize(
            640,
            null,
            function ($constraint) {
              $constraint->aspectRatio();
            }
            )->save($imageFileName);

            $resized_thumb  = Image::make($imageFileName)->resize(320, 213)->save($imageThumbName);

          } catch (\Exception $e) {
            return redirect(route('import-leads'))->with('error', $e->getMessage());
          }

          //$zip->extractTo(public_path('uploads').'/'.'images');
        }
        $zip->close();
      }

      return redirect(route('import-leads'))->with('success', 'Images uploaded successfully...');

    }

    public function importSaveAll(Request $request){

      $path = storage_path('csv_uploads');

      $files = glob("$path/*.csv");

      foreach($files as $file) {

        $ads = array();
        if (($handle = fopen($file, "r")) !== FALSE) {
          while (($data = fgetcsv($handle, 4096, ",")) !== FALSE) {

            $post_title = $data[0];
            $description = $data[1];
            $category_id = $data[18];
            $name = $data[2];
            $email = $data[3];
            $phone = $data[4];
            $country = $data[5];
            $state_name = $data[6];
            $city_name = $data[7];
            $zipcode = $data[8];
            $address = $data[9];
            $vid_url = $data[11];
            $feature1 = $data[14];
            $feature2 = $data[15];
            $escrow_amount = $data[12];
            $referral_fee = $data[13];
            $price_range = $data[17];
            $referral_name_info = $data[22];
            $referral_email = $data[23];
            $referral_phone = $data[24];
            $referral_fax = $data[25];
            $referral_address = $data[26];
            $image_name = $data[10];
            $lead_status = $data[16];
            $category_type = $data[19];
            $cat_type_status = $data[20];
            $contract_signed = $data[21];


            $feature1_data = [];
            $feature1_data = array();
            $active = 0;

            $feature1_array = explode(',', $feature1);

            foreach($feature1_array as $n=>$v){
              $d = explode(':', $v);
              $feature1_data[$n] = $d[1];
            }

            $feature2_array = explode(',', $feature2);
            foreach($feature2_array as $k=>$fs2){
              $d2 = explode(':', $fs2);
              if($d2[1] != '0'){
                $feature2_data[] = $d2[0];
              }
            }

            if($country == 'Canada'){
              $ctr = '38';
            }else{
              $ctr = '231';
            }

            $state = State::where('state_name', '=', trim($state_name))->where('country_id', '=', $ctr)->first();
            if(!$state){
              return 'State is not found '.$state_name;
              exit;
            }
            $stid = $state->id;
            $city = City::where('city_name', 'LIKE', trim($city_name))->where('state_id', '=', $stid)->first();
            if(!$city){
              return 'City is not found: '.trim($city_name).', '.trim($state_name);
              exit;
            }

            $user = User::where('email', '=', $email)->first();
            $mycity = ($city) ? $city->id : '';

            if($user){
              $user_id = $user->id;
              $active = '1';
            }else{
              //  $user_name_exp = explode(' ', $data[3]);
              $new_user = [
                'name' => $name,
                //'first_name' => $user_name_exp[0],
                //'last_name'  => $user_name_exp[1],
                'email' => $email,
                'phone' => $phone,
                'sms_activation_code' => ''
              ];

              $new_usr = User::create($new_user);
              $user_id = $new_usr->id;
              $active = '0';
            }

            $category = Category::whereId($category_id)->first();

            $ads = [
              'title' => $post_title,
              'slug' => unique_slug($post_title),
              'description' => $description,
              'sub_category_id' => $category_id,
              'seller_name' => $name,
              'seller_email' => $email,
              'seller_phone' => $phone,
              'country_id'  => $ctr,
              'state_id'  => $stid,
              'city_id' => $mycity,
              'zipcode' => $zipcode,
              'address' => $address,
              'video_url' => $vid_url,
              'escrow_amount' => $escrow_amount,
              'referral_fee' => $referral_fee,
              'feature_1' =>  serialize($feature1_data),
              'feature_2' => serialize($feature2_data),
              'price_range' => $price_range,
              'status' => $lead_status,
              'user_id' => $user_id,
              'category_type' => $category_type,
              'cat_type_status' => $cat_type_status
            ];

            $ad = Ad::create($ads);

            if($ad){
              $refs = [
                'ad_id' => $ad->id,
                'referral_name' => $referral_name_info,
                'referral_contact_email' => $referral_email,
                'referral_contact_phone' => $referral_phone,
                'referral_contact_fax' => $referral_fax,
                'referral_contact_address' => $referral_address,
              ];

              $title_company = ReferralContactInfo::create($refs);
              $image = [
                'user_id' => $user_id,
                'ad_id' => $ad->id,
                'media_name' => $image_name,
                'type'  => 'image',
                'storage' => 'public',
                'ref' => 'ad'
              ];
              $media = Media::create($image);
            }
          }

          fclose($handle);
        } else {
          echo "Could not open file: " . $file;
        }
      }

      //end for
      $this->removeFiles();
      Session::flash('success', 'New leads have been saved successfully');
      return 1;
    }

    public function importReset(){
      $this->removeFiles();
      return back()->with('success', 'All files have been deleted from the server');
    }

    public function removeFiles(){
      $path = storage_path('csv_uploads');
      $file = new Filesystem;
      $file->cleanDirectory($path);
    }

    public function allUserMessages(){
      $title = 'Messages';
      $user = Auth::user();
      $notif = $this->getUserNotifications($user->id, '10');
      $notifications_users = $notif[0];
      $user_notification_count = $notif[1];
      return view('tymbl.dashboard.all_messages', compact('title', 'notifications_users', 'user_notification_count'));
    }

    public function getUserMessagesData(){
      $user = Auth::user();

      $messages = NotificationsUsers::select('id', 'subject', 'created_at', 'user_read')->where('recipient', '=', $user->id)->orderBy('id', 'desc')->get();


      return  Datatables::of($messages)

      ->editColumn('id', function($messages){
        if($messages->user_read == '0'){
          $html = '<i class="far fa-envelope"></i>';
        }else{
          $html = '<i class="far fa-envelope-open"></i>';
        }

        return $html;
      })

      ->editColumn('subject', function($messages){
        $url = '/dashboard/message/'.safe_output($messages->id);

        if($messages->user_read == '0'){
          $html = '<div style="overflow: hidden"><a class="messages-unread" rel="'.$messages->id.'" href="'.$url.'">'.safe_output($messages->subject).'</a></div>';
        }else{
          $html = '<div style="overflow: hidden"><a rel="'.$messages->id.'" href="'.$url.'">'.safe_output($messages->subject).'</a></div>';
        }

        return $html;
      })

      ->make();
    }

    public function getUserNotifications($user, $limit){
      $user_notification = NotificationsUsers::where('recipient', '=', $user)->limit($limit)->orderBy('id', 'desc')->get();
      $notification_count = count($user_notification);
      $data = [$user_notification, $notification_count];
      return $data;
    }

    public function getUserMessage($id){
      $user = Auth::user();
      $title = "Message";
      $message = NotificationsUsers::where('recipient', '=', $user->id)->where('id', '=', $id)->first();

      if($message->user_read == '0'){
        $message->user_read = '1';
        $message->save();
      }

      return view('tymbl.dashboard.user_message_single', compact('title', 'message'));
    }

    public function isFeaturedAd(Request $request){
      $ad = Ad::whereId($request->id)->first();
      $ad->is_featured = $request->checked;
      $ad->save();
      return $ad;
    }

    public function pausedAds(){
      $title = 'Paused Leads';

      $term = request()->get('q');
      $page = request()->get('p');

      if(!isset($term) || $term == ''){
        $ads   = Ad::with('city', 'country', 'state')->whereStatus('2')->orderBy('updated_at', 'desc')->paginate(10);
      }else{
        $ads = $this->searchAds($term, $page, '0');
      }
      $adtype = '2';

      $users = User::where('user_type', '=', 'user')->where('active_status', '=', '1')->get();
      return view('tymbl.dashboard.inactive_ads', compact('title', 'ads', 'users', 'adtype'));
    }

    public function featuredAds(){

      $term = request()->get('q');
      $page = request()->get('p');

      $title = 'Featured Leads';

      if(!isset($term) || $term == ''){
        $ads   = Ad::with('city', 'country', 'state')->whereStatus('1')->where('is_featured', '=', '1')->orderBy('id', 'desc')->paginate(10);
      }else{
        $ads = $this->searchAds($term, $page, '1');
      }

      $adtype = '1';

      $users = User::where('user_type', '=', 'user')->where('active_status', '=', '1')->get();
      return view('tymbl.dashboard.all_ads', compact('title', 'ads', 'users', 'adtype'));
    }

    public function successfulLeads(){
      if(Auth::user()->user_type != 'admin'){
        return redirect('dashboard')->with('error', 'You cannot access this area.');
      }
      $title = 'Reserved Leads';
      return view('tymbl.dashboard.successful_leads', compact('title'));
    }

    public function successLeadsData(){
      $ads = Ad::with('lead_notes')->select('id', 'title', 'seller_name', 'slug')->where('status', '=', '5')->orderBy('updated_at', 'desc')->get();

      return  Datatables::of($ads)

      ->editColumn('id', function($ads){

        $image = Media::where('ad_id', '=', $ads->id)->first();

        if($image && $image->media_name != 'NULL'){


          if(file_exists('uploads/images/thumbs/'.$image->media_name)){
            $img = '<img src="/uploads/images/thumbs/'.$image->media_name.'" class="img-responsive" />';
          }else{
            $img = '<img src="/uploads/images/'.$image->media_name.'" class="img-responsive" />';
          }

        }else{
          if($ads->category_type == "selling"){
            $img = '<img class="img-fluid" src="'.asset("/assets/img/tymbl/house.png").'" class="d-block w-100" />';
          }else{
            $img = '<img class="img-fluid" src="'.asset("/assets/img/tymbl/person.png").'" class="d-block w-100" />';
          }
        }

        $html = '<div>'.$img.'</div>';
        return $html;
      })

      ->editColumn('title', function($ads){

        $ref_info = ReferralContactInfo::where('ad_id', '=', $ads->id)->first();

        $payment = Payment::where('ad_id', '=', $ads->id)->first();
        $contract = ListingContracts::where('listing_id', '=', $ads->id)->first();
        $user = User::where('id', '=', $contract->buyer_id)->first();

        $refname = isset($ref_info->referral_name) ? $ref_info->referral_name : '';
        $refadd = isset($ref_info->referral_contact_address) ? $ref_info->referral_contact_address : '';
        $refemail = isset($ref_info->referral_contact_email) ? $ref_info->referral_contact_email : '';
        $refphone = isset($ref_info->referral_contact_phone) ? $ref_info->referral_contact_phone : '';
        $reserved_to = isset($user->name) ? $user->name : '';

        $html = '<div style="position: absolute; right: 0; margin-right: 20px;"><button id="relist-ad" rel="'.$ads->id.'" role="button" class="bg-primary text-light drc2">REPOST AD</button>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<span id="wait-notice"></span></div>';

        $html .= '<div class="jd-success-data"><div class="drc"><h5><a href="/listing/'.$ads->id.'/'.$ads->slug.'" target="_blank">'.safe_output($ads->title).'</a></h5></div>';
        $html .= '<div class="drc"><span class="dbc-reserved">ID #</span> '.safe_output($ads->id).'</div>';
        $html .= '<div class="drc"><span class="dbc-reserved">Posted By</span> '.safe_output($ads->seller_name).'</div>';
        $html .= '<div class="drc"><span class="dbc-reserved">Payment Method</span> '.$payment->payment_method.'</div>';
        $html .= '<div class="drc"><span class="dbc-reserved">Payment ID</span> #'.$payment->local_transaction_id.'</div>';
        $html .= '<div class="drc"><span class="dbc-reserved">Contract ID</span> '.$contract->user_contract_id.'</div>';
        $html .= '<div class="drc"><span class="dbc-reserved">Date Signed</span> '.$contract->updated_at.'</div>';
        $html .= '<div class="drc"><span class="dbc-reserved">Reserved To</span> '.$reserved_to.'</div>';
        $html .= '<hr style="width: 80%; float:left;"><div class="ref-info"><span class="dbc-reserved">&nbsp;</span><h5>Referral Info</h5>';
        $html .= '<div class="drc"><span class="dbc-reserved">Name</span> '.$refname.'</div>';
        $html .= '<div class="drc"><span class="dbc-reserved">Address</span> '.$refadd.'</div>';
        $html .= '<div class="drc"><span class="dbc-reserved">Email</span> '.$refemail.'</div>';
        $html .= '<div class="drc"><span class="dbc-reserved">Phone</span> '.$refphone.'</div>';
        $html .= '</div>';

        //lead notes
        $html .= '<div class="notes">';
        $html .= '<div class="m-accordion m-accordion--default" id="m_accordion_1" role="tablist">';
        $html .= '<div class="m-accordion__item" style="padding: 1rem;">';
        $html .= '<div class="m-accordion__item-head collapsed" role="tab" data-toggle="collapse" href="#note-accordion-'.$ads->id.'" aria-expanded="false">';
        $html .= '<span class="m-accordion__item-icon"><i
        class="fa flaticon-speech-bubble-1"></i></span>
        <span class="m-accordion__item-title">Notes</span>
        <span class="m-accordion__item-mode"></span></div>';
        $html .= '<div class="m-accordion__item-body collapse"
        id="note-accordion-'.$ads->id.'" role="tabpanel"
        aria-labelledby="m_accordion_1_item_1_head"
        data-parent="#m_accordion_1">
        <div class="m-accordion__item-content">';
        $html .= '<form method="POST" action="/lead-note-add" accept-charset="UTF-8" class="m-form">
        <input type="hidden" name="ad_id"
        value="'.$ads->id.'">
        <div class="form-group">
        <div class="form-group">
        <textarea class="form-control" rows="2" name="lead_note_body" cols="50" style="width: 100%;"></textarea>
        </div>
        </div>
        <input class="btn btn-primary btn-sm float-right" type="submit" value="Add Note">
        </form>';
        $html .= '<div class="clearfix"></div>';
        $html .= '<div class="all-notes" style="padding-top: 2rem;">';

        if(count($ads->lead_notes) == 0){
          $html .= '<p>No note added</p>';
        }


        foreach($ads->lead_notes as $note){
          $note_body = $note->lead_note_body;
          $note_date = $note->created_at->format('d M Y');



          $html .='<div class="single-note">
          <div class="m-separator m-separator--space m-separator--dashed"></div>
          <div class="note-body"> <p>'.$note_body.'</p>';
          $html .='<div class="single-note-foot row">
          <div class="col-sm-6">
          <small>By:
          <strong>'.auth()->user()->name.'</strong> &nbsp;&nbsp;|&nbsp;&nbsp; '.$note_date.'
          </small></div>';
          $html .= '<div class="col-sm-6">
          <form method="POST" action="/lead-note-delete" class="m-form">
          <input type="hidden" name="id" value="'.$note->id.'">

          <button class="m-portlet__nav-link btn m-btn m-btn--hover-danger m-btn--icon m-btn--icon-only m-btn--pill float-right" type="submit" value="Delete" data-toggle="m-tooltip" title="Delete Note" data-original-title="Delete Note"> <i class="flaticon-delete" style="font-size: 16px;"></i></button>
          </form>
          </div>
          </div>
          </div>';}



          return $html;
        })

        ->make();
      }



      public function searchAds($term, $page, $featured){
        $ads   = Ad::with('city', 'country', 'state')->whereStatus($page)->where('title', 'LIKE', "%$term%")->where('is_featured', '=', $featured)->orderBy('updated_at', 'desc')->paginate(20);
        return $ads;
      }

      public function transactionsSettingsBuyingCheck(){
        $reserved_contract = ListingContracts::where('listing_id', request()->id)->first();
        $contract = LaravelEsignatureWrapper::getContract($reserved_contract->contract_id);
        $status = $contract['data']['status'];

        if($status == 'signed'){
          $ad = Ad::where('id', request()->id)->update(['status' => '5']);
          $reserved = ListingContracts::where('listing_id', request()->id)->update(['list_status' => '1']);

          if($ad){
            $referral_transaction = Payment::where('ad_id', '=', $reserved_contract->listing_id)->first();

            if($referral_transaction){
            //$this->sendReferralNotification(request()->id);

            $broker = Broker::where('user_id', '=', $reserved_contract->buyer_id)->first();
            $referral_info = ReferralContactInfo::where('ad_id', '=', request()->id)->first();

            $contract = ListingContracts::where('listing_id', request()->id)->first();
            $contract_id = $contract->contract_id;

            $url = LaravelEsignatureWrapper::getContract($contract_id);
            $user = User::where('id', '=', $reserved_contract->buyer_id)->first();
            $transaction = Payment::where('ad_id', '=', request()->id)->first();
            $transaction_id = $transaction->local_transaction_id;
            //$contract_url = $url['data']['signers'][1]['embedded_url'];

            //$this->dispatch(new SendContractToBroker($broker, $referral_info, $user, $contract_id, $transaction_id));
            SendContractToBroker::dispatch($broker, $referral_info, $user, $contract_id, $transaction_id)->delay(1);

            return redirect()->route('reserved-leads-success', ['id' => request()->id]);
        }


          }

          //return redirect()->route('reserved-leads');

        }else{
          return redirect()->route('dashboard')->with('error', 'You decline the contract');
        }
      }

      public function reservedLeadSuccess(){
        $title = 'Reserved Lead Success';
        return view('tymbl.lead_reserved_sucess', compact('title'));
      }



      public function transactionsSettingsBuying(){

        $user = Auth::user()->id;

        $lists = DB::table('ads')->join('listing_contracts', 'ads.id', '=', 'listing_contracts.listing_id')->leftJoin('media', 'media.ad_id', '=', 'ads.id')->select('ads.*', 'listing_contracts.*', 'media.*')->where('listing_contracts.buyer_id', $user)->where('listing_contracts.list_status', '!=', '3')->where('listing_contracts.list_status', '!=', '0')->orderBy('ads.updated_at', 'desc')->paginate('10');

        foreach($lists as $li){
          $listid[] = $li->listing_id;
          $userid[] = $li->user_id;
        }

        if(isset($listid)){
          $referral = ReferralContactInfo::whereIn('ad_id', $listid)->orderBy('id', 'desc')->get();
          //$transaction_reports = TransactionReports::whereIn('ad_id', $listid)->orderBy('id', 'desc')->get();
          $ad_tci = TitleCompanyInfoLTB::whereIn('ad_id', $listid)->orderBy('id', 'desc')->get();
        }

        //$notifications_users = NotificationsUsers::where('recipient', '=', $user)->where('user_read', '=', '0')->get();
        //$user_notification_count = count($notifications_users);

        $title = trans('app.transactions');
        $header = "Reserved Leads";
        $no_button = false;
        $selltab = '0';

        return view('tymbl.dashboard.transaction', compact('title', 'lists', 'header', 'referral', 'no_button', 'ad_tci', 'user', 'selltab'));
      }

      public function transactionsSettingsSelling(){
        $user = Auth::user()->id;

        $lists = DB::table('ads')->join('listing_contracts', 'ads.id', '=', 'listing_contracts.listing_id')->join('media', 'media.ad_id', '=', 'ads.id')->select('ads.*', 'listing_contracts.*', 'media.*')->where('ads.user_id', $user)->where('ads.status', '!=', '0')->orderBy('ads.id', 'desc')->paginate('10');

        foreach($lists as $li){
          $listid[] = $li->listing_id;
          $userid[] = $li->user_id;
        }

        if(isset($listid)){
          $referral = ReferralContactInfo::whereIn('ad_id', $listid)->orderBy('id', 'desc')->get();
          $transaction_reports = TransactionReports::whereIn('ad_id', $listid)->orderBy('id', 'desc')->get();
        }

        $no_button = true;
        $title = trans('app.transactions');
        $header = "Lead Status";
        $selltab = '1';

        //$notifications_users = NotificationsUsers::where('recipient', '=', $user)->where('user_read', '=', '0')->get();
        //$user_notification_count = count($notifications_users);

        return view('tymbl.dashboard.transaction', compact('title', 'lists', 'header', 'referral', 'no_button', 'transaction_reports', 'user', 'selltab'));
      }

      public function relistAd(Request $request){
        $ad = Ad::whereId($request->id)->first();

        if(Auth::user()->user_type != 'admin'){
          return '0';
        }

        if($ad){
          $ad->status = '1';
          $ad->save();

          $listing_contract = ListingContracts::where('listing_id', '=', $request->id)->first();

          $dt = Carbon::parse($listing_contract->updated_at);
          $date_diff = $dt->diffInDays(Carbon::now());

          if($listing_contract){
            $listing_contract->list_status = '3';
            $listing_contract->save();
            $this->sendRelistedEmail($ad->id, $date_diff);
          }
        }

        return '1';
      }

      public function sendReferralNotification(Request $request){
        $id = $request->id;
        $contract = ListingContracts::where('listing_id', '=', $id)->first();
        $user = User::whereId($contract->buyer_id)->first();
        $ad = Ad::whereId($id)->first();
        $transaction = Payment::where('ad_id', '=', $id)->first();
        $email = array($user->email);
        $amount = $transaction->currency.$transaction->total;

        $email_template = $transaction->payment_method == 'free' ? 'emails.referral_notice' : 'emails.referral_notice_reservation_fee';

        Mail::send($email_template, ['name' => $user->name, 'contract_id' => $contract->contract_id, 'transaction_id' => $transaction->local_transaction_id, 'id' => $ad->id, 'title' => $ad->title, 'slug' => $ad->slug, 'amount' => $amount, 'date_reserved' => $contract->updated_at], function ($message) use ($email, $ad)
        {
          $message->from('info@tymbl.com','Tymbl Team');
          $message->to($email);
          $message->subject('Referral Agreement for Ad#'.$ad->id.' has been signed!');
        });
      }

      public function sendRelistedEmail($id, $date_diff){

        $contract = ListingContracts::where('listing_id', '=', $id)->first();
        $ad = Ad::where('id', '=', $contract->listing_id)->first();

        $user = User::whereId($contract->buyer_id)->first();

        $reason = $date_diff >= 2 ? 'Lack of contract' : 'Inactivity. No contact with the prospect was made within 24 hours since the lead was reserved.';

        $email = array($user->email);
        Mail::send('emails.relist_email', ['name' => $user->name, 'date_reserved' => $contract->updated_at, 'contract_id' => $contract->contract_id, 'id' => $ad->id, 'title' => $ad->title, 'slug' => $ad->slug, 'reason' => $reason], function ($message) use ($email, $contract)
        {
          $message->from('info@tymbl.com','Tymbl Team');
          $message->to($email);
          $message->cc('support@tymbl.com');
          $message->subject('Referral Agreement #'.$contract->contract_id.' Termination notice');
        });
      }

    }
