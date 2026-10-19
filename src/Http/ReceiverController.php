<?php

namespace Calmify\Laravel\Http;

use Calmify\Laravel\Events\PostReceived;
use Calmify\Laravel\Events\PostUnpublished;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mews\Purifier\Facades\Purifier;

// Receives posts from Calmify. Every request carries the key in the
// X-Calmify-Key header, compared in constant time. A request with post_id
// updates that post (its id and slug must match); one without creates a post.
// DELETE with post_id and slug unpublishes the post (hides it, keeps the row).
class ReceiverController extends Controller
{
    // What Calmify sends: headings, paragraphs, lists, quotes, emphasis and
    // links with their rel. Purifier's default list has no headings and
    // strips rel (nofollow), so the package passes its own.
    private const PURIFIER = [
        'HTML.Allowed' => 'h2,h3,h4,p,br,strong,b,em,i,u,blockquote,ul,ol,li,a[href|title|rel],img[src|alt|width|height]',
        'Attr.AllowedRel' => ['nofollow', 'noopener', 'noreferrer'],
    ];

    private const MAX_BODY = 600 * 1024;
    private const MAX_IMAGE = 5 * 1024 * 1024;

    private function authorized(Request $request): bool
    {
        $expected = (string) config('calmify.api_key');
        return $expected !== '' && hash_equals($expected, (string) $request->header('X-Calmify-Key', ''));
    }

    // Connection check: creates nothing.
    public function ping(Request $request): JsonResponse
    {
        if (! $this->authorized($request)) {
            return response()->json(['error' => 'unauthorized'], 401);
        }
        return response()->json(['ok' => true, 'receiver' => 'laravel']);
    }

