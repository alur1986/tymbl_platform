<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Ad;
use App\Brand;
use App\CarsVehicle;
use App\Category;
use App\City;
use App\Comment;
use App\Country;
use App\Media;
use App\Payment;
use App\Report_ad;
use Docusign;
use App\State;
use App\Sub_Category;
use App\User;
use Carbon\Carbon;
use App\Listing;
use App\ListingDocusignEnvelope;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Intervention\Image\Facades\Image;

class ListingsController extends Controller
{

  public function index()
  {
    $title = trans('app.all_ads');
    $ads   = Ad::with('city', 'country', 'state')->whereStatus('1')->orderBy('id', 'desc')->paginate(20);

    return view('admin.all_ads', compact('title', 'ads'));
  }


  public function create(){

    if(!Auth::check()){
      return redirect('login');
    }

    $title      = trans('app.post_an_ad');
    $categories = Category::orderBy('category_name', 'asc')->get();
    $countries  = Country::all();

    if(old('country') == ''){
      $previous_states = State::where('country_id', '231')->get();
      $previous_cities = City::where('state_id', old('state'))->get();
    }else{
      $previous_states = State::where('country_id', old('country'))->get();
      $previous_cities = City::where('state_id', old('state'))->get();
    }

    //Render the view
    return view(
      'create_list',
      compact(
        'listing_title',
        'categories',
        'countries',
        //'ads_images',
        'previous_states',
        'previous_cities'
        )
      );

    }

    //save listing
    public function store(Request $request)
    {

      $user_id = 0;
      if (Auth::check()) {
        $user_id = Auth::user()->id;
      }

      $this->validateRequest($request);


      if ($request->category) {
        $sub_category = Category::findOrFail($request->category);
      }

      $slug = $sub_category->category_slug;

      //dd($sub_category);

      $data = [
        'title'           => $request->listing_title,
        'slug'            => $slug,
        'description'     => $request->listing_description,
        'category_id'     => $sub_category->id,
        'sub_category_id' => $request->category,
        'seller_name'    => $request->seller_name,
        'seller_email'   => $request->seller_email,
        'seller_phone'   => $request->seller_phone,
        'country_id'     => $request->country,
        'state_id'       => $request->state,
        'city_id'        => $request->city,
        'address'        => $request->address,
        'video_url'      => $request->video_url,
        'category_type'  => $sub_category->category_type,
        'status'         => '0',
        'user_id'        => $user_id,
        'refferal_contact_info' => $request->refferal_contact_info,
        'escrow_amount'  => $request->escrow_amount,
      ];

      $listing_created = Listing::create($data);

      if($listing_created){
        $data['listing_id'] = $listing_created['id'];
        $this->createNewDocusignEnvelope($data);
      }

      return back()->with('success', trans('app.listing_created_msg'));

    }

    /**
    * @param Request $request
    * @param         $ads_price_plan
    * @param         $sub_category
    */
    private function validateRequest(Request $request)
    {
      $rules = [
        'category'       => 'required|not_in:0',
        'listing_title'       => 'required',
        'listing_description' => 'required',
        'country'        => 'required',
        'state'          => 'required:not_in:0',
        'city'           => 'required:not_in:0',
        'seller_name'    => 'required',
        'seller_email'   => 'required',
        'seller_phone'   => 'required',
        'address'        => 'required',
        'refferal_contact_info' => 'required',
        'escrow_amount'   => 'required',
      ];

      $this->validate($request, $rules);
    }

