<?php

namespace App\Http\Controllers\Networks;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\TwitterUserName;
use App\Models\DataTwitterPost;
use App\Models\Segment;
use App\Models\Template;
use App\Repositories\TwitterRepository;
use App\Exceptions\DataUpdateException;

use Illuminate\Support\Str;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\Rule;


class TwitterMentionController extends Controller
{
    private $twitterApiConnection;
    private $twitterRepo;

    public function __construct(TwitterRepository $twitterRepo)
    {
        $this->twitterApiConnection = Http::withToken(config('app.twitter_bearer_token'));
        $this->twitterRepo = $twitterRepo;
    }


    public function index(Request $request){

    }

    public function show(Request $request, $twitterMention){
        $mention = TwitterUserName::with('posts')->find($twitterMention);

        return view('networks.twitterMentions.show',['mention' => $mention,'segment' => $request->segment]);
    }

    public function create(Request $request){

        $template = Template::where('type','twitterMention')->get();
        return view('networks.twitterMentions.create',['templates' => $template]);

    }

    public function store(Request $request){

        $data = $request->validate([
            'mention' => 'required|string|min:3|max:25',
            'maximum_posts' => 'required|numeric|gt:9|lt:40',
            'moderation' => 'required|in:noModeration,autoApprove,manual',
            'refresh' => 'required|in:automatic,manualonly,eachday',
            'segment' => 'nullable|numeric',
            'wall' => 'nullable|numeric',
            'template' => 'required|exists:templates,id',
        ]);

        $mention = trim($data['mention']);

        if(substr($mention,0,1) !== '@'){
            return redirect()->back()->withErrors(['mention' => trans('networks/twitterMentions/create.error_no_at_symbol') . \Str::of($mention)->camel()])->withInput();
        }

        if(str_contains($mention,' ')){
            return redirect()->back()->withErrors(['mention' => trans('netwworks/twitterMentions/create.error_mention_with_spaces') . \Str::of($mention)->camel()])->withInput();
        }

        $mention = substr($mention,1);

        try{
            if (is_null($data['segment']) && isset($data['wall'])) {;

                // beginning a database transaction to revert all changes made to the db in case of a query failure...

                DB::beginTransaction();
                $segment = Segment::create([
                    'type' => 'twitterMention',
                    'playlist_id' => $data['wall'],
                    'order' => $this->getNextOrderNumber($data['wall'])
                ]);
            }
            else {
                $segment = Segment::find($data['segment']);
            }

            if(is_null($segment)){
                return redirect()->back()->withErrors(['mention' => 'Segment was not created try again']);
            }
            else
            {
                $username = $this->twitterRepo->create_twitter_username($segment,$data);

                if ($username) {
                    $this->twitterRepo->collect_tweets($username,$data['maximum_posts'],$segment,true);
                }
                else {
                    return redirect()
                    ->back()
                    ->withErrors(['mention' => 'Either username is wrongly formatted or another segment already exists with ' . $data['mention']]);
                }
            }
            DB::commit();
        }
        catch(\Exception $e){
            DB::rollback();
        }

        return redirect()->route('walls.index');
    }
    public function edit(Request $request,TwitterUserName $twitterMention){
         return view('networks.twitterMentions.edit', ['mention' => $twitterMention, 'segment' => $request->segment]);
    }
    public function update(){

    }
    public function destroy(Request $request, TwitterUserName $twitterMention){

        if ($twitterMention->delete()) {
            $segment = $twitterMention->segment;

            if (is_null($segment->twitterMention)) {
                $segment->delete();
            }

            return redirect()->route('walls.index');
        }
        else {
            return back()->withError("Couldn\'t delete that record");
        }
    }

    public function manageContent(Request $request, TwitterUserName $twitterMention)
    {
        $request->validate(['onlyShow' => [Rule::in(['new', 'published', 'unpublished'])]]);

        $onlyShow = $request->input('onlyShow') ?? 'new';

        return view('networks.shared.manage_content', ['dataSetting' => $twitterMention, 'onlyShow' => $onlyShow]);
    }

    private function getNextOrderNumber($wall){

        $maxOrder = Segment::where('playlist_id',$wall)->max('order');
        if(is_null($maxOrder)){
            $maxOrder = 0;
        }
        return intval($maxOrder) + 1;
    }

    public function updatePosts(Request $request){
        $permittedActions = ['approve', 'disapprove', 'approveDisapproved'];

        if (!in_array($request->action, $permittedActions)) {
            return redirec()->back()->withErrors(['prohibited_action' => 'This '.$request->action.' is not allowed to perform']);
        }

        $posts = $request->posts;

        switch ($request->action) {
            case 'approve':
                foreach ($posts as $postId) {
                    $result = DataTwitterPost::find($postId)->approvePost();
                    if (!$result) {
                        throw new DataUpdateException('Data was n0t updated..try once again');
                    }
                }
                break;
            case 'disapprove':
                foreach ($posts as $postId) {
                    $result = DataTwitterPost::find($postId)->disapprovePost();
                    if (!$result) {
                        throw new DataUpdateException('Data was n0t updated..try once again');
                    }
                }
                break;
            case 'approveDisapproved':
                $hashtag = DataSettingsTwitterHashtag::find($request->resourceId);
                if (is_null($hashtag)) {
                    throw new DataNotFoundException('Requested hashtag againest this resource is was not found');
                }
                foreach ($hashtag->disapprovedPosts() as $post) {
                    $result = $post->approvePost();
                    if (!$result) {
                        throw new DataUpdateException('Data was n0t updated..try once again');
                    }
                }
                break;
        }

    }
}
