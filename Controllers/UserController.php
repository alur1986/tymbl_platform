<?php

namespace App\Http\Controllers;

use App\Ad;
use App\Country;
use App\Favorite;
use App\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use App\UserTitleCompanyInfo;
use App\Http\Requests;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Intervention\Image\Facades\Image;
use Yajra\Datatables\Datatables;
use App\Notification;
use App\NotificationTask;
use App\State;
use App\City;
use App\Mls;
use App\Broker;
use Twilio\Rest\Client;
use Torann\GeoIP\Facades\GeoIP;
use App\Jobs\SendNewRegistrationEmail;
use App\UsZip;
use App\CaZip;
use App\NotificationsUsers;
use App\ContactQuery;

class UserController extends Controller
{
  /**
  * Display a listing of the resource.
  *
  * @return \Illuminate\Http\Response
  */
  public function index()
  {
    $title = trans('app.users');

  //  if(env('APP_DEMO') == true){
   //  return view('admin.no_data_for_demo', compact('title'));
  //  }
    $user = Auth::user();
    //$notifications_users = $this->getUserNotifications($user);
    //$user_notification_count = count($notifications_users);
    return view('tymbl.dashboard.users', compact('title','user'));
  }

  public function userAdd(){
    $title = "Add User";
    $states = State::where('country_id', '231')->orWhere('country_id','38')->get();
    $mls = Mls::all();

    $user = Auth::user();
    //$notifications_users = $this->getUserNotifications($user);
    //$user_notification_count = count($notifications_users);

    return view('tymbl.dashboard.user_add', compact('title', 'states', 'mls'));
  }

  public function addUser(Request $request){
    //dd($request);
    $user = User::where('email', '=', $request->email)->first();
    $zip = null === $request->zipcode ? '0' : $request->zipcode;

    if($user){
      return back()->withInput()->with('error', 'Email address has been registered already');
    }else{
      $name = explode(' ', $request->name);
      $data = [
        'first_name'        => $name[0],
        'last_name'         => end($name),
        'name'              => $request->name,
        'email'             => $request->email,
        'password'          => bcrypt($request->password),
        'phone'             => $request->phone,
        'title'             => $request->title,
        'country_id'        => $request->country,
        'zip_code'          => $zip,
        'user_type'         => $request->user_type,
        'active_status'     => '1',
        'banned'            => '0',
        'activation_code'   => str_random(30),
        're_license_number' => $request->re_license_number,
        'mls_id'               => $request->mls,
        'sms_activation_code' => '',
      ];

      $user = User::create($data);
      if($user){
        if($request->user_type == 'user'){
          $title_company_data = [
            'user_id'         => $user->id,
            'company_name'    => $request->company_name,
            'representative_name' => $request->representative_name,
            'representative_email' => $request->representative_email
          ];

          $broker_data = [
            'user_id'         => $user->id,
            'name'            => $request->broker_name,
            'address'         => '',
          ];

          $notification_data = [
            'user_id'       => $user->id,
            'email'         => $user->email,
            'phone'         => $user->phone,
            'country_id'    => $request->country,
            'state_id'      => $request->state,
            'city_id'       => $request->city,
            'range'         => '10',
            'interests'     => '1'
          ];

          $title_company = UserTitleCompanyInfo::create($title_company_data);
          $brokers = Broker::create($broker_data);
          $notifications = Notification::create($notification_data);
        }
      }

      return back()->with('success', 'User has been added');
    }
  }

  public function usersData(){
    $users = User::select('id','name', 'active_status', 'banned', 'name', 'zip_code', 'created_at', 'last_login', 'country_id')->whereUserType('user')->orderBy('created_at', 'DESC')->get();
    return  Datatables::of($users)
    ->editColumn('name', function($user){
      $html = '<a href="'.route('user_info', $user->id).'" title="View Profile" data-userid="'.$user->id.'" data-toggle="modal" data-target="#userModal">'.$user->name.'</a>';
      return $html;
    })
    ->editColumn('active_status', function($user){
      $html = '';
      switch ($user->active_status) {
        case "0":
        $html = '<i class="fas fa-circle stat-pending mx-auto" title="Pending"></i>';
        break;
        case "1":
        $html = '<i class="fas fa-circle stat-active mx-auto" title="Active"></i>';
        break;
        case "2":
        $html = '<i class="fas fa-circle stat-block mx-auto" title="Blocked"></i>';
        break;
        default:
        $html = '';
      }
      return $html;
    })

    ->editColumn('banned', function($user){
      $html = '';
      switch ($user->banned) {
        case "0":
        $html = '<i class="fas fa-circle stat-active mx-auto" title="Live"></i>';
        break;
        case "1":
        $html = '<i class="fas fa-circle stat-block mx-auto" title="Banned"></i>';
      }
      return $html;
    })
    ->editColumn('zip_code',function($user){

      $country= '';
      $state = '';
      $city = '';
      $loc = '';

      if($user->country_id == '231'){
        $country = 'United States';
        $loc = UsZip::where('zip', '=', $user->zip_code)->first();
      }elseif($user->country_id ==  '38'){
        $country = 'Canada';
        $loc = CaZip::where('zip', '=', $user->zip_code)->first();
      }

      if(isset($loc->state)){
        $state = $loc->state;
      }

      if(isset($loc->city)){
        $city = $loc->city.', ';
      }

      if($user->zip_code == '0'){
        $user_zip = '';
      }else {
        $user_zip = $user->zip_code;
      }

      $location = $city.$state.'<br>'.$user_zip.' '.$country;

      return $location;
    })
    ->removeColumn('id')
    ->removeColumn('created_at')
    ->make();
  }

