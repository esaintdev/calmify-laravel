<?php

namespace Calmify\Laravel\Http;

use Calmify\Laravel\Events\PostReceived;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mews\Purifier\Facades\Purifier;

// Receives posts from Calmify. Every request carries the key in the
// X-Calmify-Key header, compared in constant time.
class ReceiverController extends Controller
{
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
        ]);
        if ($v->fails()) {
            return response()->json(['error' => 'invalid', 'fields' => $v->errors()], 422);
        }
        $data = $v->validated();
        $table = config('calmify.table');

        if ($existing = DB::table($table)->where('idempotency_key', $key)->first()) {
            return response()->json($this->reply($existing), 200);
        }

        $imagePath = null;
        if (! empty($data['image_url'])) {
            $imagePath = $this->downloadCover($data['image_url'], $data['slug']);
            if ($imagePath === null) {
                return response()->json(['error' => 'image_rejected'], 422);
            }
        }

        try {
            $id = DB::table($table)->insertGetId([
                'idempotency_key' => $key,
                'slug' => $data['slug'],
                'title' => $data['title'],
                'content_html' => Purifier::clean($data['content']),
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
