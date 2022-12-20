<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Ad;
use App\Payouts;
use App\Broker;
use App\ListingContracts;
use LaravelEsignatureWrapper;
use Illuminate\Support\Facades\Mail;
use Response;
use App\Jobs\ProcessSendEmail;
use App\Jobs\ProcessSendContract;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\ReferralContactInfo;
use App\User;
use App\NotificationsUsers;
use App\TransactionReports;
use App\TitleCompanyInfoLTB;
use DateTime;
use App\ListingShortUrl;
use Twilio\Rest\Client;
use Bitly;


class ContractSigned extends Controller
{
  public function index(Request $request){
    if($request->input('status') == 'contract-signed'){
      \Mail::raw('hello world contract signed '.$request, function($message) {
        $message->subject('message subject')->to('newtonboy@gmail.com');
      });
      $doc_url = $request->input('contract_pdf_url');
      $pdf_url = $doc_url['contract_pdf_url'];
      $this->downloadContract($pdf_url);
    }
  }

  //signed by referral party
  public function referralSigned(Request $request){
    $lid = Ad::where('id', $request->id)->update(['status' => '5']);
    $reserved = ListingContracts::where('listing_id', $request->id)->update(['list_status' => '1']);
    return $lid;
  }

  public function blankPage(){
    $title = 'Thank you.';
  }


  public function sendContractToTitleCompany(Request $request){

    $email = $request->email;
    $url = $request->url;
    $code_id = $request->code;
    $title_company_name = $request->title_company_name;

    $users = DB::table('listing_contracts')->join('users', 'users.id', '=', 'listing_contracts.buyer_id')->first();
    $buyer_name = $users->name;
    $subject = "New Referral Agreement signed";

    $sendEmailJob = (new ProcessSendEmail($email, $url, $code_id, $title_company_name, $buyer_name, $subject));
    $this->dispatch($sendEmailJob);

    $this->sendContactToBuyer($request->id);
    $this->sendContractToPoster($request->id);

    $transaction_report = [
      'ad_id' => $request->id,
      'status' => 'Contract sent to Title Company',
    ];

    $tr = TransactionReports::create($transaction_report);

    return '1';

  }


  public function sendContractToTitleCompanyBuy($id, $email, $code, $url, $title_company_name){

    $email = $email;
    $url = $url;
    $code_id = $code;
    $title_company_name = $title_company_name;

    $users = DB::table('listing_contracts')->join('users', 'users.id', '=', 'listing_contracts.buyer_id')->first();
    $buyer_name = $users->name;
    $subject = "New Referral Agreement signed";

    $sendEmailJob = (new ProcessSendEmail($email, $url, $code_id, $title_company_name, $buyer_name, $subject));
    $this->dispatch($sendEmailJob);
    $this->sendContactToBuyer($id);
    $this->sendContractToPoster($id);

    $transaction_report = [
      'ad_id' => $id,
      'status' => 'Contract sent to Title Company',
    ];

    $tr = TransactionReports::create($transaction_report);

    if($tr){
      return redirect()->route('reserved-leads')->with('success', 'Title Company details have been save. A copy of the contract has been forwarded to '.$title_company_name);
    }else{
      return redirect()->back()->with('error', 'An error occured while processing request. Please try again later.');
    }

  }

  public function sendTitleCompanyReminder(){

    $ltb_infos = TitleCompanyInfoLTB::where('company_name', '=', '')->where('representative_name', '=', '')->where('representative_email', '=', '')->get();


    foreach($ltb_infos as $ltb){
      $user = User::where('id', '=', $ltb->user_id)->first();

      $dateSigned = new DateTime($ltb->updated_at);
      $today   = new DateTime(date("Y-m-d H:i:s"));
      $interval = $dateSigned->diff($today);
      $weeks = floor($interval->d/7);
      $subject = 'Please complete Title Company details';

      //echo $weeks;

      if($weeks >= 2 && $weeks % 2 == 0){

        if($user){

          $contract = ListingContracts::where('listing_id', '=', $ltb->ad_id)->first();

          $ad = Ad::where('id', '=', $ltb->ad_id)->where('status', '<>', '6')->first();
          $url = 'listing/'.$ltb->ad_id.'/'.$ad->slug;
          $title_company_url = '/referral-document/'.$ltb->ad_id.'/title_company';

          $email = array($user->email);
          Mail::send('emails.reminder_ltb', ['name'=>$user->name, 'weeks' => $weeks, 'contract_id' => $contract->contract_id, 'url' => $url, 'lead_name' => $ad->title, 'title_company_url' => $title_company_url], function ($message) use ($email, $subject)
          {
            $message->from('info@tymbl.com','Tymbl Team');
            $message->to($email);
            $message->subject($subject);
          });
        }

        $short_url = ListingShortUrl::where('listing_id', '=', $ltb->ad_id)->first();

        $this->sendSMS($user, $weeks, $ad, $contract);

      }

    }

  }

