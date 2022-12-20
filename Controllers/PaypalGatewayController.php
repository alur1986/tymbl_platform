<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use PayPal\Rest\ApiContext;
use PayPal\Api\Payer;
use PayPal\Api\Item;
use PayPal\Api\ItemList;
use PayPal\Api\Amount;
use PayPal\Api\Payment;
use PayPal\Api\Transaction;
use PayPal\Api\RedirectUrls;
use PayPal\Api\PaymentExecution;
use PayPal\Api\Payout;
use PayPal\Api\PayoutSenderBatchHeader;
use PayPal\Api\PayoutItem;
use PayPal\Auth\OAuthTokenCredential;
use PayPal\Api\Currency;
use PayPal\Api\RefundRequest;
use PayPal\Api\Refund;
use PayPal\Api\Sale;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Input;
use URL;
use App\Payment as LP;
use App\Ad;
use App\Payouts;
use Illuminate\Support\Facades\Auth;

class PaypalGatewayController extends Controller
{
  public function __construct()
  {
    /** PayPal api context **/
    $paypal_conf = config('paypal');
    $this->_api_context = new ApiContext(new OAuthTokenCredential(
      $paypal_conf['client_id'],
      $paypal_conf['secret'])
    );
    $this->_api_context->setConfig($paypal_conf['settings']);
  }

  public function makePaypalPayment(Request $request)
  {

    $total = trim(preg_replace('/[^0-9.]/i', '', $request->amount));
    //$total_cost = (trim(str_replace(',', '', $total)) + (2.9 / 100) * trim(str_replace(',', '', $total))) + 0.30;
    $total_cost = trim(str_replace(',', '', $total));
    $currency = strpos($request->amount, 'USD$') !== false ? 'USD' : 'CAD';

    $payer = new Payer();
    $payer->setPaymentMethod('paypal');

    $item_1 = new Item();
    $item_1->setName($request->transaction_id)->setCurrency($currency)->setQuantity(1)->setPrice($total_cost);
    $item_list = new ItemList();
    $item_list->setItems(array($item_1));

    $amount = new Amount();
    $amount->setCurrency($currency)->setTotal($total_cost);

    $transaction = new Transaction();
    $transaction->setAmount($amount)->setItemList($item_list)->setDescription($request->ad_name.' [AD#'.$request->ad_id.']');

    $redirect_urls = new RedirectUrls();
    $redirect_urls->setReturnUrl(URL::route('status'))->setCancelUrl(URL::route('cancel'));

    $payment = new Payment();
    $payment->setIntent('Sale')->setPayer($payer)->setRedirectUrls($redirect_urls)->setTransactions(array($transaction));
    /** dd($payment->create($this->_api_context));exit; **/

    try {
      $payment->create($this->_api_context);
    } catch (\PayPal\Exception\PPConnectionException $ex) {
      if (configt('app.debug')) {
        Session::put('error', 'Connection timeout');
        return Redirect::route('/payment/pay');
      } else {
        Session::put('error', 'Some error occur, sorry for inconvenient');
        return Redirect::route('/payment/pay');
      }
    }

    foreach ($payment->getLinks() as $link) {
      if ($link->getRel() == 'approval_url') {
        $redirect_url = $link->getHref();
        break;
      }
    }
    /** add payment ID to session **/
    Session::put('paypal_payment_id', $payment->getId());
    if (isset($redirect_url)) {
      /** redirect to paypal **/
      return Redirect::away($redirect_url);
    }
    Session::put('error', 'Unknown error occurred');
    return Redirect::route('success');
  }

  public function paymentStatus(Request $request){

    $payment_id = Session::get('paypal_payment_id');

    /** clear the session payment ID **/

    if (empty(Input::get('PayerID')) || empty(Input::get('token'))) {
      Session::put('error', 'Payment failed');
      return Redirect::route('status');
    }
    $payment = Payment::get($payment_id, $this->_api_context);
    $execution = new PaymentExecution();
    $execution->setPayerId(Input::get('PayerID'));
    /**Execute the payment **/
    $result = $payment->execute($execution, $this->_api_context);

    if ($result->getState() == 'approved') {
      //Session::put('pp', $result);
      Session::put('success', 'Payment success');
      return Redirect::route('success');
    }
    Session::put('error', 'Payment failed');
    return Redirect::route('fail');
  }

  public function paymentCancel(Request $request){
    //echo $request->session()->get('local_transaction_id');
    //dd($request);
    //return view('tymbl.paypal_cancel', compact('title'));
    $id = Auth::id();
    LP::where('local_transaction_id', '=', $request->session()->get('local_transaction_id'))->update(['status' => 'failed', 'user_id' => $id]);
    return view('tymbl.paypal_cancel', compact('title'));
  }

