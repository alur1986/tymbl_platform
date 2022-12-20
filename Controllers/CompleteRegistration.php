<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CompleteRegistration extends Controller
{
    public function stepTwo(){
    	return view('tymbl.complete_registration');
    }

    //Default thank you page
    public function verify(){
      return view('tymbl.verify_registration');
    }
}
