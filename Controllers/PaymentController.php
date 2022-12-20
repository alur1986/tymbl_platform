<?php

namespace App\Http\Controllers;

use App\Ad;
use App\Payment;
use Illuminate\Http\Request;
use App\User;
use App\Http\Requests;
use Illuminate\Support\Facades\Auth;
use Yajra\Datatables\Datatables;
use App\NotificationsUsers;
use App\UserTitleCompanyInfo;
use App\Broker;

class PaymentController extends Controller
{

    public function index(){
        $title = trans('app.payments');
        $user = Auth::user()->id;
        $notifications_users = NotificationsUsers::where('recipient', '=', $user)->where('user_read', '=', '0')->get();
        $user_notification_count = count($notifications_users);
        return view('tymbl.dashboard.payments', compact('title', 'notifications_users', 'user_notification_count'));
    }
    public function paymentsData(){
        $user = Auth::user();
        if ($user->is_admin()){
            $payments = Payment::select('id','ad_id', 'user_id', 'amount','payment_method', 'status','local_transaction_id', 'created_at')->with('ad', 'user')->orderBy('id', 'desc')->get();

        }else{
            $payments = Payment::select('id','ad_id', 'user_id', 'amount','payment_method', 'status','local_transaction_id', 'created_at')->whereUserId($user->id)->with('ad', 'user')->orderBy('id', 'desc')->get();
        }

        return  Datatables::of($payments)

            ->editColumn('ad_id', function($payment){
                if ($payment->ad)
                return '<a href="'.route('single_ad', [$payment->ad->id, $payment->ad->slug]).'" target="_blank">'.$payment->ad->title.'</a>';
            })
            ->editColumn('user_id', function($payment){
                if ($payment->user){
                    return '<a href="'.route('user_info', $payment->user->id).'"  target="_blank"> '.$payment->user->name.'</a>';
                }
                return trans('app.no_user');
            })
            ->editColumn('status', function($payment){
                return '<a href="'.route('payment_info', $payment->local_transaction_id).'"  target="_blank"> '.$payment->status.'</a>';
            })
            ->editColumn('created_at',function($user){
                return $user->created_at_datetime();
            })
            ->removeColumn('local_transaction_id')
            ->make();
    }

    public function paymentInfo($tran_id){
        $payment = Payment::where('local_transaction_id', $tran_id)->first() ;

        if (!$payment){
            return view('admin.error.error_404');
        }

        $title = trans('app.payment_info');
        return view('tymbl.dashboard.payment_info', compact('title', 'payment'));

    }

    /**
     * @param $transaction_id
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     *
     * Checkout Method
     */

    public function checkout($transaction_id){
        $payment = Payment::whereLocalTransactionId($transaction_id)->whereStatus('initial')->first();
        $title = trans('app.checkout');
        if ($payment){
            return view('checkout', compact('title','payment'));
        }
        return view('invalid_transaction', compact('title','payment'));
    }

    /**
     * @param Request $request
     * @param $transaction_id
     * @return array
     *
     * Used by Stripe
     */

