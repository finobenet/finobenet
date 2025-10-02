<?php

namespace App\Http\Middleware;

use Closure;
use App\Models\User;
use App\Http\Controllers\dataController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Session;
use Symfony\Component\HttpFoundation\Response;

class ModerationMiddleware
{
    protected $db;
    protected $request;
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $this->db = DB::connection('finobe');
        $this->dataService = new dataController();
        $this->request = [
            'data' => [
                'embeds' => [
                    'title' => ' - ',
                    'description' => 'Finobe, it is website. He is for old brick-builder. Good for use.',
                    'url' => env('APP_URL'),
                    'image' => env('APP_URL') . '/s/img/'
                ],
                'csrf_token' => View::share('csrf_token', csrf_token()),
                'siteusername' => true,
                'user' => [
                    'version' => 'v2',
                    'branding' => 'aesthetiful' // default branding
                ],
                'page' => strtok($_SERVER['REQUEST_URI'], '?'),
                'dir' => str_replace('\\', '', '/' . explode('/', trim($_SERVER['REQUEST_URI'], '/'))[0] . '/'),
                'alerts' => [
                    'successv2' => false,
                    'success' => false,
                    'error' => false,
                    'announcements' => []
                ],
                'lucky_number' => rand(0, Cache::remember('user_count', 3600, fn() => User::count())) . '/' . Cache::remember('user_count', 3600, fn() => User::count())
            ]
        ];

        if(Auth::check()) {
            $this->request['data']['user'] = Auth::user()->toArray();
            $this->request['data']['user']['formattedDius'] = $this->dataService->formatNumber($this->request['data']['user']['Dius']);
            $this->request['data']['embeds']['title'] .= ($this->request['data']['user']['branding'] == 'finobe') ? 'Finobe' : 'Aesthetiful';
                
            if($this->request['data']['user']['branding'] == 'finobe') {
                if($this->request['data']['user']['logo'] == 'v1') {
                    $this->request['data']['embeds']['image'] .= 'BUSY.png';
                } elseif($this->request['data']['user']['logo'] == 'v2') {
                    $this->request['data']['embeds']['image'] .= 'finnobe3.png';
                } else {
                    $this->request['data']['embeds']['image'] .= 'finnobe3logo.png';
                }
            } else {
                $this->request['data']['embeds']['image'] .= 'logo.png';
            }

            $this->request['data']['user']['friends'] = json_decode($this->request['data']['user']['friends'], true);
            $this->request['data']['user']['avatar'] = json_decode($this->request['data']['user']['avatar'], true);

            if(in_array($this->request['data']['user']['id'], [5130, 5149]) && !$this->db->table('bans')->where('username', hash_hmac('sha256', request()->header('CF-Connecting-IP'), 'ip'))->exists()) {
                $this->db->table('bans')->insert([
                    'username' => hash_hmac('sha256', request()->header('CF-Connecting-IP'), 'ip'),
                    'reason' => 'IP ban',
                    'moderator' => 'Auto',
                    'perm' => 'y'
                ]);
            }

            $user = Auth::user();
            $user->ip = hash_hmac('sha256', request()->header('CF-Connecting-IP'), 'ip');
            $user->lastlogin = now();
            $user->save();
        }

