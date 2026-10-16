<?php

namespace Calmify\Laravel\Console;

// Sets KEY=value in the app's .env (adds it if missing).
class EnvFile
{
    public static function set(string $key, string $value): void
    {
        $path = base_path('.env');
        $env = file_exists($path) ? file_get_contents($path) : '';
        $line = $key.'='.$value;
        $env = preg_match('/^'.preg_quote($key, '/').'=.*$/m', $env)
            ? preg_replace('/^'.preg_quote($key, '/').'=.*$/m', $line, $env)
            : rtrim($env, "\n")."\n".$line."\n";
        file_put_contents($path, $env);
    }
}