  public function userInfo($id){
    $title = trans('app.user_info');
    $user = User::find($id);
    $mls = Mls::find($user->mls_id);
    $broker = Broker::whereUserId($user->id)->first();
    $ads = $user->ads()->paginate(20);
    $title_company = UserTitleCompanyInfo::where('user_id', '=', $user->id)->first();

    //$notifications_users = $this->getUserNotifications($user);
    //$user_notification_count = count($notifications_users);

    if (!$user){
      return view('admin.error.error_404');
    }

    return view('tymbl.dashboard.user_info', compact('title', 'user', 'ads', 'mls', 'broker', 'title_company'));
  }

  public function userEditStatus(Request $request){
    $status = '0';
    $user = User::whereId($request->user_id)->first();
    $user->active_status = $request->status;
    $user->save();

    if($user){
      $status = '1';
    }

    return $status;
  }


  public function userEditBanned(Request $request){
    $banned = '0';
    $user = User::whereId($request->user_id)->first();
    $user->banned = $request->banned;
    $user->save();

    if($user){
      $banned = '1';
    }

    return $banned;
  }



  /**
  * Show the form for creating a new resource.
  *
  * @return \Illuminate\Http\Response
  */
  public function create()
  {
    $countries = Country::all();
    return view('theme.user_create', compact('countries'));
  }

  /**
  * Store a newly created resource in storage.
  *
  * @param  \Illuminate\Http\Request  $request
  * @return \Illuminate\Http\Response
  */
  public function store(Request $request)
  {

    $rules = [
      'first_name'    => 'required',
      'email'    => 'required|email',
      'title'    => 'required',
      'country'    => 'required',
      'password'    => 'required|confirmed',
      'password_confirmation'    => 'required',
      'phone'    => 'required',
      'agree'    => 'required',
    ];
    $this->validate($request, $rules);

    $active_status = get_option('verification_email_after_registration');

    $data = [
      'first_name'        => $request->first_name,
      'last_name'         => $request->last_name,
      'name'              => $request->first_name.' '.$request->last_name,
      'email'             => $request->email,
      'password'          => bcrypt($request->password),
      'phone'             => $request->phone,
      'title'            => $request->title,
      'country_id'        => $request->country,
      'user_type'         => 'user',
      'active_status'     => ($active_status == '1') ? '0' : '1',
      'banned'     => ($banned == '0') ? '1' : '0',
      'activation_code'   => str_random(30)
    ];

    $user_create = User::create($data);

    if ($user_create){
      $registration_success_activating_msg = "";
      if ($active_status == '1') {
        try {
          $registration_success_activating_msg = ", we've sent you an activation email, please follow email instruction";

          Mail::send('emails.activation_email', ['user' => $data], function ($m) use ($data) {
            $m->from(get_option('email_address'), get_option('site_name'));
            $m->to($data['email'], $data['name'])->subject(trans('app.activate_email_subject'));
          });
        } catch (\Exception $e) {
          $registration_success_activating_msg = ", we can't send you activation email during an email error, please contact with your admin";
        }
      }
      return redirect(route('login'))->with('registration_success', trans('app.registration_success'). $registration_success_activating_msg);
    } else {
      return back()->withInput()->with('error', trans('app.error_msg'));
    }
  }

  public function activatingAccount($activation_code){
    $get_user = User::whereActivationCode($activation_code)->first();
    if (!$get_user){
      $error = trans('app.invalid_activation_code');
      //return view('theme.invalid', compact('error'));
      return redirect(route('login'))->with('error', 'Either account has been activated or user does not exist. Login now.');
    }
    $get_user->active_status = '1';
    $get_user->banned = '0';
    $get_user->is_email_verified = '1';
    $get_user->activation_code = '';
    $get_user->save();
    //Session::flash('success', trans('app.account_activated'));
    return redirect(route('login'))->with('success', trans('app.account_activated'));
  }

