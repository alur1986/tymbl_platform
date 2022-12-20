<?php

namespace App\Http\Controllers;

use App\Ad;
use App\Comment;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\URL;
use Yajra\Datatables\Datatables;
use App\NotificationsUsers;

class CommentController extends Controller
{

  public function index(){
    $title = trans('app.comments');
    return view('admin.comments', compact('title'));
  }

  public function commentData(){
    $user = Auth::user();

    if ($user->is_admin()){
      $comments = Comment::select('id','ad_id','comment_id', 'author_name', 'author_email', 'author_ip', 'comment', 'approved', 'created_at')->orderBy('id', 'desc')->get();
    }else{
      //Get user specific comments
      $get_ad_ids = $user->ads->pluck('id')->toArray();
      $comments = Comment::select('id','ad_id','comment_id', 'author_name', 'author_email', 'author_ip', 'comment', 'approved', 'created_at')->whereIn('ad_id', $get_ad_ids)->orderBy('id', 'desc')->get();
    }

    return  Datatables::of($comments)
    ->editColumn('author_name',function($comment){
      $html = "<p><i class='fa fa-user'></i> {$comment->author_name} </p>";
      $html .= "<p><i class='fa fa-envelope-o'></i> {$comment->author_email} </p>";
      $html .= "<p><i class='fa fa-map-marker'></i> {$comment->author_ip} </p>";

      $label = '<p>';
      if ($comment->approved == 1){
        $label .= '<span class="label label-success"><i class="fa fa-check-circle-o"></i> </span>';
      }else{
        $label .= '<span class="label label-default"><i class="fa fa-exclamation-circle"></i> </span>';
      }

      if ($comment->comment_id){
        $label .= '<span class="label label-default"><i class="fa fa-reply"></i> </span>';
      }else{
        $label .= '<span class="label label-info"><i class="fa fa-comment-o"></i> </span>';
      }

      $label .= '</p>';

      $html .= $label;

      return $html;
    })
    ->editColumn('created_at',function($comment){
      return $comment->created_at_datetime();
    })
    ->editColumn('comment',function($comment){
      $html = '';
      if ($comment->ad) {
        $html .= '<blockquote><i><a href="'.route('single_ad', [$comment->ad->id, $comment->ad->slug]).'#comment-'.$comment->id.'" target="_blank">';
        if ($comment->comment_id) {
          $html .= '<i class="fa fa-reply"></i> ';
        } else {
          $html .= '<i class="fa fa-comment-o"></i> ';
        }
        $html .= safe_output($comment->ad->title);

        $html .= '</a></i></blockquote>';
      }
      $html .= safe_output($comment->comment);

      return $html;
    })
    ->addColumn('actions', function($comment){
      $button = '';

      if ($comment->approved != 1){
        $button .= '<a href="javascript:;" class="btn btn-success comment_action" data-action="approve" data-id="'.$comment->id.'"><i class="fa fa-check-circle-o"></i> </a>';
      }
      $button .= '<a href="javascript:;" class="btn btn-danger comment_action" data-action="trash" data-id="'.$comment->id.'"><i class="fa fa-trash-o"></i> </a>';

      return $button;
    })
    ->removeColumn('id', 'author_email', 'author_ip', 'approved', 'comment_id', 'ad_id')
    ->make();
  }



  public function postComments(Request $request,$id){

    $rules = [
      'comment'   => 'required'
    ];
    $user_id = 0;

    $author_name = $request->author_name;
    $author_email = $request->author_email;
    if ( ! Auth::check()){
      $rules['author_name']  = 'required';
      $rules['author_email']  = 'required';
    }else{
      $user = Auth::user();
      $user_id = $user->id;
      $author_name = $user->name;
      $author_email = $user->email;
    }
    $this->validate($request, $rules);

    $ip = $request->ip();
    $comment_id = $request->comment_id;
    if ( ! $comment_id){
      $comment_id = 0;
    }

    //Auto approve if this ad owner
    $approved = 0;
    if (Auth::check()){
      $user_id = Auth::user()->id;
      $ad = Ad::find($id);
      if ($user_id == $ad->user_id){
        $approved = 1;
      }
    }else{
      $request->session()->flash('success', trans('app.comment_posted'));
    }

    $data = [
      'user_id'       => $user_id,
      'ad_id'         => $id,
      'comment_id'    => $comment_id,
      'author_name'   => $author_name,
      'author_email'  => $author_email,
      'author_ip'     => $ip,
      'comment'       => $request->comment,
      'approved'      => '1',
    ];

    //If it reply, make it approve
    if ($comment_id){
      $data['approved'] = 1;
    }
    $post_comment = Comment::create($data);

    if($post_comment && $post_comment->approved == '0'){
      $request->session()->flash('message', 'Thank you. Your comment has been submitted and being reviewed.');
    }

    $back_url = URL::previous().'#comment-'.$post_comment->id;
    return redirect($back_url);
  }


  public function commentAction(Request $request){
    $user = Auth::user();

    //Preventing unauthorised action
    $comment = Comment::find($request->comment_id);
    $comment_ad = Ad::find($comment->ad_id);

    if ($user->id != $comment_ad->user_id &&  ! $user->is_admin() ){
      return ['success' => false];
    }

    switch ($request->action){
      case 'approve':
      $comment->approved = 1;
      $comment->save();
      break;

      case 'trash':
      $comment->delete();
      break;
    }
    return ['success' => 1];

  }

  public function userComments(){
    $title = trans('app.comments');
    $user = Auth::user();

    if($user->is_admin()){
      $ads = DB::table('ads')->join('comments', 'comments.ad_id', 'ads.id')->orderBy('comments.created_at', 'desc')->paginate(10);
    }else{
      $ads = DB::table('ads')->join('comments', 'comments.ad_id', 'ads.id')->where('ads.user_id', '=', $user->id)->where('comments.approved', '=', '1')->orderBy('comments.created_at', 'desc')->paginate(10);
    }


    //$notifications_users = NotificationsUsers::where('recipient', '=', $user->id)->where('user_read', '=', '0')->get();
    //$user_notification_count = count($notifications_users);
    //dd($ads);
    return view('tymbl.dashboard.comments', compact('title', 'ads', 'user'));
  }

  public function approveComment(Request $request){
    $comment = Comment::find($request->comment_id);
    $comment->approved = 1;
    $comment->save();
    return 1;
  }

  public function deleteComment(Request $request){
    $comment = Comment::destroy($request->comment_id);
    return 1;
  }

  public function blockComment(Request $request){
    $comment = Comment::find($request->comment_id);
    $comment->approved = 2;
    $comment->save();
    return 1;
  }

}
