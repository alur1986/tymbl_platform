<?php

namespace App\Http\Controllers;

use App\SocialAccountService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Session;
use App\Http\Requests;
use Laravel\Socialite\Facades\Socialite;
use App\UserTitleCompanyInfo;
use App\NotificationNewRegistration;
use App\NotificationsUsers;
use App\Users;

class SocialLogin extends Controller
{

  public function redirectFacebook(){
    return Socialite::driver('facebook')->redirect();
  }

  public function callbackFacebook(SocialAccountService $service){
    try {
      $fb_user = Socialite::driver('facebook')->user();
      $user = $service->createOrGetFBUser($fb_user);
      if (!$user){
        return redirect(route('facebook_redirect'));
      }

      $notification_new_reg = NotificationNewRegistration::where('user_id', '=', $user->id)->first();
      $userid = UserTitleCompanyInfo::where('user_id', '=', $user->id)->first();

      if(!$userid){
        //send thank you email
        if(!$notification_new_reg){
          if($user->email){
            //send thank you email
            $this->sendEmail($user);
            $nnr = [
              'user_id' => $user->id,
              'notified' => '1',
            ];
            $newreg = NotificationNewRegistration::create($nnr);
          }
        }

        //https://tymbl.com/dashboard/u/posts/profile
        auth()->login($user);

        if($notification_new_reg){
          if($user->user_type =='admin'){
            return redirect(route('dashboard'));
          }
          return redirect()->route('profile_edit')->with('success', 'Please complete the profile if you want to reserve or post referrals');
        }else{
          if($user->phone == '' || $user->country_id == '' || $user->state_id == '' || $user->city_id == ''){
            return redirect()->route('profile_edit')->with('success', 'Thank you for registering! Please complete the profile if you want to reserve or post referrals');
          }else{
            return redirect(route('home'));
          }
        }

      }else{
        auth()->login($user);
        return redirect(route('home'));
      }

    } catch (\Exception $e){
      return redirect(route('login'))->with('error', $e->getMessage());
    }
  }

  public function redirectGoogle(){
    return Socialite::driver('google')->redirect();
  }

  public function callbackGoogle(SocialAccountService $service){
    try {
      $google_user = Socialite::driver('google')->user();
      $user = $service->createOrGetGoogleUser($google_user);
      if (!$user){
        return redirect(route('google_redirect'));
      }

      $notification_new_reg = NotificationNewRegistration::where('user_id', '=', $user->id)->first();
      $userid = UserTitleCompanyInfo::where('user_id', '=', $user->id)->first();

      if(!$userid){
        //send thank you email
        if(!$notification_new_reg){
          if($user->email){
            //send thank you email
            $this->sendEmail($user);
            $nnr = [
              'user_id' => $user->id,
              'notified' => '1',
            ];
            $newreg = NotificationNewRegistration::create($nnr);
          }
        }

        auth()->login($user);

        if($notification_new_reg){
          if($user->user_type =='admin'){
            return redirect(route('dashboard'));
          }
          return redirect()->route('profile_edit')->with('success', 'Please complete the profile if you want to reserve or post referrals');
        }else{
          if($user->phone == '' || $user->country_id == '' || $user->state_id == '' || $user->city_id == ''){
            return redirect()->route('profile_edit')->with('success', 'Thank you for registering! Please complete the profile if you want to reserve or post referrals');
          }else{
            return redirect(route('home'));
          }
        }

      }else{
        auth()->login($user);
        return redirect(route('home'));
      }

    } catch (\Exception $e){
      //return $e->getMessage();
      return redirect(route('login'))->with('error', $e->getMessage());
    }
  }

  public function redirectTwitter(){
    return Socialite::driver('twitter')->redirect();
  }

  public function callbackTwitter(SocialAccountService $service){
    try {
      $twitter_user = Socialite::driver('twitter')->user();
      $user = $service->createOrGetTwitterUser($twitter_user);
      if (!$user){
        return redirect(route('twitter_redirect'));
      }

      $notification_new_reg = NotificationNewRegistration::where('user_id', '=', $user->id)->first();
      $userid = UserTitleCompanyInfo::where('user_id', '=', $user->id)->first();

      if(!$userid){
        //send thank you email
        if(!$notification_new_reg){
          if($user->email){
            //send thank you email
            $this->sendEmail($user);
            $nnr = [
              'user_id' => $user->id,
              'notified' => '1',
            ];
            $newreg = NotificationNewRegistration::create($nnr);
          }
        }

        auth()->login($user);
        if($notification_new_reg){
          if($user->user_type =='admin'){
            return redirect(route('dashboard'));
          }
          return redirect()->route('profile_edit')->with('success', 'Please complete the profile if you want to reserve or post referrals');
        }else{
          if($user->phone == '' || $user->country_id == '' || $user->state_id == '' || $user->city_id == ''){
            return redirect()->route('profile_edit')->with('success', 'Thank you for registering! Please complete the profile if you want to reserve or post referrals');
          }else{
            return redirect(route('home'));
          }
        }

      }else{
        auth()->login($user);
        return redirect(route('home'));
      }

    } catch (\Exception $e){
      //return $e->getMessage();
      return redirect(route('login'))->with('error', $e->getMessage());
    }
  }

  public function sendEmail($user){
    Mail::send('emails.welcome_social', ['user' => $user], function ($m) use ($user) {
      $m->from('info@tymbl.com', 'Tymbl Team');
      $m->to($user->email, $user->name)->subject('Thanks for joining Tymbl');
    });
  }
}
