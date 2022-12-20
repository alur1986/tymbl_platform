<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\User;
use Illuminate\Foundation\Auth\AuthenticatesUsers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;
use URL;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;


class LoginController extends Controller
{
  /*
  |--------------------------------------------------------------------------
  | Login Controller
  |--------------------------------------------------------------------------
  |
  | This controller handles authenticating users for the application and
  | redirecting them to your home screen. The controller uses a trait
  | to conveniently provide its functionality to your applications.
  |
  */

  use AuthenticatesUsers;

  public function login(Request $request)
  {
    $request->session()->put('prev_url', url()->previous());
    $this->validateLogin($request);

    //Check if active account
    $user = User::whereEmail($request->email)->first();
    if ($user){
      if ($user->active_status != '1'){
        return redirect()->back()->with('error', trans('app.user_account_wrong'));
      }
    }

    // If the class is using the ThrottlesLogins trait, we can automatically throttle
    // the login attempts for this application. We'll key this by the username and
    // the IP address of the client making these requests into this application.
    if ($this->hasTooManyLoginAttempts($request)) {
      $this->fireLockoutEvent($request);

      return $this->sendLockoutResponse($request);
    }

    if ($this->attemptLogin($request)) {
      return $this->sendLoginResponse($request);
    }

    // If the login attempt was unsuccessful we will increment the number of attempts
    // to login and redirect the user back to the login form. Of course, when this
    // user surpasses their maximum number of attempts they will get locked out.
    $this->incrementLoginAttempts($request);
    return $this->sendFailedLoginResponse($request);
  }

  /**
  * Where to redirect users after login.
  *
  * @var string
  */
  protected $redirectTo = '/';
  /**
  * Create a new controller instance.
  *
  * @return void
  */
  public function __construct()
  {
    session(['url.intended' => url()->previous()]);
    $this->redirectTo = Session::get('url.intended');
    $this->middleware('guest')->except('logout');
  }

  protected function authenticated(Request $request, $user)
  {
    //if(Session::get('url.intended') === ''){
    //return redirect('/');
    //}else{
    //return redirect()->intended(Session::get('url.intended'))->with('message', 'ga_login');
    //}
    $url = explode('/', Session::get('url.intended'));

    $user->update([
        'last_login' => Carbon::now(),
        
    ]);

   
    if(!in_array('login', $url) && !in_array('verify', $url)){
      return redirect()->intended(Session::get('url.intended'))->with('message', 'ga_login');
    }else{
      return redirect(route('dashboard'));
    }

  }

  protected function redirectTo(){
    return url()->previous();
  }

  public function logout()
  {
    Session::flush();
    Auth::logout();

    return redirect('/');
  }
}
