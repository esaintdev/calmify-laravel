<?php

namespace Calmify\Laravel\Events;

// Fired after Calmify unpublishes a post: the row stays (with its cover) but
// published_at is null. Hide your own copy of it ($post->slug). Publishing it
// again from Calmify fires PostReceived with updated = true.
class PostUnpublished
{
    public function __construct(public object $post) {}
}
