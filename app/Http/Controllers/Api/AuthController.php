<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use App\Exceptions\UnprocessEntityException;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class AuthController extends Controller {
    public function login(Request $request) {
        $requestBody = $request->json()->all();
        $validation = Validator::make($requestBody, [
            'email' => 'required|string|email',
            'password' => 'required|string'
        ]);

        if($validation->fails()) throw new ValidationException($validation);

        return $this->doLogin($requestBody['email'], $requestBody['password']);
    }

    private function handleResponse ($response) {
        return response()->json([
            'user_id' => $response['user']->id,
            'name' => $response['user']->name,
            'email' => $response['user']->email,
            'role' => $response['role'] ?? null,
            'token_type' => 'Bearer',
            'roles' => $response['roles'] ?? null,
            'expires_at' => Carbon::parse($response['token']->token->expires_at)->toDateTimeString(),
            'email_verified_at' => $response['user']->email_verified_at,
            'login_at' => $response['user']->login_at,
            'access_token' => $response['token']->accessToken,
            'permissions' => $response['permissions'],
        ], 200);
    }

    private function doLogin($email, $password) {
        $credentials = [
            'email' => $email, 
            'password' => $password,
            'is_active' => true
        ];

        if(!Auth::attempt($credentials)) {
            throw new AuthenticationException('Email or password you entered is incorrect!');
        }

        try {
            $user = Auth::user();
            $user = User::find($user->id);
            
            if(!$user){
                throw new UnprocessEntityException('Login failed! Proccess has been failed');
            }
            
            $permissions = $user->roles->first()->permissions;
            $permissions = $permissions->map(function($p){
                return $p['name'];
            });
            
            $tokenResult = $user->createToken('Laravel Password Grant Client', $permissions->toArray());
            $token = $tokenResult->token;
            
            $token->save();
            
            $user->login_at = Carbon::now();
            $user->save();
            
            $response = [
                'user' => $user, 
                'roles' => $user->getRoleNames(), 
                'token' => $tokenResult,
                'permissions' => $permissions, 
            ];

            return $this->handleResponse($response);
        } catch (UnprocessEntityException $e) {
            return response()->json($e);
        }
    }

    public function register(Request $request) {
      $requestBody = $request->json()->all();
  
      $validation = Validator::make($requestBody, [
        'name' => 'string|required',
        'email' => 'email|unique:users|required',
        'password' => 'string',
        'role' => 'string|exists:roles,name|required',
        'is_active' => 'boolean|required'
      ]);
  
      if($validation->fails()) throw new ValidationException($validation);
      
      $requestBody['password'] = Hash::make($requestBody['password']);
      
      $data = User::create($requestBody);
      $data->assignRole($requestBody['role']);

      return response()->json($data);
    }
}