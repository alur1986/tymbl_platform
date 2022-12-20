<?php
namespace App\Http\Controllers;

use App\Ad;
use App\Brand;
use App\CarsVehicle;
use App\Category;
use App\City;
use App\Comment;
use App\Country;
use App\Job;
use App\Media;
use App\Payment;
use App\Report_ad;
use App\ReferralContactInfo;
use Docusign;
use App\State;
use App\Sub_Category;
use App\User;
use App\UserTitleCompanyInfo;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Intervention\Image\Facades\Image;
use Illuminate\Support\Facades\Session;
use App\Listing;
use App\ListingDocusignEnvelope;
use LaravelEsignatureWrapper;
use App\ListingContracts;
use App\ListingRatings;
use App\Notification;
use App\NotificationTask;
use Bitly;
use App\ListingShortUrl;
use URL;
use Illuminate\Support\Facades\Mail;
use App\Favorite;
use App\UsZip;
use App\CaZip;
use App\NotificationSearch;
use App\RelistedAds;
use App\AdsSaveByUser;
use App\Mls;
use App\NotificationsUsers;
use App\TitleCompanyInfoLTB;
use App\Broker;

class AdsController extends Controller
{
  /**
  * Display a listing of the resource.
  *
  * @return \Illuminate\Http\Response
  */
  public function index()
  {
    $term = request()->get('q');
    $page = request()->get('p');

    //$ds_envelope_id = ListingDocusignEnvelope::select('docusign_id')->where('listing_id', $id)->first();
    $title = 'Active Leads';

    if(!isset($term) || $term == ''){
      $ads = Ad::with('city', 'country', 'state')->whereStatus('1')->orderBy('updated_at', 'desc')->paginate(20);
    }else{
      $ads = $this->searchAds($term, $page);
    }
    $adtype = '1';

    //$user = Auth::user();
    //$notifications_users = $this->getUserNotifications($user);
    //$user_notification_count = count($notifications_users);
    $users = User::where('user_type', '=', 'user')->where('active_status', '=', '1')->get();
    return view('tymbl.dashboard.all_ads', compact('title', 'ads', 'users', 'adtype'));
  }

  public function adminPendingAds()
  {
    $title = trans('app.pending_ads');

    $term = request()->get('q');
    $page = request()->get('p');

    if(!isset($term) || $term == ''){
      $ads   = Ad::with('city', 'country', 'state')->whereStatus('0')->orderBy('id', 'desc')->paginate(20);
    }else{
      $ads = $this->searchAds($term, $page);
    }

    return view('admin.all_ads', compact('title', 'ads'));
  }

  public function adminBlockedAds()
  {

    $title = 'Blocked Leads';
    $term = request()->get('q');
    $page = request()->get('p');

    if($term == ''){
      $ads   = Ad::with('city', 'country', 'state')->whereStatus('3')->orderBy('id', 'desc')->paginate(20);
    }else{
      $ads = $this->searchAds($term, $page);
    }

    $adtype = '3';
    //$user = Auth::user();
    //$notifications_users = $this->getUserNotifications($user);
    //$user_notification_count = count($notifications_users);

    return view('tymbl.dashboard.all_ads', compact('title', 'ads', 'adtype'));
  }

  public function myLists()
  {
    $title = trans('app.my_ads');
    $user = Auth::user();
    $ads  = $user->ads()->with('city', 'country', 'state')->orderBy('updated_at', 'desc')->paginate(10);
    //$notifications_users = $this->getUserNotifications($user);
    //$user_notification_count = count($notifications_users);
    return view('tymbl.dashboard.my_lists', compact('title', 'ads'));
  }

  public function pendingAds()
  {
    $title = trans('app.my_ads');
    $user = Auth::user();
    $term = request()->get('q');
    $page = request()->get('p');

    if(!isset($term) || $term == ''){
      $ads  = $user->ads()->whereStatus('0')->with('city', 'country', 'state')->orderBy('id', 'desc')->paginate(20);
    }else{
      $ads = $this->searchAds($term, $page);
    }

    $adtype = '0';

    return view('admin.pending_ads', compact('title', 'ads', 'adtype'));
  }

  public function searchAds($term, $page){

    $ads   = Ad::with('city', 'country', 'state')->whereStatus($page)->where('title', 'LIKE', "%$term%")->orderBy('updated_at', 'desc')->paginate(20);

    return $ads;
  }

  public function favoriteAds()
  {
    $title = trans('app.favourite_ads');

    $user = Auth::user();
    $ads  = $user->favourite_ads()->with('city', 'country', 'state')->orderBy('id', 'desc')->paginate(30);

    return view('tymbl.dashboard.favorite', compact('title', 'ads'));
  }

  /**
  * Show the form for creating a new resource.
  *
  * @return \Illuminate\Http\Response
  */
  public function create()
  {
    $gapid = config('app.google')['google_api'];
    if(!Auth::check()){
      Session::put('url.intended', URL::current());
      return redirect('login');
    }

    if(!Auth::user()->email){
      return redirect(route('profile'))->with('error', 'Complete your profile before you create a lead.');
    }

    $loc = $this->getClientLocation();
    $current_country = $loc['country'];
    $broker = Broker::where('user_id', '=', Auth::user()->id)->first();

    if(Auth::user()->user_type == 'user'){
    	if(!Auth::user()->phone || !Auth::user()->country_id || !Auth::user()->state_id || !Auth::user()->city_id){
      		return redirect(route('profile'))->with('error', 'Complete your profile before you create a lead.');
    	}

    }

    $title      = trans('app.post_an_ad');
    $categories = Category::orderBy('category_name', 'asc')->get();
    $countries  = Country::all();

    if(old('country') == ''){
      if($current_country == 'United States'){
        $cid = '231';
      }elseif($current_country == 'Canada'){
        $cid = '38';
      }else{
        $cid = '231';
      }

      $previous_states = State::where('country_id', $cid)->orderBy('state_name', 'asc')->get();
      $previous_cities = City::where('state_id', old('state'))->orderBy('city_name', 'asc')->get();
      $previous_zips = [];
    }else{
      $previous_states = State::where('country_id', old('country'))->orderBy('state_name', 'asc')->get();
      $previous_cities = City::where('state_id', old('state'))->orderBy('city_name', 'asc')->get();
      $selected_state = State::where('id', old('state'))->first();

      if(old('city') != '')
      {
        $selected_city = City::where('id', old('city'))->first();
        if($selected_city){
          $previous_zips = UsZip::where('state', '=', $selected_state->state_name)->where('city', '=', $selected_city->city_name)->get();
        }

      }

      //echo $selected_city->city_name;
    }

    return view(
      'tymbl.create_list',
      compact(
        'title',
        'categories',
        'countries',
        'ads_images',
        'previous_states',
        'previous_cities',
        'current_country',
        'previous_zips',
        'gapid'
        )
      );
    }

    public function callback()
    {
      dd('callback', $_GET);
    }

