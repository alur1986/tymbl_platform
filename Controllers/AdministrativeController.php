<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Ad;
use App\User;
use App\Country;
use App\State;
use App\City;
use App\Category;
use App\Notification;
use App\NotificationTask;
use Illuminate\Support\Facades\Validator;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Mail;

class AdministrativeController extends Controller
{

  private $admin = array('newtonboy', 'timour', 'andy', 'pavel', 'min');

  public function checkServerStatus(){
    $host = request()->getHost();
    $port = 80;
    $timeout = 10;

    try{
      $status = fsockopen($host, $port, $errno, $errstr, $timeout);
      return 'OK';
    }catch(\Exception $ex){
      return 'FAIL';
    }

  }

  public function checkDbServerStatus(){
    try {
      $status = DB::connection()->getDatabaseName();
      return 'DB OK';
    } catch (\Exception $e) {
      die("Could not connect to the database.  Please check your configuration. error:" . $e );
    }
  }

  public function generalMailer(){
    Mail::raw('Server is down', function($message) {
      $message->subject('Please check tymbl.com');
      $message->from('info@tymbl.com', 'Server status down');
      $message->to('newtonboy@gmail.com')->cc('info@tymbl.com');
    });
  }

  public function validateEmail($email)
  {

    $emailIsValid = FALSE;

    if (!empty($email))
    {
      $domain = ltrim(stristr($email, '@'), '@') . '.';
      $user   = stristr($email, '@', TRUE);

      if(!empty($user) && !empty($domain) && checkdnsrr($domain)){
        $emailIsValid = TRUE;
      }
    }

    return $emailIsValid;
  }

  public function testPost(Request $request){


    if(!$this->checkPostHeader($request)){
      echo 'username or password error\r\n';
      exit;
    }

    $time = microtime(true) - $_SERVER["REQUEST_TIME_FLOAT"];

    $user_exitsts = User::where('email', $request->email)->first();
    if($user_exitsts){
      return "User already existst ".$time."\r\n";
      exit;
    }

    $country = Country::where('country_name', $request->country)->first();
    $state = State::where('country_id', $country->id)->where('state_name', $request->state)->first();
    $city = City::where('state_id', $state->id)->where('city_name', $request->city)->first();

    $user_add = new User;
    $user_add->name = $request->name;
    $user_add->email = $request->email;
    $user_add->sms_activation_code = mt_rand(100000,999999);
    $user_add->zip_code = $request->postal_code;
    $user_add->save();

    if($user_add){
      echo "User ".$user_add->name." was added to the  db successfully.\n";

      $add_notification = new Notification;
      $add_notification->user_id = $user_add->id;
      $add_notification->email = $request->email;
      $add_notification->country_id = $country->id;
      $add_notification->state_id = $state->id;
      $add_notification->city_id = $city->id;
      $add_notification->range = $request->distance;
      $add_notification->interests = $request->interest;
      $add_notification->save();

      if($add_notification){
        echo "Notification for user ".$user_add->name." has been created.\n execution time (".$time.")\n";
      }else{
        echo "An error occured during data processing";
      }

    }else{
      echo "An error occured duting data processing";
    }

  }

  public function checkAllEntries(Request $request){

    if(!$this->checkPostHeader($request)){
      echo 'username or password error \r\n';
      exit;
    }

    $users = User::orderBy('id', 'desc')->take(5)->get();
    foreach($users as $user){
      echo $user->name." | ".$user->email."\n";
    }

  }

  public function checkPostHeader($request){

    if(isset($request)){

      preg_match("'xxx12345(.*?)54321xxx'si", $request->headers->get('php-auth-pw'), $match);

      if(in_array($request->headers->get('php-auth-user'), $this->admin)){
        if($match[1] == $request->headers->get('php-auth-user')){
          return true;
        }
      }
    }

    return false;
  }

  public function deleteFavoriteList(){
    echo 'test';
  }

  public function getPostById(Request $request){
    $ad = Ad::whereId($request->id)->first();
    return $ad;
  }

  public function testPostLocal(Request $request){

    $title = filter_var($request->listing_title, FILTER_SANITIZE_STRING);
    $slug  = unique_slug($title);
    $video_url = $request->video_url ? $request->video_url : '';
    $feature1 = serialize($request->feature);
    $feature2 = serialize($request->feature2);

    $category_type_status = $request->cat_type_q == 'yes' ? 'qualified' : 'unqualified';
    $referral_fee = (($request->referral_fee+10)/100);

    $sub_category   = null;
    $ads_price_plan = get_option('ads_price_plan');

    //if($request->category) {
      $sub_category = Category::findOrFail($request->category);
    //}

    if($request->cat_type == 'ltb'){
      $ctype = 'buying';
    }elseif($request->cat_type == 'lts'){
      $ctype = 'selling';
    }else{
      $ctype = 'other';
    }

    $data = [
      'title'           => strip_tags($request->listing_title),
      'slug'            => $slug,
      'description'     => filter_var($request->listing_description, FILTER_SANITIZE_STRING),
      'category_id'     => $sub_category->category_id,
      'sub_category_id' => $request->category,
      'type'            => $request->type,
      //'ad_condition'    => $request->condition,
      'price'           => $request->price,
      'seller_name'    => filter_var($request->seller_name, FILTER_SANITIZE_STRING),
      'seller_email'   => filter_var($request->seller_email, FILTER_VALIDATE_EMAIL),
      'seller_phone'   => preg_replace('/\D/', '', $request->seller_phone),
      'country_id'     => $request->country,
      'state_id'       => $request->state,
      'city_id'        => $request->city,
      'zipcode'        => $request->zipcode,
      //'address'        => filter_var($request->referral_contact_address, FILTER_SANITIZE_STRING),
      'video_url'      => $video_url,
      'category_type'  => $ctype,
      'price_plan'     => 'regular',
      'listing_status' => '3',
      'price_range'    => $request->price_range,
      'referral_fee'   => $referral_fee,
      //'mark_ad_urgent' => $mark_ad_urgent,
      'status'         => '5',
      'user_id'        => $request->user_id,
      'latitude'       => $request->latitude,
      'longitude'      => $request->longitude,
      'escrow_amount'  => $request->escrow_amount,
      'feature_1'      => $feature1,
      'feature_2'      => $feature2,
      'cat_type_status'=> $category_type_status,
      'date_contacted_qualified' =>  date("Y-m-d", strtotime($request->date_contacted_qualified))
    ];

    $created_ad = Ad::create($data);

    $remove_ad = Ad::find($created_ad->id);
    $remove_ad->delete();

    return $created_ad;
  }

}
