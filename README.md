# calmify/laravel

Receive articles from [Calmify](https://calmifye.vercel.app) on a Laravel site.

```bash
composer require calmify/laravel
php artisan calmify:install          # key in .env, table, route
php artisan calmify:pair CALM-XXXX-XXXX   # code from Calmify → Websites → Connection → Connect
```

Posts arrive at `POST /api/calmify/posts` and are saved to `calmify_posts` (sanitized HTML, cover image on the `public` disk). Show them on your site, or listen for `Calmify\Laravel\Events\PostReceived` to copy them into your own model.

When an article is edited and sent again from Calmify ("Update on site"), the request carries the post's id (`post_id`) and the receiver replaces that row (title, content, meta, cover; the slug and first publish date stay), answers `200` with the same id, and fires `PostReceived` again with `$event->updated === true`. A listener that copies by slug (`updateOrCreate(['slug' => $post->slug], …)`) needs no change. The previous cover file is deleted when a new one (or none) replaces it, so copy `image_path` again on every event.

"Unpublish from site" in Calmify sends `DELETE` to the same address with `post_id` and `slug`. The receiver sets `published_at` to null (the row and cover stay) and fires `Calmify\Laravel\Events\PostUnpublished`; hide your own copy there, for example `Article::where('slug', $event->post->slug)->update(['published_at' => null])`. Publishing again from Calmify restores the post and fires `PostReceived` with `updated === true`. Configure with `php artisan vendor:publish --tag=calmify-config`.

Full guide: https://calmifye.vercel.app/docs/connect
