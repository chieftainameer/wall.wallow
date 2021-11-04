<?php

namespace App\Repositories;

use  App\Interfaces\TwitterInterface;
use App\Jobs\RetrieveTweetsJob;
use App\Models\DataTwitterPost;
use App\Models\DataSettingsTwitterHashtag;
use App\Models\Schedule;
use App\Models\TwitterUserName;
use App\Jobs\TweetMediaDownload;

use App\Exceptions\DuplicateDataConflictException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TwitterRepository implements TwitterInterface
{
    private $twitterApiConnection;
    private $schedules;
    private $schedulesRepo;
    public function __construct(SchedulesRepository $scheduleRepo)
    {
        $this->twitterApiConnection = Http::withToken(config('app.twitter_bearer_token'));
        $this->schedules = config('app.schedules');
        $this->schedulesRepo = $scheduleRepo;
    }

    public function create_hashtags($segment, $tag, $data)
    {
        if ($this->check_duplicate_hashtag_or_username($segment, $tag,false)) {
            return $segment->twitterHashtag()->create([
                'hashtag_name' => $tag,
                'maximum_posts' => $data['maximum_posts'],
                'moderation' => $data['moderation'],
                'refresh' => $data['refresh'],
                'template_id' => $data['template']
            ]);
        } else {
            return false;
        }
    }

    public function retrieve_hashtags_posts()
    {
    }

    public function create_twitter_posts($tag, $responseBody, $isRequestTypeMention = false)
    {
        if(! property_exists($responseBody->includes, 'media')){
            $media = null;
        }
        else
        {
            $media = $responseBody->includes->media;
        }

        for($i = count($responseBody->data) - 1; $i >= 0; $i--)
        {
            $post = $this->addToDatabase($responseBody->data[$i], $tag, $responseBody->includes->users, $media, $isRequestTypeMention);
            if(!is_null($post) && !is_null($post->tweet_media_url)){
                TweetMediaDownload::dispatch($post);
            }
        }

    }

    public function addToDatabase($tweet, $tag, $users, $media, $isRequestTypeMention)
    {

        $post = new DataTwitterPost();

        $post->tweet_id = $tweet->id;
        $post->tweet_text = $tweet->text;
        $post->tweet_author_id = $tweet->author_id;
        $post->likes = $tweet->public_metrics->like_count;
        $post->retweets = $tweet->public_metrics->retweet_count;
        $post->replies = $tweet->public_metrics->reply_count;
        foreach ($users as $user) {
            if ($user->id == $tweet->author_id) {
                $post->author_name = $user->name;
                $post->profile_image_url = $user->profile_image_url;
                $post->user_name = $user->username;
            }
        }

        if (property_exists($tweet, 'attachments')) {
            foreach ($media as $m) {
                if ($m->media_key == $tweet->attachments->media_keys[0]) {
                    if ($m->type == 'photo') {
                        $post->tweet_media_url = $m->url;
                    } else {
                        $post->tweet_media_url = $m->preview_image_url;
                    }
                }
            }
        }

        if ($isRequestTypeMention) {
            $post->twitter_user_name_id = $tag->id;
        } else {
            $post->data_settings_twitter_hashtag_id = $tag->id;
        }
        $post->save();
        return $post;
    }

    public function create_twitter_username($segment, $data)
    {
        $username = substr($data['mention'], 1);
        if($this->check_duplicate_hashtag_or_username($segment,$username,true)){
        $result = $segment->twitterUserName()->create([
            'twitter_handle' => trim($username),
            'maximum_posts' => $data['maximum_posts'],
            'moderation' => $data['moderation'],
            'refresh' => $data['refresh'],
            'template_id' => $data['template'],
        ]);

        return $result;
    }
    else
    {
        return false;
    }
    }

    public function check_duplicate_hashtag_or_username($segment, $tag,$isModelTypeUserName)
    {

        if($isModelTypeUserName){
            $duplicateEntry = TwitterUserName::where('twitter_handle',$tag)->first();
        }
        else{
            $duplicateEntry = DataSettingsTwitterHashtag::where('hashtag_name', $tag)->first();

        }
        if(is_null($duplicateEntry)){
            return true;
        }
        else
        {
            return false;
        }
    }

    public function collect_tweets($modelEntity,$numberOfPosts,$segment,$isModelTypeUserName = false)
    {
        $entity = $this->isHashtagOrUsername($modelEntity); // figures out the model class
        if ($modelEntity->posts->count() > 0)
        {
            $maxId = $modelEntity->posts->max('id');
            $latestTweet = $modelEntity->posts->where('id','=',$maxId)->first();
            $latestTweetId = $latestTweet->tweet_id;
            $url = config('app.twitter_api_url') . '?query=%' . $entity . '%20-is%3Aretweet%20lang%3Aen&tweet.fields=created_at,entities,public_metrics&expansions=attachments.media_keys,author_id&media.fields=url,type,preview_image_url,public_metrics&user.fields=name,profile_image_url,public_metrics&max_results=100&since_id=' . $latestTweetId;
        }
        else
        {
            $url = config('app.twitter_api_url') . '?query=%' . $entity . '%20-is%3Aretweet%20lang%3Aen&tweet.fields=created_at,entities,public_metrics&expansions=attachments.media_keys,author_id&media.fields=url,type,preview_image_url,public_metrics&user.fields=name,profile_image_url,public_metrics&max_results=' . $numberOfPosts;
        }
        // check whether the url is correct and a response is recieved
        try{
            $response = $this->twitterApiConnection->get($url);
            if(! $response->successful()){
                return redirect()->back()
                    ->withErrors(['twitter-api-error' => 'Connection with Twitter API could not be established']);
            }
        }catch(\Exception $e){
            return redirect()->back()
                ->withErrors(['twitter-url-error' => 'Error with the twitter API url occured']);
        }

        $responseBody = json_decode($response->body());
        if($responseBody->meta->result_count == 0){

            Log::alert("No new tweets found",['tweets' => $responseBody ]);

            $minutes = $this->schedulesRepo->setSchedule($segment->id); // from ScheduleRepository

            Log::notice("Dispatching a job which will be available after ", ['minutes' => $minutes]);

            RetrieveTweetsJob::dispatch($modelEntity,$numberOfPosts,$segment,$isModelTypeUserName)
                ->delay(now()->addMinutes($minutes));

            return redirect()
                ->back()
                ->withErrors(['hashtag' => 'No posts found for this twitter hashtag/username'.$entity . ' will try later to grab some']);
        }
        else
        {
            Log::info("Tweets retrieved ",['Number of tweets' => $responseBody->meta->result_count,'tweets' => $responseBody->data]);

            $this->create_twitter_posts($modelEntity, $responseBody,$isModelTypeUserName);
            $minutes = $this->schedulesRepo->defautlSchedule($segment->id);

            Log::info("A job is dispatched which will be available after ",['minutes' => $minutes]);

            RetrieveTweetsJob::dispatch($modelEntity,$numberOfPosts,$segment,$isModelTypeUserName)
                ->delay(now()->addMinutes($minutes));
        }
    }

    public function isHashtagOrUsername($entityModel)
    {
        if (get_class($entityModel) === "App\Models\DataSettingsTwitterHashtag")
        {
            // 23(23 = #) is concatenated with the hashtag name
            return '23'.$entityModel->hashtag_name;
        }
        else
        {
            // 40(40 = @) is concatenated with twitter username
            return '40'.$entityModel->twitter_handle;
        }
    }


} // class ends here
