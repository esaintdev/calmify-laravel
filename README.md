# calmify/laravel

Receive articles from [Calmify](https://calmifye.vercel.app) on a Laravel site.

```bash
composer require calmify/laravel
php artisan calmify:install          # key in .env, table, route
php artisan calmify:pair CALM-XXXX-XXXX   # code from Calmify → Websites → Connection → Connect
```

Posts arrive at `POST /api/calmify/posts` and are saved to `calmify_posts` (sanitized HTML, cover image on the `public` disk). Show them on your site, or listen for `Calmify\Laravel\Events\PostReceived` to copy them into your own model. Configure with `php artisan vendor:publish --tag=calmify-config`.

Full guide: https://calmifye.vercel.app/docs/connect
