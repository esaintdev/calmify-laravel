<?php

namespace Calmify\Laravel\Events;

// Fired after a post from Calmify is saved. Listen for it to copy the post
// into your own model (the saved row is in $post).
class PostReceived
{
    public function __construct(public object $post) {}
}
