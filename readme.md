## Verifying users phone numbers in a Laravel application with Twilio verify

In this tutorial, we will look at how to verify phone numbers using Twilio Verify by building a simple authentication system in Laravel.

[Twilio Verify](https://www.twilio.com/verify) makes it easier and safer than custom verification systems to verify a user’s phone number. It ensures that the phone number is valid by sending an SMS short code to the number during registration. This can help reduce the amount of fake accounts created and failure rates when sending SMS notifications to users.

## Prerequisite

In order to follow this tutorial, you will need:

- Basic knowledge of Laravel
- [Laravel](https://laravel.com/docs/master) Installed on your local machine
- [Composer](https://getcomposer.org/) globally installed
- [Twilio Account](https://www.twilio.com/referral/B2YAW1)

## Getting started

Create a new Laravel project using the [Laravel Installer](https://laravel.com/docs/5.8#installation). If you don’t have it installed or prefer to use [Composer](https://laravel.com/docs/6.x/installation), you can check how to do so from the [Laravel documentation](https://laravel.com/docs/master). Run this command in your console window to generate a fresh Laravel project:

    $ laravel new twilio-phone-verify  

Now change your working directory to `twilio-phone-verify` and install the [Twilio PHP SDK](https://www.twilio.com/docs/libraries/php) via composer:

    $ cd twilio-phone-verify
    $ composer require twilio/sdk 

If you don’t have Composer installed on your computer you can do so by following the instructions [here](https://getcomposer.org/doc/00-intro.md).

You will need your Twilio credentials from the Twilio dashboard to complete the next step. Head over to your [dashboard](https://www.twilio.com/console) and grab your `account_sid` and `auth_token`.

![](https://paper-attachments.dropbox.com/s_F2A8B2F68E4E7251C0E01BC69920BEB8CE8E4B362D8D4BA952FACA12F8136664_1573635422612_Group+8.png)

Navigate to the [Verify](https://www.twilio.com/console/verify) section to create a new [Twilio Verify Service](https://www.twilio.com/console/verify/services). Take note of the `sid` generated for you after creating the Verify service as this will be used for authenticating the instance of the Verify sdk. 

![](https://paper-attachments.dropbox.com/s_F2A8B2F68E4E7251C0E01BC69920BEB8CE8E4B362D8D4BA952FACA12F8136664_1573635718713_Group+10.png)

Update the `.env` file with your Twilio credentials. Open up `.env` located at the root of the project directory and add these values:

    TWILIO_SID="INSERT YOUR TWILIO SID HERE"
    TWILIO_AUTH_TOKEN="INSERT YOUR TWILIO TOKEN HERE"
    TWILIO_VERIFY_SID="INSERT YOUR TWILIO SYNC SERVICE SID"

### Setting up Database
This tutorial will require a [MySQL](https://www.mysql.com/) database for your application. If you use a MySQL client like [phpMyAdmin](https://www.phpmyadmin.net/) to manage your databases, then go ahead and create a database named `phone-verify` and skip this section. If not, install MySQL from the [official site](https://www.mysql.com/downloads/) for your platform of choice. After successful installation, fire up your terminal and run this command to login to MySQL:

    $ mysql -u {your_user_name}

**NOTE:** *Add the `-p` flag if you have a password for your mysql instance.*

Once you are logged in, run the following command to create a new database

    mysql> create database phone-verify;
    mysql> exit;

Update your environmental variables with your database credentials. Open up `.env` and make the following adjustments:

    DB_DATABASE=phone-verify
    DB_USERNAME={your_user_name}
    DB_PASSWORD={password if any}

### Updating User Migration and Model
Now that your database is successfully set up, update your `user` [migrations](https://laravel.com/docs/6.x/migrations) to create the necessary columns for your users. By default Laravel creates a `user` migration and [Model](https://laravel.com/docs/6.x/eloquent) when a new project is generated. Only a few adjustments will be needed to fit the needs of this tutorial.

Open up the project folder in your favourite IDE/text editor to start updating the needed fields in the *users* table. Open up the *users* migration file (`database/migrations/2014_10_12_000000_create_users_table.php`) and make the following adjustments to the `up()` method:

       public function up()
        {
            Schema::create('users', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('name');
                $table->string('phone_number')->unique();
                $table->boolean('isVerified')->default(false);
                $table->string('password');
                $table->rememberToken();
                $table->timestamps();
            });
        }

The `phone_number` and `isVerified` fields were added for storing a user’s phone number and checking whether the phone number has been verified respectively.

Run the following command in the project directory root to add this table to your database:

    $ php artisan migrate

If the file get migrated successfully, you will see the file name (`{time_stamp}_create_users_table`) printed out in the console window.

Now update the `[fillable](https://laravel.com/docs/6.x/eloquent#mass-assignment)` properties of the *User* model to include the `phone_number` and `isVerified` fields. Open up `app/User.php` and make the following changes to the `$fillable` array:

     /**
         * The attributes that are mass assignable.
         *
         * @var array
         */
        protected $fillable = [
            'name', 'email', 'password', 'phone_number', 'isVerified'
        ];


## Implementing Authentication Logic

At this point, you have successfully set up your Laravel project with the Twilio PHP SDK and created your database. Next you'll write out our logic for authenticating a user. First, generate an `AuthController` which will house all needed logic for each authentication step. Open up a new console window in the project root directory and run the following command to generate a [Controller](https://laravel.com/docs/6.x/controllers):

    $ php artisan make:controller AuthController

The above command will generate a controller class file in `app/Http/Controllers/AuthController.php`.

### Registering Users
It's now time to implement your authentication logic. You will implement the registration logic first. 
Let’s assume that you are going to be sending out SMS notifications to registered users from your application. You will need to ensure that the phone numbers stored in your database are correct. There’s no better place to enforce this validation than at the point of registration. To accomplish this, you will make use of [Twilio Verify](https://www.twilio.com/verify) to check if the phone number entered by your user is a valid phone number. 

Open up `app/Http/Controllers/AuthController.php` and add the following method:

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

Take a closer look at the code above. After validating the data coming in via the `$request` property, your Twilio credentials stored in the `.env` file are retrieved using the built-in PHP [getenv()](http://php.net/manual/en/function.getenv.php) function. They are then passed into the Twilio Client to create a new instance. After which, your `verify` service is accessed from the instance the Twilio client using:

    $twilio->verify->v2->services($twilio_verify_sid)
                ->verifications
                ->create($data['phone_number'], "sms");

The Twilio Verify service `sid` was also passed to the `service` which allows access to the Twilio Verify service you created earlier in this tutorial. Next you called the `->verifications->create()` method by passing in the phone number to be verified and a channel for delivery. The OTP can be either `mail`, `sms` or `call`. You are currently making use of the `sms` channel which means your OTP code will be sent to the user via SMS. Next, the user’s data is stored in the database using the [Eloquent](https://laravel.com/docs/6.x/eloquent) [`create`](https://laravel.com/docs/6.x/eloquent#mass-assignment) method:

    User::create([
                'name' => $data['name'],
                'phone_number' => $data['phone_number'],
                'password' => Hash::make($data['password']),
            ]);

After that the user is redirected to a `verify` page sending their `phone_number` as data for the view.

### Verifying Phone number OTP
After successful registration of the user, you will need to create a way for verifying the OTP sent to them via your `channel` of choice. Create a `verify` method to be used to verify the user’s phone number against the OTP code entered in your form. Open `app/Http/Controllers/AuthController.php` and add the following method:

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

Just like in the `register()` method, the data above is retrieved from the request and instantiates the Twilio SDK with your credentials before accessing the `verify` service. Let’s take a look at how this is structured:

    $verification = $twilio->verify->v2->services($twilio_verify_sid)
                ->verificationChecks
                ->create($data['verification_code'], array('to' => $data['phone_number']));

From the above you can tell that you are accessing the Twilio Verify service as earlier, but this time you are making use of another method made available via the service:

    ->verificationChecks->create($data['verification_code'], array('to' => $data['phone_number']));

The `create()` function takes in two parameters, a `string` of the `OTP` code sent to the user and an `array` with a `to` property whose value is the user’s phone number which the OTP was sent to.
The `verificationChecks->create()` method returns an object which contains several properties including a boolean property `valid`, which is either `true` or `false` depending on whether the OTP entered is valid or not:

    if ($verification->valid) {
                $user = tap(User::where('phone_number', $data['phone_number']))->update(['isVerified' => true]);
                /* Authenticate user */
                Auth::login($user->first());
                return redirect()->route('home')->with(['message' => 'Phone number verified']);
            }
            return back()->with(['phone_number' => $data['phone_number'], 'error' => 'Invalid verification code entered!']);

Next the code checks to see if the `valid` property is true and then procees to update the `isVerified` field of the user to `true`.  The application then proceeds to [manually authenticate](https://laravel.com/docs/6.x/authentication#other-authentication-methods) the user using Laravel’s `Auth::login` method which will login and remember the given `User` model instance.
 
 **Note:** *The `User` model must implement the `Authenticatable` interface before it can be used with the Laravel `Auth::login` method.*

After successful verification of the user, they are redirected to the application dashboard.


## Building The Views

All logic for registering and verifying a user has been written. Now let’s build the view that the user will use to interact with your application. A [layout](https://laravel.com/docs/6.x/blade#defining-a-layout) serving as the main interface of your application will be needed. Create a folder named `layouts` in `resources/views/`. Next create a file named `app.blade.php` in the `layouts` folder. Now open up the newly created file (`resources/views/layouts/app.blade.php`) and add the following:

    <!doctype html>
    <html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <!-- CSRF Token -->
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <title>{{ config('app.name', 'Laravel') }}</title>
        <!-- Styles -->
        <link href="https://stackpath.bootstrapcdn.com/bootstrap/4.2.1/css/bootstrap.min.css" rel="stylesheet"
            integrity="sha384-GJzZqFGwb1QTTN6wy59ffF1BuGJpLSa9DkKMp0DgiMDm4iYMj70gZWKYbI706tWS" crossorigin="anonymous">
    </head>
    <body>
        <div id="app">
            <nav class="navbar navbar-expand-md navbar-light bg-white shadow-sm">
                <div class="container">
                    <a class="navbar-brand" href="{{ url('/') }}">
                        {{ config('app.name', 'Laravel') }}
                    </a>
                    <button class="navbar-toggler" type="button" data-toggle="collapse"
                        data-target="#navbarSupportedContent" aria-controls="navbarSupportedContent" aria-expanded="false"
                        aria-label="{{ __('Toggle navigation') }}">
                        <span class="navbar-toggler-icon"></span>
                    </button>
                    <div class="collapse navbar-collapse" id="navbarSupportedContent">
                        <!-- Left Side Of Navbar -->
                        <ul class="navbar-nav mr-auto">
                        </ul>
                        <!-- Right Side Of Navbar -->
                        <ul class="navbar-nav ml-auto">
                            <!-- Authentication Links -->
                            @guest
                            <li class="nav-item">
                                <a class="nav-link" href="{{ route('register') }}">{{ __('Register') }}</a>
                            </li>
                            @else
                            <li class="nav-item dropdown">
                                <a id="navbarDropdown" class="nav-link dropdown-toggle" href="#" role="button"
                                    data-toggle="dropdown" aria-haspopup="true" aria-expanded="false" v-pre>
                                    {{ Auth::user()->name }} <span class="caret"></span>
                                </a>
    
                            </li>
                            @endguest
                        </ul>
                    </div>
                </div>
            </nav>
            <main class="py-4">
                @yield('content')
            </main>
        </div>
    </body>
    </html>
    

***Note:** To expedite the creation of this application, Bootstrap is being utilized for styling your application and forms.*

Next create a folder called `auth` in `resources/views/`. Now create the following files and paste in their respective content. In `resources/views/auth/register.blade.php`: 

    @extends('layouts.app')
    @section('content')
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-8">
                <div class="card">
                    <div class="card-header">{{ __('Register') }}</div>
                    <div class="card-body">
                        <form method="POST" action="{{ route('register') }}">
                            @csrf
                            <div class="form-group row">
                                <label for="name" class="col-md-4 col-form-label text-md-right">{{ __('Name') }}</label>
                                <div class="col-md-6">
                                    <input id="name" type="text" class="form-control @error('name') is-invalid @enderror" name="name" value="{{ old('name') }}" required autocomplete="name" autofocus>
                                    @error('name')
                                        <span class="invalid-feedback" role="alert">
                                            <strong>{{ $message }}</strong>
                                        </span>
                                    @enderror
                                </div>
                            </div>
                            <div class="form-group row">
                                <label for="phone_number" class="col-md-4 col-form-label text-md-right">{{ __('Phone Number') }}</label>
                                <div class="col-md-6">
                                    <input id="phone_number" type="tel" class="form-control @error('phone_number') is-invalid @enderror" name="phone_number" value="{{ old('phone_number') }}" required>
                                    @error('phone_number')
                                        <span class="invalid-feedback" role="alert">
                                            <strong>{{ $message }}</strong>
                                        </span>
                                    @enderror
                                </div>
                            </div>
                            <div class="form-group row">
                                <label for="password" class="col-md-4 col-form-label text-md-right">{{ __('Password') }}</label>
                                <div class="col-md-6">
                                    <input id="password" type="password" class="form-control @error('password') is-invalid @enderror" name="password" required autocomplete="new-password">
                                    @error('password')
                                        <span class="invalid-feedback" role="alert">
                                            <strong>{{ $message }}</strong>
                                        </span>
                                    @enderror
                                </div>
                            </div>
                            <div class="form-group row">
                                <label for="password-confirm" class="col-md-4 col-form-label text-md-right">{{ __('Confirm Password') }}</label>
                                <div class="col-md-6">
                                    <input id="password-confirm" type="password" class="form-control" name="password_confirmation" required autocomplete="new-password">
                                </div>
                            </div>
                            <div class="form-group row mb-0">
                                <div class="col-md-6 offset-md-4">
                                    <button type="submit" class="btn btn-primary">
                                        {{ __('Register') }}
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
    @endsection
    

and now in `resources/views/auth/verify.blade.php`:

    @extends('layouts.app')
    @section('content')
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-8">
                <div class="card">
                    <div class="card-header">{{ __('Verify Your Phone Number') }}</div>
                    <div class="card-body">
                        @if (session('error'))
                        <div class="alert alert-danger" role="alert">
                            {{session('error')}}
                        </div>
                        @endif
                        Please enter the OTP sent to your number: {{session('phone_number')}}
                        <form action="{{route('verify')}}" method="post">
                            @csrf
                            <div class="form-group row">
                                <label for="verification_code"
                                    class="col-md-4 col-form-label text-md-right">{{ __('Phone Number') }}</label>
                                <div class="col-md-6">
                                    <input type="hidden" name="phone_number" value="{{session('phone_number')}}">
                                    <input id="verification_code" type="tel"
                                        class="form-control @error('verification_code') is-invalid @enderror"
                                        name="verification_code" value="{{ old('verification_code') }}" required>
                                    @error('verification_code')
                                    <span class="invalid-feedback" role="alert">
                                        <strong>{{ $message }}</strong>
                                    </span>
                                    @enderror
                                </div>
                            </div>
                            <div class="form-group row mb-0">
                                <div class="col-md-6 offset-md-4">
                                    <button type="submit" class="btn btn-primary">
                                        {{ __('Verify Phone Number') }}
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
    @endsection
    

Lastly, create a page where verified users will be taken to by creating a file called `home.blade.php` in `resources/views/`. Add the following content (`resources/views/home.blade.php`):

    @extends('layouts.app')
    @section('content')
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-8">
                <div class="card">
                    <div class="card-header">Dashboard</div>
                    <div class="card-body">
                        @if (session('message'))
                            <div class="alert alert-success" role="alert">
                                {{ session('message') }}
                            </div>
                        @endif
                        You are logged in!
                    </div>
                </div>
            </div>
        </div>
    </div>
    @endsection
    


## Updating Our Routes

Awesome! Now that you have completed creating the view, let’s update your `routes/web.php` file with the needed routes for your application. Open up `routes/web.php` and make the following changes:

    <?php
    /*
    |--------------------------------------------------------------------------
    | Web Routes
    |--------------------------------------------------------------------------
    |
    | Here is where you can register web routes for your application. These
    | routes are loaded by the RouteServiceProvider within a group which
    | contains the "web" middleware group. Now create something great!
    |
     */
    Route::get('/', function () {
        return view('auth.register');
    })->name('register');
    
    Route::get('/verify', function () {
        return view('auth.verify');
    })->name('verify');
    
    Route::get('/home', function () {
        return view('home');
    })->name('home');
    
    Route::post('/', 'AuthController@create')->name('register');
    Route::post('/verify', 'AuthController@verify')->name('verify');
    


## Testing Our Application

Now that you are done with building the application, let’s test it out. Open up your console window and navigate to the project directory and run the following command:

    $ php artisan serve

This will serve your Laravel application on a localhost port, normally `8000`. Open up the localhost link printed out after running the command on your browser and you should be greeted with registration page similar to this:

![](https://paper-attachments.dropbox.com/s_F2A8B2F68E4E7251C0E01BC69920BEB8CE8E4B362D8D4BA952FACA12F8136664_1573634680020_Screenshot+from+2019-11-13+09-44-21.png)

Fill out the registration form to trigger a OTP code to be sent. You will use this code in filling out the form in the page you were redirected to. 

![](https://paper-attachments.dropbox.com/s_F2A8B2F68E4E7251C0E01BC69920BEB8CE8E4B362D8D4BA952FACA12F8136664_1573634963714_Peek+2019-11-13+09-48.gif)

## Conclusion

Great! By completing this tutorial, you have learned how to make use of Twilio's Verify Service for validating phone number(s) in a Laravel application. Also, we learned how to manually authenticate a user in a Laravel application.
If you would like to take a look at the complete source code for this tutorial, you can find it on [Github](https://github.com/thecodearcher/twilio-verify-phone-number-verification).

I’d love to answer any question(s) you might have concerning this tutorial. You can reach me via:

- Email: [brian.iyoha@gmail.com](mailto:brian.iyoha@gmail.com)
- Twitter: [thecodearcher](https://twitter.com/thecodearcher)
- GitHub: [thecodearcher](https://github.com/thecodearcher)
