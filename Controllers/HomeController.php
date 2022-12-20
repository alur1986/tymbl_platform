<?php

namespace App\Http\Controllers;

use App\Ad;
use App\Category;
use App\Contact_query;
use App\Country;
use App\State;
use App\City;
use App\Post;
use App\Slider;
use App\User;
use Illuminate\Http\Request;
use App\Http\Requests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Yajra\Datatables\Datatables;
use Torann\GeoIP\Facades\GeoIP;
use App\Media;
use Illuminate\Support\Facades\Mail;
use App\Jobs\SendNewRegistrationEmail;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Pagination\Paginator;
use Illuminate\Pagination\LengthAwarePaginator;
use App\NotificationsUsers;
use Zillow\ZillowClient;
use App\HomeWorth;
use App\UsZip;
use App\CaZip;
use App\ReferralContactInfo;
use Analytics;
use Spatie\Analytics\AnalyticsClientFactory;





class HomeController extends Controller
{

  public function index(){
    
 // $client = AnalyticsClientFactory::createForConfig(config('analytics'));
  //$ga  = new Analytics($client, config('analytics.view_id'));
  //$ga_data_visitors = $ga->fetchTotalVisitorsAndPageViews(Period::days(7));
  //$ga_data_browsers = $ga->fetchTopBrowsers(Period::days(7), 20);
  //$ga_data_referrers =  $ga->fetchTopReferrers(Period::days(7), 20);
  

    $loc = $this->getClientLocation();
    $country = $loc['country'];

    $allowed_countries = ['United States', 'Canada'];

    if(in_array($country, $allowed_countries)){
      $ctry = Country::where('country_name', $country)->first();
      $states = State::where('country_id', $ctry->id)->get();
    }

    $my_state = State::where('state_name', $loc['state_name'])->where('country_id', $ctry->id)->first();
    $conditions = ['state_id' => $my_state->id];
    $my_city = City::where($conditions)->where('city_name', 'REGEXP', $loc['city'])->first();

    if($my_city){
      $my_loc_ads = Ad::where('city_id', $my_city->id)->where('status', '=', '1')->with('city', 'state', 'country', 'category')->orderBy('ads.id', 'desc')->limit(10)->get();
    }

    $featured_ads = Ad::where('is_featured', '1')->with('city', 'state', 'country', 'category')->orderBy('ads.id', 'desc')->limit(10)->get();

    $user = Auth::user();

    if($user && $user->user_type == 'admin'){
      $recent_ads = Ad::where('status', '=', '1')->where('cat_type_status', '=', 'qualified')->with('city', 'state', 'country', 'category')->orderBy('ads.id', 'desc')->limit(10)->paginate(8);

      $recent_ads_unqualified = Ad::where('status', '=', '1')->where('cat_type_status', '=', 'unqualified')->with('city', 'state', 'country', 'category')->orderBy('ads.id', 'desc')->limit(10)->paginate(8);

      $reserved_ads = Ad::where('status', '=', '5')->with('city', 'state', 'country', 'category')->orderBy('ads.id', 'desc')->limit(10)->paginate(8);

    }else{
      $recent_ads = Ad::where('status', '=', '1')->where('cat_type_status', '=', 'qualified')->where('country_id', '=', $ctry->id)->with('city', 'state', 'country', 'category')->orderBy('ads.id', 'desc')->limit(10)->paginate(8);

      $recent_ads_unqualified = Ad::where('status', '=', '1')->where('cat_type_status', '=', 'unqualified')->where('country_id', '=', $ctry->id)->with('city', 'state', 'country', 'category')->orderBy('ads.id', 'desc')->limit(10)->paginate(8);

      $reserved_ads = Ad::where('status', '=', '5')->with('city', 'state', 'country', 'category')->orderBy('ads.id', 'desc')->limit(10)->paginate(8);
    }

    $listing_categories = Category::all();

    $total_ads_count = Ad::active()->count();
    $user_count = User::count();
    $zip_code = $loc['zip'];

    return view('tymbl.home', compact('featured_ads', 'total_ads_count', 'user_count', 'states', 'listing_categories', 'recent_ads', 'recent_ads_unqualified', 'reserved_ads', 'my_loc_ads', 'zip_code'));
  }

  public function contactUs(){
    $title = trans('app.contact_us');
    return view('tymbl.contact_us', compact('title'));
  }

