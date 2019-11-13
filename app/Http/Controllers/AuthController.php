<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Twilio\Rest\Client;

class AuthController extends Controller
{

    /**
     * Create a new user instance after a valid registration.
     *
     * @param  array  $data
     * @return \App\User
     */
    protected function create(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone_number' => ['required', 'numeric', 'unique:users'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        /* Get credentials from .env */
        $token = getenv("TWILIO_AUTH_TOKEN");
        $twilio_sid = getenv("TWILIO_SID");
        $twilio_verify_sid = getenv("TWILIO_VERIFY_SID");

        $twilio = new Client($twilio_sid, $token);

        $twilio->verify->v2->services($twilio_verify_sid)
            ->verifications
            ->create($data['phone_number'], "sms");

        User::create([
            'name' => $data['name'],
            'phone_number' => $data['phone_number'],
            'password' => Hash::make($data['password']),
        ]);

        return redirect()->route('verify')->with(['phone_number' => $data['phone_number']]);
    }

    protected function verify(Request $request)
    {
        $data = $request->validate([
            'verification_code' => ['required', 'numeric'],
            'phone_number' => ['required', 'string'],
        ]);

        /* Get credentials from .env */
        $token = getenv("TWILIO_AUTH_TOKEN");
        $twilio_sid = getenv("TWILIO_SID");
        $twilio_verify_sid = getenv("TWILIO_VERIFY_SID");

        $twilio = new Client($twilio_sid, $token);

        $verification = $twilio->verify->v2->services($twilio_verify_sid)
            ->verificationChecks
            ->create($data['verification_code'], array('to' => $data['phone_number']));

        if ($verification->valid) {
            $user = tap(User::where('phone_number', $data['phone_number']))->update(['isVerified' => true]);
            /* Authenticate user */
            Auth::login($user->first());
            return redirect()->route('home')->with(['message' => 'Phone number verified']);
        }
        return back()->with(['phone_number' => $data['phone_number'], 'error' => 'Invalid verification code entered!']);
    }

}
