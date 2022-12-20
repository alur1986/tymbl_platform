<?php
namespace App\Http\Controllers\Auth;
//require  '../vendor/autoload.php';

use App\User;
use App\UserTitleCompanyInfo;
use App\Http\Controllers\Controller;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Foundation\Auth\RegistersUsers;
use App\Mail\WelcomeMail;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Session;
use Twilio\Rest\Client;
use App\Mls;
use App\State;
use App\City;
use App\Broker;
use App\Country;
use App\NotificationNewRegistration;
use App\UsZip;
use App\CaZip;
use App\NotificationsUsers;
use Illuminate\Support\Facades\Log;
use App\Notification;
use Intervention\Image\Facades\Image;

class RegisterController extends Controller
{
  /*
  |--------------------------------------------------------------------------
  | Register Controller
  |--------------------------------------------------------------------------
  |
  | This controller handles the registration of new users as well as their
  | validation and creation. By default this controller uses a trait to
  | provide this functionality without requiring any additional code.
  |
  */

  use RegistersUsers;

  /**
  * Handle a registration request for the application.
  *
  * @param  \Illuminate\Http\Request  $request
  * @return \Illuminate\Http\Response
  */

  //start custom registration view for AB testing
  /*
  public function showRegistrationForm() {
  $mls = Mls::all();
  $states = State::all();
  $country_code = '231';

  foreach($mls as $client){
  $mls_client[$client->state][] = array("id" =>$client->id, "name" => $client->name);
}

$title = 'Registration - Tymbl';
if($page == 'a'){
return view('ab_registration.a', compact('title', 'mls_client', 'states', 'country_code'));
}else{
return view('ab_registration.b', compact('title', 'mls_client', 'states', 'country_code'));
}
}
*/

public function showRegistrationForm() {
  $gapid = config('app.google')['google_api'];
  $mls = Mls::all();
  $states = State::all();

  $client_ip = $this->get_client_ip();
  $user_loc = geoip($ip = $client_ip);
  $whitelist = array('127.0.0.1', '::1');

  if(in_array($client_ip, $whitelist)){
    $user_loc = geoip($ip = '174.22.204.238');
    $country = $user_loc->country;
  }else{
    $user_loc = geoip($ip = $client_ip);
    if($user_loc->country == 'Philippines' || $user_loc->country == 'Bangladesh'){
      $country = 'United States';
    }else{
      $country = $user_loc->country;
    }
  }

  $country_data = Country::where('country_name', $country)->first();
  $country_code = $country_data->id;

  foreach($mls as $client){
    $mls_client[$client->state][] = array("id" =>$client->id, "name" => $client->name);
  }

  $title = 'Registration - Tymbl';
  return view('ab_registration.a', compact('title', 'mls_client', 'states', 'country_code', 'gapid'));

}

public function showRegistrationFormB() {
  $mls = Mls::all();
  $states = State::all();
  $country_code = '231';

  foreach($mls as $client){
    $mls_client[$client->state][] = array("id" =>$client->id, "name" => $client->name);
  }

  $title = 'Registration - Tymbl';
  return view('ab_registration.b', compact('title', 'mls_client', 'states', 'country_code'));

}

public function register(Request $request)
{

  if($request->from == 'a'){
    $this->validateForA($request);
  }else{
    $this->validator($request->all())->validate();
  }

  event(new Registered($user = $this->create($request->all())));

  //Logs user in automatically
  //$this->guard()->login($user);
  if($request->from == 'a'){
    return $user;
  }else{
    return $this->registered($request, $user) ? '0' : redirect($this->redirectPath());
  }
}

/**
* Where to redirect users after registration.
*
* @var string
*/
protected $redirectTo = '/login/verify';

/**
* Create a new controller instance.
*
* @return void
*/
public function __construct()
{
  $this->middleware('guest');
}

/**
* Get a validator for an incoming registration request.
*
* @param  array  $data
* @return \Illuminate\Contracts\Validation\Validator
*/
protected function validator(array $data)
{
  //$data['phone'] = preg_replace('/\D/', '', $data['phone']);
  return Validator::make($data, [
    'first_name' => 'required|string|max:255',
    'last_fname' => 'required|string|max:255',
    'email' => 'required|string|email|max:255|unique:users',
    'password' => 'required|confirmed|min:6',
    'mls' => 'required|not_in:0',
    'brokerage_name' => 'required|string|max:300',
    'phone' => 'required|digits:10',
    'address' => 'required|string|max:300',
    're_license_number' => 'required|string',
    //'state' => 'required',
    //'representative_email' => 'required|string|email|max:255',
    //'representative_name' => 'required|string|max:255',
  ]);
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
  $ipaddress = 'UNKNOWN';
  return $ipaddress;
}




/**
* Create a new user instance after a valid registration.
*
* @param  array  $data
* @return User
*/
protected function create(array $data)
{

//Log::info($data['brokerage_name']);

  $range = $data['range'];
  $broker_name = $data['brokerage_name'];
  $broker_contact_person = $data['brokerage_contact_person'];
  $broker_email = $data['brokerage_email'];
  $broker_phone = $data['brokerage_phone'];
  $ip = $this->get_client_ip();

  if($data['from'] == 'a' || $data['from'] == ''){
    $user = User::where('email', $data['email'])->first();
    if($user){
      return 'Email already exists '.$data['email'];
      exit;
    }
  }


  $countryid = '';

  $name = $data['first_name'].' '.$data['last_name'];
  $state_id = State::where('state_name', '=', $data['state'])->first();

  if(!$state_id){
    return 'on_state';
    exit;
  }

  if($data['city_name'] == '' || $data['city_name'] === 'null'){
    return 'on_city';
    exit;
  }

  if($data['zip_code'] == '' || $data['zip_code'] === 'null'){
    return 'on_zip';
    exit;
  }

  if($data['tc_checked'] != '1'){
    return 'on_tc';
    exit;
  }

  if($data['email'] == 'testuser2@test.com'){
    $stateid = '3924';
    $cityid = '42847';
    $photo = '';
    $countryid = '231';
  }else{
    $stateid = $state_id->id;
    $cityid = $data['city'];
    $countryid = $data['country'];
  }

  $user = User::create([
    'first_name' => filter_var($data['first_name'], FILTER_SANITIZE_STRING),
    'last_name' => filter_var($data['last_name'], FILTER_SANITIZE_STRING),
    'name' => filter_var($name, FILTER_SANITIZE_STRING),
    'email' => $data['email'],
    'password' => bcrypt($data['password']),
    'user_type' => 'user',
    'ip'=>$ip,
    'active_status' => '0',
    'activation_code' => sha1(time()),
    'sms_activation_code' =>  mt_rand(100000,999999),
    'address' => filter_var($data['address'], FILTER_SANITIZE_STRING),
    'phone' => $data['phone'],
    'zip_code' => $data['zip_code'],
    'sms_notify' => $data['sms_notify'],
    'send_account_info' => $data['send_account_info'],
    'state_id' => $stateid,
    'city_id' => $cityid,
    //'photo' => $photo,
    'country_id' => $countryid,
    'location_range' => $data['range']
  ]);


if($data['email'] != 'testuser2@test.com'){

  if($user->sms_notify == '1'){
    //['user_id', 'email', 'phone', 'country_id', 'state_id', 'city_id'];
    if(isset($data['zip_pills'])){
      //get the country id
      $zip_info = '';

      foreach($data['zip_pills'] as $d=>$pill){
        //Log::info($pill);

        if($user->country_id == '231'){
          $zip_info = UsZip::where('zip', '=', $pill)->first();
        }else{
          $zip_info = CaZip::where('zip', '=', $pill)->first();
        }

        if($zip_info){
          $state_id = State::where('state_name', '=', $zip_info->state)->first();
          $city_id = City::where('city_name', '=', $zip_info->city)->first();
          $zip_id = $zip_info->zip;

          $data = [
            'user_id' => $user->id,
            'email' => $user->email,
            'phone' => $user->phone,
            'country_id' => $user->country_id,
            'state_id' => isset($state_id) ? $state_id->id : '0',
            'city_id' => isset($city_id) ? $city_id->id : '0',
            'zip_id' => $zip_info->zip,
            'loc_range' => $user->location_range
          ];

          $lead_notify = Notification::create($data);

          //Log::info($lead_notify->id);
          //return $lead_notify;
        }
      //end for loop
      }
    }
    else
        {
      
      $lead_notify = Notification::create(
        [
          'user_id' => $user->id,
          'email' => $user->email,
          'phone' => $user->phone,
          'country_id' => $user->country_id,
          'state_id' => $user->state_id,
          'city_id' => $user->city_id,
          'zip_id' => $user->zip_code,
          'loc_range' => $user->location_range
        ]
      );
    }
  }
}

  if($user){

    if($data['email'] != 'testuser2@test.com'){
      //turn this on

      if($user->send_account_info == '1'){
       
        $this->sendSMS($user);
      }

      //Create user table even if fields are blank
      $company_name = isset($data['company_name']) ? $data['company_name'] : ' ';
      $representative_name = isset($data['representative_name']) ? $data['representative_name'] : ' ';
      $representative_email = isset($data['representative_email']) ? $data['representative_email'] : ' ';

      $title_company = UserTitleCompanyInfo::firstOrCreate([
        'user_id' => $user->id,
        'company_name' => $company_name,
        'representative_name' => $representative_name,
        'representative_email' => $representative_email
      ]);
      $title_company->save();

      $registration_notification = NotificationNewRegistration::firstOrCreate(
        [
          'user_id' => $user->id,
          'notified' => '0'
        ]
      );
      $registration_notification->save();

      if($broker_name != ''){
       
        $broker = Broker::where('user_id', $user->id)->first();

        $broker_data = [
          'user_id' => $user->id,
          'name' => filter_var($broker_name, FILTER_SANITIZE_STRING),
          'broker_contact_person' => filter_var($broker_contact_person, FILTER_SANITIZE_STRING),
          'broker_email' => filter_var($broker_email, FILTER_SANITIZE_STRING),
          'broker_phone' => filter_var($broker_phone, FILTER_SANITIZE_STRING),
        ];

        if($broker){
          $broker_update = Broker::where('user_id', '=', $user->id)->update($broker_data);
        }else{
          $broker_create = Broker::create($broker_data);
          //Log::info('yes created '.$broker_create->user_id);
        }
      }

      $subject = "Welcome to Tymbl!";
      $notif_to_user = "Your account has been created. Please complete your profile details to be able to post ads or reserve ads.";

      $notif = [
        'type' => 'reminder',
        'recipient' => $user->id,
        'sender'  => '0',
        'subject' => $subject,
        'contents' => $notif_to_user,
        'user_read' => '0'
      ];

      $nofitication = NotificationsUsers::firstOrCreate($notif);

      //send SMS verification code
      if($data['phone'] != ""){
        Session::flash('success', '1');
      }else{
        Session::flash('success', '2');
      }
      Log::channel('reg')->debug('New Registration: '.$user);
    }
  }


  return $user;
}

//Send SMS verification code using Twilio
public function sendSMS($user){
  $sid = config('app.twilio')['account_sid'];
  $token = config('app.twilio')['app_token'];
  $from = config('app.twilio')['from_num'];
  $to = '+1'.preg_replace('/\D/', '', $user->phone);
  $client = new Client($sid, $token);

  try {
    $message = $client->messages
    ->create($to, // to
    array(
      "body" => "Welcome to Tymbl. Your verification code is: ".$user->sms_activation_code,
      "from" => '+1'.$from
    )
  );

} catch (\Exception $e){
  if($e->getCode() == 21211)
  {
    $message = $e->getMessage();
    Log::info('SMS error'.$message);
  }
}
}

public function validateForA($data){

  if($data['first_name'] == '' || !preg_match("/^[a-zA-Z0-9 ]*$/", $data['first_name'])){
    return 'First Name is required';
  }elseif($data['last_name'] == '' || !preg_match("/^[a-zA-Z0-9 ]*$/", $data['last_name'])){
    return 'Last Name is required';
  }elseif($data['email'] == '' || filter_var($data['email'], FILTER_VALIDATE_EMAIL) == FALSE){
    return 'Email address is either empty or invalid';
  }elseif(strlen($data['password']) < 8){
    return 'Password is too short.';
  }elseif(!$data['password'] == $data['password_confirmation']){
    return 'The password confirmation does not match.';
  }elseif($data['brokerage_name'] == ''){
    return 'Brokerage Name is required.';
  }elseif($data['address'] == ''){
    return 'Address is required';
  }elseif($data['phone'] == ''){
    return 'Phone is required';
  }
}

public function getZipState(Request $request){

  $client_ip = $this->get_client_ip();

  $user_loc = geoip($ip = $client_ip);

  $whitelist = array('127.0.0.1', '::1');

  if(in_array($client_ip, $whitelist)){

    $user_loc = geoip($ip = '174.22.204.238');

    $country = $user_loc->country;

  }else{

    $user_loc = geoip($ip = $client_ip);

    if($user_loc->country == 'Philippines' || $user_loc->country == 'Bangladesh'){

      $country = 'United States';

    }else{

      $country = $user_loc->country;
    }
  }

  if($country == 'United States'){

    $zip = UsZip::where('state', '=', trim($request->state))->get();

  }else{

    $zip = CaZip::where('state', '=', trim($request->state))->get();
  }
  return $zip;
}

public function registerCheckEmail(Request $request){

  $user = User::where('email', '=', $request->email)->first();

  if($user){

    return '1';
  }

  else{

    return '0';
  }
}

public function uploadImages(Request $request){

  if ($request->hasFile('photo')){
    //$rules = ['photo'=>'mimes:jpeg,jpg,png'];
    //$this->validate($request, $rules);
    $image = $request->file('photo');
    $file_base_name = str_replace('.'.$image->getClientOriginalExtension(), '', $image->getClientOriginalName());
    $resized_thumb = Image::make($image)->resize(300, 300)->stream();
    $image_name = strtolower(time().str_random(5).'-'.str_slug($file_base_name)).'.' . $image->getClientOriginalExtension();
    $imageFileName  = 'uploads/avatar/'.$image_name;
    //$imageThumbName = 'uploads/images/thumbs/'.$image_name;

    try {
      $resized = Image::make($image)->resize(
        640,
        null,
        function ($constraint) {
          $constraint->aspectRatio();
        })->save(public_path($imageFileName));

        //  $resized_thumb  = Image::make($image)->resize(320, 213)->save(public_path($imageThumbName));

        if (file_exists($imageFileName)){

          $previous_photo= $user->photo;
          $previous_photo_storage= $user->photo_storage;

          //$user->photo = $image_name;
          //$user->photo_storage = get_option('default_storage');
          //$user->save();

          if ($previous_photo){
            $previous_photo_path = 'uploads/avatar/'.$previous_photo;
            $storage = Storage::disk($previous_photo_storage);
            if ($storage->has($previous_photo_path)){
              $storage->delete($previous_photo_path);
            }
          }
        }

      } catch (\Exception $e) {
        return $image_name;

      }
    }

    return $image_name;


}


}
