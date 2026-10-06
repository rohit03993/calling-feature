<?php

namespace App\Http\Middleware;

use App\Models\School;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateSchool
{
    public function handle(Request $request, Closure $next): Response
    {
        $code = (string) $request->header('X-School-Code');
        $token = (string) $request->bearerToken();
        $school = School::query()->where('code', $code)->where('enabled', true)->first();

        if ($school === null || $token === '' || ! hash_equals((string) $school->secret, $token)) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $request->attributes->set('school', $school);

        return $next($request);
    }
}
