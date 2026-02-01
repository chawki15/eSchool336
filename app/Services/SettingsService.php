<?php


namespace App\Services;


use App\Models\Setting;
use App\Models\SessionYear;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;


class SettingsService
{
    protected string $cacheKey = 'app_settings';


public function all(): array
{
return Cache::rememberForever($this->cacheKey, function () {
return Setting::pluck('value', 'key')->toArray();
});
}
public function get(string $key, $default = null)
{
return $this->all()[$key] ?? $default;
}


public static function set(string $key, $value): void
{
Setting::updateOrCreate(['key' => $key], ['value' => $value]);
Cache::forget('app_settings');
}


public static function setMany(array $data): void
{
foreach ($data as $key => $value) {
    self::set($key, $value);
}
}


public static function setSessionYear(int $id): void
{
SessionYear::query()->update(['is_active' => 0]);
SessionYear::where('id', $id)->update(['is_active' => 1]);
self::set('session_year', $id);
}


public static function handleFile(string $key, UploadedFile $file): void
{
    $path = $file->store('settings', 'public');
self::set($key, $path);
}
}