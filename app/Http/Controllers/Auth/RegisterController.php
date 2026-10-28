<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Profile;
use App\Models\Role;
use App\Models\User;
use App\Traits\ActivationTrait;
use App\Traits\CaptchaTrait;
use App\Traits\CaptureIpTrait;
use Illuminate\Foundation\Auth\RegistersUsers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class RegisterController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | Register Controller
    |--------------------------------------------------------------------------
    |
    | This controller handles the registration of new users as well as their
    | validation and creation. By default this controller uses a trait to
    | provide this functionality without requiring any additional code.
    |
     */

    use ActivationTrait;
    use CaptchaTrait;
    use RegistersUsers;

    /**
     * Each role's variant for an account awaiting activation (ActivateController::activationRole() reverses it).
     */
    public const UNVERIFIED_ROLES = ['user' => 'unverified', 'financeadmin' => 'unverifiedfinanceadmin'];

    /**
     * Where to redirect users after registration.
     *
     * @var string
     */
    protected $redirectTo = '/activate';

    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct()
    {
        // As middleware, not in the constructor: route:list builds every controller with no request to check.
        $this->middleware(function (Request $request, $next) {
            if (config('auth.require_invite_token')
                && (empty($request->invite_token)
                    || $request->invite_token != config('auth.invite_token'))
            ) {
                abort(403);
            }

            return $next($request);
        });
        $this->middleware('guest', [
            'except' => 'logout',
        ]);
    }

    /**
     * Get a validator for an incoming registration request.
     *
     * @param  array  $data
     * @return \Illuminate\Contracts\Validation\Validator
     */
    protected function validator(array $data)
    {
        $data['captcha'] = $this->captchaCheck();

        if (! config('settings.reCaptchStatus')) {
            $data['captcha'] = true;
        }

        return Validator::make(
            $data,
            [
                'name'                  => 'required|max:255|unique:users'
                                           . '|alpha_dash',
                'first_name'            => 'alpha_dash',
                'last_name'             => 'alpha_dash',
                'email'                 => 'required|email|max:255|unique:users',
                'password'              => 'required|min:6|max:30|confirmed',
                'password_confirmation' => 'required|same:password',
                'g-recaptcha-response'  => '',
                'captcha'               => 'required|min:1',
            ],
            [
                'name.unique'                   => trans('auth.userNameTaken'),
                'name.required'                 => trans('auth.userNameRequired'),
                'first_name.required'           => trans('auth.fNameRequired'),
                'last_name.required'            => trans('auth.lNameRequired'),
                'email.required'                => trans('auth.emailRequired'),
                'email.email'                   => trans('auth.emailInvalid'),
                'password.required'             => trans('auth.passwordRequired'),
                'password.min'                  => trans('auth.PasswordMin'),
                'password.max'                  => trans('auth.PasswordMax'),
                'g-recaptcha-response.required' => trans('auth.captchaRequire'),
                'captcha.min'                   => trans('auth.CaptchaWrong'),
            ]
        );
    }

    /**
     * The role of a new account: financeadmin for a valid finance invite token, user otherwise; the unverified variant
     * of either while the account awaits activation.
     */
    public static function registrationRole(mixed $financeInviteToken, bool $activated): ?Role
    {
        $validToken = is_string($financeInviteToken) && $financeInviteToken !== ''
            && hash_equals((string) config('auth.finance_invite_token'), $financeInviteToken);
        $slug = $validToken ? 'financeadmin' : 'user';

        return Role::where('slug', '=', $activated ? $slug : self::UNVERIFIED_ROLES[$slug])->first();
    }

    /**
     * Create a new user instance after a valid registration.
     *
     * @param  array  $data
     * @return User
     */
    protected function create(array $data)
    {
        $ipAddress = new CaptureIpTrait();
        $activated = !config('settings.activation');
        $role      = self::registrationRole($data['myfinance2_invite_token'] ?? null, $activated);

        $user = User::create([
            'name'              => strip_tags($data['name']),
            'first_name'        => strip_tags($data['first_name']),
            'last_name'         => strip_tags($data['last_name']),
            'email'             => $data['email'],
            'password'          => Hash::make($data['password']),
            'token'             => str_random(64),
            'signup_ip_address' => $ipAddress->getClientIp(),
            'activated'         => $activated,
        ]);

        $user->attachRole($role);
        $this->initiateEmailActivation($user);

        $profile = new Profile();
        $user->profile()->save($profile);
        $user->save();

        return $user;
    }
}

