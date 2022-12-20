<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\ListingContracts;
use App\Ad;
use App\City;
use App\State;
use App\User;
use App\Media;
use App\UserTitleCompanyInfo;
use LaravelEsignatureWrapper;
use App\ReferralContactInfo;
use Illuminate\Support\Facades\Mail;
use App\Jobs\ProcessSendEmail;
use App\Jobs\ProcessSendEmailByTc;
use App\TcApprovalSenderTaskModel;
use App\Http\Controllers\PaypalGatewayController;
use App\Payouts;
use App\Payment as LP;
use App\NotificationsUsers;
use App\TransactionReports;
use App\TitleCompanyInfoLTB;
use Illuminate\Support\Facades\Auth;
use App\Http\Controllers\ContractSigned;

class TitleCompanyController extends Controller
{
  public function loginByCode($id){
    return view('auth.tymbl_title_company', compact('title', 'id', 'type'));
  }

  public function validateCode(Request $request){
    $contract_id = base64_decode($request->id);
    $code_id = $request->code;
    $valid_contract = ListingContracts::where([['user_contract_id','=',$contract_id],['code_id','=', $code_id], ['list_status', '=', '0']])->first();

    if(!$valid_contract){
      return redirect()->back()->with('error', 'Invalid Code')->withInput();
    }else{
      return redirect('title-company/approve/'.$request->id);
    }
  }

  public function approveContract($id){
    $contract_id = base64_decode($id);
    $valid_contract = ListingContracts::where('user_contract_id', $contract_id)->where('list_status', '0')->first();
    $ad = Ad::whereId($valid_contract->listing_id)->first();
    $state_name = State::whereId($ad->state_id)->first();
    $city_name= City::whereId($ad->city_id)->first();
    $state = $state_name->state_name;
    $city = $city_name->city_name;
    $user = User::whereId($ad->user_id)->first();
    $media = Media::where('ad_id', $ad->id)->first();
    return view('auth.tymbl_title_company_approve', compact('title', 'id', 'type', 'ad', 'valid_contract', 'user', 'state', 'city', 'media'));
  }

