<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Notification;
use App\NotificationTask;
use App\Ad;
use Illuminate\Support\Facades\Mail;
use Twilio\Rest\Client;
use App\ListingShortUrl;
use App\User;
use Illuminate\Support\Facades\DB;
use App\NotificationNewRegistration;
use App\NotificationSearch;
use Illuminate\Support\Facades\Route;
use App\Media;
use App\TransactionReports;
use Illuminate\Support\Facades\Log;
use Bitly;
use App\UsZip;
use App\CaZip;

class NotificationTaskController extends Controller
{
  //Send SMS or email notification of new listing
  public function notificationTask(){
    ////Log::info('email notification start');

    $notification_text = 'ACT NOW! There is a new referral in your area available on Tymbl.';

    //check if there is new entry in notification task table

    $new_listing = NotificationTask::where('user_notified', '=', '0')->get();

    foreach($new_listing as $listing){
      $ad = Ad::whereId($listing->listing_id)->where('status', '=', '1')->first();

      if($ad){
        ////Log::info('has ad id '.$ad->id);
        if(request()->test == '1'){
          $this->processSender($ad, request()->test_email);
          return 'done sending notification';
        }else{
          ////Log::info('no test start');
          $this->processSender($ad, '0');
          $this->performUserZipsNotification($ad);
          $this->performDistanceRangeNotification($ad);
          $done_sent_notif = NotificationTask::where('listing_id', $ad->id)->update(['user_notified'=>'1']);
        }

      }
    }
  }

  public function processSender($ad, $test_email){

    //Log::info('started regular notifcation');

    $media_name = '';
    $link = '';

    if($test_email != '0'){
      $users = User::where('email', '=', $test_email)->get();
    }else{
      $users = User::where('sms_notify', '=', '1')->where('active_status', '=', '1')->where('user_type', '=', 'user')->where('city_id', '=', $ad->city_id)->where('zip_code', '=', $ad->zipcode)->get();
      ////Log::info('no test users '.$ad->zipcode);
    }


    if($users){
      $media = Media::where('ad_id', '=', $ad->id)->first();
      $short_url = ListingShortUrl::where('listing_id', '=', $ad->id)->first();

      if($media){
        $media_name = $media->media_name;
      }

      if($short_url){
        $link = $short_url->url;
      }

      foreach($users as $user){

        if($user->email != ''){
          $this->sendNotificatioByEmail($user->email, $ad->id, $ad->slug, $link, $ad->media_name, $ad->title, $ad->address);
          //Log::info('sent email notification '.$user->email);
        }

        if($user->phone != ''){
          $to = '+1'.str_replace("-","",$user->phone);
          $this->sendNotificatioBySMS($user->phone, $ad->id, $ad->slug, $link);
          //Log::info('sent sms notification '.$user->email);
        }
      }
    }




  }

  public function notifyUserSavedSearch(){
    $saved_notification = NotificationSearch::where('user_notified', '=', '0')->get();
    foreach($saved_notification as $sn){

      $res = $this->internalCurl($sn->terms, $sn->user_id);
      if($res == '1'){
        $sn->user_notified = '1';
        $sn->save();
      }
    }
  }

  public function performUserZipsNotification($ad){
    //Log::info('process other '.$ad->id);
    //Log::info('start user zips notification');
    $notif = Notification::where('zip_id', '=', $ad->zipcode)->where('loc_range', '=', '0')->get();

    if($notif){
      foreach($notif as $notification){
        $user = User::where('email', '=', $notification->email)->where('active_status', '=', '1')->where('user_type', '=', 'user')->where('sms_notify', '=', '1')->first();

        if($user){
          $media = Media::where('ad_id', '=', $ad->id)->first();
          $short_url = ListingShortUrl::where('listing_id', '=', $ad->id)->first();

          if($media){
            $media_name = $media->media_name;
          }

          if($short_url){
            $link = $short_url->url;
          }

          if($user->email != ''){
            $this->sendNotificatioByEmail($user->email, $ad->id, $ad->slug, $link, $ad->media_name, $ad->title, $ad->address);
            //Log::info('sent email notification '.$user->email);
          }

          if($user->phone != ''){
            $to = '+1'.str_replace("-","",$user->phone);
            $this->sendNotificatioBySMS($user->phone, $ad->id, $ad->slug, $link);
            //Log::info('sent sms notification '.$user->email);
          }
        }

      }
    }
  }

