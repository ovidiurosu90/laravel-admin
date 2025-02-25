<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Models\User;
use Auth;

class AjaxController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
    }

    /**
     * @param Request $request
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getUsersToImpersonate(Request $request)
    {
        $currentUser = Auth::user();
        if (!$currentUser->canImpersonate()) {
            return response()->json([
                'message' => 'Current User cannot impersonate!'
            ], 405);
        }

        $users = User::with('roles')
            ->select('id', 'name', 'email')
            ->where('id', '!=', $currentUser->id)
            ->get();

        $return = [];
        foreach ($users as $user) {
            if (!$user->canBeImpersonated()) {
                continue;
            }

            $return[] = $user;
        }

        return response()->json($return);
    }
}