    /**
    * Store a newly created resource in storage.
    *
    * @param DocusignPayment           $ds
    * @param  \Illuminate\Http\Request $request
    * @return \Illuminate\Http\Response
    * @throws \Exception
    */
    public function store(Request $request)
    {

      try {
        //TODO: add middleware and/or policy
        //get user id
        $user_id = 0;
        $new_id = '';
        if (Auth::check()) {
          $user_id = Auth::user()->id;
        }

        //$this->checkLocationPost($request);

        //for Unit Testing only
        if($request->session()->exists('testcaller')){
          $request['city'] = '90210';
          $user_id = $request->session()->get('testuserid');
          session(['errors' => '0']);
        }

        $sub_category   = null;
        $ads_price_plan = get_option('ads_price_plan');

        $countries = array('United States', 'Canada');
        if(!in_array($request->country_name, $countries)){
          return redirect()->back()->withInput()->with('error',  'Country is invalid');
        }
        $current_ctry = $request->country_name == 'United States' ? '231' : '38';

        $state = State::where('country_id', '=', $current_ctry)->where('state_name', '=', $request->state_name)->first();

        if(!$state){
          return redirect()->back()->withInput()->with('error',  'State is invalid. Please input correct State or use the address auto-suggestion.');
        }

        $city = City::where('state_id', '=', $state->id)->where('city_name', '=', $request->city_name)->first();

        if(!$city){
          return redirect()->back()->withInput()->with('error',  'City is invalid. Please input correct City or use the address auto-suggestion.');
        }

        if ($request->category) {
          $sub_category = Category::findOrFail($request->category);
        }

        $this->validateRequest($request, $ads_price_plan, $sub_category);


        $title = filter_var($request->listing_title, FILTER_SANITIZE_STRING);
        $slug  = unique_slug($title);
        $video_url = $request->video_url ? $request->video_url : '';
        $feature1 = serialize($request->feature);
        $feature2 = serialize($request->feature2);

        $category_type_status = $request->cat_type_q == 'yes' ? 'qualified' : 'unqualified';
        $referral_fee = (($request->referral_fee+10)/100);

        //Checks referral info
        if($request->referral_first_name == '' || $request->referral_last_name == ''){
          return redirect()->back()->withInput()->with('error',  'Prospect Name is required');
        }

        if($request->referral_contact_phone == ''){
          return redirect()->back()->withInput()->with('error',  'Prospect Phone Number is required');
        }

        if($request->cat_type_q == 'yes'){
          if($request->date_contacted_qualified){
            //echo 'yes';
          }else{
            return redirect()->back()->withInput()->with('error',  'Invalid date');
          }
        }

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
          'country_id'     => $current_ctry,
          'state_id'       => $state->id,
          'city_id'        => $city->id,
          'zipcode'        => $request->zipcode,
          //'address'        => filter_var($request->referral_contact_address, FILTER_SANITIZE_STRING),
          'video_url'      => $video_url,
          'category_type'  => $ctype,
          'price_plan'     => 'regular',
          'listing_status' => '1',
          'price_range'    => $request->price_range,
          'referral_fee'   => $referral_fee,
          //'mark_ad_urgent' => $mark_ad_urgent,
          //'status'         => '1',
          'user_id'        => $user_id,
          'latitude'       => $request->latitude,
          'longitude'      => $request->longitude,
          //'escrow_amount'  => $request->escrow_amount,
          'feature_1'      => $feature1,
          'feature_2'      => $feature2,
          'cat_type_status'=> $category_type_status,
          'date_contacted_qualified' =>  date("Y-m-d", strtotime($request->date_contacted_qualified))
        ];
        //Check ads moderation settings
        if (get_option('ads_moderation') == 'direct_publish') {
          $data['status'] = '1';
        }

        $created_ad = Ad::create($data);

        $new_id = $created_ad->id;
        $referral_contact_name = $request->referral_first_name. ' '.$request->referral_last_name;

        $referral_data = [
          'ad_id' => $new_id,
          'referral_name' => $referral_contact_name,
          'referral_first_name' => $request->referral_first_name,
          'referral_last_name' => $request->referral_last_name,
          'referral_contact_email' => filter_var($request->referral_contact_email, FILTER_SANITIZE_STRING),
          'referral_contact_address' => filter_var($request->referral_contact_address, FILTER_SANITIZE_STRING),
          'referral_contact_phone' => preg_replace('/\D/', '', $request->referral_contact_phone),
          'referral_contact_fax' =>  preg_replace('/\D/', '', $request->referral_contact_fax),
        ];
        $create_referral_info = ReferralContactInfo::create($referral_data);

        /**
        * if add created
        */
        if ($created_ad) {
          $data['listing_id'] = $created_ad['id'];

          if(!$request->session()->exists('testcaller')){
            //Add new record to notification task
            $notify_task_data = array('listing_id' => $created_ad->id);
            NotificationTask::create($notify_task_data);

            //create short url
            $short_url = Bitly::getUrl(url("/listing/{$created_ad->id}/{$created_ad->slug}"));
            $short_url_data = ['listing_id' => $created_ad->id, 'url' => $short_url];
            ListingShortUrl::create($short_url_data);
          }

          $this->uploadAdsImage($request, $created_ad->id);

          //DOCUSIGN integration
          //TODO: user's should be updated
          //$ds->send(User::findOrFail(2), User::findOrFail(3));
          $catname_id = '';

          switch ($request->category) {
            case '1':
            $catname_id = 'SellDSFH';
            break;
            case '2':
            $catname_id = 'SellCondo';
            break;
            case '3':
            $catname_id = 'SellMFH';
            break;
            case '4':
            $catname_id = 'SellComm';
            break;
            case '5':
            $catname_id = 'SellOther';
            break;
            case '6':
            $catname_id = 'BuyDSFH';
            break;
            case '7':
            $catname_id = 'BuyCondo';
            break;
            case '8':
            $catname_id = 'BuyMFH';
            break;
            case '9':
            $catname_id = 'BuyMFH';
            break;
            case '10':
            $catname_id = 'BuyMFH';
            break;
            default:
            '';
          }

          $request->session()->flash('message', $catname_id);
          if (Auth::check()) {
            //dd('112');
            return redirect(route('my-leads'))->with('success', trans('app.ad_created_msg'));
          }

          //DB::commit();
          return back()->with('success', trans('app.ad_created_msg'));
        }
        //dd($request->input());

      } catch (\Exception $e) {
        //DB::rollBack();
        //turn dd to discover overall errors
        dd('ERROR:',$e);
        //return redirect()->back()->withErrors($e->errors())
        //with('error', $e->getMessage());//withErrors($e->errors())//->
        //;
        //->with('error', $e->getMessage());
        //return redirect()->back()->withInput($request->all())->withErrors($e->errors());
        //dd($e['message']);
        return redirect()->back()->withInput($request->all())->with('error',  $e->getMessage());
        //return back()->withInput()->with('error_message','Unexpected error occurred while trying to process your request');
      }
    }

    /**
    * Display the specified resource.
    *
    * @param  int $id
    * @return \Illuminate\Http\Response
    */
    public function show($id)
    {
      //
    }

    /**
    * Show the form for editing the specified resource.
    *
    * @param  int $id
    * @return \Illuminate\Http\Response
    */
    public function edit($id)
    {
      $gapid = config('app.google')['google_api'];
      $user    = Auth::user();
      $user_id = $user->id;

      $title = trans('app.edit_ad');
      $ad = Ad::find($id);

      if (!$ad) {
        return view('admin.error.error_404');
      }

      if (!$user->is_admin()) {
        if ($ad->user_id != $user_id) {
          return view('admin.error.error_404');
        }
      }

      $countries = Country::where('id', '=', '38')->where('id', '=', '231')->get();

      $previous_states = State::where('country_id', $ad->country_id)->get();
      $previous_cities = City::where('state_id', $ad->state_id)->get();
      $categories = Category::orderBy('category_name', 'asc')->get();

      $selected_state = State::where('id', $ad->state_id)->first();
      $selected_city = City::where('id', $ad->city_id)->first();

      $referral = ReferralContactInfo::where('ad_id', '=', $ad->id)->orderBy('id', 'desc')->first();
      $referral_name = '';
      $referral_first_name = '';
      $referral_last_name = '';


      if($referral){
        if(!$referral->referral_first_name || !$referral->referral_last_name){
          $referral_name = explode(' ', $referral->referral_name);
          $referral_first_name = $referral_name[0];
          $referral_last_name = end($referral_name);
        }else{
          $referral_first_name = $referral->referral_first_name;
          $referral_last_name = $referral->referral_last_name;
        }
      }

      $ctype = $ad->sub_category_type;

      //$user = Auth::user();
      //$notifications_users = $this->getUserNotifications($user);
      //$user_notification_count = count($notifications_users);
      $price_range = $ad->price_range;

      return view('tymbl.dashboard.edit_post', compact('title', 'countries', 'ad', 'previous_states', 'previous_cities', 'categories', 'ctype', 'referral_first_name', 'referral_last_name', 'price_range', 'referral', 'gapid'));
    }

    /**
    * Update the specified resource in storage.
    *
    * @param  \Illuminate\Http\Request $request
    * @param  int                      $id
    * @return \Illuminate\Http\Response
    */
    public function update(Request $request, $id)
    {

      $ad = Ad::find($id);
      $user    = Auth::user();
      $user_id = $user->id;
      $ref_fee = preg_replace('/\D/', '',  $request->referral_fee);
      $poster = User::whereId($ad->user_id)->first();

      if (!$user->is_admin()) {
        if ($ad->user_id != $user_id) {
          return view('admin.error.error_404');
        }
      }

      $sub_category = Category::find($ad->sub_category_id);
      $ads_price_plan = get_option('ads_price_plan');

      $title = $request->ad_title;
      $slug = unique_slug($title);
      $feature1 = serialize($request->feature);
      $feature2 = serialize($request->feature2);

      $is_negotialble = $request->negotiable ? $request->negotiable : '0';
      $video_url      = $request->video_url ? $request->video_url : '';
      $category_type_status = $request->cat_type_q == 'yes' ? 'qualified' : 'unqualified';
      $referral_fee = (($ref_fee+10)/100);

      $countries = array('United States', 'Canada');
      if(!in_array($request->country_name, $countries)){
        return redirect()->back()->withInput()->with('error',  'Country is invalid');
      }
      $current_ctry = $request->country_name == 'United States' ? '231' : '38';

      $state = State::where('country_id', '=', $current_ctry)->where('state_name', '=', $request->state_name)->first();

      if(!$state){
        return redirect()->back()->withInput()->with('error',  'State is invalid. Please input correct State or use the address auto-suggestion.');
      }

      $city = City::where('state_id', '=', $state->id)->where('city_name', '=', $request->city_name)->first();

      if(!$city){
        return redirect()->back()->withInput()->with('error',  'City is invalid. Please input correct City or use the address auto-suggestion.');
      }

      $data = [
        'title'         => $request->ad_title,
        'description'   => $request->ad_description,
        //'escrow_amount' => $request->price,
        //'is_negotiable' => $is_negotialble,
        //'seller_name'  => $request->seller_name,
        //'seller_email' => $request->seller_email,
        //'seller_phone' => $request->seller_phone,
        'country_id'   => $current_ctry,
        'state_id'     => $state->id,
        'city_id'      => $city->id,
        'address'      => $request->referral_contact_address,
        'video_url'    => $video_url,
        //'latitude'     => $request->latitude,
        //'longitude'    => $request->longitude,
        'seller_email' => filter_var($poster->email, FILTER_VALIDATE_EMAIL),
        'feature_1'    => $feature1,
        'feature_2'    => $feature2,
        'category_id'     => $sub_category->category_id,
        'sub_category_id' => $request->category,
        'category_type'  => $request->cat_type,
        'zipcode'      => $request->zipcode,
        'price_range' => $request->price_range,
        'cat_type_status' => $category_type_status,
        'referral_fee'  => $referral_fee,
        'date_contacted_qualified' =>  date("Y-m-d", strtotime($request->date_contacted_qualified))
      ];

      $updated_ad = $ad->update($data);


      $referral_name = $request->referral_first_name.' '.$request->referral_last_name;

      if($updated_ad){

        $data= [
          'referral_name' => $referral_name,
          'referral_first_name' => $request->referral_first_name,
          'referral_last_name' => $request->referral_last_name,
          'referral_contact_phone' => $request->referral_contact_phone,
          'referral_contact_fax' => $request->referral_contact_fax,
          'referral_contact_address' => $request->referral_contact_address
        ];

        $referral = ReferralContactInfo::where('ad_id', '=', $id)->update($data);
      }

      $catname_id = '';

      switch ($request->category) {
        case '1':
        $catname_id = 'SellDSFH';
        break;
        case '2':
        $catname_id = 'SellCondo';
        break;
        case '3':
        $catname_id = 'SellMFH';
        break;
        case '4':
        $catname_id = 'SellComm';
        break;
        case '5':
        $catname_id = 'SellOther';
        break;
        case '6':
        $catname_id = 'BuyDSFH';
        break;
        case '7':
        $catname_id = 'BuyCondo';
        break;
        case '8':
        $catname_id = 'BuyMFH';
        break;
        case '9':
        $catname_id = 'BuyMFH';
        break;
        case '10':
        $catname_id = 'BuyMFH';
        break;
        default:
        '';
      }

      /**
      * iF add created
      */
      if ($updated_ad) {
        //Upload new image
        $this->uploadAdsImage($request, $ad->id);
      }

      return redirect()->back()->with('success', trans('app.ad_updated'));
    }

    public function adStatusChange(Request $request)
    {

      $slug = $request->slug;
      $ad = Ad::whereSlug($slug)->first();

      if($ad) {
        $value = $request->value;
        $ad->status = $value;
        $ad->save();

        if ($value == 1) {
          return ['success' => 1, 'msg' => 'Lead activated'];
        } elseif ($value == 2) {
          return ['success' => 1, 'msg' => 'Lead inactive'];
        } elseif ($value == 3) {
          return ['success' => 1, 'msg' => 'Lead blocked'];
        }elseif ($value == 7) {
          return ['success' => 1, 'msg' => 'Lead moved to VIP'];
        }
      }

      return ['success' => 0, 'msg' => trans('app.error_msg')];
    }

    /**
    * Remove the specified resource from storage.
    *
    * @param  int $id
    * @return \Illuminate\Http\Response
    */
    public function destroy(Request $request)
    {
      $slug = $request->slug;
      $ad   = Ad::whereSlug($slug)->first();
      $ad_id = $ad->id;
      $poster = User::whereId($ad->user_id)->first();
      if ($ad) {
        $media = Media::whereAdId($ad->id)->get();
        if ($media->count() > 0) {
          foreach ($media as $m) {
            $storage = Storage::disk($m->storage);
            if ($storage->has('uploads/images/' . $m->media_name)) {
              $storage->delete('uploads/images/' . $m->media_name);
            }
            if ($m->type == 'image') {
              if ($storage->has('uploads/images/thumbs/' . $m->media_name)) {
                $storage->delete('uploads/images/thumbs/' . $m->media_name);
              }
            }
            $m->delete();
          }
        }
        $ad->delete();

        if($ad){
          $referrals = ReferralContactInfo::where('ad_id','=', $ad_id)->get();
          foreach($referrals as $referral){
            $ad_ids[]= array($referral->id);
          }
          $del_referrals=ReferralContactInfo::whereIn('id',$ad_ids)->delete();

        }


        if(Auth::check() && Auth::user()->user_type == "admin"){
          $email = array($poster->email);
          $id = $ad->id;
          Mail::send('emails.ad_removed', ['id' => $id, 'poster' => $poster->name, 'listing_name' => $ad->title, ], function ($message) use ($email, $id)
          {
            $message->from('info@tymbl.com','Tymbl Team');
            $message->to($email);
            $message->subject('Lead '.$id.' Removed');
          });
        }

        return ['success' => 1, 'msg' => trans('app.media_deleted_msg')];
      }

      return ['success' => 0, 'msg' => trans('app.error_msg')];
    }

    public function getSubCategoryByCategory(Request $request)
    {
      $category_id = $request->category_id;
      $brands      = Sub_Category::whereCategoryId($category_id)->select('id', 'category_name', 'category_slug')->get();

      return $brands;
    }

    public function getBrandByCategory(Request $request)
    {
      $category_id = $request->category_id;
      $brands      = Brand::whereCategoryId($category_id)->select('id', 'brand_name')->get();

      //Save into session about last category choice
      session(['last_category_choice' => $request->ad_type]);

      return $brands;
    }

    public function getStateByCountry(Request $request)
    {

      if($request->country_id){
        $states = State::whereCountryId($request->country_id)->select('id', 'state_name')->get();

        return $states;
      }

    }

    public function getCityByState(Request $request)
    {

      if($request->city_name){
        $state_id = $request->state_id;
        $cities   = City::whereStateId($state_id)->select('id', 'city_name')->orderBy('city_name', 'asc')->get();

        $data = [
          'city_name' => ucfirst($request->city_name),
          'state_id' => $state_id
        ];

        if(count($cities) <= 0){

          $city_add = City::create($data);

          if($city_add){
            $cities   = City::whereStateId($state_id)->select('id', 'city_name')->orderBy('city_name', 'asc')->get();
          }

        }else{
          $city = City::whereStateId($state_id)->where('city_name', '=', ucfirst($request->city_name))->first();
          if(!$city){
            $city_add = City::create($data);

            if($city_add){
              $cities   = City::whereStateId($state_id)->select('id', 'city_name')->orderBy('city_name', 'asc')->get();
            }
          }
        }


        return $cities;
      }

    }

    public function getMlsByState(Request $request){
      $state_id = $request->state_id;
      $states = State::whereId($state_id)->select('id', 'state_name')->first();
      $mls = Mls::where('state', '=', $states->state_name)->get();
      return $mls;
    }

    public function getZipByCity(Request $request)
    {

      if(trim($request->country_id ) == '231'){
        $zip = UsZip::where('state', '=', trim($request->state_id))->where('city', '=', trim($request->city_id))->get();
      }else{
        $zip = CaZip::where('state', '=', trim($request->state_id))->where('city', '=', trim($request->city_id))->get();
      }

      return $zip;
    }

    public function getCitiesByStates(Request $request)
    {
      $error = false;
      $states = explode(',', $request->states);

      foreach($states as $state){
        $selected_states[] = State::whereId($state)->select('id', 'state_name')->get();
      }

      foreach($selected_states as $single_state){

        try {
          $cities[$single_state[0]->state_name] = City::whereStateId($single_state[0]->id)->select('id', 'city_name')->get();
        }
        catch (\Exception $e) {
          //return $e->getMessage();
          $error = true;
        }
      }

      foreach($cities as $k=>$v){
        if($v != ''){
          $r[] = array($k, $v);
        }
      }

      return $r;
    }

    public function getParentCategoryInfo(Request $request)
    {
      $category_id  = $request->category_id;
      $sub_category = Category::find($category_id);
      $category     = Category::find($sub_category->category_id);

      return $category;
    }

    public function uploadAdsImage(Request $request, $ad_id = 0)
    {
      $user_id = 0;

      if (Auth::check()) {
        $user_id = Auth::user()->id;
      }

      if ($request->hasFile('images')) {
        $images = $request->file('images');

        foreach ($images as $image) {
          $valid_extensions = ['jpg', 'jpeg', 'png'];
          if (!in_array(strtolower($image->getClientOriginalExtension()), $valid_extensions)) {
            return redirect()->back()->withInput($request->input())->with('error', 'Only .jpg, .jpeg and .png is allowed extension');
          }

          $file_base_name = str_replace('.' . $image->getClientOriginalExtension(), '', $image->getClientOriginalName());
          $image_name = strtolower(time() . str_random(5) . '-' . str_slug($file_base_name)) . '.' . $image->getClientOriginalExtension();

          $imageFileName  = 'uploads/images/'.$image_name;
          $imageThumbName = 'uploads/images/thumbs/'.$image_name;

          try {

            $resized = Image::make($image)->resize(
              640,
              null,
              function ($constraint) {
                $constraint->aspectRatio();
              }
              )->save(public_path($imageFileName));
              $resized_thumb  = Image::make($image)->resize(320, 213)->save(public_path($imageThumbName));

              if (file_exists($imageFileName)) {
                //Save image name into db
                $created_img_db = Media::create(['user_id' => $user_id, 'ad_id' => $ad_id, 'media_name' => $image_name, 'type' => 'image', 'storage' => get_option('default_storage'), 'ref' => 'ad']);

                $img_url = media_url($created_img_db, false);

              }
            } catch (\Exception $e) {
              return redirect()->back()->withInput($request->input())->with('error', $e->getMessage());
            }
          }
        }
      }

      /**
      * @param Request $request
      * @return array
      * @throws \Exception
      */

      public function deleteMedia(Request $request)
      {
        $media_id = $request->media_id;
        $media    = Media::find($media_id);

        $storage = Storage::disk($media->storage);
        if ($storage->has('uploads/images/' . $media->media_name)) {
          $storage->delete('uploads/images/' . $media->media_name);
        }

        if ($media->type == 'image') {
          if ($storage->has('uploads/images/thumbs/' . $media->media_name)) {
            $storage->delete('uploads/images/thumbs/' . $media->media_name);
          }
        }

        $media->delete();

        return ['success' => 1, 'msg' => trans('app.media_deleted_msg')];
      }

      /**
      * @param Request $request
      * @return array
      */
      public function featureMediaCreatingAds(Request $request)
      {
        $user_id  = Auth::user()->id;
        $media_id = $request->media_id;

        Media::whereUserId($user_id)->whereAdId(0)->whereRef('ad')->update(['is_feature' => '0']);
        Media::whereId($media_id)->update(['is_feature' => '1']);

        return ['success' => 1, 'msg' => trans('app.media_featured_msg')];
      }

      /**
      * @return mixed
      */

      public function appendMediaImage()
      {
        $user_id    = Auth::user()->id;
        $ads_images = Media::whereUserId($user_id)->whereAdId(0)->whereRef('ad')->get();

        return view('admin.append_media', compact('ads_images'));
      }

      /**
      * Listing
      * @param Request $request
      * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
      */

      public function listing(Request $request)
      {
        $ads                = Ad::active();
        $business_ads_count = Ad::active()->business();
        $personal_ads_count = Ad::active()->personal();

        $premium_ads = Ad::activePremium();

        if ($request->q) {
          $ads = $ads->where(
            function ($ads) use ($request) {
              $ads->where('title', 'like', "%{$request->q}%")->orWhere('description', 'like', "%{$request->q}%");
            }
          );

          $business_ads_count = $business_ads_count->where(
            function ($business_ads_count) use ($request) {
              $business_ads_count->where('title', 'like', "%{$request->q}%")->orWhere('description', 'like', "%{$request->q}%");
            }
          );

          $personal_ads_count = $personal_ads_count->where(
            function ($personal_ads_count) use ($request) {
              $personal_ads_count->where('title', 'like', "%{$request->q}%")->orWhere('description', 'like', "%{$request->q}%");
            }
          );
        }
        if ($request->category) {
          $ads                = $ads->whereCategoryId($request->category);
          $business_ads_count = $business_ads_count->whereCategoryId($request->category);
          $personal_ads_count = $personal_ads_count->whereCategoryId($request->category);

          $premium_ads = $premium_ads->whereCategoryId($request->category);
        }
        if ($request->sub_category) {
          $ads                = $ads->whereSubCategoryId($request->sub_category);
          $business_ads_count = $business_ads_count->whereSubCategoryId($request->sub_category);
          $personal_ads_count = $personal_ads_count->whereSubCategoryId($request->sub_category);

          $premium_ads = $premium_ads->whereSubCategoryId($request->sub_category);
        }
        if ($request->brand) {
          $ads                = $ads->whereBrandId($request->brand);
          $business_ads_count = $business_ads_count->whereBrandId($request->brand);
          $personal_ads_count = $personal_ads_count->whereBrandId($request->brand);
        }
        if ($request->condition) {
          $ads                = $ads->whereAdCondition($request->condition);
          $business_ads_count = $business_ads_count->whereAdCondition($request->condition);
          $personal_ads_count = $personal_ads_count->whereAdCondition($request->condition);
        }
        if ($request->type) {
          $ads                = $ads->whereType($request->type);
          $business_ads_count = $business_ads_count->whereType($request->type);
          $personal_ads_count = $personal_ads_count->whereType($request->type);
        }
        if ($request->country) {
          $ads                = $ads->whereCountryId($request->country);
          $business_ads_count = $business_ads_count->whereCountryId($request->country);
          $personal_ads_count = $personal_ads_count->whereCountryId($request->country);
        }
        if ($request->state) {
          $ads                = $ads->whereStateId($request->state);
          $business_ads_count = $business_ads_count->whereStateId($request->state);
          $personal_ads_count = $personal_ads_count->whereStateId($request->state);
        }
        if ($request->city) {
          $ads                = $ads->whereCityId($request->city);
          $business_ads_count = $business_ads_count->whereCityId($request->city);
          $personal_ads_count = $personal_ads_count->whereCityId($request->city);
        }
        if ($request->min_price) {
          $ads                = $ads->where('price', '>=', $request->min_price);
          $business_ads_count = $business_ads_count->where('price', '>=', $request->min_price);
          $personal_ads_count = $personal_ads_count->where('price', '>=', $request->min_price);
        }
        if ($request->max_price) {
          $ads                = $ads->where('price', '<=', $request->max_price);
          $business_ads_count = $business_ads_count->where('price', '<=', $request->max_price);
          $personal_ads_count = $personal_ads_count->where('price', '<=', $request->max_price);
        }
        if ($request->adType) {
          if ($request->adType == 'business') {
            $ads = $ads->business();
          } elseif ($request->adType == 'personal') {
            $ads = $ads->personal();
          }
        }
        if ($request->user_id) {
          $ads                = $ads->whereUserId($request->user_id);
          $business_ads_count = $business_ads_count->whereUserId($request->user_id);
          $personal_ads_count = $personal_ads_count->whereUserId($request->user_id);
        }
        if ($request->shortBy) {
          switch ($request->shortBy) {
            case 'price_high_to_low':
            $ads = $ads->orderBy('price', 'desc');
            break;
            case 'price_low_to_height':
            $ads = $ads->orderBy('price', 'asc');
            break;
            case 'latest':
            $ads = $ads->orderBy('id', 'desc');
            break;
          }
        } else {
          $ads = $ads->orderBy('id', 'desc');
        }


        $ads_per_page = get_option('ads_per_page');
        $ads          = $ads->with('feature_img', 'country', 'state', 'city', 'category');
        $ads          = $ads->paginate($ads_per_page);


        //Check max impressions
        $max_impressions  = get_option('premium_ads_max_impressions');
        $premium_ads      = $premium_ads->where('max_impression', '<', $max_impressions);
        $take_premium_ads = get_option('number_of_premium_ads_in_listing');
        if ($take_premium_ads > 0) {
          $premium_order_by      = get_option('order_by_premium_ads_in_listing');
          $premium_ads           = $premium_ads->take($take_premium_ads);
          $last_days_premium_ads = get_option('number_of_last_days_premium_ads');

          $premium_ads = $premium_ads->where('created_at', '>=', Carbon::now()->timezone(get_option('default_timezone'))->subDays($last_days_premium_ads));

          if ($premium_order_by == 'latest') {
            $premium_ads = $premium_ads->orderBy('id', 'desc');
          } elseif ($premium_order_by == 'random') {
            $premium_ads = $premium_ads->orderByRaw('RAND()');
          }

          $premium_ads = $premium_ads->get();

        } else {
          $premium_ads = false;
        }

        $business_ads_count = $business_ads_count->count();
        $personal_ads_count = $personal_ads_count->count();

        $title      = trans('app.post_an_ad');
        $categories = Category::where('category_id', 0)->get();
        $countries  = Country::all();

        $selected_categories     = Category::find($request->category);
        $selected_sub_categories = Category::find($request->sub_category);

        $selected_countries = Country::find($request->country);
        $selected_states    = State::find($request->state);

        return view(
          'listing',
          compact(
            //'top_categories',
            'ads',
            'title',
            'categories',
            'countries',
            'selected_categories',
            'selected_sub_categories',
            'selected_countries',
            'selected_states',
            'personal_ads_count',
            'business_ads_count',
            'premium_ads'
            )
          );
        }

        //search listings
        public function searchListings(Request $request){
          //http://127.0.0.1:8000/search/US/state-3919/cat-7-buying-condo
          $state = $cat = 'all';
          if ($request->state_id) {
            $state = 'state-' . $request->state_id;
          }
          if ($request->category_id) {
            $cat = 'cat-' . $request->category_id;
          }
          $search_url = route('search', [$state, $cat]);
          $search_url = $search_url . http_build_query($request);

          return redirect($search_url);
        }

        /**
        * @param null $segment_one
        * @param null $segment_two
        * @param null $segment_three
        * @param null $segment_four
        * @param null $segment_five
        * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
        *
        * Search ads
        */

        public function search(Request $request)
        {
          Session::put('url.intended', $request->fullUrl());
          $search_terms = $request->q;
          $pagination_output = null;
          $category_id = null;
          $city_id = null;
          $ads = null;
          $query_category = null;
          $listing_categories = null;
          $city_name = null;
          $list_type = '';
          $cat_ids[] = '';
          $all_cats[] = '';
          $conditions[] = '';
          $rstat = '1';

          $listing_categories = Category::all();

          //get current user location
          $loc = $this->getClientLocation();
          $country = $loc['country'];
          $zip_code = $loc['zip'];

          $allowed_countries = ['United States', 'Canada'];

          if($request->rstat=='1'){
            $rstat = '5';
          }

          if(in_array($country, $allowed_countries)){
            $ctry = Country::where('country_name', $country)->first();
            $states = State::where('country_id', $ctry->id)->get();
            //dd($states);
          }

          $cities = City::all();
          $ads = Ad::where('country_id', '=', $ctry->id)->where('status', '=', $rstat);

          if($request->qtype != ''){
            $qtype = $request->qtype == '1' ? 'qualified' : 'unqualified';
            $ads = $ads->where('cat_type_status', '=', $qtype);
          }

          if($ctry->id == '38'){
            $db = 'ca_zip';
          }else{
            $db = 'us_zip';
          }

          if($request->toggler_location == 'distance'){

            $current_location =  DB::select('select * from '.$db.' where zip = ?', [$request->zipcode]);

            foreach($current_location as $cl)
            {
              $zip =  $cl->zip;
              $lat = $cl->latitude;
              $long = $cl->longitude;

              $nearby_locations = DB::select("SELECT id, zip, city, latitude, longitude, SQRT(POW(69.1 * (latitude - ?), 2) + POW(69.1 * (? - longitude) * COS(latitude / 57.3), 2)) AS distance FROM ".$db." HAVING distance < ? ORDER BY distance", [$lat, $long, $request->distance_range]);
            }

            if(isset($nearby_locations)){
              foreach($nearby_locations as $k=>$locs){
                $allzips[$k] = $locs->zip;
              }
              $ads = $ads->whereIn('zipcode', $allzips);
            }
          }

          if($request->toggler_location == 'location'){
            $ads = $ads->where('state_id', $request->state)->where('city_id', $request->city)->where('country_id', '=', $ctry->id);
          }

          if($search_terms != ''){
            $ads = $ads->where(function($query) use ($request){
              return $query->where('title', 'like', '%'.$request->q.'%')->orWhere('description', 'like', '%'.$request->q.'%')->orWhere('address', 'like', '%'.$request->q.'%')->orWhere('zipcode', $request->q);
            });
          }

          if($request->cat_type){
            if($request->cat_type == 'all'){
              $buysell_cats = Category::where('category_type', 'buying')->orWhere('category_type', 'selling')->get();
            }else{
              $buysell_cats = Category::where('category_type', $request->cat_type)->get();
            }

            foreach($buysell_cats as $c=>$bc){
              $all_cats[$c] = $bc->id;
            }
          }

          if(isset($request->listing_type) && isset($request->cat_type) ){

            if($request->cat_type == 'all'){
              $cat = DB::SELECT("SELECT id FROM categories");
            }else{
              foreach($request->listing_type as $lt){
                $list_type .= "'".$request->cat_type."-".$lt."', ";
              }
              $list_type = substr(trim($list_type), 0, -1);
              $cat = DB::SELECT("SELECT id FROM categories WHERE category_slug IN ($list_type)");
            }

            foreach ($cat as $c => $v) {
              $cat_ids[$c] = $v->id;
            }

            $ads = $ads->whereIn('sub_category_id', $cat_ids);
          }

          if($request->cat_type){
            $ads = $ads->whereIn('sub_category_id', $all_cats);
          }

          if(is_array($request->feature)){
            if(count($request->feature) > 0){
              foreach($request->feature as $c=>$f){
                //SELECT * FROM `ads` WHERE feature_1 REGEXP 'i:0;s:1:"1";'
                $charlen = strlen($f);
                if($charlen >0 || !$f == ''){
                  $ads = $ads->where('feature_1', 'REGEXP', 'i:'.$c.';s:'.$charlen.':"'.$f.'";');
                }
              }
            }
          }

          if(is_array($request->feature2)){
            if(count($request->feature2) > 0){
              foreach($request->feature2 as $c2=>$f2){
                //SELECT * FROM `ads` WHERE feature_1 REGEXP 'i:0;s:1:"1";'
                if(isset($f2) || strlen($f2) > 0){
                  $ads = $ads->where('feature_2', 'REGEXP', $f2);
                }
              }
            }
          }

          if($request->price){
            $ads = $ads->where('price_range', 'LIKE', '%'.$request->price.'%');
          }

          //Sort by filter
          if (request('shortBy')) {
            switch (request('shortBy')) {
              case 'price_high_to_low':
              $ads = $ads->orderBy('escrow_amount', 'DESC');
              break;
              case 'price_low_to_high':
              $ads = $ads->orderBy('escrow_amount', 'ASC');
              break;
              case 'latest':
              $ads = $ads->orderBy('id', 'desc');
              break;
            }
          } else {
            $ads = $ads->orderBy('id', 'desc');
          }

          $ads = $ads->paginate(8);

          $title = "Search";

          if($request->from && $request->from == 'ctrl'){
            return $ads;
            exit;
          }

          return view(
            'tymbl.search', compact('ads', 'title',
            //'premium_ads',
            'pagination_output', 'category_id', 'city_id', 'city_name', 'query_category', 'listing_categories', 'states', 'cities', 'zip_code'));
          }

          public function searchTermsLocation($ads, $request){
            //echo 'hello';
            //$ads = DB::select(DB::raw("SELECT * FROM ads WHERE state_id = '$request->state' AND city_id = '$request->city'"));
            //dd($ads);
            $ads = $ads->where('title', 'like', '%'.$request->q.'%')->where('city_id', $request->city);
            //return $ads;
          }

          public function searchTermsNearby($ads, $request){
            $zip = '';
            $lat = '';
            $long = '';
            $allzips[] = '';

            $current_location =  DB::select('select * from zipcode_us where zip = ?', [$request->zipcode]);

            foreach($current_location as $cl)
            {
              $zip =  $cl->zip;
              $lat = $cl->latitude;
              $long = $cl->longitude;
            }

            $nearby_locations = DB::select("SELECT id, zip, city, latitude, longitude, SQRT(POW(69.1 * (latitude - ?), 2) + POW(69.1 * (? - longitude) * COS(latitude / 57.3), 2)) AS distance FROM zipcode_us HAVING distance < ? ORDER BY distance", [$lat, $long, $request->range]);

            foreach($nearby_locations as $k=>$locs){
              $allzips[$k] = $locs->zip;
              //echo $locs->zip.', ';
            }

            $conditions = ['status' => '1',];
            $ads = $ads->where('title', 'like', '%'.$request->q.'%')->whereIn('zipcode', $allzips);
            $ads = $ads->orWhere('description', 'like', '%'.$request->q.'%')->whereIn('zipcode', $allzips);
            $ads = $ads->orWhere('address', 'like', '%'.$request->q.'%')->whereIn('zipcode', $allzips);
            $ads = $ads->orWhere('slug', 'like', '%'.$request->q.'%')->whereIn('zipcode', $allzips);
            //return $ads;
          }

          public function searchTerms($ads, $request){
            $ads = $ads->where('title', 'REGEXP', $request->q);
            $ads = $ads->orWhere('zipcode', 'REGEXP', $request->q);
            $ads = $ads->orWhere('address', 'REGEXP', $request->q);
            $ads = $ads->orWhere('description', 'REGEXP', $request->q);
          }

          /**
          * @param Request $request
          * @return \Illuminate\Http\RedirectResponse|\Illuminate\Routing\Redirector
          *
          * Redirect map state to search route
          */
          public function mapToSearch(Request $request)
          {
            if (!$request->country) {
              return redirect(route('search'));
            }
            if ($request->country && !$request->state) {
              $country = Country::whereCountryCode(strtoupper($request->country))->first();
              if ($country) {
                return redirect(route('search', [$country->country_code]));
              }
            }
            if ($request->country && $request->state) {
              $country = Country::whereCountryCode(strtoupper($request->country))->first();
              if ($country) {
                $state = State::where('state_name', 'like', "%{$request->state}%")->first();
                if ($state) {
                  return redirect(route('search', [$country->country_code, 'state-' . $state->id]));
                }
                return redirect(route('search', [$country->country_code]));
              }
            }

            return redirect(route('search'));
          }

          /**
          * @param Request $request
          * @return \Illuminate\Http\RedirectResponse|\Illuminate\Routing\Redirector
          *
          * Redirect to search route
          */
          public function searchRedirect(Request $request)

          {
            $city = $cat = null;
            if ($request->city) {
              $city = 'city-' . $request->city;
            }
            if ($request->cat) {
              $cat = 'cat-' . $request->cat;
            }
            $search_url = route('search', [$city, $cat]);
            $search_url = $search_url . '?' . http_build_query(['q' => $request->q]);

            return redirect($search_url);
          }


          public function adsByUser($user_id = 0)
          {
            $user = User::find($user_id);

            if (!$user_id || !$user) {
              return redirect(route('search'));
            }


            if($user->country_id == '231'){
              $loc = UsZip::where('zip', '=', $user->zip_code)->first();
            }else{
              $loc = CaZip::where('zip', '=', $user->zip_code)->first();
            }

            $title = trans('app.ads_by') . ' ' . $user->name;
            $ads   = Ad::active()->whereUserId($user_id)->where('status', '=', '1')->orderBy('id', 'desc')->paginate(10);

            $user_rating1 = DB::select("SELECT COALESCE(SUM(rt.rating), 0) as rt1 FROM listing_ratings rt WHERE rt.user_id = '$user_id' AND rt.rating = 1");
            $user_rating2 = DB::select("SELECT COALESCE(SUM(rt.rating), 0) as rt1 FROM listing_ratings rt WHERE rt.user_id = '$user_id' AND rt.rating = 2");
            $user_rating3 = DB::select("SELECT COALESCE(SUM(rt.rating), 0) as rt1 FROM listing_ratings rt WHERE rt.user_id = '$user_id 'AND rt.rating = 3");
            $user_rating4 = DB::select("SELECT COALESCE(SUM(rt.rating), 0) as rt1 FROM listing_ratings rt WHERE rt.user_id = '$user_id' AND rt.rating = 4");
            $user_rating5 = DB::select("SELECT COALESCE(SUM(rt.rating), 0) as rt1 FROM listing_ratings rt WHERE rt.user_id = '$user_id' AND rt.rating = 5");

            $rating1 = $user_rating1[0]->rt1*1;
            $rating2 = $user_rating2[0]->rt1*2;
            $rating3 = $user_rating3[0]->rt1*3;
            $rating4 = $user_rating4[0]->rt1*4;
            $rating5 = $user_rating5[0]->rt1*5;

            try {
              $final_user_rating = round((($rating1 + $rating2 + $rating3 + $rating4 + $rating5) / ($user_rating1[0]->rt1  + $user_rating2[0]->rt1 + $user_rating3[0]->rt1 + $user_rating4[0]->rt1 + $user_rating5[0]->rt1)), 1);
            }
            catch (\Exception $e) {
              $final_user_rating = 0;
              //return $e->getMessage();
            }

            return view('tymbl.ads_by_user', compact('ads', 'title', 'user', 'final_user_rating', 'loc'));
          }

          /**
          * @param $slug
          * @return mixed
          */
          public function singleAd($id, $slug)
          {
            Session::put('url.intended', URL::current());
            $limit_regular_ads = get_option('number_of_free_ads_in_home');
            //$ad = Ad::whereSlug($slug)->first();

            $ad = Ad::find($id);
            $poster_id = $ad->user_id;
            $ad_watching = '';


            if (!$ad) {
              return view('error_404');
            }

            if (!$ad->status==2) {
              if (Auth::check()) {
                $user_id = Auth::user()->id;
                if ($user_id != $ad->user_id) {
                  return view('error_404');
                }
              } else {
                return view('error_404');
              }
            } else {
              $ad->view = $ad->view + 1;
              $ad->save();
            }

            $title = $ad->title;
            $agreement_signed = ListingContracts::where('listing_id', '=', $ad->id)->orderBy('id', 'desc')->first();
            $relisted_ad = RelistedAds::where('ad_id', '=', $ad->id)->orderBy('id', 'desc')->first();
            //start user rating
            $final_user_rating = 0;
            $related_ads = Ad::active()->whereCategoryId($ad->category_id)->where('id', '!=', $ad->id)->with('category', 'city')->limit($limit_regular_ads)->orderByRaw('RAND()')->get();

            if(Auth::check()){
              $ad_watching = AdsSaveByUser::where('user_id', '=', Auth::user()->id)->first();
            }

            $user_rating1 = DB::select("SELECT COALESCE(SUM(rt.rating), 0) as rt1 FROM listing_ratings rt WHERE rt.user_id = '$poster_id' AND rt.rating = 1");
            $user_rating2 = DB::select("SELECT COALESCE(SUM(rt.rating), 0) as rt1 FROM listing_ratings rt WHERE rt.user_id = '$poster_id' AND rt.rating = 2");
            $user_rating3 = DB::select("SELECT COALESCE(SUM(rt.rating), 0) as rt1 FROM listing_ratings rt WHERE rt.user_id = '$poster_id 'AND rt.rating = 3");
            $user_rating4 = DB::select("SELECT COALESCE(SUM(rt.rating), 0) as rt1 FROM listing_ratings rt WHERE rt.user_id = '$poster_id' AND rt.rating = 4");
            $user_rating5 = DB::select("SELECT COALESCE(SUM(rt.rating), 0) as rt1 FROM listing_ratings rt WHERE rt.user_id = '$poster_id' AND rt.rating = 5");

            $rating1 = $user_rating1[0]->rt1*1;
            $rating2 = $user_rating2[0]->rt1*2;
            $rating3 = $user_rating3[0]->rt1*3;
            $rating4 = $user_rating4[0]->rt1*4;
            $rating5 = $user_rating5[0]->rt1*5;

            try {
              $final_user_rating = round((($rating1 + $rating2 + $rating3 + $rating4 + $rating5) / ($user_rating1[0]->rt1  + $user_rating2[0]->rt1 + $user_rating3[0]->rt1 + $user_rating4[0]->rt1 + $user_rating5[0]->rt1)), 1);
            }
            catch (\Exception $e) {
              $final_user_rating = 0;
              //return $e->getMessage();
            }

            return view('tymbl.single_view_page', compact('ad', 'title', 'related_ads', 'final_user_rating', 'relisted_ad', 'agreement_signed', 'ad_watching'));
          }

          public function switchGridListView(Request $request)
          {
            session(['grid_list_view' => $request->grid_list_view]);
          }

          /**
          * @param Request $request
          * @return array
          */
          public function reportAds(Request $request)
          {
            $ad = Ad::whereSlug($request->slug)->first();
            if ($ad) {
              $data = [
                'ad_id'   => $ad->id,
                'reason'  => $request->reason,
                'email'   => $request->email,
                'message' => $request->message,
              ];
              Report_ad::create($data);
              return ['status' => 1, 'msg' => trans('app.ad_reported_msg')];
            }
            return ['status' => 0, 'msg' => trans('app.error_msg')];
          }


          public function reports()
          {
            $reports = Report_ad::orderBy('id', 'desc')->with('ad')->paginate(20);
            $title   = trans('app.ad_reports');
            return view('admin.ad_reports', compact('title', 'reports'));
          }

          public function deleteReports(Request $request)
          {
            Report_ad::find($request->id)->delete();
            return ['success' => 1, 'msg' => trans('app.report_deleted_success')];
          }

          public function reportsByAds($slug)
          {
            $user = Auth::user();
            if ($user->is_admin()) {
              $ad = Ad::whereSlug($slug)->first();
            } else {
              $ad = Ad::whereSlug($slug)->whereUserId($user->id)->first();
            }

            if (!$ad) {
              return view('admin.error.error_404');
            }

            $reports = $ad->reports()->paginate(20);
            $title = trans('app.ad_reports');
            return view('admin.reports_by_ads', compact('title', 'ad', 'reports'));
          }

          /**
          * Apply to job
          */
          public function applyJob(Request $request)
          {
            $rules = [
              'name'         => 'required',
              'email'        => 'required',
              'phone_number' => 'required',
              'message'      => 'required',
              'resume'       => 'required',
            ];

            $validator = Validator::make($request->all(), $rules);

            $user_id = 0;
            if (Auth::check()) {
              $user_id = Auth::user()->id;
            }

            $request->session()->flash('job_validation_fails', true);
            if ($validator->fails()) {
              return redirect()->back()->withInput($request->input())->withErrors($validator);
            }

            if ($request->hasFile('resume')) {
              $image = $request->file('resume');
              $valid_extensions = ['pdf', 'doc', 'docx'];
              if (!in_array(strtolower($image->getClientOriginalExtension()), $valid_extensions)) {
                return redirect()->back()->withInput($request->input())->with('error', trans('app.resume_file_type_allowed_msg'));
              }

              $file_base_name = str_replace('.' . $image->getClientOriginalExtension(), '', $image->getClientOriginalName());

              $image_name = strtolower(time() . str_random(5) . '-' . str_slug($file_base_name)) . '.' . $image->getClientOriginalExtension();

              $imageFileName = 'uploads/resume/' . $image_name;
              try {
                //Upload original image
                $is_uploaded = current_disk()->put($imageFileName, file_get_contents($image), 'public');

                $application_data = [
                  'ad_id'            => $request->ad_id,
                  'job_id'           => $request->job_id,
                  'user_id'          => $user_id,
                  'name'             => $request->name,
                  'email'            => $request->email,
                  'phone_number'     => $request->phone_number,
                  'message'          => $request->message,
                  'resume'           => $image_name,
                  'application_type' => 'job_applied',
                ];
                JobApplication::create($application_data);
                $request->session()->forget('job_validation_fails');
                return redirect()->back()->withInput($request->input())->with('success', trans('app.job_applied_success_msg'));
              } catch (\Exception $e) {
                return redirect()->back()->withInput($request->input())->with('error', $e->getMessage());
              }
            }

            return redirect()->back()->withInput($request->input())->with('error', trans('app.error_msg'));
          }


          /**
          * @param Request $request
          * @param         $ads_price_plan
          * @param         $sub_category
          */
          private function validateRequest(Request $request, $ads_price_plan, $sub_category = null)
          {

            $rules = [
              'category'       => 'required|not_in:0',
              'listing_title'       => 'required',
              'listing_description' => 'required',
              'country'        => 'required',
              'state_name'          => 'required',
              //'city'           => 'required:not_in:0',
              'seller_name'    => 'required',
              'seller_email'   => 'required|email',
              //'address'        => 'required',
              'referral_first_name' => 'required',
              'referral_last_name' => 'required',
              //'referral_contact_email' => 'required',
              'referral_contact_phone' => 'required',
              //'referral_contact_address' => 'required',
              'referral_fee'    => 'required|regex:/^\d{1,9}(\.\d{1,2})?%?$/',
              //'escrow_amount'   => 'required|numeric',
              'price_range'     => 'required:not_in:0'
            ];
            //reCaptcha
            //if (get_option('enable_recaptcha_post_ad') == 1) {
            //$rules['g-recaptcha-response'] = 'required';
            //}
            $request->referral_fee = preg_replace('/\D/', '',  $request->referral_fee);
            $this->validate($request, $rules);
          }

          //start esignature.io
          public function createContract($id){



            $request_data = Ad::where('id', $id)->first();
            $seller = User::where('id', '=', $request_data->user_id)->first();
            $broker = Broker::where('user_id', '=',  $request_data->user_id)->first();

            $broker_name = '';
            if($broker){
              $broker_name = $broker->name;
            }

            if(!Auth::check()){
              return redirect('login')->with('message', 'Please login to continue.');
            }

            $buyer = Auth::user();
            $broker = Broker::where('user_id', '=', $buyer->id)->first();
            $seller_name = $request_data['seller_name'];
            $seller_email = $request_data['seller_email'];
            $seller_address = $request_data['address'];
            $seller_phone = $request_data['seller_phone'];
            $seller_fax = $request_data['seller_phone'];
            $ref_fee_percent = (float)($request_data['referral_fee']*100)-10;
            $listing_type = $request_data['category_type']  == 'selling' ? 'Seller' : 'Buyer';
            $referral = ReferralContactInfo::where('ad_id', $request_data->user_id)->first();
            $chbox1 = $request_data['category_type'] == 'selling' ? 'x' : '☐';
            $chbox2 = $request_data['category_type'] == 'buying' ? 'x' : '☐';

            $whitelist = array("127.0.0.1", "::1", "34.216.20.30", "localhost");
            $client_ip = $this->get_client_ip();
            //echo 'hello'.$this->get_client_ip();

            $template_id_use = config('app.esig')['template_id'];

            $data = ["template_id" => $template_id_use,
            "signers" => array (
              //pavel ***change email address
              ["name" => "Pavel Stepanov", "email" =>  "info@tymbl.com", "auto_sign"=>  "yes", "skip_signature_request_email"=>  "yes"],
              //buyer
              ["name" => $buyer->name, "email" =>   $buyer->email, "auto_sign"=>  "no", "skip_signature_request_email"=>  "yes",
              //"redirect_url" => url('referral-thankyou')
              ],
              //seller
              ["name" => $seller_name, "email" =>  $seller->email, "auto_sign"=>  "yes", "skip_signature_request_email"=>  "yes",],
            ),
            "custom_fields" => array(
              ["api_key" => "date", "value" => date("Y-m-d")],
              ["api_key" => "ref_fee_percentage", "value" => $ref_fee_percent],
              ["api_key" => "txt_referring_firm", "value" => $seller->name],
              ["api_key" => "txt_destination_firm", "value" => $buyer->name],
              ["api_key" => "referral_fee", "value" => $listing_type],
              ["api_key" => "seller_name", "value" => $broker_name],
              ["api_key" => "seller_email","value" => $seller->email,],
              ["api_key" => "seller_address", "value" => $seller->address],
              ["api_key" => "seller_phone", "value" => $seller->phone],
              ["api_key" => "seller_fax", "value" => $request_data['seller_phone']],
              ["api_key" => "buyer_name", "value" => $broker->name],
              ["api_key" => "buyer_address", "value" => $buyer->address],
              ["api_key" => "buyer_phone", "value" => $buyer->phone],
              ["api_key" => "buyer_fax", "value" => $buyer->phone],
              ["api_key" => "buyer_email", "value" => $buyer->email],

              /*** disable for now
              //["api_key" => "prospect_seller", "value" => $chbox1],
              //["api_key" => "prospect_buyer", "value" => $chbox2],
              //["api_key" => "prospect_other", "value" => '☐'],
              //["api_key" => "prospect_name", "value" => $referral->referral_name],
              //["api_key" => "prospect_address", "value" => $referral->referral_contact_address],
              //["api_key" => "prospect_phone", "value" => $referral->referral_contact_phone],
              //["api_key" => "prospect_fax", "value" => $referral->referral_contact_fax],
              //["api_key" => "prospect_email", "value" => $referral->referral_contact_email],
              ***/
            ),

            "locale" => "en",
            "embedded" => "yes",
            "test" => "XXXxxxXXXxxx",
            "status" => 'queued'
          ];



          $contract = LaravelEsignatureWrapper::createContract($data);

          $code = $this->contactCode();

          if($contract){
            $created_contract['contract_id'] = $contract['data']['contract_id'];
            $created_contract['listing_id'] = $request_data['id'];
            $created_contract['user_contract_id'] = $contract['data']['contract']['signers'][1]['id'];
            $listing = ListingContracts::firstOrNew(array('listing_id' => $id));
            $listing->contract_id = $contract['data']['contract_id'];
            $listing->user_contract_id = $contract['data']['contract']['signers'][1]['id'];
            $listing->code_id = $code;
            $listing->list_status = '0';
            $listing->buyer_id = Auth::user()->id;
            $listing->save();
          }
        }

        public function contactCode(){
          $code = substr(str_shuffle("ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz1234567890"), 0, 6);
          $contracts = ListingContracts::where('code_id', $code)->first();
          if($contracts){
            $this->contactCode();
          }else{
            return $code;
          }
        }

        public function loadReferralAgreement($id){

          if(Auth::guest())
          {
            return redirect('login');
          }

          $buyer =  Auth::user()->id;

          $contract_url = '';
          $type = '';
          $check_id_error = '';

          $validListID = Ad::where('id', $id)->first();

          $user_is_buyer = false;
          try{
            $user_is_buyer = $validListID->user_id == $buyer ? true : false;
          }catch(\Exception $e) {
            $user_is_buyer = false;
          }

          if($user_is_buyer){
            $check_id_error = 'Invalid List ID';
          }else{

            $validList = Ad::where('id', $id)->first();

            if($validList['status'] == '3'){
              $check_id_error = 'Invalid List';
            }else{

              $contract = $this->createContract($id);

              $contract = ListingContracts::where('listing_id', $id)->first();
              $contract_id = $contract['contract_id'];
              $category_type = Ad::where('id', $id)->first();
              $type = $category_type['category_type'];

              if(!Auth::check()){
                return redirect('login')->with('message', 'Please login to continue.');
              }

              $url = LaravelEsignatureWrapper::getContract($contract_id);
              $contract_url = $url['data']['signers'][1]['embedded_url'];
            }
          }
          return view('tymbl.esignature', compact('title', 'contract_url', 'id', 'type', 'check_id_error'));
        }

        //user rating
        public function postRating(Request $request){
          $user = Auth::user();
          $reason = $request->reason == ''  ? 'na' : $request->reason;
          $other = $request->other == '' ? 'na' : $request->other;
          $rating = ListingRatings::firstOrNew(array('user_id' => $user->id));

          $rating->listing_id = $request->list_id;
          $rating->rating = $request->id;
          $rating->user_id = $user->id;
          $rating->low_rating_reason = $reason;
          $rating->other = $other;
          $rating->save();
        }

        //add city
        public function saveNotification(Request $request){
          $user_id = Auth::user()->id;

          $country_id = Country::where('country_name', $request->country)->first();

          if($request->city == 'na'){
            $saveNotification = Notification::firstOrCreate(['user_id'=> $user_id, 'email'=>$request->email, 'phone'=>$request->phone, 'country_id'=>$country_id->id, 'state_id'=>$request->state, 'city_id'=>'0']);
            User::whereId($user_id)->update(['notification_optin' => 1]);
          }else{
            $states[] = City::where('id', trim($request->city))->first();
            foreach($states as $state){
              //echo $state->state_id;
              $correct_state = State::where('id', $state->state_id)->first();
              if($correct_state->country_id == $country_id->id){
                $saveNotification = Notification::firstOrCreate(['user_id'=> $user_id, 'email'=>$request->email, 'phone'=>$request->phone, 'country_id'=>$country_id->id, 'state_id'=>$state->state_id, 'city_id'=>$request->city]);
                User::whereId($user_id)->update(['notification_optin' => 1]);
              }
            }
          }
          return $saveNotification;
        }

        public function removeNotification(Request $request){
          $user_id = Auth::user()->id;
          $deletedCountry = Notification::where(['email' => $request->email, 'country_id' => $request->old_country])->delete();
          $saveNotification = Notification::firstOrCreate(['user_id'=> $user_id, 'email'=>$request->email, 'phone'=>$request->phone, 'country_id'=>$request->new_country, 'state_id'=>'0', 'city_id'=>'0']);
          return $saveNotification;
        }

        public function removeByState(Request $request){

          $user_id = Auth::user()->id;

          $state = State::where('state_name', '=', $request->state)->where('country_id', '=', $request->country_id)->first();
          $deletedState = Notification::where('state_id', '=', $state->id)->where('user_id', '=', $user_id)->delete();
          return $deletedState;
        }

        public function removeByCity(Request $request){
          $user_id = Auth::user()->id;
          $city = City::where('city_name', $request->city)->first();
          $deletedCity = Notification::where('city_id', $city->id)->where('user_id', '=', $user_id)->delete();
          return $deletedCity;
        }

        public function loadReferralAgreementSuccess($id){


          $title = '';
          $type = '';
          $popup = '';

          $validList = Ad::where('id', $id)->first();
          $title_company = UserTitleCompanyInfo::where('user_id', Auth::user()->id)->first();
          $title_company_name = $title_company->company_name;
          $title_company_id = $title_company->id;
          $final_listing_id = ListingContracts::where('listing_id', $id)->first();
          $email = $title_company->representative_email;
          $code = $final_listing_id->code_id;
          $url =  url("/title-company/".base64_encode($final_listing_id->user_contract_id));

          $ad_tci = TitleCompanyInfoLTB::where('ad_id', '=', $validList->id)->where('user_id', '=', Auth::user()->id)->first();

          $popup = isset($ad_tci) ? 'yes' : 'no';

          if($validList->category_type == 'buying'){

            $info = ['ad_id' => $validList->id, 'user_id' => Auth::user()->id, 'representative_name' => '', 'company_name' => '', 'representative_email' => ''];

            if($ad_tci){
              $ltb_info = TitleCompanyInfoLTB::where('ad_id', '=', $validList->id)->where('user_id', '=', Auth::user()->id)->update($info);

            }else{

              $ltb_info = TitleCompanyInfoLTB::create($info);
            }

            $success_template = 'tymbl.referral_success_buyer';

          }else{

            $success_template = 'tymbl.referral_success';
          }

          return view($success_template, compact('title', 'id', 'type', 'title_company_name', 'title_company_id', 'url', 'code', 'email', 'popup', 'validList'));
        }

        public function loadReferralAgreementSuccessTc($id){
          return view('tymbl.referral_success_title_company', compact('title', 'id'));
        }

        public function loadListingCart($id){

          if (Auth::guest()){
            return redirect('/login');
          }

          $user_id = Auth::user()->id;
          $validList = Ad::where(['id' => $id, 'status' => '1'])->first();

          //$title_company_c = UserTitleCompanyInfo::where('user_id', '=', Auth::user()->id)->first();
          $logged_user = User::whereId(Auth::user()->id)->first();

          //if(!$title_company_c){
            //return redirect()->route('profile')->with('error', 'Please complete your profile to proceed.');

          if($logged_user->user_type == 'user'){
            if($logged_user->phone == '' || $logged_user->country_id == ''){
              return redirect()->route('profile')->with('error', 'Please complete your profile to proceed.');
            }
          }elseif($logged_user->user_type == 'admin'){
            return back()->with('error', 'You are admin');
          }


          if($user_id == $validList->user_id){
            return back()->with('error', 'You are not allowed to reserve your own lead');
          }

          $listing_image = Media::where(['ad_id' => $id, 'type' => 'image'])->orderBy('id', 'desc')->first();
          $city = City::whereId($validList->city_id)->first();
          $state = State::whereId($validList->state_id)->first();
          $country = Country::whereId($validList->country_id)->first();
          $image = '';
          if($listing_image){
            $image = $listing_image->media_name;
          }else{
            $image = '';
          }
          return view('tymbl.referral_page', compact('title', 'id', 'validList', 'image', 'city', 'state', 'country', 'listing_image'));
        }

        public function displayCart(Request $request){
          $ad_id = $request->referral_id;
          $ad = Ad::whereId($ad_id)->first();
          $currency = $ad->country_id == '231' ? 'USD$' : 'CAD$';
          $total_cost = $ad->escrow_amount + (2.9 / 100) * $ad->escrow_amount;
          $currency = $ad->country_id == '231' ? 'USD$' : 'CAD$';
          $amount = $currency.$ad->escrow_amount;

          //TBL + current date/time + ad id
          $transaction_id = 'TBL'.strtotime('now').'-'.$ad_id;
          $data = ['amount' => $ad->escrow_amount, 'total' => $total_cost, 'payment_method' => 'paypal', 'status' => 'initial', 'currency' => $currency, 'local_transaction_id' => $transaction_id];
          //dd($data);

          $payment = Payment::firstOrCreate(['ad_id' => $ad_id], $data);
          $media = Media::where('ad_id', $ad_id)->orderBy('id', 'desc')->get();
          //dd($media);

          $transaction_id = $payment->local_transaction_id;
          Session::put('local_transaction_id', $transaction_id);
          return view('tymbl.pay', compact('title', 'ad', 'media', 'amount', 'transaction_id'));
        }

        public function detectLocation(Request $request){
          $distance = 2;
          //$lat = '45.469230';
          //$long = '-122.693540';
          $lat = $request->lat;
          $long = $request->long;

          $myloc = DB::select("SELECT id, zip, city, latitude, longitude, SQRT(POW(69.1 * (latitude - ?), 2) + POW(69.1 * (? - longitude) * COS(latitude / 57.3), 2)) AS distance FROM us_zip HAVING distance < ? ORDER BY distance", [$lat, $long, $distance]);

          return $myloc;
        }

        public function deleteFavoriteList(Request $request){
          $fav = Favorite::where('ad_id', $request->ad_id)->first();
          if($fav){
            $fav->delete();
            return 1;
          }else {
            return '2';
          }
        }

        public function get_client_ip() {
          $ipaddress = '';
          if (getenv('HTTP_CLIENT_IP'))
          $ipaddress = getenv('HTTP_CLIENT_IP');
          else if(getenv('HTTP_X_FORWARDED_FOR'))
          $ipaddress = getenv('HTTP_X_FORWARDED_FOR');
          else if(getenv('HTTP_X_FORWARDED'))
          $ipaddress = getenv('HTTP_X_FORWARDED');
          else if(getenv('HTTP_FORWARDED_FOR'))
          $ipaddress = getenv('HTTP_FORWARDED_FOR');
          else if(getenv('HTTP_FORWARDED'))
          $ipaddress = getenv('HTTP_FORWARDED');
          else if(getenv('REMOTE_ADDR'))
          $ipaddress = getenv('REMOTE_ADDR');
          else

            if(Session::has('testcaller')){
              $ipaddress =  '174.22.204.238';
            }else{
              if(!request()->from == 'ctrl'){
                $ipaddress = $_SERVER['REMOTE_ADDR'];
              }
            }

          return $ipaddress;
        }

        public function getClientLocation(){
          $user_loc = '';
          $country = '';
          $loc = array();
          $whitelist = array('127.0.0.1', '::1');
          $client_ip = $this->get_client_ip();

          //for Unit Testing only
          if(Session::has('testcaller')){
            $user_loc = geoip($ip = '174.22.204.238');
            $country = $user_loc->country;
          }else{
            if(in_array($client_ip, $whitelist)){
              //us
              $user_loc = geoip($ip = '64.235.246.0');
              //Canada
              //$user_loc = geoip($ip = '192.206.151.131');
              $country = $user_loc->country;
            }else{
              $user_loc = geoip($ip = $client_ip);
              if($user_loc->country != 'United States' || $user_loc->country != 'Canada'){
                $user_loc = geoip($ip = '64.235.246.0');
                $country = $user_loc->country;
              }else{
                $country = $user_loc->country;
              }
            }
          }

          $loc = array('country' => $country, 'zip' => $user_loc->postal_code);
          return $loc;
        }

        public function saveUserSearch(Request $request){
          $data = [
            'user_id' => $request->user_id,
            'terms'   => $request->terms
          ];

          $notification_search = NotificationSearch::create($data);

          if($notification_search){
            return '1';
            exit;
          }
          return '0';
        }

        public function saveLead(Request $request){

          $adsaved = AdsSaveByUser::where('user_id', '=', $request->user_id)->where('ad_id', '=', $request->ad_id)->first();
          $status = '';

          if($adsaved){
            $adsaved->delete();
            $status = '0';
          }else{
            $data = [
              'user_id' => $request->user_id,
              'ad_id'   => $request->ad_id
            ];

            $adsaved = AdsSaveByUser::create($data);
            $status = '1';
          }
          return $status;
        }

        public function adminReserveAd(Request $request){
          return $request;
        }

        public function getCity(Request $request){
          //return $request;
          $state = State::where('state_name', '=', $request->state)->first();
          $city = City::where('state_id', '=', $state->id)->orderBy('city_name', 'asc')->get();
          return $city;
        }

        public function adminSpecialAds(){
          $title = 'VIP Leads';
          $term = request()->get('q');
          $page = request()->get('p');

          if($term == ''){
            $ads   = Ad::with('city', 'country', 'state')->whereStatus('7')->orderBy('updated_at', 'desc')->paginate(20);
          }else{
            $ads = $this->searchAds($term, $page);
          }

          $adtype = '7';
          //$user = Auth::user();
          //$notifications_users = $this->getUserNotifications($user);
          //$user_notification_count = count($notifications_users);

          return view('tymbl.dashboard.all_ads', compact('title', 'ads', 'adtype'));
        }

        public function checkLocationPost(Request $request){



          $countries = array('231', '38');
          if(!in_array($request->country, $countries)){
            return redirect()->back()->withInput()->with('error',  'Country is invalid');
            exit;
          }else{
            return 'hhhhey';
            exit;
          }

          return redirect()->back()->withInput()->with('error',  'Country is invalid');
          exit;

          $state = State::where('country_id', '=', $request->country)->where('state_name', '=', $request->state_name)->first();

          if(!$state){
            return redirect()->back()->withInput()->with('error',  'State is invalid. Please use the address autosuggestion.');
            exit;
          }

          $city = City::where('state_id', '=', $request->state)->where('city_name', '=', $request->city_name)->first();

          if(!$city){
            return redirect()->back()->withInput()->with('error',  'City is invalid. Please use the address autosuggestion.');
            exit;
          }

        }

        public function reserveNoBroker(){
          return redirect(route('profile_edit'))->with('error', 'Complete your profile before you create a lead.');
        }


        //end here
      }


//everything ends here