  public function performDistanceRangeNotification($ad){
    //Log::info('start user distance range notification');
    ////echo $ad->zipcode.'<br>';

    //check notification table
    $notif = Notification::where('loc_range', '!=', '0')->get();

    if($notif){
      foreach($notif as $n){
        ////echo 'check for user '.$n->email.'<br>';
        $db = '';
        $user_mail = '';
        $user = '';

        //search for zip in the us/ca zip code tables
        if($n->country_id == '231'){
          $db = 'us_zip';
          $current_location = UsZip::where('zip', '=', $n->zip_id)->first();
        }else{
          $db = 'ca_zip';
          $current_location = CaZip::where('zip', '=', $n->zip_id)->first();
        }

        if($current_location){
          $zip =  $current_location->zip;
          $lat = $current_location->latitude;
          $long = $current_location->longitude;

          //calculate and get zip codes
          $nearby_locations = DB::select("SELECT id, zip, city, latitude, longitude, SQRT(POW(69.1 * (latitude - ?), 2) + POW(69.1 * (? - longitude) * COS(latitude / 57.3), 2)) AS distance FROM ".$db." HAVING distance < ? ORDER BY distance", [$lat, $long, $n->loc_range]);

          if($nearby_locations){
            foreach($nearby_locations as $nl){
              ////echo 'zip to check '.$nl->zip.'<br>';
              //search for ads with zip codes found
              if($ad->zipcode == $nl->zip){
                ////echo 'found '.$ad->id.' '.$nl->zip.' for user '.$n->email. ' will email...<br>';

                $user = User::where('email', '=', $n->email)->where('active_status', '=', '1')->where('user_type', '=', 'user')->where('sms_notify', '=', '1')->first();

                //dd($user);
                ////echo 'mail to '.$user->email;

                if($user){
                  //echo 'mail to '.$user->email;
                  $media = Media::where('ad_id', '=', $ad->id)->first();
                  $short_url = ListingShortUrl::where('listing_id', '=', $ad->id)->first();

                  if($media){
                    $media_name = $media->media_name;
                  }

                  if($short_url){
                    $link = $short_url->url;
                  }

                  if($user->email != ''){
                    $this->sendNotificatioByEmail($user->email, $ad->id, $ad->slug, $link, $ad->media_name, $ad->title, $ad->address);
                    //Log::info('sent email notification '.$user->email);
                    ////echo 'user '.$n->email.' sent email';
                  }

                  if($user->phone != ''){
                    $to = '+1'.str_replace("-","",$user->phone);
                    $this->sendNotificatioBySMS($user->phone, $ad->id, $ad->slug, $link);
                    //Log::info('sent sms notification '.$user->email);
                  }
                }
              }
            }
          }

        }
      }
    }
  }

  public function internalCurl($terms, $user_id){
    $terms = htmlspecialchars_decode($terms);
    $url = url('/search');
    parse_str($terms.'&from=ctrl', $output);
    $req = Request::create($url, 'GET', $output);
    $res = app()->handle($req);
    $resAd = json_decode($res->getContent());

    if(isset($resAd->total) && $resAd->total >= 1){

      foreach($resAd->data as $ad){
        $ad_id = $ad->id;
        $slug = $ad->slug;
        $media = Media::where('ad_id', '=', $ad_id)->where('type', '=', 'image')->first();
        $title = $ad->title;
      }

      $link = url('listing').'/'.$ad_id.'/'.$slug;
      $tiny_url = ListingShortUrl::where('listing_id', '=', $ad_id)->first();
      $user = User::whereId($user_id)->first();
      $name = $user->name;
      $email = $user->email;
      $image = $media->media_name;
      $this->sendSavedSearchNotification($name, $email, $link, $image, $title);

      if($user->phone){
        $this->sendSmsSeach($user->phone, $tiny_url->url);
      }

      return '1';
    }

    return '0';
  }