  /**
  * Display the specified resource.
  *
  * @param  int  $id
  * @return \Illuminate\Http\Response
  */
  public function show($id)
  {
    //
  }

  /**
  * Show the form for editing the specified resource.
  *
  * @param  int  $id
  * @return \Illuminate\Http\Response
  */
  public function edit($id)
  {
    //
  }

  /**
  * Update the specified resource in storage.
  *
  * @param  \Illuminate\Http\Request  $request
  * @param  int  $id
  * @return \Illuminate\Http\Response
  */
  public function update(Request $request, $id)
  {
    //
  }

  /**
  * Remove the specified resource from storage.
  *
  * @param  int  $id
  * @return \Illuminate\Http\Response
  */
  public function destroy(Request $request){
    //
    $user = User::find($request->id);
    if($user){
      $user->delete();
    }


    $tc = UserTitleCompanyInfo::where('user_id', '=', $request->id)->first();
    if($tc){
      $tc->delete();
    }


    $notify = Notification::where('user_id', '=', $request->id)->first();
    if($notify){
      $notify->delete();
    }


    $broker = Broker::where('user_id', '=', $request->id)->first();
    if($broker){
      $broker->delete();
    }


    if($user){
      return '1';
    }

  }

  public function profile(){

    $title = trans('app.profile');
    $user = Auth::user();
    $mls = Mls::where('id', '=', $user->mls_id)->first();
    $broker = Broker::where('user_id', '=', $user->id)->first();
    $user_state = State::where('id', '=', $user->state_id)->first();
    $user_city = City::where('id', '=', $user->city_id)->first();

    $email = Auth::user()->email;
    $phone = Auth::user()->phone;
    $last_login = Auth::user()->last_login;   
    $countries[] = '231';
    $states = '';
    $cities = '';

    $notifications = Notification::where('user_id', '=', $user->id)->get();

    //$notifications_users = $this->getUserNotifications($user);
    //$user_notification_count = NotificationsUsers::where('recipient', '=', $user->id)->count();

    return view('tymbl.dashboard.profile', compact('title', 'user', 'mls', 'broker', 'optin', 'countries', 'states', 'cities', 'email', 'phone', 'user_state', 'banned', 'user_city', 'notifications', 'last_login'));
  }

  public function profileEdit(){
    $gapid = config('app.google')['google_api'];
    session(['from_page' => str_replace(url('/'), '', url()->previous())]);

    $title = trans('app.profile_edit');
    $zips = '';
    $user_state = '';
    $zip = '';

    $user = Auth::user();

    $user_country = Country::whereId($user->country_id)->first();


    $broker = Broker::where('user_id', '=', $user->id)->first();

      if($user->country_id == '231'){
        $zip = UsZip::where('zip', '=', $user->zip_code)->first();
        $states = State::where('country_id', '=', $user->country_id)->get();
        $cities = City::where('state_id', '=', $user->state_id)->get();
        if($zip){
          $user_state = State::where('state_name', '=', $zip->state)->first();
          $user_city = City::where('id', '=', $user->city_id)->first();
          $zips = UsZip::where('city', '=', $zip->city)->get();
        }
      }elseif($user->country_id == '38'){
        $zip = CaZip::where('zip', '=', $user->zip_code)->first();
        $states = State::where('country_id', '=', $user->country_id)->get();
        $cities = City::where('state_id', '=', $user->state_id)->get();
        if($zip){
          $user_state = State::where('state_name', '=', $zip->state)->first();
          $user_city = City::where('id', '=', $user->city_id)->first();
          $zips = CaZip::where('city', '=', $zip->city)->get();
        }
      }

    $mls = Mls::all();
    $user_mls = Mls::where('id', '=', $user->mls_id)->first();

    $user_name = explode(' ', $user->name);
    $first_name = '';
    $last_name = '';
    if(count($user_name) > 2){
      $sliced_name = array_slice($user_name, 0, -1);
      $first_name = implode(" ", $sliced_name);
    }else{
      $first_name = $user_name[0];
    }
    $last_name = end($user_name);

    $notifications = Notification::where('user_id', '=', $user->id)->get();

    return view('tymbl.dashboard.profile_edit', compact('title', 'user', 'user_country', 'broker', 'states', 'mls', 'user_mls', 'banned', 'user_state', 'user_city', 'cities', 'zips', 'first_name', 'last_name', 'last_login', 'gapid', 'notifications'));
  }