  public function approveContractByTitle(Request $request){

    $updated_status = ListingContracts::where('contract_id', '=', $request->id)->update(array('list_status' => $request->status));
    $updated_contract = ListingContracts::where('contract_id', '=', $request->id)->first();
    $listing = Ad::whereId($updated_contract->listing_id)->first();
    $listing_seller_id = $listing->user_id;
    $listing_seller = User::whereId($listing_seller_id)->first();
    $buyer = User::whereId($updated_contract->buyer_id)->first();
    $email = $buyer->email;
    $title_company = UserTitleCompanyInfo::where('user_id', $buyer->id)->first();

    if($request->status === '1'){
      $subject = 'Reservation for #'.$listing->id.' was approved';
      $seller = $buyer->name;
      $reason = "approved";
      $url = url('ad/'.$listing->id.'/'.$listing->slug);
      $listing_name = $listing->title;
      $contract_id = $updated_contract->contract_id;
      $title_company_name = $title_company->company_name;
      $repost = '0';

      $data = ['contract_id' => $listing->id,
      'email_sent_to_buyer' => '0',
      'email_sent_to_seller' => '0',
      'reason' => '1'
    ];

    $tastm = TcApprovalSenderTaskModel::create($data);

    //$notif_to_buyer = "<p>Here a status of your reservation: ".$listing->title." [id:".$listing->id."]</p>";
    //$notif_to_buyer .= "<p>Status: Reservation Approved</p>";
    //$notif_to_buyer .= "<p>Signed on: ".$tastm->created_at."</p>";

    $notif_to_buyer = $this->constructMessageBodyReservation($listing->id, $listing->title, $reason, 'seller');

    $notif = [
      'type' => 'reminder',
      'recipient' => $buyer->id,
      'sender'  => '0',
      'subject' => $subject,
      'contents' => $notif_to_buyer,
      'user_read' => '0'
    ];

    $nofitication = NotificationsUsers::create($notif);

    $transaction_report = [
      'ad_id' => $listing->id,
      'status' => 'Title Company approved the referral',
    ];

    $tr = TransactionReports::create($transaction_report);
  }else{
    $subject = "Reservation for '.$listing->id.'was not approved";
    $seller = $listing_seller->name;
    $reason = "rejected";
    $url = url('ad/'.$listing->id.'/'.$listing->slug);
    $listing_name = $listing->title;
    $contract_id = $updated_contract->contract_id;
    $title_company_name = $title_company->company_name;
    $repost = '1';

    $data = ['contract_id' => $listing->id,
    'email_sent_to_buyer' => '0',
    'email_sent_to_seller' => '0',
    'reason' => '0'
  ];

  $tasm = TcApprovalSenderTaskModel::create($data);
  //$notif_to_buyer = "<p>Here a status of your reservation: ".$listing->title." [id:".$listing->id."]</p>";
  //$notif_to_buyer .= "<p>Status: Reservation Not Approved</p>";
  //$notif_to_buyer .= "<p>Signed on: ".$tastm->created_at."</p>";

  $notif_to_buyer = $this->constructMessageBodyReservation($listing->id, $listing->title, $reason, 'buyer');

  $notif = [
    'type' => 'reminder',
    'recipient' => $listing->user_id,
    'sender'  => '0',
    'subject' => $subject,
    'contents' => $notif_to_buyer,
    'user_read' => '0'
  ];

  $nofitication = NotificationsUsers::create($notif);

  $transaction_report = [
    'ad_id' => $listing->id,
    'status' => 'Title Company did not approve the referral',
  ];

  $tr = TransactionReports::create($transaction_report);
}
return $updated_status;
}

public function constructMessageBodyReservation($listing_id, $listing_title, $reason, $user_type){


  $status = $reason == 'approved' ? 'Approved' : 'Not Approved';
  $link = $user_type == 'buyer' ? '<a href="/dashboard/reserved-leads">View Transaction</a>' : '<a href="/dashboard/lead-status">View Transaction</a>';

  $notif = '<p>The referral for #'.$listing_id.' was <b>'.$reason.'</p>';
  $notif .= '<p>Listing Status: '.$link.'</p>';

  return $notif;

}

public function approveContractStatus($id){
  if($id == '1'){
    $title = 'Reservation is approved';
    $status_text = 'Reservation is approved';
  }else{
    $title = 'Reservation is not approved';
    $status_text = 'Reservation was not approved';
  }
  return view('auth.tymbl_title_company_approve_status', compact('title', 'status_text'));
}

public function sendListingApprovalByTc($status, $seller, $reason, $url, $contract_id, $recipient, $referral_name, $listing_name, $repost){

  $sendEmailJob = (new ProcessSendEmailByTc($seller, $referral_name, $recipient, $reason, $url, $contract_id, $listing_name, $repost));
  $this->dispatch($sendEmailJob);

}

public function sendListingApprovalToSellers($status, $seller, $reason, $url, $contract_id, $recipient, $referral_name, $listing_name, $repost){

  $sendEmailJob = (new ProcessSendEmailByTc($seller, $referral_name, $recipient, $reason, $url, $contract_id, $listing_name, $repost));
  $this->dispatch($sendEmailJob);

}

public function sendListingApprovalToBuyers($status, $seller, $reason, $url, $contract_id, $recipient, $referral_name, $listing_name, $repost){
  $sendEmailJob = (new ProcessSendEmailByTc($seller, $referral_name, $recipient, $reason, $url, $contract_id, $listing_name, $repost));
  $this->dispatch($sendEmailJob);
}

public function sendReminder(Request $request){

  if($request->id == '0'){
    $contract = ListingContracts::where('contract_id', $request->contract_id)->first();

    $post = Ad::whereId($contract->listing_id)->first();

    if($request->ltb == '1'){
      $listing = TitleCompanyInfoLTB::where('user_id', $contract->buyer_id)->first();
    }else{
      $listing = UserTitleCompanyInfo::where('user_id', $contract->buyer_id)->first();
    }

    $email = $listing->representative_email;
    $url = url('title-company/'.base64_encode($contract->user_contract_id));
    $code_id = $contract->code_id;

    //$updated_status = ListingContracts::where('contract_id', '=', $request->id)->update(array('list_status' => $request->status));
    //$updated_contract = ListingContracts::where('contract_id', '=', $request->id)->first();
    $buyer = User::whereId($contract->buyer_id)->first();

    //public function __construct($email, $url, $code_id, $title_company_name, $buyer_name, $subject)
    $sendEmailJob = (new ProcessSendEmail($email, $url, $code_id, $listing->company_name, $buyer->name, 'Listing Contract Has Been Approved'));
    $this->dispatch($sendEmailJob);

    return redirect()->back()->with('success', 'Message has been sent');

  }else{
    ListingContracts::where('contract_id', $request->contract_id)->update(['list_status' => '4']);
    $contract = ListingContracts::where('contract_id', $request->contract_id)->first();
    $ad = Ad::whereId($contract->listing_id)->update(['status' => '1']);
    return redirect()->back();
  }

}

//sent via cron job
public function sendApprovalNotification(){
  $contract_list = TcApprovalSenderTaskModel::where('email_sent_to_buyer', '0')->orWhere('email_sent_to_seller', '0')->get();

  if($contract_list){
    foreach($contract_list as $list){
      //get the ad
      $ad = Ad::whereId($list->contract_id)->first();

      if($ad){
        //get the contract
        $contract = ListingContracts::join('users', 'users.id', '=', 'listing_contracts.buyer_id')->where('listing_id', $list->contract_id)->first();
        //get the title company
        $title_company = UserTitleCompanyInfo::where('user_id', $contract->buyer_id)->first();
        $seller = User::whereId($ad->user_id)->first();


        //if sale status is 1 = successful
        if($list->reason == '1'){

          //check if email sent to seller
          if($list->email_sent_to_seller == '0'){
            $escrow = $ad->country_id == '231' ? 'USD$'.number_format($ad->escrow_amount, 2) : 'CAD$'.number_format($ad->escrow_amount, 2);

            //initiate seller payout
            $payout = new PaypalGatewayController;
            $payout->payoutSeller($ad->seller_email, $escrow, $ad->title, $list->contract_id);

            $payouts = Payouts::where('list_id', $list->contract_id)->first();
            $payout_sender_id = $contract->contract_id;
            $payout_date = date_format($contract->created_at,"Y/m/d");

            $subject = "Your Referral ".$ad->id." has been successful!";

            $this->sendEmailSaleStatusToSeller($ad->id, $ad->seller_email, $ad->title, $title_company->company_name, $list->reason, $escrow, $payout_sender_id, $payout_date, $contract->name, $subject,  date_format($contract->created_at,"Y/m/d"), $seller->name);
            $tastm = TcApprovalSenderTaskModel::where('contract_id', $list->contract_id)->update(['email_sent_to_seller' => '1']);


            $notif_to_buyer = "<p>Here a status of your reservation: ".$ad->title." [id:".$ad->id."]</p>";
            $notif_to_buyer .= "<p>Status: Reservation Successful</p>";
            $notif_to_buyer .= "<p>Signed on: ".$contract->created_at."</p>";

            $notif = [
              'type' => 'reminder',
              'recipient' => $ad->user_id,
              'sender'  => '0',
              'subject' => $subject,
              'contents' => $notif_to_buyer,
              'user_read' => '0'
            ];

            $nofitication = NotificationsUsers::create($notif);
            $transaction_report = [
              'ad_id' => $ad->id,
              'status' => 'Reservation successful',
            ];

            $tr = TransactionReports::create($transaction_report);

          }

          //check if email sent to buyer
          /*
          if($list->email_sent_to_buyer == '0'){

          $payouts = Payouts::where('list_id', $list->contract_id)->first();
          $payout_sender_id = $payouts->sender_id;
          $payout_date = $payouts->created_at;

          $this->sendEmailSaleStatusToBuyer($contract->email, $ad->title, $title_company->company_name, $list->reason, $escrow, $payout_sender_id, $payout_date, $contract->name);
          TcApprovalSenderTaskModel::where('contract_id', $list->contract_id)->update(['email_sent_to_buyer' => '1']);
        }
        */

      }else{
        //check if email sent to buyer
        $escrow = $ad->country_id == '231' ? 'USD$'.number_format($ad->escrow_amount, 2) : 'CAD$'.number_format($ad->escrow_amount, 2);
        $listing_id = LP::where('ad_id', $list->contract_id)->first();
        $sale_id = $listing_id->charge_id_or_token;

        //initiate refund
        $refund = new PaypalGatewayController;
        $refund->refundPayment($ad->seller_email, $escrow, $ad->title, $list->contract_id, $sale_id);

        $payouts = Payouts::where('list_id', $list->contract_id)->first();
        $payout_sender_id = $contract->contract_id;
        $payout_date = date_format($contract->created_at,"Y/m/d");
        $subject = "Referral ".$ad->id." did not result in a sale";

        if($list->email_sent_to_buyer == '0'){
          $this->sendEmailSaleStatusToBuyer($ad->id, $contract->email, $ad->title, $title_company->company_name, $list->reason, $escrow, $payout_sender_id, $payout_date, $contract->name, $subject,  date_format($contract->created_at,"Y/m/d"));
          $tastm = TcApprovalSenderTaskModel::where('contract_id', $list->contract_id)->update(['email_sent_to_buyer' => '1']);
          $ad = Ad::whereId($list->contract_id)->first();
          $ad->status = '1';
          $ad->save();

          $notif_to_buyer = "<p>Here a status of your reservation: ".$ad->title." [id:".$ad->id."]</p>";
          $notif_to_buyer .= "<p>Status: Referral Not Successful</p>";
          $notif_to_buyer .= "<p>Signed on: ".$contract->created_at."</p>";

          $notif = [
            'type' => 'reminder',
            'recipient' => $list->contract_id,
            'sender'  => '0',
            'subject' => $subject,
            'contents' => $notif_to_buyer,
            'user_read' => '0'
          ];

          $nofitication = NotificationsUsers::create($notif);
          $transaction_report = [
            'ad_id' => $ad->id,
            'status' => 'Referral not successful',
          ];

          $tr = TransactionReports::create($transaction_report);

        }

        //check if email sent to seller
        if($list->email_sent_to_seller == '0'){
          $this->sendEmailSaleStatusToSeller($ad->id, $ad->seller_email, $ad->title, $title_company->company_name, $list->reason, $escrow, $payout_sender_id, $payout_date, $contract->name, $subject,  date_format($contract->created_at,"Y/m/d"), $seller->name);
          $tastm = TcApprovalSenderTaskModel::where('contract_id', $list->contract_id)->update(['email_sent_to_seller' => '1']);

          $notif_to_buyer = "<p>Here a status of your reservation: ".$ad->title." [id:".$ad->id."]</p>";
          $notif_to_buyer .= "<p>Status: Referral Not Successful</p>";
          $notif_to_buyer .= "<p>Signed on: ".$tastm->created_at."</p>";

          $notif = [
            'type' => 'reminder',
            'recipient' => $ad->user_id,
            'sender'  => '0',
            'subject' => $subject,
            'contents' => $notif_to_buyer,
            'user_read' => '0'
          ];

          $nofitication = NotificationsUsers::create($notif);

          $transaction_report = [
            'ad_id' => $ad->id,
            'status' => 'Referral not successful',
          ];

          $tr = TransactionReports::create($transaction_report);

        }
      }
    }
  }
}
}

public function sendEmailSaleStatusToBuyer($id, $email, $listing_name, $title_company_name, $reason, $escrow, $payout_sender_id, $payout_date, $buyer, $subject, $date_signed){

  //$subject = array($subject);
  $email = array($email);
  Mail::send('emails.approve_status_buyer', ['id'=>$id, 'listing_name' => $listing_name, 'title_company_name' => $title_company_name, 'reason' => $reason, 'escrow' => $escrow, 'payout_sender_id' => $payout_sender_id, 'payout_date' => $payout_date, 'buyer' => $buyer, 'date_signed' => $date_signed], function ($message) use ($email, $subject)
  {
    $message->from('info@tymbl.com','Tymbl Team');
    $message->to($email);
    $message->subject($subject);
  });
}

public function sendEmailSaleStatusToSeller($id, $email, $listing_name, $title_company_name, $reason, $escrow, $payout_sender_id, $payout_date, $buyer, $subject, $date_signed, $seller){

  //$subject = array($subject);
  $email = array($email);
  Mail::send('emails.approve_status_seller', ['id' => $id, 'listing_name' => $listing_name, 'title_company_name' => $title_company_name, 'reason' => $reason, 'escrow' => $escrow, 'payout_sender_id' => $payout_sender_id, 'payout_date' => $payout_date, 'buyer' => $buyer, 'date_signed' => $date_signed, 'poster' => $seller], function ($message) use ($email, $subject)
  {
    $message->from('info@tymbl.com','Tymbl Team');
    $message->to($email);
    $message->subject($subject);
  });
}

public function titleCompanyLbt(Request $request){

  $userid = $request->test == '1' ? $request->tester : Auth::user()->id;

  $title_company = TitleCompanyInfoLTB::where('ad_id', '=', $request->ad_id)->where('user_id', '=', $userid)->first();
  if($title_company){
    $title_company->company_name = $request->company_name;
    $title_company->representative_name = $request->representative_name;
    $title_company->representative_email = $request->representative_email;
    $title_company->save();

    $final_listing_id = ListingContracts::where('listing_id', $request->ad_id)->first();

    $email = $request->representative_email;
    $title_company_name = $request->company_name;
    $code = $final_listing_id->code_id;
    $url =  url("/title-company/".base64_encode($final_listing_id->user_contract_id));

    $contract = new ContractSigned();
    return $contract->sendContractToTitleCompanyBuy($request->ad_id, $email, $code, $url, $title_company_name);

    //return redirect()->action('ContractSigned@sendContractToTitleCompany', $buy);

  }
  //dd($tile_company);
}

public function sendEmailOverdue(){
  $listing = ListingContracts::where('list_status', '=', '0')->get();

  if($listing){
    foreach($listing as $list){
      $d = date_create($list->created_at);
      $d2 = date_create(date('Y-m-d h:i:s'));
      $dd = date_diff($d,$d2);
      if($dd->d >= 90){

        $ad = Ad::whereId($list->listing_id)->first();
        $user = User::where('id', '=', $list->buyer_id)->first();
        $poster = User::where('id', '=', $ad->user_id)->first();

        $email = array($user->email);
        $id = $ad->id;
        $date_signed = date_format($list->created_at,"Y/m/d");
        $escrow = $ad->country_id == '231' ? 'USD$'.number_format($ad->escrow_amount, 2) : 'CAD$'.number_format($ad->escrow_amount, 2);

        //send overdue message to buyer

        Mail::send('emails.approve_status_buyer_overdue', ['id' => $id, 'buyer' => $user->name, 'listing_name' => $ad->title, 'contract_id' => $list->contract_id, 'date_signed' => $date_signed, 'escrow' => $escrow], function ($message) use ($email, $id)
        {
          $message->from('info@tymbl.com','Tymbl Team');
          $message->to($email);
          $message->subject('Referral '.$id.' did not result in a sale.');
        });

        //send overdue message to seller

        $email = array($poster->email);
        Mail::send('emails.approve_status_seller_overdue', ['id' => $id, 'poster' => $poster->name, 'listing_name' => $ad->title, 'contract_id' => $list->contract_id, 'date_signed' => $date_signed, 'escrow' => $escrow], function ($message) use ($email, $id)
        {
          $message->from('info@tymbl.com','Tymbl Team');
          $message->to($email);
          $message->subject('Referral '.$id.' did not result in a sale.');
        });


      }
    }
  }
}

}