  public function sendSMS($user, $weeks, $ad, $contract){
    $sid = config('app.twilio')['account_sid'];
    $token = config('app.twilio')['app_token'];
    $from = config('app.twilio')['from_num'];
    $to = '+1'.str_replace("-","",$user->phone);
    $client = new Client($sid, $token);
    $short_url = Bitly::getUrl(url("/referral-document/".$ad->id."/title_company"));

    if($client){
      try {
        $message = $client->messages
        ->create($to, // to
        array(
          "body" => 'Hello '.$user->first_name.'! It\'s been '.$weeks.' weeks since you signed the referral agreement #'.$contract->contract_id.' Once you find a dream home for '.$ad->title.', please make sure to update the title company info you get from the Seller agent '.$short_url,
          "from" => '+1'.$from
        )
      );
    } catch ( \Services_Twilio_RestException $e ) {
      Log::info('SMS Error.', 'Error sending sms to '.$user->phone);
    }
  }

}


public function sendTitleCompanyReminderTest(){

  $ltb_infos = TitleCompanyInfoLTB::where('company_name', '=', '')->where('representative_name', '=', '')->where('representative_email', '=', '')->get();


  foreach($ltb_infos as $ltb){
    $user = User::where('id', '=', $ltb->user_id)->first();

    $dateSigned = new DateTime($ltb->updated_at);
    $today   = new DateTime(date("Y-m-d H:i:s"));
    $interval = $dateSigned->diff($today);
    $weeks = floor($interval->d/7);
    $subject = 'Please complete Title Company details';

    //echo $weeks;

    //if($weeks >= 2 && $weeks % 2 == 0){

    if($user){

      $contract = ListingContracts::where('listing_id', '=', $ltb->ad_id)->first();

      $ad = Ad::where('id', '=', $ltb->ad_id)->where('status', '<>', '6')->first();
      $url = 'listing/'.$ltb->ad_id.'/'.$ad->slug;
      $title_company_url = '/referral-document/'.$ltb->ad_id.'/title_company';

      $email = array($user->email);
      Mail::send('emails.reminder_ltb', ['name'=>$user->name, 'weeks' => $weeks, 'contract_id' => $contract->contract_id, 'url' => $url, 'lead_name' => $ad->title, 'title_company_url' => $title_company_url], function ($message) use ($email, $subject)
      {
        $message->from('info@tymbl.com','Tymbl Team');
        $message->to($email);
        $message->subject($subject);
      });
    }

    //}

  }

}


public function sendContactToBuyer($id){
  $contract = ListingContracts::join('users', 'users.id', '=', 'listing_contracts.buyer_id')->where('listing_id', $id)->first();

  $ad = Ad::whereId($id)->first();
  $payout = Payouts::where('list_id', $id)->first();
  $broker = Broker::join('users', 'users.id', '=', 'brokers.user_id')->first();

  //id, name of buyer, ad id, ad title, transaction id, amount
  //$email, $id, $buyer, $seller, $listing_title, $payout_sender_id, $payout, $broker, $subject

  $subject = "Ad #".$id." is Successfully Reserved!";

  $formatted_amount = trim(preg_replace("/[^0-9.]/", "", trim($ad->escrow_amount)));
  $currency = substr($ad->escrow_amount, 0, 3);
  $total = floatval($formatted_amount);
  if($ad->country_id == '231'){
    $total = 'USD$'.$total;
  }else{
    $total = 'CAD$'.$total;
  }

  //$notif_to_buyer = "<p>Here a status of your reservation: ".$ad->title." [id:".$ad->id."]</p>";
  //$notif_to_buyer .= "<p>Status: Contract Signed</p>";
  //$notif_to_buyer .= "<p>Signed on: ".$contract->created_at."</p>";

  $notif_to_buyer = $this->constructMessageBodyContract($ad->id, $ad->title, 'Signed', $contract->created_at, $contract->contract_id, $total, 'buyer');

  $notif = [
    'type' => 'reminder',
    'recipient' => $contract->buyer_id,
    'sender'  => '0',
    'subject' => $subject,
    'contents' => $notif_to_buyer,
    'user_read' => '0'
  ];

  $nofitication = NotificationsUsers::create($notif);

  $transaction_report = [
    'ad_id' => $ad->id,
    'status' => 'Contract signed',
  ];

  $tr = TransactionReports::create($transaction_report);
  $referral_contact = ReferralContactInfo::where('ad_id', '=', $contract->listing_id)->first();

  //$sendEmailJob = (new ProcessSendContract($contract->email, $id, $contract->name, "0", $ad->title, $contract->contract_id, $total, $broker->name, $subject));
  //$this->dispatch($sendEmailJob);

  $email = array($contract->email);
  $subject = 'Ad #'.$ad->id.' si successfully reserved!';
  //$image = http://127.0.0.1:8000/uploads/images/1550681731phsea-single-family-house-475877-1280.jpg
  Mail::send('emails.tbl', ['buyer_name' => $contract->first_name.' '.$contract->last_name, 'transaction_id' => $contract->contract_id, 'ad_title' =>$ad->title, 'referral_name' => $referral_contact->referral_name, 'referral_contact_email' => $referral_contact->referral_contact_email, 'referral_phone' => $referral_contact->referral_contact_phone, 'referral_address' => $referral_contact->referral_contact_address], function ($message) use ($email, $subject)
  {
    $message->from('info@tymbl.com','Tymbl Team');
    $message->to($email);
    $message->subject($subject);
  }
  );

}

public function sendContactToBuyerTBL($id){
  $contract = ListingContracts::join('users', 'users.id', '=', 'listing_contracts.buyer_id')->where('listing_id', $id)->first();

  dd($contracts);

  $ad = Ad::whereId($id)->first();
  $payout = Payouts::where('list_id', $id)->first();
  $broker = Broker::join('users', 'users.id', '=', 'brokers.user_id')->first();

  //id, name of buyer, ad id, ad title, transaction id, amount
  //$email, $id, $buyer, $seller, $listing_title, $payout_sender_id, $payout, $broker, $subject

  $subject = "Ad #".$id." is Successfully Reserved!";

  $formatted_amount = trim(preg_replace("/[^0-9.]/", "", trim($ad->escrow_amount)));
  $currency = substr($ad->escrow_amount, 0, 3);
  $total = floatval($formatted_amount);
  if($ad->country_id == '231'){
    $total = 'USD$'.$total;
  }else{
    $total = 'CAD$'.$total;
  }

  //$notif_to_buyer = "<p>Here a status of your reservation: ".$ad->title." [id:".$ad->id."]</p>";
  //$notif_to_buyer .= "<p>Status: Contract Signed</p>";
  //$notif_to_buyer .= "<p>Signed on: ".$contract->created_at."</p>";

  $notif_to_buyer = $this->constructMessageBodyContract($ad->id, $ad->title, 'Signed', $contract->created_at, $contract->contract_id, $total, 'buyer');

  $notif = [
    'type' => 'reminder',
    'recipient' => $contract->buyer_id,
    'sender'  => '0',
    'subject' => $subject,
    'contents' => $notif_to_buyer,
    'user_read' => '0'
  ];

  $nofitication = NotificationsUsers::create($notif);

  $transaction_report = [
    'ad_id' => $ad->id,
    'status' => 'Contract signed',
  ];

  $tr = TransactionReports::create($transaction_report);

  //$sendEmailJob = (new ProcessSendContract($contract->email, $id, $contract->name, "0", $ad->title, $contract->contract_id, $total, $broker->name, $subject));
  //$this->dispatch($sendEmailJob);

  $email = array($email);
  //$image = http://127.0.0.1:8000/uploads/images/1550681731phsea-single-family-house-475877-1280.jpg
  Mail::send('emails.tbl', ['name' => $buyer_name, 'email' => $email, 'link' => $link, 'image' => $image, 'title' => $title], function ($message) use ($email)
  {
    $message->from('info@tymbl.com','Tymbl Team');
    $message->to($email);
    $message->subject("New referral available in your area on Tymbl!");
  }
  );

}

public function sendContractToPoster($id){
  $contract = ListingContracts::join('users', 'users.id', '=', 'listing_contracts.buyer_id')->where('listing_id', $id)->first();
  $ad = Ad::join('users', 'users.id', '=', 'ads.user_id')->where('ads.id', $id)->first();
  $payout = Payouts::where('list_id', $id)->first();
  $broker = Broker::where('user_id', $contract->buyer_id)->first();

  //id, name of buyer, ad id, ad title, transaction id, amount
  //$email, $id, $buyer, $seller, $listing_title, $payout_sender_id, $payout, $broker, $subject

  $subject = "Referral Agreement for Ad#".$contract->listing_id." has been signed!";
  $formatted_amount = trim(preg_replace("/[^0-9.]/", "", trim($ad->escrow_amount)));
  $currency = substr($ad->escrow_amount, 0, 3);
  $total = floatval($formatted_amount);
  if($ad->country_id == '231'){
    $total = 'USD$'.$total;
  }else{
    $total = 'CAD$'.$total;
  }
  //send notification to poster
  //$buyer = User::whereId($contract->buyer_id)->first();

  $notif_to_seller = $this->constructMessageBodyContract($ad->id, $ad->title, 'Signed', $contract->created_at, $contract->contract_id, $total, 'seller');

  $notif = [
    'type' => 'reminder',
    'recipient' => $ad->user_id,
    'sender'  => '0',
    'subject' => $subject,
    'contents' => $notif_to_seller,
    'user_read' => '0'
  ];

  $nofitication = NotificationsUsers::create($notif);

  $sendEmailJob = (new ProcessSendContract($ad->email, $id, $contract->name, $ad->name, $ad->title, $contract->contract_id, $total, $broker->name, $subject));
  $this->dispatch($sendEmailJob);
}


public function downloadContract($contract_id)
{
  $headers = array('Content-Type: application/pdf',);
  if (file_exists('downloads/'.$contract_id.'.pdf')) {
    $file = public_path('downloads/'.$contract_id.'.pdf');
  }else{
    $url = LaravelEsignatureWrapper::getContract($contract_id);
    $pdf_url = $url['data']['contract_pdf_url'];

    if($pdf_url != ''){
      $pdf_file = file_get_contents($pdf_url);
      file_put_contents(public_path('downloads/'.$contract_id.'.pdf'), $pdf_file);
    }

    $file = public_path('downloads/'.$contract_id.'.pdf');
  }

  return Response::download($file, $contract_id.'.pdf', $headers);
}

public function constructMessageBodyContract($ad_id, $ad_title, $status, $date, $transaction_id, $amount, $user_type){

  $listing_status = $status == 'Signed' ? 'Reserved. Awaiting approval' : 'Referral Agreement was not signed';

  $notif = '<p>The Referral Agreement for #'.$ad_id.' was <b>'.$status.'</b> on <b>'.$date.'</b></p>';
  $notif .= '<p>Listing Status: '.$listing_status.'</p>';

  if($status == 'Signed'){
    if($user_type == 'buyer'){
      $notif .= '<p>Note:</p>';
      $notif .= 'You have successfully signed  #'.$ad_id.' + '.$ad_title.', Contract ID - '.$transaction_id.'. The funds in the amount of '.$amount.' have been collected and placed into the Tymbl escrow account. A copy of the signed agreement has been sent to the title company you indicated.';
    }else{
      $notif .= '<p>Note:</p>';
      $notif .= 'The funds in the amount of '.$amount.' you set to reserve your listing have been collected and placed into the Tymbl escrow account. A copy of the signed agreement has been sent to the title company selected by the buyer';
    }
  }

  return $notif;
}

public function signerSinged(Request $request){
  //Log::info('User signed.', $request->status);
  //Log::info('User signed.', $request->data['contract_id']);
  //Log::info('User signed.', $request);

  $email = array('newtonboy@gmail.com');
  $subject = 'server test';

  if($request->status == 'signer-signed'){

    $contract_id = $request->data['contract_id'];
    $signer_id = '';

    $listing = ListingContracts::where('contract_id', '=', $contract_id)->first();
    $request_data = Ad::where('id', '=', $listing->listing_id)->first();
    $poster = User::whereId($request_data->user_id)->first();

    $referral = ReferralContactInfo::where('user_id', $request_data->user_id)->first();
    $chbox1 = $request_data->category_type == 'selling' ? 'x' : '☐';
    $chbox2 = $request_data->category_type == 'buying' ? 'x' : '☐';


    //foreach($contract['data']['signers'] as $rd=>$e){
    if($contract['data']['signers']['name'] == $poster->name && $contract['data']['signers']['email'] == $poster->email){
      //$signer_id = $e['id'] ;
      $contract = LaravelEsignatureWrapper::getContract($contract_id);
      $signer_id = $contract['data']['signers'][2]['id'];

      $seller_name = $request_data->seller_name;
      $seller_email = $request_data->seller_email;

      $template_id_use = "f361a44c-f57c-47f3-a35c-44e7c60d4730";
      $data = ["template_id" => $template_id_use,

      "signers" => array (
        //seller
        ["name" => $seller_name, "email" =>  $seller_email, "auto_sign"=>  "yes", "skip_signature_request_email"=>  "yes",],
      ),

      "custom_fields" => array(
        ["api_key" => "prospect_seller", "value" => $chbox1],
        ["api_key" => "prospect_buyer", "value" => $chbox2],
        ["api_key" => "prospect_other", "value" => '☐'],
        ["api_key" => "prospect_name", "value" => $referral->referral_name],
        ["api_key" => "prospect_address", "value" => $referral->referral_contact_address],
        ["api_key" => "prospect_phone", "value" => $referral->referral_contact_phone],
        ["api_key" => "prospect_fax", "value" => $referral->referral_contact_fax],
        ["api_key" => "prospect_email", "value" => $referral->referral_contact_email],
      ),

      "test" => "yes",
      "status" => 'queued'
    ];

    $contract = LaravelEsignatureWrapper::addContractRecipient($contract_id, $signer_id, $data);

  }else{
    exit;
  }
  // }
}

}
}