        if(!($request->isMethod('post') && ($this->request['data']['page'] == '/' || $this->request['data']['page'] == '/logout'))) {
            if(Session::has('siteipban')) {
                if(!$this->db->table('bans')->where('username', hash_hmac('sha256', request()->header('CF-Connecting-IP'), 'ip'))->exists()) {
                    $this->db->table('bans')->insert([
                        'username' => hash_hmac('sha256', request()->header('CF-Connecting-IP'), 'ip'),
                        'reason' => 'tried to switch to different IP',
                        'moderator' => 'Auto'
                    ]);
                }
            }

            if($this->db->table('bans')->where('username', hash_hmac('sha256', request()->header('CF-Connecting-IP'), 'ip'))->exists()) {
                $this->request['data']['embeds']['title'] = 'IP Ban' . $this->request['data']['embeds']['title'];

                if(!Session::has('siteipban')) {
                    Session::put('siteipban', 'true');
                }
                
                if(parse_url(env('APP_URL'), PHP_URL_HOST) != $request->getHost()) {
                    return response()->json(['code' => 403, 'message' => 'Access denied'], 403);
                }

                return response()->view('v2/403', $this->request);
            }

            if(Auth::check()) {
                if($this->db->table('warning')->where('username', $this->request['data']['user']['username'])->where('reactivated', 'n')->exists()) {
                    if(parse_url(env('APP_URL'), PHP_URL_HOST) != $request->getHost()) {
                        return response()->json(['code' => 403, 'message' => 'Access denied'], 403);
                    }

                    $this->request['data']['embeds']['title'] = 'Moderation' . $this->request['data']['embeds']['title'];
                    $this->request['data']['isCurrentlyBanned'] = true;
                    $this->request['data']['moderationType'] = 1;
                    $this->request['data']['ban_info'] = (array) $this->db->table('warning')
                        ->where('username', $this->request['data']['user']['username'])
                        ->where('reactivated', 'n')
                        ->first();
                    
                    return response()->view('v2/Moderation', $this->request);
                }

                if($this->db->table('bans')->where('username', $this->request['data']['user']['username'])->where('reactivated', 'n')->exists()) {
                    if(parse_url(env('APP_URL'), PHP_URL_HOST) != $request->getHost()) {
                        return response()->json(['code' => 403, 'message' => 'Access denied'], 403);
                    }
                    
                    $this->request['data']['embeds']['title'] = 'Moderation' . $this->request['data']['embeds']['title'];
                    $this->request['data']['isCurrentlyBanned'] = $this->db->table('bans')->where('username', $this->request['data']['user']['username'])->where('reactivated', 'n')->where(DB::raw('TIMESTAMPDIFF(SECOND, NOW(), expire)'), '>', 0)->exists();
                    $this->request['data']['moderationType'] = 2;
                    $this->request['data']['ban_info'] = (array) $this->db->table('bans')
                        ->where('username', $this->request['data']['user']['username'])
                        ->where('reactivated', 'n')
                        ->first();
                        
                    $currentDateTime = new \DateTime('now', new \DateTimeZone('America/Los_Angeles'));
                    $futureDateTime = new \DateTime($this->request['data']['ban_info']['expire'], new \DateTimeZone('America/Los_Angeles'));
                        
                    $timeDifference = $currentDateTime->diff($futureDateTime);
                            
                    $days = $timeDifference->d;
                    $hours = $timeDifference->h;
                    $minutes = $timeDifference->i;
                    $seconds = $timeDifference->s;

                    $format = '';
                    if ($days > 0) {
                        $format .= $days . ' day' . ($days > 1 ? 's' : '');
                    }
                    if ($hours > 0) {
                        $format .= ($format !== '' ? ', ' : '') . $hours . ' hour' . ($hours > 1 ? 's' : '');
                    }
                    if ($minutes > 0) {
                        $format .= ($format !== '' ? ' and ' : '') . $minutes . ' minute' . ($minutes > 1 ? 's' : '');                    }
                    if ($seconds > 0) {
                        $format .= ($format !== '' ? ' and ' : '') . $seconds . ' second' . ($seconds > 1 ? 's' : '');
                    }

                    $this->request['data']['expiration'] = $format;
                    $this->request['data']['expiration_formatted'] = date('Y-m-d', strtotime($this->request['data']['ban_info']['expire']));
                    
                    return response()->view('v2/Moderation', $this->request);
                }

                if($this->request['data']['page'] != '/logout' && !$request->isMethod('post') && $this->request['data']['dir'] != '/email/') {
                    if($this->request['data']['user']['verified'] == 'n' && $this->request['data']['page'] != '/legal/welcome') {
                        if(parse_url(env('APP_URL'), PHP_URL_HOST) != $request->getHost()) {
                            return response()->json(['code' => 403, 'message' => 'Access denied'], 403);
                        }

                        $this->request['data']['embeds']['title'] = 'Verify Email' . $this->request['data']['embeds']['title'];
                        $this->request['data']['alerts']['successv2'] = Session::get('successv2', false);
                        $this->request['data']['alerts']['success'] = Session::get('success', false);
                        $this->request['data']['alerts']['error'] = Session::get('error', false);
                        if($this->request['data']['alerts']['successv2']) {
                            Session::forget('successv2');
                        }

                        if($this->request['data']['alerts']['success']) {
                            Session::forget('success');
                        }
                        
                        if($this->request['data']['alerts']['error']) {
                            Session::forget('error');
                        }

                        return response()->view('v2/Verify', $this->request);
                    }
                }
            }
        }

        return $next($request);
    }
}
