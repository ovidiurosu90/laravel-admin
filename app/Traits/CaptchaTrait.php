<?php

namespace App\Traits;

use ReCaptcha\ReCaptcha;
use Request;

trait CaptchaTrait
{
    /**
     * Check Google Captcha Passed or Failed.
     *
     * @return bool
     */
    public function captchaCheck()
    {
        // MODIFIED: Changed from deprecated Request::get() to request()->input()
        // WHY: Request::get() is deprecated in Symfony 7.4 in favor of request()->input()
        $response = request()->input('g-recaptcha-response');
        $remoteip = $_SERVER['REMOTE_ADDR'];
        $secret = config('settings.reCaptchSecret');

        $recaptcha = new ReCaptcha($secret);
        $resp = $recaptcha->verify($response, $remoteip);

        if ($resp->isSuccess()) {
            return true;
        }

        return false;
    }
}
