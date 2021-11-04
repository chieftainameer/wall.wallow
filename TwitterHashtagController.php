<?php

namespace App\Http\Controllers\Networks;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Template;
use App\Models\DataSettingsTwitterHashtag;
use App\Models\DataTwitterPost;
use App\Models\TwitterUserName;
use App\Repositories\TwitterRepository;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\Rule;


use App\Models\Segment;
use App\Exceptions\CreationFailedException;
use App\Exceptions\DataNotFoundException;
use App\Exceptions\InvalidActionPerformException;
use App\Exceptions\DataUpdateException;

class TwitterHashtagController extends Controller
{
    private $twitterApiConnection;
    private $twitterRepo;

    public function __construct(TwitterRepository $twitterRepo)
    {
        $this->twitterApiConnection = Http::withToken(config('app.twitter_bearer_token'));
        $this->twitterRepo = $twitterRepo;
    }

    public function index(Request $req)

    {
        $validator = Validator::make(['segment' => $req->segment], [
            'segment' => 'required|numeric'
        ]);

        if ($validator->fails()) {
            abort(404);
        }
        abort_unless(Segment::find($req->segment)->exists(), 404);

        $segment_detail = Segment::with('twitterHashtag', 'playlist', 'twitterUserName')
            ->where('type', 'twitterHashtag')
            ->where('id', $req->segment)
            ->first();

        return view('networks.twitterHashtags.index', ['segment' => $segment_detail]);
    }

    public function show($twitterHashtag, Request $request)
    {

            $tag = DataSettingsTwitterHashtag::with('posts')->find($twitterHashtag);
            return view('networks.twitterHashtags.show', ['segment' => $request->segment, 'hashtag' => $tag]);
    }

    public function create(Request $request)
    {
        $templates = Template::where('type', 'twitterHashtag')->get();
        return view('networks.twitterHashtags.create', ['templates' => $templates]);
    }

    public function store(Request $request)
    {
        //  validation rules for the incoming data
        $data = $request->validate([
            'hashtag' => 'required|string|min:2|max:191',
            'maximum_posts' => 'required|numeric|gt:10|lt:40',
            'moderation' => 'required|in:noModeration,autoApprove,manual',
            'refresh' => 'required|in:automatic,manualonly,eachday',
            'segment' => 'nullable|numeric',
            'wall' => 'nullable|numeric',
            'template' => 'required|exists:templates,id'
        ]);

        // retrieving all of the hashtags passed through the request object
        $hashtag = trim($data['hashtag']);

        if(substr($hashtag,0,1) !== '#'){
            return redirect()
            ->back()
            ->withErrors(['hashtag' => trans('networks/twitterHashtags/create.error_no_hashtag_symbol') . \Str::of($hashtag)->camel()])->withInput();
        }

        if(str_contains($hashtag,' ')){
            return redirect()
            ->back()
            ->withErrors(['hashtag' => trans('networks/twitterHashtags/create.error_hashtag_with_spaces') . \Str::of($hashtag)->camel()])->withInput();
        }

        $hashtag = substr($hashtag,1);

        // if a segment does not exist then create one or just return it if exists
        try {

            if (is_null($data['segment']) && isset($data['wall'])) {;

                // beginning a database transaction to revert all changes made to the db in case of a query failure...

                DB::beginTransaction();
                $segment = Segment::create([
                    'type' => 'twitterHashtag',
                    'playlist_id' => $data['wall'],
                    'order' => $this->getNextOrderNumber($data['wall'])
                ]);
            }

            else {
                $segment = Segment::find($data['segment']);
            }


            if(is_null($segment)){
                return redirect()
                ->back()
                ->withErrors(['hashtag' => 'Segment was not created try again']);
            }

                $hashtagEntity = $this->twitterRepo->create_hashtags($segment, $hashtag, $data);
                if ($hashtagEntity) {
                    $this->twitterRepo->collect_tweets($hashtagEntity,$data['maximum_posts'],$segment);
                }
                else {
                    return redirect()
                    ->back()
                    ->withErrors(['hashtag' => 'Whole operation aborted! #' . $hashtag . ' already associated with other segment']);
                }

            DB::commit();
        }

        catch (\Exception $e) {
            DB::rollback();
        }

        return redirect()->route('walls.index');
    }

    public function edit(Request $request, DataSettingsTwitterHashtag $twitterHashtag)
    {
        return view('networks.twitterHashtags.edit', ['hashtag' => $twitterHashtag, 'segment' => $request->segment]);
    }

    public function update(Request $request, DataSettingsTwitterHashtag $twitterHashtag)
    {
        $data = $request->validate([
            'maximum_posts' => 'required|numeric|gt:1|lt:30',
            'moderation' => 'required|in:noModeration,autoApprove,manual',
            'refresh' => 'required|in:automatic,manualonly,eachday',
            'segment' => 'required|numeric'
        ]);

        $segment = $data['segment'];
        unset($data['segment']);
        $twitterHashtag->update($data);
        return view('networks.twitterHashtags.show', ['hashtag' => $twitterHashtag, 'segment' => $segment]);
    }

    public function destroy(Request $request, DataSettingsTwitterHashtag $twitterHashtag)
    {
        if ($twitterHashtag->delete()) {
            $segment = $twitterHashtag->segment;
            if (is_null($segment->twitterHashtag)) {
                $segment->delete();
            }

            return redirect()->route('walls.index');
        }

        else {
            return back()->withError("Couldn\'t delete that record");
        }
    }

    public function manageContent(Request $request, DataSettingsTwitterHashtag $twitterHashtag)
    {
        $request->validate(['onlyShow' => [Rule::in(['new', 'published', 'unpublished'])]]);

        $onlyShow = $request->input('onlyShow') ?? 'new';

        return view('networks.shared.manage_content', ['dataSetting' => $twitterHashtag, 'onlyShow' => $onlyShow]);
    }

    private function getNextOrderNumber($wall)
    {
        $maxOrder = Segment::where('playlist_id', $wall)->max('order');
        if (is_null($maxOrder)) {
            $maxOrder = 0;
        }
        return intval($maxOrder) + 1;
    }

    public function updatePosts(Request $request)
    {
        $permittedActions = ['approve', 'disapprove', 'approveDisapproved'];

        if (!in_array($request->action, $permittedActions)) {
            throw new InvalidActionPerformException("Sorry... the performed action is not a permitted action");
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