    private function createNewDocusignEnvelope($data){

      //determine if poster is a buyer or a seller
      $category_type = $data['category_type'] == 'buying' ? 'buyers' : 'sellers';
      //exit;

      $template_id = 'a04475c5-bf82-4391-bde1-c65f4c0331c4';

      $envelope_created = Docusign::createEnvelope(
        array(
          'templateId'     => $template_id, // Template ID
          'emailSubject'   => $category_type.' Envelope Subject', // Subject of email sent to all recipients
          'status'         => 'created', // created = draft ('sent' will send the envelope!)
          'templateRoles'  => array(
            ['name'     => $data['seller_name'],
            'email'    => $data['seller_email'],
            'roleName' => $category_type,
            'clientUserId'  => $data['listing_id']])
          ));

          if($envelope_created){
            $envelope['docusign_id'] = $envelope_created['envelopeId'];
            $envelope['listing_id'] = $data['listing_id'];

            ListingDocusignEnvelope::create($envelope);
            $envelopeId = $envelope_created['envelopeId'];
            $envelope_recipients = Docusign::getEnvelopeRecipients($envelopeId, true);

            //dd($envelope_recipients);
            //exit;

            $recipientId = $category_type == 'buyers' ? '1' : '2';

            $envelope_tabs = Docusign::getEnvelopeTabs($envelopeId, $recipientId);

            if($recipientId=='1'){

              $full_name_tab = $envelope_tabs['fullNameTabs'][0]['tabId'];
              $date_sign_tab = $envelope_tabs['dateSignedTabs'][0]['tabId'];
              $company_tab = $envelope_tabs['companyTabs'][0]['tabId'];
              $office_num_tab = $envelope_tabs['textTabs'][0]['tabId'];
              $office_address_tab = $envelope_tabs['textTabs'][1]['tabId'];
              $phone_tab = $envelope_tabs['textTabs'][2]['tabId'];
              $fax_tab = $envelope_tabs['textTabs'][3]['tabId'];
              $address_tab2 = $envelope_tabs['textTabs'][4]['tabId'];
              $email_tabs = $envelope_tabs['emailTabs'][0]['tabId'];
              $checkbox_tab = $envelope_tabs['checkboxTabs'][0]['tabId'];

              $tabs = [
                'fullNameTabs' => [['tabId' => $full_name_tab, 'value' => $data['seller_name']]],
                'companyTabs' => [['tabId' => $full_name_tab, 'value' => $data['seller_name']]],
                'emailTabs' => [['tabId' => $email_tabs, 'value' => $data['seller_email']]],
                'dateSignedTabs' => [['tabId' => $date_sign_tab, 'value' => date('Y-m-d')]],
                'checkboxTabs' => [['tabId' => $checkbox_tab, 'value' => 'selected']],
                'textTabs' => [
                  ['tabId' => $office_num_tab, 'value' => ''],
                  ['tabId' => $office_address_tab, 'value' => $data['address']],
                  ['tabId' => $phone_tab, 'value' => $data['seller_phone']],
                  ['tabId' => $fax_tab, 'value' => $data['seller_phone']],
                  ['tabId' => $address_tab2, 'value' => $data['address']],
                ],
              ];

            }else{

              $full_name_tab = $envelope_tabs['fullNameTabs'][0]['tabId'];
              $full_name2_tab = $envelope_tabs['fullNameTabs'][1]['tabId'];
              $email_tabs = $envelope_tabs['emailTabs'][0]['tabId'];
              $email2_tabs = $envelope_tabs['emailTabs'][1]['tabId'];
              $company_tab = $envelope_tabs['companyTabs'][0]['tabId'];
              $checkbox_tab = $envelope_tabs['checkboxTabs'][0]['tabId'];
              $checkbox1_tab = $envelope_tabs['checkboxTabs'][1]['tabId'];
              $checkbox2_tab = $envelope_tabs['checkboxTabs'][2]['tabId'];

              //textTabs
              $office_address_tab = $envelope_tabs['textTabs'][0]['tabId'];
              $agent_type_tab = $envelope_tabs['textTabs'][1]['tabId'];
              $address_tab = $envelope_tabs['textTabs'][2]['tabId'];
              $address2_tab = $envelope_tabs['textTabs'][3]['tabId'];
              $phone_tab = $envelope_tabs['textTabs'][4]['tabId'];
              $fax_tab = $envelope_tabs['textTabs'][5]['tabId'];
              $address3_tab = $envelope_tabs['textTabs'][6]['tabId'];
              $address4_tab = $envelope_tabs['textTabs'][7]['tabId'];
              $phone2_tab = $envelope_tabs['textTabs'][8]['tabId'];
              $fax2_tab = $envelope_tabs['textTabs'][9]['tabId'];
              $agent_type2_tab = $envelope_tabs['textTabs'][10]['tabId'];

              $tabs = [
                'fullNameTabs' => [
                  ['tabId' => $full_name_tab, 'value' => $data['seller_name']],
                  ['tabId' => $full_name2_tab, 'value' => $data['seller_name']]
                ],
                'companyTabs' => [['tabId' => $full_name_tab , 'value' => $data['seller_name']]],
                'emailTabs' => [
                  ['tabId' => $email_tabs, 'value' => $data['seller_email']],
                  ['tabId' => $email2_tabs, 'value' => $data['seller_email']],

                ],
                'checkboxTabs' => [
                  ['tabId' => $checkbox_tab, 'selected' => true],
                  ['tabId' => $checkbox1_tab, 'selected' => false],
                  ['tabId' => $checkbox2_tab, 'selected' => false],
                ],
                'textTabs' => [
                  ['tabId' => $office_address_tab, 'value' => ''],
                  ['tabId' => $agent_type_tab, 'value' => 'Sellers'],
                  ['tabId' => $address_tab, 'value' => $data['address']],
                  ['tabId' => $phone_tab, 'value' => $data['seller_phone']],
                  ['tabId' => $fax_tab, 'value' => $data['seller_phone']],
                  ['tabId' => $address3_tab, 'value' => $data['address']],
                  ['tabId' => $address4_tab, 'value' => $data['address']],
                  ['tabId' => $phone2_tab, 'value' => $data['seller_phone']],
                  ['tabId' => $fax2_tab, 'value' => $data['seller_phone']],
                  ['tabId' => $agent_type2_tab, 'value' => 'Sellers'],
                ],
              ];
            }


            Docusign::updateRecipientTabs($envelopeId, $recipientId, $tabs);

          }
        }
      }