  public function profileEditPost(Request $request){

    $countries = array('231', '38');
    if(!in_array($request->countryid, $countries)){
      return redirect()->back()->withInput()->with('error',  'Country is invalid');
    }

    $state = State::where('country_id', '=', $request->countryid)->where('state_name', '=', $request->state_name)->first();

    if(!$state){
      return redirect()->back()->withInput()->with('error',  'State is invalid. Please input correct State or use the address auto-suggestion.');
    }

    if($request->city_name == '' && $request->zip_code != ''){
      return redirect()->back()->withInput()->with('error',  'City is invalid. Please input correct City or use the address auto-suggestion.');
    }

    $city = City::where('state_id', '=', $state->id)->where('city_name', '=', ucfirst($request->city_name))->first();

    if(!$city){
      //return redirect()->back()->withInput()->with('error',  'City is invalid. Please input correct City or use the address auto-suggestion.');
      $new_city = City::create(['city_name' => ucfirst($request->city_name), 'state_id' => $state->id]);
    }

    if(!isset($request->zip_code)){
      $zipcode = '';
    }else{
      $zipcode = $request->zip_code;
    }


    $user_id = Auth::user()->id;
    $user = User::find($user_id);

    $name = $request->first_name.' '.$request->last_name;

    //Query other info table
    $info = UserTitleCompanyInfo::where('user_id', $user_id);

    //Validating
    //$rules = [
      //'//email'    => 'required|email|unique:users,email',
      //'representative_name' => 'required',
      //'representative_email' => 'required',
      //'phone' => 'required|numeric',
     //];

    //$this->validate($request, $rules);

    //dd($request);
    $sanitize_email = filter_var($request->email, FILTER_VALIDATE_EMAIL);
    $sanitize_address = filter_var($request->address, FILTER_SANITIZE_STRING);
    $sanitize_name = filter_var($name, FILTER_SANITIZE_STRING);
    $sanitize_mls = filter_var($request->mls, FILTER_SANITIZE_STRING);
    $sanitize_phone = preg_replace('/[^0-9]/', '', $request->phone);

    $request->merge(['name' => $sanitize_name, 'zip_code' => $zipcode, 'country_id' => $request->countryid, 'state_id' => $state->id, 'city_id' => $city->id, 'email' => $sanitize_email, 'address' => $sanitize_address, 'phone' => $sanitize_phone]);

    $inputs = array_except($request->input(), ['_token', 'photo', 'company_name', 'representative_name', 'representative_email', 'broker_name', 'mls_state', 'mls_id', 'send_account_info', 'sms_notify', 'state_name', 'city_name', 'country', 'state', 'city', 'countryid']);

    //$name_update = $user->whereId($user_id)->update(['name' => $user->first_name.' '.$user->last_name]);

    try {

      $user_exists = User::where('email', '=', $request->email)->where('id', '!=', $user->id)->first();

      if($user_exists){
        $email_proposed = $request->email;
        $request->merge(array('email' => $user->email));
        return redirect()->back()->withInput()->with('error',  $email_proposed.' is already being used. ');
      }

      $user_update = $user->whereId($user_id)->update($inputs);


      if($user_update){
        $ads_by_user = Ad::where('user_id', '=', $user_id)->get();
        foreach($ads_by_user as $ad){
          $update_ad = Ad::where('id', '=', $ad->id)->update(['seller_email' => $sanitize_email, 'seller_phone' => $sanitize_phone]);
        }
      }else{
        return redirect()->back()->withInput()->with('error',  'An error occured during process, please check your submission.');
      }
    } catch (\Exception $e) {
        //
    }

    $sms_notify = $request->sms_notify == 'on' ? '1' : '0';
    $send_account_info = $request->send_account_info == 'on' ? '1' : '0';

    $send_account_info_update_2 = $user->whereId($user_id)->update(['send_account_info' => $send_account_info]);
    $sms_notify_update = $user->whereId($user_id)->update(['sms_notify' => $sms_notify]);

    if($request->mls_id != ''){
      $mls = Mls::where('name', '=', $request->mls_id)->first();

      if($mls){
        $mymls = $user->whereId($user_id)->update(['mls_id' => $mls->id] );
      }else{
        if($request->country_id == '231'){
          $zip = UsZip::where('zip', '=', $request->zip_code)->first();
        }else{
          $zip = CaZip::where('zip', '=', $request->zip_code)->first();
        }

        $state = State::where('state_name', '=', $zip->state)->first();

        $mls_data = [
          'state' =>  $state->state_name,
          'name' => $request->mls_id,
        ];
        $mls_add = Mls::create($mls_data);
        $user->mls_id = $mls_add->id;
        $user->save();
      }
    }else{
      $mymls = $user->whereId($user_id)->update(['mls_id' => '0'] );
    }

    $title_company = UserTitleCompanyInfo::updateOrCreate(
      ['user_id' => $user_id],
      ['company_name' => $request['company_name'], 'representative_name' => $request['representative_name'], 'representative_email' => $request['representative_email']]
    );

    $title_company->save();

    if(!isset($request->sms_notify)){
      $notifications = Notification::where('user_id', '=', $user->id)->delete();
    }

    if($request->broker_name){
      //$broker = Broker::updateOrCreate(array('user_id' => $user->id, 'name' => $request->broker_name));
      $broker_data = [
        'name' => filter_var($request->broker_name, FILTER_SANITIZE_STRING),
        'broker_contact_person' => filter_var($request->broker_contact_person, FILTER_SANITIZE_STRING),
        'broker_email' => filter_var($request->broker_email, FILTER_SANITIZE_STRING),
        'broker_phone' => filter_var($request->broker_phone, FILTER_SANITIZE_STRING),

      ];
      $broker = Broker::updateOrCreate(['user_id' => $user->id], $broker_data);
      
      if(isset($old_broker_data)){
        if(serialize($old_broker_data) != serialize($broker_data)){
          $broker = Broker::updateOrCreate(['user_id' => $user->id], $broker_data);
          $updated_broker = Broker::whereId($broker->id)->first();

          if($old_broker->broker_email != $request->broker_email){
            Broker::where('id', '=', $updated_broker->id)->update(['broker_verified' => '0']);
            $this->brokerConfirm($user, $broker);
          }

        }
      }else{
        $broker = Broker::updateOrCreate(['user_id' => $user->id], $broker_data);
        $updated_broker = Broker::whereId($broker->id)->first();
      }


    }

    if ($request->hasFile('photo')){

      $rules = ['photo'=>'mimes:jpeg,jpg,png'];
      $this->validate($request, $rules);

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

            $user->photo = $image_name;
            $user->photo_storage = get_option('default_storage');
            $user->save();

            if ($previous_photo){
              $previous_photo_path = 'uploads/avatar/'.$previous_photo;
              $storage = Storage::disk($previous_photo_storage);
              if ($storage->has($previous_photo_path)){
                $storage->delete($previous_photo_path);
              }
            }
          }

        } catch (\Exception $e) {
          return redirect()->back()->withInput($request->input())->with('error', $e->getMessage());
        }
      }

      if (strpos(session('from_page'), 'success') !== false){
        //session(['from_page' => '']);
        return redirect(session('from_page'));
      }else{
        return redirect(route('profile'))->with('success', trans('app.profile_edit_success_msg'));
      }
    }

    public function updateUserNotification(Request $request){

      $user = Auth::user();

      $notifications = Notification::where('user_id', '=', $user->id)->delete();

      if($request->type=='1'){
        $data = [
          'user_id' => $user->id,
          'email' => $user->email,
          'phone' => $user->phone,
          'country_id' => '0',
          'city_id' => '0',
          'state_id' => '0',
          'zip_id' => '0',
          'loc_range' => $request->notification_value,
          'interest' => '0'
        ];
        $new_notification = Notification::create($data);

      }else{

        $notification_val = explode(',', trim($request->notification_value));

        foreach($notification_val as $n=>$v){

          $data = [
            'user_id' => $user->id,
            'email' => $user->email,
            'phone' => $user->phone,
            'country_id' => '0',
            'city_id' => '0',
            'state_id' => '0',
            'zip_id' => trim($v, ' x'),
            'loc_range' => '0',
            'interest' => '0'
          ];
          $new_notification = Notification::create($data);
        }

      }

      if($new_notification){
        return '1';
        exit;
      }

      return '0';

    }

    public function administrators(){
      $title = trans('app.administrators');
      $users = User::whereUserType('admin')->get();

      return view('tymbl.dashboard.administrators', compact('title', 'users'));
    }

    public function addAdministrator(){
      $title = trans('app.add_administrator');
      $countries = Country::all();

      return view('admin.add_administrator', compact('title', 'countries'));
    }


    public function storeAdministrator(Request $request){
      $rules = [
        'name'                  => 'required',
        'email'                 => 'required|email',
        'phone'                 => 'required',
        'title'                => 'required',
        'country'               => 'required',
        'password'              => 'required|confirmed',
        'password_confirmation' => 'required',
      ];
      $this->validate($request, $rules);

      $data = [
        'name'              => $request->name,
        'email'             => $request->email,
        'password'          => bcrypt($request->password),
        'phone'             => $request->phone,
        'title'             => $request->title,
        'country_id'        => $request->country,
        'user_type'         => 'admin',
        'active_status'     => '1',
        'banned'            => '0',
        'activation_code'   => str_random(30)
      ];

      $user_create = User::create($data);
      return redirect(route('administrators'))->with('success', trans('app.registration_success'));
    }

    public function administratorBlockUnblock(Request $request){
      $status = $request->status == 'unblock'? '1' : '2';
      $user_id = $request->user_id;
      User::whereId($user_id)->update(['active_status' => $status]);

      if ($status ==1){
        return ['success' => 1, 'msg' => trans('app.administrator_unblocked')];
      }
      return ['success' => 1, 'msg' => trans('app.administrator_blocked')];

    }

     public function changeBanned(Request $request) {

      $user =  User::find($request->user_id);
      $title = "Update Banned Status";

      
      return view('tymbl.dashboard.change_banned', compact('title','user'));
    }



    public function changePassword()
    {
      $user = Auth::user();
      $title = trans('app.change_password');
      //$notifications_users = $this->getUserNotifications($user);
      //$user_notification_count = NotificationsUsers::where('recipient', '=', $user->id)->count();
      return view('tymbl.dashboard.change_password', compact('title'));
    }

    public function changePasswordPost(Request $request)
    {
      $rules = [
        'old_password'  => 'required',
        'new_password'  => 'required|confirmed',
        'new_password_confirmation'  => 'required',
      ];
      $this->validate($request, $rules);

      $old_password = $request->old_password;
      $new_password = $request->new_password;

      if(Auth::check())
      {
        $logged_user = Auth::user();

        if(Hash::check($old_password, $logged_user->password))
        {
          $logged_user->password = Hash::make($new_password);
          $logged_user->save();
          return redirect()->back()->with('success', trans('app.password_changed_msg'));
        }
        return redirect()->back()->with('error', trans('app.wrong_old_password'));
      }
    }

    /**
    * @param Request $request
    * @return array
    */

    public function saveAdAsFavorite(Request $request){

      if (!Auth::check()){
      return ['status'=>0, 'msg'=> trans('app.error_msg'), 'redirect_url' => route('login')];
      }

      $user = Auth::user();

      $slug = $request->slug;
      $ad = Ad::where('slug', '=', $slug)->first();

      if ($ad){
        $get_previous_favorite = Favorite::whereUserId($user->id)->whereAdId($ad->id)->first();

        if (!$get_previous_favorite){
          Favorite::create(['user_id'=>$user->id, 'ad_id'=>$ad->id]);
          return ['status'=>1, 'action'=>'added', 'msg'=>'<i class="fa fa-heart"></i> '.trans('app.remove_from_favorite')];
        }else{
          $get_previous_favorite->delete();
          return ['status'=>1, 'action'=>'removed', 'msg'=>'<i class="fa fa-heart-o"></i> '.trans('app.save_ad_as_favorite')];
        }
      }
      return ['status'=>0, 'msg'=> trans('app.error_msg')];
    }

    public function replyByEmailPost(Request $request){
      $data = $request->all();
      $data['email'];
      $ad_id = $request->ad_id;
      $ad = Ad::find($ad_id);
      if ($ad){
        $to_email = $ad->user->email;
        if ($to_email){
          try{
            Mail::send('emails.reply_by_email', ['data' => $data], function ($m) use ($data, $ad) {
              $m->from(get_option('email_address'), get_option('site_name'));
              $m->to($ad->user->email, $ad->user->name)->subject('query from '.$ad->title);
              $m->replyTo($data['email'], $data['name']);
            });
          }catch (\Exception $e){
            //
          }
          return ['status'=>1, 'msg'=> trans('app.email_has_been_sent')];
        }
      }
      return ['status'=>0, 'msg'=> trans('app.error_msg')];
    }

    public function saveNotification(Request $request){
      $user_id = Auth::user()->id;

      if($request->optin == '2'){
        User::whereId($user_id)->update(['notification_optin' => 2]);
      }else{
        $saveNotification = Notification::firstOrCreate(['user_id'=> $user_id, 'email'=>$request->email, 'phone'=>$request->phone, 'country_id'=>$request->country_id, 'state_id'=>$request->state_id, 'city_id'=>$request->city_id]);
        User::whereId($user_id)->update(['notification_optin' => 1]);
      }
      //return $user_id ;
    }

    public function notification(){
      $title = 'Notification';

      if (Auth::check()) {
        $user = Auth::user()->id;
      }
      $email = Auth::user()->email;
      $phone = Auth::user()->phone;

      $optin = Notification::whereUserId($user)->get();

      if(count($optin) >= 1){
        foreach($optin as $i){
          $country[] = $i->country_id;
          $state[] = State::where('id', $i->state_id)->get();
          $city[] = City::where('id', $i->city_id)->get();
        }
        $email = $i->email;
        $phone = $i->phone;
        $countries = array_unique($country);
        $states = array_unique($state);
        $cities = array_unique($city);
      }else{
        $countries[] = '231';
        $states = '';
        $cities = '';
      }
      //return $states;

      return view('tymbl.dashboard.notification', compact('title', 'user', 'optin', 'countries', 'states', 'cities', 'email', 'phone'));
    }

    public function editEmailOrPhone(Request $request){
      //$optin = Notification::where(['email'=>$request->email, 'phone'=>$request->phone])->get();
      $optin = Notification::where('user_id', '=', $request->id)->update(['email' => $request->new_email, 'phone' => $request->new_phone]);

      return $optin;
    }


    //pre-registration page
    public function preRegistration(){

      $whitelist = array('127.0.0.1', "::1");
      $client_ip = $this->get_client_ip();

      if(Session::has('testcaller')){
        $user_loc = geoip($ip = '174.22.204.238');
        $country = $user_loc->country;
      }else{
        if(in_array($client_ip, $whitelist)){
          $user_loc = geoip($ip = '174.22.204.238');
        }else{
          $user_loc = geoip($ip = $client_ip);
          if($user_loc->country == 'Philippines' || $user_loc->country == 'Bangladesh'){
            $user_loc = geoip($ip = '174.22.204.238');
          }
        }
      }

      //dd($user_loc);
      $title = "Preregister";

      $postal_code = $user_loc->postal_code;
      $country = $user_loc->country;
      $state = $user_loc->state_name;
      $city = $user_loc->city;
      $country_id = $country == 'Canada'? '38' : '231';
      $all_states = State::where('country_id', '=', $country_id)->get();

      if(request()->old('state')){
        $state = request()->old('state');
      }

      if(request()->old('city')){
        $city = request()->old('city');
      }

      if(Session::has('testcaller')){
        $single_state = State::where('state_name', '=', 'Alabama')->first();
        $all_cities = City::where('state_id', '=', $single_state->id)->get();
        $all_zips = UsZip::where('state', '=', $state)->where('city', '=', $city)->get();
      }else{
        $single_state = State::where('state_name', '=', $state)->first();
        $all_cities = City::where('state_id', '=', $single_state->id)->get();
        $all_zips = UsZip::where('state', '=', $state)->where('city', '=', $city)->get();
      }

      return view('landing.index', compact('title', 'country', 'state', 'city', 'current_country', 'postal_code', 'all_states', 'all_cities', 'all_zips'));
    }

    public function preRegisterUser(Request $request){


      $rules = [
        'name'           => 'required|Regex:/^[\D]+$/i|max:100',
        'email'          => 'required|email|max:255|unique:users',
        'zipcode'    => 'required',

      ];
      $this->validate($request, $rules);

      $user_exitsts = User::where('email', $request->email)->get();
      if(count($user_exitsts) >= 1){
        return redirect()->back()->with('error', 'Email aready exists')->withInput();
      }

      //echo $request->state;

      if(Session::has('testcaller')){
        $country = Country::where('country_name', 'United States')->first();
        $state = State::where('country_id', '231')->where('state_name', 'Alabama')->first();
        $city = City::where('state_id', '3919')->where('city_name', 'Alabaster')->first();
      }else{
        $country = Country::where('country_name', $request->country)->first();
        $state = State::where('country_id', $country->id)->where('state_name', $request->state)->first();
        $city = City::where('state_id', $state->id)->where('city_name', $request->city)->first();
      }

      $user_add = new User;
      $user_add->name = $request->name;
      $user_add->email = $request->email;
      $user_add->sms_activation_code = mt_rand(100000,999999);
      $user_add->zip_code = $request->zipcode;
      $user_add->save();

      if($user_add){
        $add_notification = new Notification;
        $add_notification->user_id = $user_add->id;
        $add_notification->email = $request->email;
        $add_notification->country_id = $country->id;
        $add_notification->state_id = $state->id;
        $add_notification->city_id = $city->id;
        $add_notification->range = $request->distance;
        //interest 1 = Interested in Referrals in my Area
        // 2 = Intereted in posting a referral
        // 3 = Both
        $add_notification->interests = $request->interest;
        $add_notification->save();

        //send email to admin for new users
        $this->sendReminderEmail($user_add->id);

        return redirect()->back()->with('success', 'Thank you. We will inform you when there are new referrals or leads in your area.')->with('f_name', $request->name)->with('f_email', $request->email)->withInput();
      }else{
        return redirect()->back()->with('error', 'An error occured when trying to save new user. Please try again later')->withInput();
      }
    }

    public function termsAndConditions(){
      $title = 'Terms And Conditions';
      return view('landing.terms-conditions', compact('title'));
    }

    public function privacyPolicy(){
      $title = 'Privacy Policy';
      return view('landing.privacy-policy', compact('title'));
    }

    public function contact(){
      $title = 'Contact Us';
      return view('landing.contact', compact('title'));
    }

    public function frequentlyAskedQuestions(){
      $title = 'FAQ';
      return view('landing.faq', compact('title'));
    }

    public function sendContact(Request $request){

      //dd($request);

      $rules = [
        'name'           => 'required|Regex:/^[\D]+$/i|max:100',
        'email'          => 'required|email|max:255',
        'message'    => 'required',
      ];

      $this->validate($request, $rules);
      $today = date('Y-m-d h:i:s');

      $to = array('info@tymbl.com');
      $data = array('to' => 'info@tymbl.com', 'from' => $request->email, 'name' => $request->name, 'message', $request->message);

      $contact_data = ['name' => $request->name, 'email' => $request->email, 'message' => $request->message];
      $db_contact = ContactQuery::create($contact_data);

      Mail::send('landing.contact-us', ['email' => $request->email, 'name' => $request->name, 'bodymessage' => $request->message, 'date' => $today], function ($message) use ($request, $to)
      {
        $message->from($request->email, 'Tymbl Contact Us Page');
        $message->to($to, "New Message from Contact Us Page");
        $message->subject("New Message from Contact Us Page");
      }
    );

    return redirect()->back()->with('success', 'Thank you for contacting us. We will get in touch with you as soon as possible.');
  }

  public function sendEmailFromPrereg(Request $request){

    $email = array($request->email);
    Mail::send('landing.register', ['name' => $request->name, 'email' => $request->email], function ($message) use ($email)
    {
      $message->from('info@tymbl.com','Tymbl Team');
      $message->to($email);
      $message->subject("Thank you for registering");
    });

    return 'ok';
  }

  public function sendReminderEmail($id)
  {
    $user = User::where('id', $id)->first();
    SendNewRegistrationEmail::dispatch($user);
  }

  public function checkEmailCurrent(Request $request){
    $user_exists = User::where('email', '=', $request->email)->first();
    if($user_exists){
      return '1';
    }else{
      return '0';
    }
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

  public function getUserNotifications(Request $request){
    $notifications_users = NotificationsUsers::where('recipient', '=', $request->id)->limit('8')->orderBy('id', 'desc')->get();
    return $notifications_users;
  }

  public function getUserInfo(Request $request){
    $user = User::whereId($request->userid)->first();
    if($user){
      $ads = Ad::where('user_id', '=', $request->userid)->where('status', '=', '1')->get();
      $country = $user->country_id == '231' ? 'United States' : 'Canada';
      $city = City::where('id', '=', $user->city_id)->first();
      $states = State::where('id', '=', $user->state_id)->first();
      $broker = Broker::where('user_id', '=', $user->id)->first();
      $mls = Mls::where('id', '=', $user->mls_id)->first();
      $count_ads = count($ads);
      $data = [$user, $country, $states, $city, $broker, $mls, $count_ads];
      return $data;
    }else{
      return '0';
    }

  }


  public function getMlsAutocomplete(Request $request){
    $mls = Mls::where('state', '=', $request->state)->get();
    return $mls;
  }

  public function adminUserEditSave(Request $request){
    $user = User::whereId($request->id)->first();

    if($request->zip == ''){
      $zip = '0';
    }else{
      $zip = $request->zip;
    }

    if($user){
      $user->active_status = $request->active_status;
      $user->name = trim(ucfirst($request->name));
      $user->first_name = trim(ucfirst($request->first_name));
      $user->last_name = trim(ucfirst($request->last_name));
      if($request->title == ''){
        $user->title = $request->title;
      }else{
        $user->title = strtolower(trim($request->title));
      }
      $user->email = trim($request->email);
      $user->phone = trim($request->phone);
      $user->address = trim($request->address);
      $user->country_id = trim($request->country_id);
      $user->state_id = trim($request->state_id);
      $user->city_id = trim($request->city_id);
      $user->zip_code = trim($zip);
      $user->mls_id = $request->mls_id;
      $user->save();

      $broker = Broker::where('user_id', '=', $request->id)->first();
      if($broker){
        if($request->brokerage_name != ''){
          $broker->name = trim($request->brokerage_name);
        }

        if($request->broker_contact_person != ''){
          $broker->broker_contact_person = trim($request->broker_contact_person);
        }

        if($request->broker_email != ''){
          $broker->broker_email = trim($request->broker_email);
        }

        if($request->broker_contact_num != ''){
          $broker->broker_phone = trim($request->broker_contact_num);
        }

        $broker->save();
        return '1';
      }else{
        Broker::create(['user_id' => $request->id, 'name' => 'null', 'broker_contact_person' => 'null', 'broker_email' => 'null', 'broker_phone' => 'null', 'broker_verified' => '0']);
        return '1';
      }
    }

    return 'An error occurred while saving. Please contact Newton';
  }



}