    public function chargePayment(Request $request, $transaction_id){
        $payment = Payment::whereLocalTransactionId($transaction_id)->whereStatus('initial')->first();
        $ad = $payment->ad;

        $first_name = $ad->seller_name;
        $last_name = null;
        $payer_email = $ad->payer_email;
        if (Auth::check()){
            $first_name = $ad->user->first_name;
            $last_name = $ad->user->last_name;
        }

        //Determine which payment method is this
        if ($payment->payment_method == 'paypal') {

            // PayPal settings
            $paypal_action_url = "https://www.paypal.com/cgi-bin/webscr";
            if (get_option('enable_paypal_sandbox') == 1){
                $paypal_action_url = "https://www.sandbox.paypal.com/cgi-bin/webscr";
            }

            $paypal_email = get_option('paypal_receiver_email');
            $return_url = route('payment_success_url', $transaction_id);
            $cancel_url = route('payment_checkout', $transaction_id);
            $notify_url = route('paypal_notify_url', $transaction_id);

            $item_name = $payment->ad->title." (".ucfirst($payment->ad->price_plan).") ad posting";

            // Check if paypal request or response
            $querystring = '';

            // Firstly Append paypal account to querystring
            $querystring .= "?business=".urlencode($paypal_email)."&";

            // Append amount& currency (£) to quersytring so it cannot be edited in html
            //The item name and amount can be brought in dynamically by querying the $_POST['item_number'] variable.
            $querystring .= "item_name=".urlencode($item_name)."&";
            $querystring .= "amount=".urlencode($payment->amount)."&";
            $querystring .= "currency_code=".urlencode($payment->currency)."&";

            $querystring .= "first_name=".urlencode($first_name)."&";
            $querystring .= "last_name=".urlencode($last_name)."&";

            //$querystring .= "payer_email=".urlencode($ad->user->email)."&";
            $querystring .= "payer_email=".urlencode($payer_email)."&";
            $querystring .= "item_number=".urlencode($payment->local_transaction_id)."&";

            //loop for posted values and append to querystring
            foreach(array_except($request->input(), '_token') as $key => $value){
                $value = urlencode(stripslashes($value));
                $querystring .= "$key=$value&";
            }

            // Append paypal return addresses
            $querystring .= "return=".urlencode(stripslashes($return_url))."&";
            $querystring .= "cancel_return=".urlencode(stripslashes($cancel_url))."&";
            $querystring .= "notify_url=".urlencode($notify_url);

            // Append querystring with custom field
            //$querystring .= "&custom=".USERID;

            // Redirect to paypal IPN
            header('location:'.$paypal_action_url.$querystring);
            exit();

        }elseif ($payment->payment_method == 'stripe'){

            $stripeToken = $request->stripeToken;
            \Stripe\Stripe::setApiKey(get_stripe_key('secret'));
            // Create the charge on Stripe's servers - this will charge the user's card
            try {
                $charge = \Stripe\Charge::create(array(
                    "amount" => ($payment->amount * 100), // amount in cents, again
                    "currency" => $payment->currency,
                    "source" => $stripeToken,
                    "description" => $payment->ad->title." (".ucfirst($payment->ad->price_plan).") ad posting"
                ));

                if ($charge->status == 'succeeded'){
                    $payment->status = 'success';
                    $payment->charge_id_or_token = $charge->id;
                    $payment->description = $charge->description;
                    $payment->payment_created = $charge->created;
                    $payment->save();

                    //Set publish ad by helper function
                    //Approved ads
                    $ad->status = '1';
                    $ad->save();

                    return ['success'=>1, 'msg'=> trans('app.payment_received_msg')];
                }
            } catch(\Stripe\Error\Card $e) {
                // The card has been declined
                $payment->status = 'declined';
                $payment->description = trans('app.payment_declined_msg');
                $payment->save();
                return ['success'=>0, 'msg'=> trans('app.payment_declined_msg')];
            }
        }

        return ['success'=>0, 'msg'=> trans('app.error_msg')];
    }

    /**
     * @param Request $request
     * @param $transaction_id
     * @return mixed
     *
     * Payment success url receive from paypal
     */

    public function paymentSuccess(Request $request, $transaction_id = null){
        $title = trans('app.payment_success');
        return view('tymbl.payment_success', compact('title'));
    }

    /**
     * @param Request $request
     * @param $transaction_id
     * @return mixed
     *
     * Ipn notify, receive from paypal
     */
    public function paypalNotify(Request $request, $transaction_id){
        $payment = Payment::whereLocalTransactionId($transaction_id)->where('status','!=','success')->first();
        $ad = $payment->ad;

        $verified = paypal_ipn_verify();
        if ($verified){
            //Payment success, we are ready approve your payment
            $payment->status = 'success';
            $payment->charge_id_or_token = $request->txn_id;
            $payment->description = $request->item_name;
            $payment->payer_email = $request->payer_email;
            $payment->payment_created = strtotime($request->payment_date);
            $payment->save();

            //Approved ads
            $ad->status = '1';
            $ad->save();

            //Sending Email...

        }else{
            $payment->status = 'declined';
            $payment->description = trans('app.payment_declined_msg');
            $payment->save();
        }
        // Reply with an empty 200 response to indicate to paypal the IPN was received correctly
        header("HTTP/1.1 200 OK");
    }


