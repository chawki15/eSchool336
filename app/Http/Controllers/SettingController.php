<?php

namespace App\Http\Controllers;

use Throwable;
use App\Models\Settings;
use App\Models\ChatMessage;
use App\Models\SessionYear;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Services\MailService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class SettingController extends Controller
{

    public function index()
    {
        if (!Auth::user()->can('setting-create')) {
            $response = array(
                'message' => trans('no_permission_message')
            );
            return redirect(route('home'))->withErrors($response);
        }

        $settings = getSettings();
        $getDateFormat = getDateFormat();
        $getTimezoneList = getTimezoneList();
        $getTimeFormat = getTimeFormat();

        $session_year = SessionYear::orderBy('id', 'desc')->get();
        // $language = Language::select('id', 'name')->orderBy('id', 'desc')->get();
        return view('settings.index', compact('settings', 'getDateFormat', 'getTimezoneList', 'getTimeFormat', 'session_year'));
    }

    public function update(Request $request)
    {
        if (!Auth::user()->can('setting-create')) {
            $response = array(
                'message' => trans('no_permission_message')
            );
            return redirect(route('home'))->withErrors($response);
        }
        $request->validate([
            'school_name' => 'required|max:255',
            'school_email' => 'required|email',
            'school_phone' => 'required',
            'school_address' => 'required',
            'time_zone' => 'required',
            'theme_color' => 'required',
            'secondary_color' => 'required',
            'session_year' => 'required',
            'school_tagline' => 'required',
            'online_payment' => 'required|in:0,1',
            'facebook' => 'required',
            'instagram' => 'required',
            'linkedin' => 'required',
            'maplink' => 'required',
            'recaptcha_site_key' => 'nullable',
            'recaptcha_secret_key' => 'nullable',
            'recaptcha_status' => 'required'
        ]);

        $settings = [
            'school_name',
            'school_email',
            'school_phone',
            'school_address',
            'time_zone',
            'date_formate',
            'time_formate',
            'theme_color',
            'session_year',
            'school_tagline',
            'online_payment',
            'secondary_color',
            'facebook',
            'instagram',
            'linkedin',
            'maplink',
            'recaptcha_site_key',
            'recaptcha_secret_key',
            'recaptcha_status'
        ];
        try {
            foreach ($settings as $row) {
                if ($row == 'session_year') {
                    $get_id = Settings::select('message')->where('type', 'session_year')->pluck('message')->first();

                    $old_year = SessionYear::find($get_id);
                    if (!empty($old_year)) {
                        $old_year->default = 0;
                        $old_year->save();
                    }

                    $session_year = SessionYear::find($request->$row);
                    $session_year->default = 1;
                    $session_year->save();
                }
                $message = $row == 'school_name' ? str_replace('"', '', $request->$row) : $request->$row;
                Settings::updateOrCreate(
                    ['type' => $row],
                    ['message' => $message]
                );
            }

            if ($request->hasFile('logo1')) {
                $get_id = Settings::select('message')->where('type', 'logo1')->pluck('message')->first();
                if (!empty($get_id) && Storage::disk('public')->exists($get_id)) {
                    Storage::disk('public')->delete($get_id);
                }
                Settings::updateOrCreate(
                    ['type' => 'logo1'],
                    ['message' => $request->file('logo1')->store('logo', 'public')]
                );
            }
            if ($request->hasFile('logo2')) {
                $get_id = Settings::select('message')->where('type', 'logo2')->pluck('message')->first();
                if (!empty($get_id) && Storage::disk('public')->exists($get_id)) {
                    Storage::disk('public')->delete($get_id);
                }
                Settings::updateOrCreate(
                    ['type' => 'logo2'],
                    ['message' => $request->file('logo2')->store('logo', 'public')]
                );
            }
            if ($request->hasFile('favicon')) {
                $get_id = Settings::select('message')->where('type', 'favicon')->pluck('message')->first();
                if (!empty($get_id) && Storage::disk('public')->exists($get_id)) {
                    Storage::disk('public')->delete($get_id);
                }
                Settings::updateOrCreate(
                    ['type' => 'favicon'],
                    ['message' => $request->file('favicon')->store('logo', 'public')]
                );
            }
            if ($request->hasFile('login_image')) {
                $get_id = Settings::select('message')->where('type', 'login_image')->pluck('message')->first();
                if (!empty($get_id) && Storage::disk('public')->exists($get_id)) {
                    Storage::disk('public')->delete($get_id);
                }
                Settings::updateOrCreate(
                    ['type' => 'login_image'],
                    ['message' => $request->file('login_image')->store('logo', 'public')]
                );
            }

            $envSettings = Settings::whereIn('type', [
                'logo1',
                'logo2',
                'favicon',
                'school_name',
                'time_zone',
                'login_image',
                'recaptcha_site_key',
                'recaptcha_secret_key'
            ])->pluck('message', 'type');
            $logo1 = $envSettings->get('logo1') ?? '';
            $logo2 = $envSettings->get('logo2') ?? '';
            $favicon = $envSettings->get('favicon') ?? '';
            $app_name = $envSettings->get('school_name') ?? '';
            $timezone = $envSettings->get('time_zone') ?? '';
            $login_image = $envSettings->get('login_image') ?? '';
            $recaptcha_site_key = $envSettings->get('recaptcha_site_key') ?? '';
            $recaptcha_secret_key = $envSettings->get('recaptcha_secret_key') ?? '';
            $normalizedAppName = preg_replace('/[\r\n]+/', ' ', (string) $app_name);
            $normalizedTimezone = preg_replace('/[\r\n]+/', ' ', (string) $timezone);
            $normalizedSiteKey = trim((string) $recaptcha_site_key);
            $normalizedSecretKey = trim((string) $recaptcha_secret_key);

            $env_update = changeEnv([
                'LOGO1' => $logo1,
                'LOGO2' => $logo2,
                'FAVICON' => $favicon,
                'LOGIN_IMAGE' => $login_image,
                'APP_NAME' => '"' . $normalizedAppName . '"',
                'TIMEZONE' => "'" . $normalizedTimezone . "'",
                'SITE_KEY' =>  $normalizedSiteKey,
                'SECRET_KEY' => $normalizedSecretKey

            ]);
            if ($env_update) {
                $response = array(
                    'error' => false,
                    'message' => trans('data_update_successfully'),
                );
            }
        } catch (Throwable $e) {
            $response = array(
                'error' => true,
                'message' => trans('error_occurred'),
                'data' => $e
            );
        }
        return response()->json($response);
    }

    public function fcm_index()
    {
        if (!Auth::user()->can('fcm-setting-create')) {
            $response = array(
                'message' => trans('no_permission_message')
            );
            return redirect(route('home'))->withErrors($response);
        }

        $settings = getSettings();
        return view('settings.fcm_key', compact('settings'));
    }

    public function email_index()
    {
        if (!Auth::user()->can('email-setting-create')) {
            $response = array(
                'message' => trans('no_permission_message')
            );
            return redirect(route('home'))->withErrors($response);
        }
        $settings = getSettings();
        return view('settings.email_configuration', compact('settings'));
    }

    public function email_update(Request $request)
    {
        if (!Auth::user()->can('email-setting-create')) {
            $response = array(
                'message' => trans('no_permission_message')
            );
            return redirect(route('home'))->withErrors($response);
        }
        $request->validate([
            'mail_mailer' => 'required',
            'mail_host' => 'required',
            'mail_port' => 'required',
            'mail_username' => 'required',
            'mail_password' => 'required',
            'mail_encryption' => 'required',
            'mail_send_from' => 'required|email',
        ]);

        $settings = [
            'mail_mailer',
            'mail_host',
            'mail_port',
            'mail_username',
            'mail_password',
            'mail_encryption',
            'mail_send_from',
        ];

        try {
            foreach ($settings as $row) {
                Settings::updateOrCreate(
                    ['type' => $row],
                    ['message' => $request->$row]
                );
                Settings::updateOrCreate(
                    ['type' => 'email_configration_verification'],
                    ['message' => 0]
                );
            }
            $env_update = changeEnv([
                'MAIL_MAILER' => $request->mail_mailer,
                'MAIL_HOST' => $request->mail_host,
                'MAIL_PORT' => $request->mail_port,
                'MAIL_USERNAME' => $request->mail_username,
                'MAIL_PASSWORD' => $request->mail_password,
                'MAIL_ENCRYPTION' => $request->mail_encryption,
                'MAIL_FROM_ADDRESS' => $request->mail_send_from

            ]);
            if ($env_update) {
                $response = array(
                    'error' => false,
                    'message' => trans('data_update_successfully'),
                );
            } else {
                $response = array(
                    'error' => false,
                    'message' => trans('error_occurred'),
                );
            }
        } catch (Throwable $e) {
            $response = array(
                'error' => true,
                'message' => trans('error_occurred'),
                'data' => $e
            );
        }
        return response()->json($response);
    }

    public function verifyEmailConfigration(Request $request)
    {
        if (!Auth::user()->can('email-setting-create')) {
            $response = array(
                'message' => trans('no_permission_message')
            );
            return redirect(route('home'))->withErrors($response);
        }
        $validator = Validator::make($request->all(), [
            'verify_email' => 'required|email',
        ]);
        if ($validator->fails()) {
            $response = array(
                'error' => true,
                'message' => $validator->errors()->first(),
            );
            return response()->json($response);
        }
        try {
            $data = [
                'email' => $request->verify_email,
            ];
            $admin_mail = env('MAIL_FROM_ADDRESS', config('mail.from.address'));
            if (!filter_var($request->verify_email, FILTER_VALIDATE_EMAIL)) {
                $response = array(
                    'error' => true,
                    'message' => trans('invalid_email'),
                );
                return response()->json($response);
            }

            // send mail and check result
            $status =  MailService::sendWithFallback('mail', $data, function ($message) use ($data, $admin_mail) {
                $message->to($data['email'])->subject('Connection Verified successfully');
                $message->from($admin_mail, 'Eschool Admin');
            });

            if ($status) {
                Settings::where('type', 'email_configration_verification')->update(['message' => 1]);

                $response = array(
                    'error' => false,
                    'message' => trans('email_sent_successfully'),
                );
            } else {
                // Sending failed (sendWithFallback returned falsy)
                $response = array(
                    'error' => true,
                    'message' => trans('email_send_failed'),
                );
            }
        } catch (Throwable $e) {
            $response = array(
                'error' => true,
                'message' => trans('error_occurred'),
                'data' => $e
            );
        }
        return response()->json($response);
    }

    public function privacy_policy_index()
    {
        if (!Auth::user()->can('privacy-policy')) {
            $response = array(
                'message' => trans('no_permission_message')
            );
            return redirect(route('home'))->withErrors($response);
        }
        $settings = Settings::where('type', 'privacy_policy')->first();
        $type = 'privacy_policy';
        return view('settings.privacy_policy', compact('settings', 'type'));
    }

    public function contact_us_index()
    {
        if (!Auth::user()->can('contact-us')) {
            $response = array(
                'message' => trans('no_permission_message')
            );
            return redirect(route('home'))->withErrors($response);
        }
        $settings = Settings::where('type', 'contact_us')->first();
        $type = 'contact_us';
        return view('settings.contact_us', compact('settings', 'type'));
    }

    public function about_us_index()
    {
        if (!Auth::user()->can('about-us')) {
            $response = array(
                'message' => trans('no_permission_message')
            );
            return redirect(route('home'))->withErrors($response);
        }
        $settings = Settings::where('type', 'about_us')->first();
        $type = 'about_us';
        return view('settings.about_us', compact('settings', 'type'));
    }

    public function terms_condition_index()
    {
        if (!Auth::user()->can('terms-condition')) {
            $response = array(
                'message' => trans('no_permission_message')
            );
            return redirect(route('home'))->withErrors($response);
        }
        $settings = Settings::where('type', 'terms_condition')->first();
        $type = 'terms_condition';
        return view('settings.terms_condition', compact('settings', 'type'));
    }

    public function setting_page_update(Request $request)
    {
        if (!Auth::user()->can('setting-create')) {
            $response = array(
                'message' => trans('no_permission_message')
            );
            return redirect(route('home'))->withErrors($response);
        }
        $validator = Validator::make($request->all(), [
            'type' => 'required',
            'message' => 'required'
        ]);
        if ($validator->fails()) {
            $response = array(
                'error' => true,
                'message' => $validator->errors()->first(),
            );
            return response()->json($response);
        }
        $type = $request->type;
        $message = $request->message;
        $setting = Settings::updateOrCreate(
            ['type' => $type],
            ['message' => $message]
        );
        if (!$setting->wasRecentlyCreated) {
            $response = array(
                'error' => false,
                'message' => trans('data_update_successfully'),
            );
        } else {
            $response = array(
                'error' => false,
                'message' => trans('data_store_successfully'),
            );
        }

        return response()->json($response);
    }

    public function app_index()
    {
        if (!Auth::user()->can('setting-create')) {
            $response = array(
                'message' => trans('no_permission_message')
            );
            return redirect(route('home'))->withErrors($response);
        }
        $settings = getSettings();
        return view('settings.app_settings', compact('settings'));
    }

    public function app_update(Request $request)
    {
        if (!Auth::user()->can('setting-create')) {
            $response = array(
                'message' => trans('no_permission_message')
            );
            return redirect(route('home'))->withErrors($response);
        }
        $request->validate([
            'app_link' => 'required',
            'ios_app_link' => 'required',
            'app_version' => 'required',
            'ios_app_version' => 'required',
            'force_app_update' => 'required',
            'app_maintenance' => 'required',
            'teacher_app_link' => 'required',
            'teacher_ios_app_link' => 'required',
            'teacher_app_version' => 'required',
            'teacher_ios_app_version' => 'required',
            'teacher_force_app_update' => 'required',
            'teacher_app_maintenance' => 'required',
        ]);

        $settings = [
            'app_link',
            'ios_app_link',
            'app_version',
            'ios_app_version',
            'force_app_update',
            'app_maintenance',
            'teacher_app_link',
            'teacher_ios_app_link',
            'teacher_app_version',
            'teacher_ios_app_version',
            'teacher_force_app_update',
            'teacher_app_maintenance',
        ];

        try {

            foreach ($settings as $row) {
                Settings::updateOrCreate(
                    ['type' => $row],
                    ['message' => $request->$row]
                );
            }

            $response = array(
                'error' => false,
                'message' => trans('data_update_successfully'),
            );
        } catch (Throwable $e) {
            $response = array(
                'error' => true,
                'message' => trans('error_occurred'),
                'data' => $e
            );
        }
        return response()->json($response);
    }

    public function notification_setting(Request $request)
    {
        if (!Auth::user()->can('setting-create')) {
            $response = array(
                'message' => trans('no_permission_message')
            );
            return redirect(route('home'))->withErrors($response);
        }
        $request->validate([
            'sender_id' => 'required',
            'project_id' => 'required',
            //'service_account_file' => 'required|mimes:json'
        ]);
        $settings = ['sender_id', 'project_id'];
        try {
            foreach ($settings as $row) {
                Settings::updateOrCreate(
                    ['type' => $row],
                    ['message' => $request->$row]
                );
            }
            if ($request->hasFile('service_account_file')) {
                $serviceAccountFile = $request->file('service_account_file');

                $get_id = Settings::where('type', 'service_account_file')->value('message');

                // Delete the existing file
                if (!empty($get_id) && Storage::disk('public')->exists($get_id)) {
                    Storage::disk('public')->delete($get_id);
                }

                $storedPath = $serviceAccountFile->store('firebase', 'public');
                Settings::updateOrCreate(
                    ['type' => 'service_account_file'],
                    ['message' => $storedPath]
                );
            }

            $sender_id = Settings::select('message')->where('type', 'sender_id')->pluck('message')->first();
            $firebase_project_id = Settings::select('message')->where('type', 'project_id')->pluck('message')->first();
            $env_update = changeEnv([
                'SENDER_ID' =>  $sender_id,
                'FIREBASE_PROJECT_ID' =>  $firebase_project_id,
            ]);
            if ($env_update) {
                $response = array(
                    'error' => false,
                    'message' => trans('data_update_successfully'),
                );
            }
        } catch (\Throwable $e) {
            $response = array(
                'error' => true,
                'message' => trans('error_occurred'),
                'data' => $e
            );
        }

        return response()->json($response);
    }

    public function chat_setting_index()
    {
        if (!Auth::user()->can('chat-settings')) {
            $response = array(
                'message' => trans('no_permission_message')
            );
            return redirect(route('home'))->withErrors($response);
        }
        $settings = getSettings();
        return view('settings.chat_setting', compact('settings'));
    }

    public function chat_setting_update(Request $request)
    {
        if (!Auth::user()->can('chat-settings')) {
            $response = array(
                'message' => trans('no_permission_message')
            );
            return redirect(route('home'))->withErrors($response);
        }
        $request->validate([
            'max_files_or_images_in_one_message' => 'required',
            'max_file_size_in_bytes' => 'required',
            'max_characters_in_text_message' => 'required',
            'automatically_messages_removed_days' => 'required',
            'info-link' => 'required'
        ]);
        $settings = ['max_files_or_images_in_one_message', 'max_file_size_in_bytes', 'max_characters_in_text_message', 'automatically_messages_removed_days', 'info-link'];
        try {
            foreach ($settings as $row) {
                Settings::updateOrCreate(
                    ['type' => $row],
                    ['message' => $request->$row]
                );
            }
            $response = array(
                'error' => false,
                'message' => trans('data_update_successfully'),
            );
        } catch (\Throwable $e) {
            $response = array(
                'error' => true,
                'message' => trans('error_occurred'),
                'data' => $e
            );
        }
        return response()->json($response);
    }

    public function delete_chat_messages(Request $request)
    {
        if (!Auth::user()->can('chat-message-delete')) {
            $response = array(
                'message' => trans('no_permission_message')
            );
            return redirect(route('home'))->withErrors($response);
        }
        $validator = Validator::make($request->all(), [
            'from_date' => 'required|date',
            'to_date' => 'required|date|after:from_date',
        ], [
            'to_date.after' => "The 'To Date' must be a date after the 'From Date'."
        ]);

        if ($validator->fails()) {
            $response = array(
                'error' => true,
                'message' => $validator->errors()->first()
            );
            return response()->json($response);
        }
        try {
            $from_date = date('Y-m-d', strtotime($request->from_date));
            $to_date = date('Y-m-d', strtotime($request->to_date));

            ChatMessage::with('file')
                ->whereDate('date', '>=', $from_date)
                ->whereDate('date', '<=', $to_date)
                ->orderBy('id')
                ->chunkById(100, function ($chat_messages) {
                    foreach ($chat_messages as $message) {
                        if ($message->file) {
                            foreach ($message->file as $file) {
                                if ($file) {
                                    if (Storage::disk('public')->exists($file->file_name)) {
                                        Storage::disk('public')->delete($file->file_name);
                                    }
                                    $file->delete();
                                }
                            }
                        }
                        $message->delete();
                    }
                });

            $response = array(
                'error' => false,
                'message' => trans('data_delete_successfully'),
            );
        } catch (\Throwable $e) {
            $response = array(
                'error' => true,
                'message' => trans('error_occurred'),
                'data' => $e
            );
        }
        return response()->json($response);
    }

    public function cron_job()
    {
        try {
            $settings = getSettings();
            $automatically_messages_removed_days = $settings['automatically_messages_removed_days'] ?? '30';

            $date = now()->subDays($automatically_messages_removed_days);

            ChatMessage::with('file')
                ->whereDate('date', '<', $date)
                ->orderBy('id')
                ->chunkById(100, function ($chat_messages) {
                    foreach ($chat_messages as $message) {
                        if ($message->file) {
                            foreach ($message->file as $file) {
                                if ($file) {
                                    if (Storage::disk('public')->exists($file->file_name)) {
                                        Storage::disk('public')->delete($file->file_name);
                                    }
                                    $file->delete();
                                }
                            }
                        }
                        $message->delete();
                    }
                });

            $response = array(
                'error' => false,
                'message' => trans('data_delete_successfully'),
            );
        } catch (\Throwable $e) {
            $response = array(
                'error' => true,
                'message' => trans('error_occurred'),
                'data' => $e
            );
        }
        return response()->json($response);
    }
}
