<?php

namespace App\Jobs;

use App\Repositories\TwitterRepository;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class RetrieveTweetsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private $hashtag;
    private $numberOfPosts;
    private $segmentId;
    private $isModelTypeUserName;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct($hashtag,$numberOfPosts,$segment,$type)
    {
        $this->hashtag = $hashtag;
        $this->numberOfPosts = $numberOfPosts;
        $this->segmentId = $segment;
        $this->isModelTypeUserName = $type;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle(TwitterRepository $repo)
    {
        try {
            echo "✅ Retrieving tweets is under process ";
            $repo->collect_tweets($this->hashtag,$this->numberOfPosts,$this->segmentId,$this->isModelTypeUserName);
        }catch(\Exception $e)
        {
            echo '❌ '.$e->getMessage();
            Log::error("Job failed with following error" , ['error' => $e->getMessage()]);
        }
    }
}