    public function receive(Request $request): JsonResponse
    {
        if (! $this->authorized($request)) {
            return response()->json(['error' => 'unauthorized'], 401);
        }
        if (strlen($request->getContent()) > self::MAX_BODY) {
            return response()->json(['error' => 'too_large'], 413);
        }
        $key = (string) $request->header('Idempotency-Key', '');
        if ($key === '' || strlen($key) > 200) {
            return response()->json(['error' => 'missing Idempotency-Key'], 422);
        }
        $v = validator($request->json()->all(), [
            'title' => 'required|string|max:300',
            'slug' => ['required', 'string', 'max:120', 'regex:/^[a-z0-9]+(-[a-z0-9]+)*$/'],
            'content' => 'required|string|max:500000',
            'category' => 'nullable|string|max:100',
            'meta_description' => 'nullable|string|max:320',
            'meta_keywords' => 'nullable|string|max:1000',
            'image_url' => 'nullable|string|max:2000',
            'image_alt' => 'nullable|string|max:300',
            'publish' => 'nullable|boolean',
            'post_id' => 'nullable|integer|min:1',
        ]);
        if ($v->fails()) {
            return response()->json(['error' => 'invalid', 'fields' => $v->errors()], 422);
        }
        $data = $v->validated();
        $table = config('calmify.table');

        if ($existing = DB::table($table)->where('idempotency_key', $key)->first()) {
            return response()->json($this->reply($existing), 200);
        }

        $current = null;
        if (! empty($data['post_id'])) {
            // The slug can't change after publishing, so both must match.
            $current = DB::table($table)->where('id', $data['post_id'])->where('slug', $data['slug'])->first();
            if (! $current) {
                return response()->json(['error' => 'post_not_found'], 404);
            }
        }

        $imagePath = null;
        if (! empty($data['image_url'])) {
            // Pairing saves the asset host; without it no cover can be fetched.
            if ((string) config('calmify.asset_host') === '') {
                return response()->json(['error' => 'asset_host_not_set'], 422);
            }
            $imagePath = $this->downloadCover($data['image_url'], $data['slug']);
            if ($imagePath === null) {
                return response()->json(['error' => 'image_rejected'], 422);
            }
        }

        if ($current) {
            return $this->update($table, $current, $key, $data, $imagePath);
        }

        try {
            $id = DB::table($table)->insertGetId([
                'idempotency_key' => $key,
                'slug' => $data['slug'],
                'title' => $data['title'],
                'content_html' => Purifier::clean($data['content'], self::PURIFIER),
                'category' => $data['category'] ?? '',
                'meta_description' => $data['meta_description'] ?? '',
                'meta_keywords' => $data['meta_keywords'] ?? '',
                'image_path' => $imagePath,
                'image_alt' => $data['image_alt'] ?? '',
                'published_at' => ($data['publish'] ?? true) ? now() : null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (QueryException $e) {
            if ($imagePath) {
                Storage::disk(config('calmify.image_disk'))->delete($imagePath);
            }
            if ($existing = DB::table($table)->where('idempotency_key', $key)->first()) {
                return response()->json($this->reply($existing), 200);
            }
            return response()->json(['error' => 'slug_taken'], 409);
        }

        $post = DB::table($table)->find($id);
        event(new PostReceived($post));
        return response()->json($this->reply($post), 201);
    }

    // Replaces the post's fields with what Calmify sent (the cover too: none
    // sent means none kept) and fires PostReceived with updated = true.
    private function update(string $table, object $current, string $key, array $data, ?string $imagePath): JsonResponse
    {
        $disk = Storage::disk(config('calmify.image_disk'));
        try {
            DB::table($table)->where('id', $current->id)->update([
                'idempotency_key' => $key,
                'title' => $data['title'],
                'content_html' => Purifier::clean($data['content'], self::PURIFIER),
                'category' => $data['category'] ?? '',
                'meta_description' => $data['meta_description'] ?? '',
                'meta_keywords' => $data['meta_keywords'] ?? '',
                'image_path' => $imagePath,
                'image_alt' => $data['image_alt'] ?? '',
                'published_at' => $current->published_at ?? (($data['publish'] ?? true) ? now() : null),
                'updated_at' => now(),
            ]);
        } catch (QueryException $e) {
            if ($imagePath) {
                $disk->delete($imagePath);
            }
            if ($existing = DB::table($table)->where('idempotency_key', $key)->first()) {
                return response()->json($this->reply($existing), 200);
            }
            throw $e;
        }
        if ($current->image_path && $current->image_path !== $imagePath) {
            $disk->delete($current->image_path);
        }
        $post = DB::table($table)->find($current->id);
        event(new PostReceived($post, true));
        return response()->json($this->reply($post), 200);
    }

    // Unpublishing hides the post: published_at becomes null, the row and its
    // cover stay so publishing again from Calmify brings the same post back.
    // Repeating it is harmless.
    public function unpublish(Request $request): JsonResponse
    {
        if (! $this->authorized($request)) {
            return response()->json(['error' => 'unauthorized'], 401);
        }
        $v = validator($request->json()->all(), [
            'post_id' => 'required|integer|min:1',
            'slug' => ['required', 'string', 'max:120', 'regex:/^[a-z0-9]+(-[a-z0-9]+)*$/'],
        ]);
        if ($v->fails()) {
            return response()->json(['error' => 'invalid', 'fields' => $v->errors()], 422);
        }
        $data = $v->validated();
        $table = config('calmify.table');
        $post = DB::table($table)->where('id', $data['post_id'])->where('slug', $data['slug'])->first();
        if (! $post) {
            return response()->json(['error' => 'post_not_found'], 404);
        }
        if ($post->published_at !== null) {
            DB::table($table)->where('id', $post->id)->update(['published_at' => null, 'updated_at' => now()]);
            $post = DB::table($table)->find($post->id);
        }
        event(new PostUnpublished($post));
        return response()->json($this->reply($post) + ['unpublished' => true], 200);
    }

    private function reply(object $post): array
    {
        return ['id' => $post->id, 'url' => str_replace('{slug}', $post->slug, config('calmify.post_url'))];
    }

    // Only from the asset host saved at pairing, over HTTPS, no redirects,
    // at most 5 MB, and only real PNG/JPEG/WebP bytes.
    private function downloadCover(string $url, string $slug): ?string
    {
        $parts = parse_url($url);
        $host = (string) config('calmify.asset_host');
        if (($parts['scheme'] ?? '') !== 'https' || $host === '' || strcasecmp($parts['host'] ?? '', $host) !== 0 || isset($parts['user'])) {
            return null;
        }
        $buffer = '';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_WRITEFUNCTION => function ($ch, $chunk) use (&$buffer) {
                $buffer .= $chunk;
                return strlen($buffer) > self::MAX_IMAGE ? 0 : strlen($chunk);
            },
        ]);
        $ok = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if (! $ok || $status !== 200 || $buffer === '' || strlen($buffer) > self::MAX_IMAGE) {
            return null;
        }
        $ext = match (true) {
            str_starts_with($buffer, "\x89PNG\r\n\x1a\n") => 'png',
            str_starts_with($buffer, "\xFF\xD8\xFF") => 'jpg',
            substr($buffer, 0, 4) === 'RIFF' && substr($buffer, 8, 4) === 'WEBP' => 'webp',
            default => null,
        };
        if ($ext === null) {
            return null;
        }
        $path = config('calmify.image_dir').'/'.$slug.'-'.Str::random(8).'.'.$ext;
        Storage::disk(config('calmify.image_disk'))->put($path, $buffer);
        return $path;
    }
}
