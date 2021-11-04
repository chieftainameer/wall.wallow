<?php

namespace App\Interfaces;

interface TwitterInterface
{
    public function create_hashtags($segment, $tag, $data);
    public function check_duplicate_hashtag_or_username($segment, $tag,$isModelTypeUserName);
    public function retrieve_hashtags_posts();
    public function create_twitter_posts($tweet, $res, $isRequestTypeMention = false);
    public function addToDatabase($tweet, $tag, $users, $media, $postType);
    public function create_twitter_username($segment, $username);
    public function collect_tweets($hashtag,$numberOfPosts,$segment);
    public function isHashtagOrUsername($entity);

}