  public function paymentSuccess(Request $request){
    $payment_id = Session::get('paypal_payment_id');
    $user_id = Auth::user()->id;
    $listing_id = LP::where('local_transaction_id', $request->session()->get('local_transaction_id'))->first();
    $paypal_email = $request->session()->get('payer_email');
    LP::where('local_transaction_id', $request->session()->get('local_transaction_id'))->update(['status' => 'success', 'user_id' => $user_id, 'charge_id_or_token' => $payment_id, 'payer_email' => $paypal_email]);
    $payment_id = Session::get('paypal_payment_id');
    Ad::whereId($listing_id->ad_id)->update(['status' => '6']);
    $ad_id = $listing_id->ad_id;

    Session::forget('paypal_payment_id');
    Session::forget('success');
    return view('tymbl.paypal_success', compact('title', 'id', 'request', 'ad_id'));
  }



  public function paymentFailed(Request $request){
    echo 'An error occured while processing your request';
  }

  /*
  send payout to the seller
  $email = seller email
  $amount = original amount of the escrow
  $contract_id = contract id
  */
  public function payoutSeller($email, $amount, $ad_title, $contract_id){

    //for testing purposes only
    $sender_item_id = "";
    $sender_batch_id = "";

    //$email= $email;
    //$email = 'reynaldo.tugadi-buyer@gmail.com';
    $sender_batch_id = uniqid();
    $sender_item_id = substr(str_shuffle("ABCDEFGHIJKLMNOPQRSTUVWXYZ"), -5).$contract_id;
    $formatted_amount = trim(preg_replace("/[^0-9.]/", "", trim($amount)));
    $currency = substr($amount, 0, 3);
    $message_to_seller = "An escrow with the amount of ".$amount." from listing ".$ad_title." has been transferred to your account.<br><br> Transaction ID: ".$sender_item_id;

    $payouts = new Payout();
    $senderBatchHeader = new PayoutSenderBatchHeader();

    $senderBatchHeader->setSenderBatchId($sender_batch_id)->setEmailSubject("Escrow tranfer");

    $escrow_fee = ($formatted_amount / 100) * 1;
    $final_amount = ($formatted_amount -$escrow_fee);

    $senderItem = new PayoutItem();
    $senderItem->setRecipientType('Email')->setNote($message_to_seller)->setReceiver($email)->setSenderItemId($sender_item_id)->setAmount(new Currency('{"value": "'.$final_amount.'", "currency": "'.$currency.'"}'));

    $payouts->setSenderBatchHeader($senderBatchHeader)->addItem($senderItem);

    $request = clone $payouts;
    try {
      $output = $payouts->createSynchronous($this->_api_context);
      $data = ['list_id' => $contract_id, 'sender_id' => $output->getBatchHeader()->getPayoutBatchId(), 'amount' => $amount, 'type' => 'Seller payout'];
      Payouts::create($data);
    } catch (Exception $ex) {
      ResultPrinter::printError("Created Single Synchronous Payout", "Payout", null, $request, $ex);
      exit(1);
    }

    //ResultPrinter::printResult("Created Single Synchronous Payout", "Payout", $output->getBatchHeader()->getPayoutBatchId(), $request, $output);

    return $output;

  }

  public function refundPayment($buyer_email, $amount, $ad_title, $contract_id, $pp_transaction_id){
    $sale = $this->getPaymentDetails($pp_transaction_id);

    $sale_id = $sale['id'];
    $sender_item_id = substr(str_shuffle("ABCDEFGHIJKLMNOPQRSTUVWXYZ"), -5).$contract_id;

    $total = $sale['total'];
    $currency = $sale['currency'];

    //$total = floatval($formatted_amount) - 0.30;
    //$total = floatval($formatted_amount);

    // ### Refund amount
    // Includes both the refunded amount (to Payer)
    // and refunded fee (to Payee). Use the $amt->details
    // field to mention fees refund details.
    $amt = new Amount();
    $amt->setCurrency($currency)->setTotal($total);
    // ### Refund object

    $refund = new Refund();
    $refund->setAmount($amt);
    // ###Sale
    // A sale transaction.
    // Create a Sale object with the
    // given sale transaction id.
    $sale = new Sale();
    $sale->setId($sale_id);
    try {
      // Create a new apiContext object so we send a new
      // PayPal-Request-Id (idempotency) header for this resource
      //$apiContext = getApiContext($clientId, $clientSecret);
      // Refund the sale
      // (See bootstrap.php for more on `ApiContext`)
      $refundedSale = $sale->refund($refund, $this->_api_context);
      $data = ['list_id' => $contract_id, 'sender_id' => $refundedSale->getId(), 'amount' => $amount, 'type' => 'Buyer refund'];
      Payouts::create($data);
    } catch (Exception $ex) {
      //ResultPrinter::printError("Refund Sale", "Sale", $refundedSale->getId(), $refund, $ex);
      exit(1);
    }
  }

  public function getPaymentDetails($transaction_id){
    $payments = Payment::get($transaction_id, $this->_api_context);
    $payments->getTransactions();
    //dd($payments);
    $obj = $payments->toJSON();
    $paypal_obj = json_decode($obj);
    $transaction['id'] = $paypal_obj->transactions[0]->related_resources[0]->sale->id;
    $transaction['total'] = $paypal_obj->transactions[0]->related_resources[0]->sale->amount->total;
    $transaction['currency'] = $paypal_obj->transactions[0]->related_resources[0]->sale->amount->currency;
    //dd($transaction);
    return $transaction;
  }



}
