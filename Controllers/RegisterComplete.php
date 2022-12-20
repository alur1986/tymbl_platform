<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\User;
use App\UserTitleCompanyInfo;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;

class RegisterComplete extends Controller
{
    public function addInfo(Request $request){

    	$user = Auth::user();

        $data = $this->validate($request, [
            'company_name' => 'required',
            'representative_name' => 'required',
            'representative_email' => 'required',
        ]);

         $user->fill([
            'phone' => $request['phone'],
            'address' => $request['address'],
            'fax' => $request['fax'],
            're_license_number' => $request['re_license_number'],
        ]);

        $user->save();

        $title_company = UserTitleCompanyInfo::create([
          'user_id' => $user->id,
          'company_name' => $data['company_name'],
          'representative_name' => $data['representative_name'],
          'representative_email' => $data['representative_email']
        ]);

        return redirect('dashboard')->with('success', 'Profile has been updated!!');
    }

    public function verifySmsCode(Request $request){
      $user = User::where('sms_activation_code',$request['sms_activation_code']) -> first();
      if($user){
        $user->sms_activation_code='';
        $user->is_sms_verified = '1';
        $user->active_status = '1';
        $user->save();
  
        return redirect('login')->with('success', 'Account activated. Please login.');
      }else{
        return redirect('login/verify')->with('error', 'Either you have entered an invalid code or your account already activated.');
      }

    }
}
