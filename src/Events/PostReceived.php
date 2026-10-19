<?php

namespace Calmify\Laravel\Events;

// Fired after a post from Calmify is saved. Listen for it to copy the post
// into your own model (the saved row is in $post). $updated is true when
// Calmify sent an edit of a post it published before (same id and slug).
class PostReceived
{
    public function __construct(public object $post, public bool $updated = false) {}
}