  public function contactUsPost(Request $request){
    $rules = [
      'name'  => 'required',
      'email'  => 'required|email',
      'message'  => 'required',
    ];
    $this->validate($request, $rules);
    Contact_query::create(array_only($request->withInput(), ['name', 'email', 'message']));
    return redirect()->back()->with('success', trans('app.your_message_has_been_sent'));
  }

  public function contactMessages(){
    $title = trans('app.contact_messages');
    $user = Auth::user();
    //$notifications_users = $this->getUserNotifications($user);
    //$user_notification_count = count($notifications_users);
    return view('tymbl.dashboard.contact_messages', compact('title'));
  }

  public function contactMessagesData(){
    $contact_messages = Contact_query::select('name', 'email', 'message','created_at')->orderBy('id', 'desc')->get();
    return  Datatables::of($contact_messages)
    ->editColumn('created_at',function($contact_message){
      return $contact_message->created_at_datetime();
    })
    ->make();
  }

  /**
  * Switch Language
  */
  public function switchLang($lang){
    session(['lang'=>$lang]);
    return back();
  }

  /**
  * Reset Database
  */
  public function resetDatabase(){
    $database_location = base_path("database-backup/classified.sql");
    // Temporary variable, used to store current query
    $templine = '';
    // Read in entire file
    $lines = file($database_location);
    // Loop through each line
    foreach ($lines as $line) {
      // Skip it if it's a comment
      if (substr($line, 0, 2) == '--' || $line == '')
      continue;
      // Add this line to the current segment
      $templine .= $line;
      // If it has a semicolon at the end, it's the end of the query
      if (substr(trim($line), -1, 1) == ';')
      {
        // Perform the query
        DB::statement($templine);
        // Reset temp variable to empty
        $templine = '';
      }
    }
    $now_time = date("Y-m-d H:m:s");
    DB::table('ads')->update(['created_at' => $now_time, 'updated_at' => $now_time]);
  }