  public function sendSavedSearchNotification($name, $email, $link, $image, $title){
    $email = array($email);
    //$image = http://127.0.0.1:8000/uploads/images/1550681731phsea-single-family-house-475877-1280.jpg
    Mail::send('emails.notification_save_search', ['name' => $name, 'email' => $email, 'link' => $link, 'image' => $image, 'title' => $title], function ($message) use ($email)
    {
      $message->from('info@tymbl.com','Tymbl Team');
      $message->to($email);
      $message->subject("New referral available in your area on Tymbl!");
    }
  );
}

public function sendSmsSeach($phone, $link){

  $notification_text = 'New referral available in your area! Act Now and reserve it on Tymbl! '.$link;
  $sid = config('app.twilio')['account_sid'];
  $token = config('app.twilio')['app_token'];
  $from = config('app.twilio')['from_num'];
  $to = '+1'.str_replace("-","",$phone);
  $client = new Client($sid, $token);

  try {
    $message = $client->messages
    ->create($to,
    array(
      "body" => $notification_text,
      "from" => '+1'.$from
    )
  );
} catch (\Exception $e){
  if($e->getCode() == 21211)
  {
    $message = $e->getMessage();
    ////Log::info('SMS error (Notification)'.$message);
  }
}
}

public function sendNotificatioByEmail($email_address, $ad_id, $ad_slug, $url, $media_name, $list_title, $address){
  $email = array($email_address);
  Mail::send('emails.notification', ['url' => $url, 'media_name' => $media_name, 'list_title' => $list_title, 'address' => $address], function ($message) use ($email)
  {
    $message->from('info@tymbl.com','Tymbl Team');
    $message->to($email);
    $message->subject("New listing available in your area.");
  }
);
}

public function sendNotificatioBySMS($phone, $ad_id, $ad_slug, $url){

  $notification_text = 'ACT NOW! There is a new referral in your area available on Tymbl. '.$url;

  $sid = config('app.twilio')['account_sid'];
  $token = config('app.twilio')['app_token'];
  $from = config('app.twilio')['from_num'];
  $to = '+1'.preg_replace('/\D/', '', $phone);
  $client = new Client($sid, $token);

  try {
    $message = $client->messages
    ->create($to,
    array(
      "body" => $notification_text,
      "from" => '+1'.$from
    )
  );
} catch (\Exception $e){
  if($e->getCode() == 21211)
  {
    $message = $e->getMessage();
    ////Log::info('SMS error (Notification)'.$message);
  }
}
}

public function sendRegistrationActivation(){
  $users = DB::table('users')->join('notification_new_registrations', 'users.id', '=', 'notification_new_registrations.user_id')->where('notification_new_registrations.notified', '=', '0')->where('users.active_status', '=', '0')->get();

  if($users){
    foreach($users as $user){

      if($user->user_type == 'user'){
        if($user->activation_code != ''){
          if($user->email){
            $this->sendWelcomeEmail($user);
            ////Log::info('Registration email sent');
          }
        }

        $notify_user = NotificationNewRegistration::where('user_id', $user->user_id)->first();
        $notify_user->notified = '1';
        $notify_user->save();
      }

    }
  }
}

public function sendSMS($user){
  $sid = config('app.twilio')['account_sid'];
  $token = config('app.twilio')['app_token'];
  $from = config('app.twilio')['from_num'];
  $to = '+1'.preg_replace('/\D/', '', $user->phone);
  $client = new Client($sid, $token);

  try {
    $message = $client->messages
    ->create($to,
    array(
      "body" => "Welcome to Tymbl. Your verification code is: ".$user->sms_activation_code,
      "from" => '+1'.$from
    )
  );
} catch (\Exception $e){
  if($e->getCode() == 21211)
  {
    $message = $e->getMessage();
    ////Log::info('SMS error'.$message);
  }
}

}

public function sendWelcomeEmail($user){
  $mail = Mail::send('emails.welcome', ['user' => $user], function ($m) use ($user) {
    $m->from('info@tymbl.com', 'Tymbl Team');
    $m->to($user->email, $user->name)->subject('Please activate your Tymbl account');
  });
}

//end here
}