    public function processNoPayment(Request $request){

      if(!Auth::check()){
        return redirect('login')->with('error', 'Please login to continue.');
      }

      $user = User::whereId(Auth::user()->id)->first();

      if($user->user_type == 'user'){
        if($user->phone == '' || $user->country_id == '' || $user->address == ''){
          return redirect()->route('profile')->with('error', 'Please complete your profile to proceed.');
        }else{
          $broker = Broker::where('user_id', '=', $user->id)->first();
          if(!$broker){
            return redirect(route('profile_edit'))->with('error', 'Complete your profile before you create a lead.');
          }
        }
      }

      $ad_id = $request->ref_id;
      $ad = Ad::whereId($ad_id)->first();

      if($user->id == $ad->user_id){
        return redirect()->back()->with('error', 'You are not allowed to reserve your own lead');
      }elseif(Auth::user()->user_type == 'admin'){
        return redirect()->back()->with('error', 'You are admin.');
      }

      $reservation_fee = '0.00';
      $currency = $ad->country_id == '231' ? 'USD$' : 'CAD$';
      $currency = $ad->country_id == '231' ? 'USD$' : 'CAD$';
      $amount = $currency.$reservation_fee;

      //TBL + current date/time + ad id
      $transaction_id = 'TBL'.strtotime('now').'-'.$ad_id;
      $data = ['amount' => $reservation_fee, 'total' => $reservation_fee, 'payment_method' => 'free', 'status' => 'initial', 'currency' => $currency, 'local_transaction_id' => $transaction_id];
      //dd($data);

      $payment = Payment::firstOrCreate(['ad_id' => $ad_id], $data);
      //$media = Media::where('ad_id', $ad_id)->orderBy('id', 'desc')->get();
      //dd($media);

      $transaction_id = $payment->local_transaction_id;
      //Session::put('local_transaction_id', $transaction_id);
      //return view('tymbl.pay', compact('title', 'ad', 'media', 'amount', 'transaction_id'));

      return $this->paymentFreeSuccess($ad_id, $transaction_id, $ad->seler_email);
    }

    public function processNoPaymentTest(Request $request){

      //$title_company = UserTitleCompanyInfo::where('user_id', '=', Auth::user()->id)->first();
      $user = User::where('email', '=', $request->user_email)->first();

      if(!$user->user_type == 'admin'){
        return '404';
      }

      $ad_id = $request->ref_id;
      $ad = Ad::whereId($ad_id)->first();

      $escrow_amount = '0.00';
      $currency = $ad->country_id == '231' ? 'USD$' : 'CAD$';
      $total_cost = $escrow_amount;
      $currency = $ad->country_id == '231' ? 'USD$' : 'CAD$';
      $amount = $currency.$escrow_amount;

      //TBL + current date/time + ad id
      $transaction_id = 'TBL'.strtotime('now').'-'.$ad_id;
      $data = ['amount' => $escrow_amount, 'total' => $total_cost, 'payment_method' => 'free', 'status' => 'initial', 'currency' => $currency, 'local_transaction_id' => $transaction_id];
      //dd($data);

      $payment = Payment::firstOrCreate(['ad_id' => $ad_id], $data);
      //$media = Media::where('ad_id', $ad_id)->orderBy('id', 'desc')->get();
      //dd($media);

      $transaction_id = $payment->local_transaction_id;
      //Session::put('local_transaction_id', $transaction_id);
      //return view('tymbl.pay', compact('title', 'ad', 'media', 'amount', 'transaction_id'));

      //return $this->paymentFreeSuccess($ad_id, $transaction_id, $ad->seler_email);

      $random_chars = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
      //tnp = tymbl no payment
      $payment_id = 'TNP'.substr(str_shuffle($random_chars), 0, 18);

      $listing_id = Payment::where('local_transaction_id', $transaction_id)->first();

      $paypal_email = $ad->seler_email;
      $pay = Payment::where('local_transaction_id', $transaction_id)->update(['status' => 'success', 'user_id' => $user->id, 'charge_id_or_token' => $payment_id, 'payer_email' => $paypal_email]);
      $payment_id = $payment_id;

      if(!$pay){
        return '404';
      }

      return '200';

    }


    public function paymentFreeSuccess($ad_id, $transaction_id, $seller_email){

      $random_chars = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
      //tnp = tymbl no payment
      $payment_id = 'TNP'.substr(str_shuffle($random_chars), 0, 18);

      $user_id = Auth::user()->id;
      $listing_id = Payment::where('local_transaction_id', $transaction_id)->first();

      $paypal_email = $seller_email;
      $pay = Payment::where('local_transaction_id', $transaction_id)->update(['status' => 'success', 'user_id' => $user_id, 'charge_id_or_token' => $payment_id, 'payer_email' => $paypal_email]);
      $payment_id = $payment_id;
      $title = "Referral Aggreement";

      return redirect()->action('AdsController@loadReferralAgreement', ['id' => $ad_id]);
    }

}