  public function sendContact(Request $request){

    $rules = [
      'name'       => 'required|Regex:/^[\D]+$/i|max:100',
      'email'      => 'required|email|max:255',
      'message'    => 'required',

    ];
    $this->validate($request, $rules);
    $today = date('Y-m-d h:i:s');

    $to = array('tymblapp@gmail.com');

    $data = array('to' => 'tymblapp@gmail.com', 'from' => $request->email, 'name' => $request->name, 'message', $request->message);

    Mail::send('landing.contact-us', ['email' => $request->email, 'name' => $request->name, 'bodymessage' => $request->message, 'date' => $today], function ($message) use ($request, $to)
    {
      $message->from($request->email, 'Tymbl Contact Us Page');
      $message->to($request->to, "New Message from Contact Us Page");
      $message->subject("New Message from Contact Us Page");
    }
  );

  return redirect()->back()->with(['success'=> 'Thank you for contacting us. We will get in touch with you as soon as possible.', 'contactSuccess' => true]);
}

public function get_client_ip() {
  $ipaddress = '';
  if (getenv('HTTP_CLIENT_IP'))
  $ipaddress = getenv('HTTP_CLIENT_IP');
  else if(getenv('HTTP_X_FORWARDED_FOR'))
  $ipaddress = getenv('HTTP_X_FORWARDED_FOR');
  else if(getenv('HTTP_X_FORWARDED'))
  $ipaddress = getenv('HTTP_X_FORWARDED');
  else if(getenv('HTTP_FORWARDED_FOR'))
  $ipaddress = getenv('HTTP_FORWARDED_FOR');
  else if(getenv('HTTP_FORWARDED'))
  $ipaddress = getenv('HTTP_FORWARDED');
  else if(getenv('REMOTE_ADDR'))
  $ipaddress = getenv('REMOTE_ADDR');
  else

  if(Session::has('testcaller')){
    $ipaddress =  '174.22.204.238';
  }else{
    $ipaddress = $_SERVER['REMOTE_ADDR'];
  }

  return $ipaddress;
}

public function getClientLocation(){
  $user_loc = '';
  $country = '';
  $loc = array();
  $whitelist = array('127.0.0.1', '::1');
  $client_ip = $this->get_client_ip();

  //for Unit Testing only
  if(Session::has('testcaller')){
    $user_loc = geoip($ip = '64.235.246.0');
    $country = $user_loc->country;
  }else{
    if(in_array($client_ip, $whitelist)){
      //us
      $user_loc = geoip($ip = '98.124.199.40');
      //Canada
      //$user_loc = geoip($ip = '192.206.151.131');
      $country = $user_loc->country;
    }else{
      $valid_countries = ['United States', 'Canada'];
      $user_loc = geoip($ip = $client_ip);
      //dd($user_loc);
      if(!in_array($user_loc->country, $valid_countries)){
        $user_loc = geoip($ip = '98.124.199.40');
        $country = $user_loc->country;
      }else{
        $country = $user_loc->country;
      }
    }
  }

  //dd($user_loc);
  //Log::info($user_loc->city.' '.$user_loc->state);

  $zip = $user_loc->postal_code;

  $loc = array('country' => $country, 'zip' => $zip, 'state_name' => $user_loc->state_name, 'city'=> $user_loc->city, 'state'=>$user_loc->state);
  return $loc;
}

//homeWorth
public function homeWorth(){
  $title = 'Home Worth';
  $loc = $this->getClientLocation();
  $gapid = config('app.google')['google_api'];
  return view('tymbl.lead_gen.home_worth', compact('title', 'loc', 'gapid'));
}

public function loadNoZpid(){
  $title = 'Home Worth';
  $loc = $this->getClientLocation();
  $gapid = config('app.google')['google_api'];
  return view('tymbl.lead_gen.result_valuation', compact('title', 'loc', 'gapid'));
}

public function homeWorthFaq(){
  $title = 'Home Worth';
  return view('tymbl.lead_gen.faq', compact('title'));
}

public function homeWorthAbout(){
  $title = 'Home Worth';
  //$client = AnalyticsClientFactory::createForConfig(config('analytics'));
  //$ga  = new Analytics($client, config('analytics.view_id'));
  //$ga_data_visitors = $ga->fetchTotalVisitorsAndPageViews(Period::days(7));
  //$ga_data_browsers = $ga->fetchTopBrowsers(Period::days(7), 20);
  //$ga_data_referrers =  $ga->fetchTopReferrers(Period::days(7), 20);

  return view('tymbl.lead_gen.about', compact('title'));
}

public function homeWorthContact(){
  $title = 'Home Worth';

  return view('tymbl.lead_gen.contact', compact('title'));
}

public function homeWorthFindHouse(Request $request){

  if(strlen($request->address) <= 6){
    return redirect(route('homeworth'))->withInput()->with('error', 'Property not found');
    exit;
  }

  $title = 'Result';
  $zwsid = config('app.zillow')['zwsid'];
  $gapid = config('app.google')['google_api'];
  $client = new ZillowClient($zwsid);
  $citystatezip = $request->citystatezip;

  $country_id = $request->country == 'United States' ? '231' : '38';
  $country_string = $request->country == 'United States' ? 'USA' : 'Canada';

  $search_address = str_replace(',', '', trim(str_replace(trim($country_string), '', $request->address)));
  $host_url = "www.exapmle.com?26sf213132aasdf1312sdf31";
  $part_url = strrpos($host_url, '?');
  $result_address = substr($host_url, 0, $part_url);

  $home_find = $this->getHomeLocByString($search_address, $country_id, $request);

  if($home_find == '0' || !$home_find){
    return redirect(route('homeworth'))->withInput()->with('error', 'Property not found');
    exit;
  }

  $clean_address = trim($home_find['address']);
  $citystatezip = trim($home_find['loc']);
  $city_id = $home_find['city_id'];
  $state_id = $home_find['state_id'];
  $zip = $home_find['zip'];
  $city_name = isset($city_id->city_name) ? $city_id->city_name : '';


  $ad_title = 'Prospect requesting home valuation on '.$clean_address.' in '.$city_name.' '.$state_id->state_name;
  $response = $client->GetDeepSearchResults(['address' => $clean_address, 'citystatezip' => $citystatezip]);
  $cisict = $city_id->id.'|'.$state_id->id.'|'.$country_id.'|'.$zip;

  if(!isset($response['results']['result']['zpid'])){
    //echo 'here no property';

    return redirect(route('valuation', ['cisict' => $cisict]))->withInput()->with('message', 'Message: Property not found');
    exit;
  }

  $protected_details = 'yes';

  if(isset($response['message']) && $response['message']['code'] == '508'){
    return redirect(route('valuation', ['cisict' => $cisict]))->withInput()->with('message', 'Message: Property not found');
    exit;
  }

  //exit;
  //$updated_property_details = $client->GetUpdatedPropertyDetails(['zpid' => $response['results']['result']['zpid']]);
  $updated_property_details =  simplexml_load_file('http://www.zillow.com/webservice/GetUpdatedPropertyDetails.htm?zws-id='.$zwsid.'&zpid='.$response['results']['result']['zpid']);

  if($updated_property_details->message->code != '501'){
    if(isset($updated_property_details->images->image->url)){
      $property_image = $updated_property_details->images->image->url;
      $protected_details = 'no';
    }
  }

  if(!isset($response['message'])){
    if(count($response) > 1){
      $zestimate = $response['results']['result'][0]['zestimate']['amount'];
      $lat = $response['results']['result'][0]['address']['latitude'];
      $lon = $response['results']['result'][0]['address']['longitude'];
      $street = $response['results']['result'][0]['address']['street'];
      $link = $response['results']['result'][0]['links']['homedetails'];
     // $client = AnalyticsClientFactory::createForConfig(config('analytics'));
     // $ga  = new Analytics($client, config('analytics.view_id'));
     // $ga_data_visitors = $ga->fetchTotalVisitorsAndPageViews(Period::days(7));
     // $ga_data_browsers = $ga->fetchTopBrowsers(Period::days(7), 20);
     // $ga_data_referrers =  $ga->fetchTopReferrers(Period::days(7),  20);

    }else {
       
     // $client = AnalyticsClientFactory::createForConfig(config('analytics'));
     // $ga  = new Analytics($client, config('analytics.view_id'));
      //$ga_data_visitors = $ga->fetchTotalVisitorsAndPageViews(Period::days(7));
      //$ga_data_browsers = $ga->fetchTopBrowsers(Period::days(7), 20);
     // $ga_data_referrers =  $ga->fetchTopReferrers(Period::days(7),  20);  
      $zestimate = $response['results']['result']['zestimate']['amount'];
      $lat = $response['results']['result']['address']['latitude'];
      $lon = $response['results']['result']['address']['longitude'];
      $street = $response['results']['result']['address']['street'];
      $link = $response['results']['result']['links']['homedetails'];

    }

    if(is_array($zestimate)){
      $zestimate = 'TBD';
    }else{
      $zestimate = number_format($zestimate, 2);
    }

    return view('tymbl.lead_gen.result', compact('title', 'zestimate', 'lat', 'lon', 'street', 'gapid', 'protected_details', 'property_image', 'link', 'ad_title', 'country_id', 'state_id', 'city_id', 'zip','ga_data_visitors','ga_data_browsers','ga_data_referrers'));
  }else{
    return redirect(route('homeworth'))->withInput()->with('error', 'Property not found');
  }
}


public function getHomeLocByString($address, $country_id, $request){

  $detected_zip_code = preg_match_all("/\b[0-9]{5}(?:-[0-9]{4})?\b/", $address, $matched_zip);
  $detected_state_code = preg_match_all("/\b[A-Z]{2}\b/", $address, $matched_state);

  if(count($matched_zip[0]) > 0 && count($matched_state[0]) > 0){

    for($i=0; $i<count($matched_state[0]); $i++){
      if($country_id == '231'){
        $current_country = 'United States';
        $loc = UsZip::whereIn('zip', $matched_zip[0])->where('state_code', '=', $matched_state[0][$i])->first();
      }else{
        $current_country = 'Canada';
        $loc = CaZip::whereIn('zip', $matched_zip[0])->first();
      }
    }

    if($loc){

      $city_id = City::where('city_name', '=', $loc->city)->first();
      $state_id = State::where('state_name', '=', $loc->state)->first();
      $zip = $loc->zip;

      $patterns = [];
      $replacements = [];
      $patterns[0] = '/'.$loc->city.'/';
      $patterns[1] = '/'.$loc->state.'/';
      $patterns[2] = '/'.$loc->state_code.'/';
      $patterns[3] = '/'.$loc->zip.'/';
      $patterns[4] = '/'.$current_country.'/';
      $replacements[0] = '';
      $replacements[1] = '';
      $replacements[2] = '';
      $replacements[3] = '';
      $replacements[4] = '';

      $clean_address = preg_replace($patterns, $replacements, $address);
      $loc = $loc->city.', '.$loc->state_code.' '.$loc->zip;
      $return_add = ['address' => trim($clean_address), 'loc' => $loc, 'city_id' => $city_id, 'state_id' => $state_id, 'zip' => $zip];

      return $return_add;
    }else{
      if($country_id == '231'){
        $current_country = 'United States';
        $loc = UsZip::where('zip', $request->zip)->where('state_code', '=', $request->state)->first();
      }else{
        $current_country = 'Canada';
        $loc = CaZip::where('zip', $request->zip)->where('state_code', '=', $request->state)->first();
      }

      if($loc){
        $city_id = City::where('city_name', '=', $loc->city)->first();
        $state_id = State::where('state_name', '=', $loc->state)->first();
        $zip = $loc->zip;

        $patterns = [];
        $replacements = [];
        $patterns[0] = '/'.$loc->city.'/';
        $patterns[1] = '/'.$loc->state.'/';
        $patterns[2] = '/'.$loc->state_code.'/';
        $patterns[3] = '/'.$loc->zip.'/';
        $patterns[3] = '/'.$current_country.'/';
        $replacements[0] = '';
        $replacements[1] = '';
        $replacements[2] = '';
        $replacements[3] = '';
        $replacements[4] = '';

        $clean_address = preg_replace($patterns, $replacements, $address);
        $loc = $loc->city.', '.$loc->state_code.' '.$loc->zip;

        $return_add = ['address' => trim($clean_address), 'loc' => $loc, 'city_id' => $city_id, 'state_id' => $state_id, 'zip' => $zip];

        return $return_add;
      }else{
        return '0';
      }
    }
  }else{

    if(count($matched_zip[0]) > 0 && count($matched_state[0]) <= 0){

      if($country_id == '231'){
        $loc = UsZip::where('zip', $matched_zip[0])->first();
      }else{
        $loc = CaZip::where('zip', $matched_zip[0])->first();
      }
    }elseif(count($matched_zip[0]) <= 0 && count($matched_state[0]) > 0){

      $gapid = config('app.google')['google_api'];
      //$this->getZipCodeByAddess($address, $gapid);

      if($country_id == '231'){
        $loc = UsZip::where('state_code', '=', $request->state)->first();
      }else{
        $loc = CaZip::where('zip', $request->zip)->first();
      }
    }else{

      if($country_id == '231'){
        $loc = UsZip::where('zip', $request->zip)->where('state_code', '=', $request->state)->first();
      }else{
        $loc = CaZip::where('zip', $request->zip)->first();
      }
    }

    //dd($loc);

    if($loc){
      $city_id = City::where('city_name', '=', $loc->city)->first();
      $state_id = State::where('state_name', '=', $loc->state)->first();
      $zip = $loc->zip;

      $patterns = [];
      $replacements = [];
      $patterns[0] = '/'.$loc->city.'/';
      $patterns[1] = '/'.$loc->state.'/';
      $patterns[2] = '/'.$loc->state_code.'/';
      $patterns[3] = '/'.$loc->zip.'/';
      $replacements[0] = '';
      $replacements[1] = '';
      $replacements[2] = '';
      $replacements[3] = '';

      $clean_address = preg_replace($patterns, $replacements, $address);
      $loc = $loc->city.', '.$loc->state_code.' '.$loc->zip;

      $return_add = ['address' => trim($clean_address), 'loc' => $loc, 'city_id' => $city_id, 'state_id' => $state_id, 'zip' => $zip];

      return $return_add;

    }else{
      return '0';
    }
  }
}

public function getZipCodeByAddess($addres, $gapid){
  $geocodeFromAddr = file_get_contents('https://maps.googleapis.com/maps/api/geocode/json?address='.$addres.'&sensor=true_or_false&key='.$gapid);
  $output1 = json_decode($geocodeFromAddr);
  dd($output1);
}

public function homeWorthSubmitHouse(Request $request){

  //$stitle = '#3515 Village Oaks Dr, Humble, TX 77339';
  //$dtitle =  preg_replace('#([\#\+]{0,3}[0-9])([a-zA-Z])*#', '', $request->title);
  //echo $dtitle;
  //dd($request);
  //exit;

  if (\App::environment(['local'])) {
    $seller_name = 'John Tully';
    $seller_email = 'natoseller75@gmail.com';
    $seller_id = '3';
  }else{
    $seller_name = 'Timour Khan';
    $seller_email = 'timourkh@gmail.com';
    $seller_id = '2';
  }

  if(!isset($request->title)){
    $city = City::whereId($request->city_id)->first();
    $state = State::whereId($request->state_id)->first();
    $ad_title = 'Prospect requesting home valuation in '.$city->city_name.' '.$state->state_name;
    $slug = unique_slug($city->city_name.' '.$state->state_name);
  }else{
    $ad_title = preg_replace('#([\#\+]{0,3}[0-9])([a-zA-Z])*#', '', $request->title);
    $slug = unique_slug($request->title);
  }

  if(isset($request->referral_contact_address)){
    $addr = $request->referral_contact_address;
  }else{
    $addr = '';
  }

  if(isset($request->zip)){
    $zip = $request->zip;
  }else{
    $zip = $request->zip_id;
  }

  $data = [
    'title'         => $ad_title,
    'description'   => '',
    'escrow_amount' => $request->price,
    'country_id'   => $request->country_id,
    'state_id'     => $request->state_id,
    'city_id'      => $request->city_id,
    'address'      => $addr,
    'video_url'    => '',
    'feature_1'    => '',
    'feature_2'    => '',
    'category_id'     => '0',
    'sub_category_id' => '11',
    'category_type'  => 'other',
    'zipcode'      => $zip,
    'price_range' => '',
    'cat_type_status' => 'unqualified',
    'referral_fee'  => '0.20',
    'slug' => $slug,
    'status' => '1',
    'escrow_amount' => '0.00',
    'user_id' => $seller_id,
    'seller_name' => $seller_name ,
    'seller_email' => $seller_email,
  ];

  $ad = Ad::create($data);

  if($ad){

    $data2 = [
      'referral_name' => $request->first_name.' '.$request->last_name,
      'referral_contact_phone' => $request->phone,
      'referral_contact_email' => $request->email,
      'referral_contact_address' => $addr,
      'ad_id' => $ad->id
    ];

    $referral_contact = ReferralContactInfo::create($data2);

    $image_name = '';
    $media = Media::create(['user_id' => $seller_id, 'ad_id' => $ad->id, 'media_name' => $image_name, 'type' => 'image', 'storage' => get_option('default_storage'), 'ref' => 'ad']);
    //dd($media);
    if($media){
      //echo 'yes';
      return redirect('homeworth')->with('success', 'Thank you! We will be in touch with you shortly!');
    }
    //return redirect()->back()->with('success', 'Thank you! Will be in touch shortly! ');
  }
}

public function homeWorthSubmitHouseInquiry(Request $request){


  $data = [
    'first_name' => $request->first_name,
    'last_name' => $request->last_name,
    'email' => $request->email,
    'phone' => $request->phone,
    'address' => $request->address
  ];

  $save_homeworth = Homeworth::create($data);

  $body = $request->message;
  $to = array('support@tymbl.com');
  $today = date('Y-m-d h:i:s');

  Mail::send('emails.contact-us-homeworth', ['email' => $request->email, 'name' => $request->first_name.' '.$request->last_name, 'bodymessage' => $body, 'date' => $today], function ($message) use ($request, $to)
  {
    $message->from('info@tymbl.com', 'Tymbl');
    $message->to($to, "New Homeworth Submission");
    $message->subject("New Homeworth Submission");
  }
);

return redirect()->back()->with('success', 'Thank you! We will be in touch with you shortly!');
}

public function loadLocation(Request $request){
  $country_id = $request->country == 'United States' ? '231' : '38';
  $old_state = '';
  $current_state = '';
  $zips = '';

  $states = State::where('country_id', '=', $country_id)->get();

  if($country_id == '231'){
    $old_state = UsZip::where('state_code', '=', $request->state)->first();
  }else{
    $old_state = CaZip::where('state_code', '=', $request->state)->first();
  }

  $current_state = State::where('state_name', '=', $old_state->state)->first();
  $current_city = City::where('city_name', '=', $request->city)->first();
  $current_zip = $request->zip;

  $cities = City::where('state_id', $current_state->id)->get();

  if($country_id == '231'){
    $zips = UsZip::where('city', '=', $current_city->city_name)->where('state_code', '=', $request->state)->get();
    $state_code = UsZip::where('state', '=', $current_state->state_name)->first();
  }else{
    $zips = CaZip::where('city', '=', $current_city->city_name)->where('state_code', '=', $request->state)->get();
    $state_code = CaZip::where('state', '=', $current_state->state_name)->first();
  }

  $results = [$state_code->state_code, $current_state->state_name, $current_city->city_name, $current_zip];

  return $results;

}

public function inMan2019(){
  $title = 'July 8, 2019';
  return view('tymbl.inman2019', compact('title'));
}

}
