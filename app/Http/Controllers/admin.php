<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Validator;

class admin extends Controller
{
    protected $db;
    protected $dataService;
    protected $request;

    public function __construct(dataController $dataService, Request $request) {
        $this->db = DB::connection('finobe');
        $this->dataService = $dataService;
        $this->request = [
            'data' => [
                'embeds' => [
                    'title' => ' - ',
                    'description' => 'Finobe, it is website. He is for old brick-builder. Good for use.',
                    'url' => env('APP_URL'),
                    'image' => env('APP_URL') . '/s/img/'
                ],
                'csrf_token' => View::share('csrf_token', csrf_token()),
                'siteusername' => Auth::check(),
                'user' => [
                    'version' => 'v2',
                    'branding' => 'aesthetiful' // default branding
                ],
                'page' => strtok($_SERVER['REQUEST_URI'], '?'),
				'dir' => str_replace('\\', '', '/' . explode('/', trim($_SERVER['REQUEST_URI'], '/'))[0] . '/'),
                'alerts' => [
                    'successv2' => Session::get('successv2', false),
					'success' => Session::get('success', false),
					'error' => Session::get('error', false),
					'announcements' => []
				],
                'lucky_number' => rand(0, Cache::remember('user_count', 3600, fn() => User::count())) . '/' . Cache::remember('user_count', 3600, fn() => User::count())
            ]
        ];

        if($this->request['data']['alerts']['successv2']) {
			Session::forget('successv2');
		}

        if($this->request['data']['alerts']['success']) {
			Session::forget('success');
		}
		
		if($this->request['data']['alerts']['error']) {
			Session::forget('error');
		}

        if($this->request['data']['siteusername']) {
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
            $this->request['data']['user']['places'] = $this->db->table('assets')
                ->where('author', $this->request['data']['user']['id'])
                ->where('asset_type', 9)
                ->count();
            
            $this->request['data']['notifications'] = [
                'data' => [],
                'ads' => (bool)env('FINOBE_ADS'),
                'info' => [
                    'number' => $this->db->table('pms')->where('touser', $this->request['data']['user']['id'])->where('readed', 'n')->count(),
                    'inbox' => $this->db->table('messages')->where('touser', $this->request['data']['user']['id'])->where('readed', 'n')->count(),
                    'incomingFriends' => 0
                ]
            ];

            $notifications = $this->db->table('pms')
                ->where('touser', $this->request['data']['user']['id'])
                ->orderBy('date', 'DESC')
                ->get()
                ->map(function ($item) {
                    return (array) $item;
                })->toArray();
            
            foreach($notifications as $notification) {
                $this->request['data']['notifications']['data'][] = [
                    'id' => $notification['id'],
                    'message' => $notification['message'],
                    'date' => date('M j Y g:i:s A', strtotime($notification['date']))
                ];
            }

            foreach($this->request['data']['user']['friends'] as $friend) {
                if($friend['status'] == 'pending') {
                    $this->request['data']['notifications']['info']['incomingFriends']++;
                }
            }

            $user = User::find($this->request['data']['user']['id'])->first();
            $user->ip = hash_hmac('sha256', request()->header('CF-Connecting-IP'), 'ip');
            $user->lastlogin = now();
            $user->save();
            
            if(strtotime($this->request['data']['user']['lastdiu']) <= time() && $this->request['data']['user']['diubanned'] == 'n') {
                /*
                $this->db->table('users')
                    ->where('username', $this->request['data']['user']['username'])
                    ->update([
                        'Dius' => $this->request['data']['user']['Dius'] + 25,
                        'lastdiu' => DB::raw('DATE_ADD(CURRENT_TIMESTAMP(), INTERVAL 1 DAY)')
                    ]);
                */
                
                $user->Dius += 25;
                $user->lastdiu = now()->addDay();
                $user->save();
            }
        } else {
            $this->request['data']['embeds']['title'] .= 'Aesthetiful';
            $this->request['data']['embeds']['image'] .= 'logo.png';
        }

        $this->request['data']['alerts']['announcements'] = $this->db->table('announcements')
            ->select('message', 'expire', 'author', 'color')
            ->where('expire', '>', now())
            ->orderBy('id', 'DESC')
            ->get()
            ->map(function ($item) {
                return (array) $item;
            })->toArray();
    }

    public function index(Request $request) {
        $this->request['data']['embeds']['title'] = 'Admin Panel' . $this->request['data']['embeds']['title'];

        if(!$this->request['data']['siteusername'] || $this->request['data']['user']['status'] != 'admin') {
            return redirect('/');
        }

        return view($this->request['data']['user']['version'] . '/Admin/Index', $this->request);
    }

    public function assets(Request $request) {
        $this->request['data']['embeds']['title'] = 'Asset Moderation' . $this->request['data']['embeds']['title'];

        if(!$this->request['data']['siteusername'] || $this->request['data']['user']['status'] != 'admin') {
            return redirect('/');
        }

        $assets = [];
        $results = $this->db->table('assets')
            ->where('visibility', 'r')
            ->where('asset_type', '!=', 1)
            ->orderBy('id', 'DESC')
            ->get()
            ->map(function ($item) {
                return (array) $item;
            })->toArray();
        
        foreach($results as $result) {
            $result['title'] = strip_tags(htmlspecialchars($result['title']));
            $result['author'] = htmlspecialchars(User::where('id', $result['author'])->value('username'));
            $result['publish'] = date('m/d/Y', strtotime($result['created']));
            $result['additional'] = json_decode($result['additional'], true);
            $assets[] = $result;
        }

        $this->request['data']['assets'] = $assets;

        return view($this->request['data']['user']['version'] . '/Admin/Assets', $this->request);
    }

    public function accept(Request $request) {
        $data = $request->all();

        if(!$this->request['data']['siteusername'] || $this->request['data']['user']['status'] != 'admin') {
            return redirect('/');
        }

        if(!isset($data['id']) || !$this->db->table('assets')->where('id', $data['id'])->exists()) {
            Session::put('error', 'error');
            return redirect('/admin/assets');
        }

        $asset = (array) $this->db->table('assets')
            ->where('id', $data['id'])
            ->first();
        
        $asset['additional'] = json_decode($asset['additional'], true);

        if($asset['visibility'] == 'r') {
            if($asset['asset_type'] == 3) {
                if(!rename(public_path('dynamic/reviewing/' . $asset['file']), '/var/www/cdn.finobe.net/audios/' . $asset['file'])) {
                    Session::put('error', error_get_last()['message']);
                    return redirect('/admin/assets');
                }
            } elseif($asset['asset_type'] == 2 || $asset['asset_type'] == 11 || $asset['asset_type'] == 12 || $asset['asset_type'] == 18) {
                $this->db->table('assets')
                    ->where('id', $asset['additional']['media']['textureAssetId'])
                    ->update([
                        'visibility' => 'n'
                    ]);
            }
        } elseif($asset['visibility'] == 'd') {
            if($asset['asset_type'] == 3) {
                if(!rename(public_path('dynamic/denied/' . $asset['file']), '/var/www/cdn.finobe.net/audios/' . $asset['file'])) {
                    Session::put('error', error_get_last()['message']);
                    return redirect('/admin/assets');
                }
            } elseif($asset['asset_type'] == 2 || $asset['asset_type'] == 11 || $asset['asset_type'] == 12 || $asset['asset_type'] == 18) {
                $this->db->table('assets')
                    ->where('id', $asset['additional']['media']['textureAssetId'])
                    ->update([
                        'visibility' => 'n'
                    ]);
            }
        }

        $this->db->table('assets')
            ->where('id', $data['id'])
            ->update([
                'visibility' => 'n'
            ]);
        
        if(!$this->db->table('purchases')->where('username', User::where('id', $asset['author'])->value('username'))->where('assetid', $data['id'])->where('author', $asset['author'])->exists()) {
            $this->db->table('purchases')->insert([
                'username' => User::where('id', $asset['author'])->value('username'),
                'assetid' => $data['id'],
                'author' => $asset['author'],
                'amount' => 0
            ]);
        }
        
        Session::put('success', 'Item accepted.');
        return redirect('/admin/assets');
    }

    public function deny(Request $request) {
        $data = $request->all();

        if(!$this->request['data']['siteusername'] || $this->request['data']['user']['status'] != 'admin') {
            return redirect('/');
        }

        if(!isset($data['id']) || !$this->db->table('assets')->where('id', $data['id'])->exists()) {
            Session::put('error', 'error');
            return redirect('/admin/assets');
        }

        $asset = (array) $this->db->table('assets')
            ->where('id', $data['id'])
            ->first();
        
        $asset['additional'] = json_decode($asset['additional'], true);

        if($asset['visibility'] == 'r') {
            if($asset['asset_type'] == 3) {
                if(!rename(public_path('dynamic/reviewing/' . $asset['file']), public_path('dynamic/denied/' . $asset['file']))) {
                    Session::put('error', error_get_last()['message']);
                    return redirect('/admin/assets');
                }
            } elseif($asset['asset_type'] == 2 || $asset['asset_type'] == 11 || $asset['asset_type'] == 12 || $asset['asset_type'] == 18) {
                if(!rename('/var/www/cdn.finobe.net/assets/' . $asset['file'], public_path('dynamic/denied/' . $asset['file']))) {
                    Session::put('error', error_get_last()['message']);
                    return redirect('/admin/assets');
                }

                $this->db->table('assets')
                    ->where('id', $asset['additional']['media']['textureAssetId'])
                    ->update([
                        'visibility' => 'd'
                    ]);
            }
        } elseif($asset['visibility'] == 'n') {
            if(!rename('/var/www/cdn.finobe.net/' . ($asset['asset_type'] == 3 ? 'audios' : 'assets') . '/' . $asset['file'], public_path('dynamic/denied/' . $asset['file']))) {
                Session::put('error', error_get_last()['message']);
                return redirect('/admin/assets');
            }
        }

        $this->db->table('assets')
            ->where('id', $data['id'])
            ->update([
                'visibility' => 'd'
            ]);
        
        Session::put('success', 'Item denied.');
        return redirect('/admin/assets');
    }

    public function bans(Request $request) {
        $this->request['data']['embeds']['title'] = 'User Moderation' . $this->request['data']['embeds']['title'];
        $data = $request->all();

        if(!$this->request['data']['siteusername'] || $this->request['data']['user']['status'] != 'admin') {
            return redirect('/');
        }

        if($request->isMethod('post')) {
            $validator = Validator::make($data, [
                'name' => [
                    'required',
                    function ($attribute, $value, $fail) {
                        $isIp = filter_var($value, FILTER_VALIDATE_IP);
                        $isUsername = is_string($value) && strlen($value) >= 3 && strlen($value) <= 255;

                        if (!$isIp && !$isUsername) {
                            $fail('The ' . $attribute . ' must be a valid IP address or a username between 3 and 255 characters.');
                        }
                    }
                ],
                'reason' => 'required|string|min:3|max:255',
                'type' => 'required|string|in:n,y|size:1',
                'date' => 'nullable|date',
                'time' => 'nullable|date_format:H:i'
            ]);

            if($validator->fails()) {
                Session::put('error', $validator->errors()->first());
                return redirect('/admin/bans');
            }

            if(!filter_var($data['name'], FILTER_VALIDATE_IP) && !User::where('username', $data['name'])->exists()) {
                Session::put('error', 'User doesn\' exist.');
                return redirect('/admin/bans');
            }

            if(filter_var($data['name'], FILTER_VALIDATE_IP)) {
                $data['name'] = hash_hmac('sha256', $data['name'], 'ip');

                if($this->db->table('bans')->where('username', $data['name'])->exists()) {
                    Session::put('error', 'This user already has an active ban');
                    return redirect('/admin/bans');
                }
            }

            if($this->db->table('bans')
                ->where('username', $data['name'])
                ->where(function ($query) {
                    $query->where('expire', '>', now())
                        ->orWhere('perm', 'y');
                })->exists()) {
                Session::put('error', 'This user already has an active ban');
                return redirect('/admin/bans');
            }

            if($data['type'] == 'n') {
                $expire = $data['date'] . ' ' . $data['time'];
                $timezone = new \DateTimeZone('America/Los_Angeles');
                $dateTime = new \DateTime($expire, $timezone);
                $expire = $dateTime->format('Y-m-d H:i:s');

                $this->db->table('bans')->insert([
                    'username' => $data['name'],
                    'reason' => $data['reason'],
                    'expire' => $expire,
                    'moderator' => $this->request['data']['user']['username'],
                    'perm' => 'n'
                ]);
            } else {
                $this->db->table('bans')->insert([
                    'username' => $data['name'],
                    'reason' => $data['reason'],
                    'moderator' => $this->request['data']['user']['username'],
                    'perm' => 'y'
                ]);
            }

            Session::put('success', 'Successfully created.');
            return redirect('/admin/bans');
        }

        return view($this->request['data']['user']['version'] . '/Admin/Ban', $this->request);
    }

    public function decider(Request $request) {
        $this->request['data']['embeds']['title'] = 'Decider' . $this->request['data']['embeds']['title'];

        if(!$this->request['data']['siteusername'] || $this->request['data']['user']['status'] != 'admin') {
            return redirect('/');
        }

        return view($this->request['data']['user']['version'] . '/Admin/Decider', $this->request);
    }

    public function prune_posts(Request $request) {
        $this->request['data']['embeds']['title'] = 'Prune Forum Posts' . $this->request['data']['embeds']['title'];
        $data = $request->all();

        if(!$this->request['data']['siteusername'] || $this->request['data']['user']['status'] != 'admin') {
            return redirect('/');
        }

        if($request->isMethod('post')) {
            if(!empty($data['id']) || !empty($data['replyid'])) {
                $id = !empty($data['id']) && empty($data['replyid']) ? $data['id'] : $data['replyid'];
                $table = !empty($data['id']) && empty($data['replyid']) ? 'forum_threads' : 'forum_replies';

                if(!$this->db->table($table)->where('id', $id)->exists()) {
                    Session::put('error', 'Forum post or reply doesn\'t exist.');
                    return redirect('/admin/prune-posts');
                }

                if($table == 'forum_threads') {
                    $results = $this->db->table('forum_replies')
                        ->select('id')
                        ->where('toid', $id)
                        ->get()
                        ->map(function ($item) {
                            return (array) $item;
                        })->toArray();
                    
                    foreach($results as $result) {
                        $this->db->table('forum_replies')
                            ->where('id', $result['id'])
                            ->delete();
                    }

                    $this->db->table('forum_threads')
                        ->where('id', $id)
                        ->delete();
                } else {
                    $this->db->table('forum_replies')
                        ->where('id', $id)
                        ->delete();
                }

                Session::put('success', 'Successfully deleted.');
                return redirect('/admin/prune-posts');
            } elseif(!empty($data['username'])) {
                if(!User::where('username', $data['username'])->exists()) {
                    Session::put('error', 'User does not exist.');
                    return redirect('/admin/prune-posts');
                }

                $threads = $this->db->table('forum_threads')
                    ->where('author', $data['username'])
                    ->get()
                    ->map(function ($item) {
                        return (array) $item;
                    })->toArray();
                
                foreach($threads as $thread) {
                    $this->db->table('forum_threads')
                        ->where('id', $thread['id'])
                        ->delete();
                }

                $replies = $this->db->table('forum_replies')
                    ->where('author', $data['username'])
                    ->get()
                    ->map(function ($item) {
                        return (array) $item;
                    })->toArray();
                
                foreach($threads as $thread) {
                    $this->db->table('forum_replies')
                        ->where('id', $thread['id'])
                        ->delete();
                }

                Session::put('success!', 'Deleted successfully');
                return redirect('/admin/prune-posts');
            }

            Session::put('error', 'Unknown error.');
            return redirect('/admin/prune-posts');
        }

        return view($this->request['data']['user']['version'] . '/Admin/Prune_posts', $this->request);
    }

    public function announcements(Request $request) {
        $this->request['data']['embeds']['title'] = 'Announcements' . $this->request['data']['embeds']['title'];
        $data = $request->all();

        if(!$this->request['data']['siteusername'] || $this->request['data']['user']['status'] != 'admin') {
            return redirect('/');
        }

        if($request->isMethod('post')) {
            $validator = Validator::make($data, [
                'message' => 'required|string|min:3|max:255',
                'date' => 'required|date',
                'time' => 'required|date_format:H:i',
                'color' => 'required|string|in:success,primary,danger,info,warning'
            ]);

            if($validator->fails()) {
                Session::put('error', $validator->errors()->first());
                return redirect('/admin/announcements');
            }

            $expire = $data['date'] . ' ' . $data['time'];
            $timezone = new \DateTimeZone('America/Los_Angeles');
            $dateTime = new \DateTime($expire, $timezone);
            $expire = $dateTime->format('Y-m-d H:i:s');

            $this->db->table('announcements')->insert([
                'author' => $this->request['data']['user']['username'],
                'message' => $data['message'],
                'expire' => $expire,
                'color' => $data['color']
            ]);

            Session::put('success', 'Successfully created.');
            return redirect('/admin/announcements');
        }

        $html = [
            'time' => 'Time: ' . date('Y-m-d H:i:s'),
            'data' => ''
        ];

        $page = isset($data['page']) ? max(1, intval($data['page'])) : 1;
        $perPage = 10;

        $paginator = $this->db->table('announcements')
            ->orderByDesc('id')
            ->paginate($perPage, ['*'], 'page', $page);

        $results = $paginator->items();

        if (count($results)) {
            $html['data'] = "<table border='1' style=\"width:100%;\"><tr>";

            foreach (array_keys((array) $results[0]) as $key) {
                $html['data'] .= "<th>" . htmlspecialchars($key) . "</th>";
            }

            $html['data'] .= "</tr>";

            foreach ($results as $row) {
                $html['data'] .= "<tr>";
                foreach ((array) $row as $key => $value) {
                    if ($key === 'username') {
                        $html['data'] .= "<td><a href=\"/user/" . htmlspecialchars($value) . "\" target=\"_blank\">" . htmlspecialchars($value) . "</a></td>";
                    } else {
                        $html['data'] .= "<td>" . htmlspecialchars($value) . "</td>";
                    }
                }
                $html['data'] .= "</tr>";
            }

            $html['data'] .= "</table><div>";

            for ($pageNum = 1; $pageNum <= $paginator->lastPage(); $pageNum++) {
                $html['data'] .= "<a href='?page={$pageNum}'" . ($pageNum == $page ? " style='font-weight: bold'" : "") . ">{$pageNum}</a> ";
            }

            $html['data'] .= "</div>";
        } else {
            $html['data'] = "0 results";
        }

        $this->request['data']['announcements'] = $html;

        return view($this->request['data']['user']['version'] . '/Admin/Announcements', $this->request);
    }

    public function lock(Request $request) {
        $data = $request->all();

        if(!$this->request['data']['siteusername'] || $this->request['data']['user']['status'] != 'admin') {
            return redirect('/');
        }

        if(!isset($data['id'])) {
            return redirect('/forum/home');
        }

        if(!$this->db->table('forum_threads')->where('id', $data['id'])->exists()) {
            Session::put('error', 'This thread doesn\'t exist');
            return redirect('/forum/home');
        }

        $this->db->table('forum_threads')
            ->where('id', $data['id'])
            ->update([
                'locked' => 'y'
            ]);
        
        Session::put('success', 'Successfully locked');
        return redirect('/forum/post?id=' . $data['id']);
    }

    public function unlock(Request $request) {
        $data = $request->all();

        if(!$this->request['data']['siteusername'] || $this->request['data']['user']['status'] != 'admin') {
            return redirect('/');
        }

        if(!isset($data['id'])) {
            return redirect('/forum/home');
        }

        if(!$this->db->table('forum_threads')->where('id', $data['id'])->exists()) {
            Session::put('error', 'This thread doesn\'t exist');
            return redirect('/forum/home');
        }

        $this->db->table('forum_threads')
            ->where('id', $data['id'])
            ->update([
                'locked' => 'n'
            ]);
        
        Session::put('success', 'Successfully unlocked');
        return redirect('/forum/post?id=' . $data['id']);
    }

    public function pin(Request $request) {
        $data = $request->all();

        if(!$this->request['data']['siteusername'] || $this->request['data']['user']['status'] != 'admin') {
            return redirect('/');
        }

        if(!isset($data['id'])) {
            return redirect('/forum/home');
        }

        if(!$this->db->table('forum_threads')->where('id', $data['id'])->exists()) {
            Session::put('error', 'This thread doesn\'t exist');
            return redirect('/forum/home');
        }

        $this->db->table('forum_threads')
            ->where('id', $data['id'])
            ->update([
                'pinned' => 'y'
            ]);
        
        Session::put('success', 'Successfully pinned');
        return redirect('/forum/post?id=' . $data['id']);
    }

    public function unpin(Request $request) {
        $data = $request->all();

        if(!$this->request['data']['siteusername'] || $this->request['data']['user']['status'] != 'admin') {
            return redirect('/');
        }

        if(!isset($data['id'])) {
            return redirect('/forum/home');
        }

        if(!$this->db->table('forum_threads')->where('id', $data['id'])->exists()) {
            Session::put('error', 'This thread doesn\'t exist');
            return redirect('/forum/home');
        }

        $this->db->table('forum_threads')
            ->where('id', $data['id'])
            ->update([
                'pinned' => 'n'
            ]);
        
        Session::put('success', 'Successfully unpinned');
        return redirect('/forum/post?id=' . $data['id']);
    }

    public function stick(Request $request) {
        $data = $request->all();

        if(!$this->request['data']['siteusername'] || $this->request['data']['user']['status'] != 'admin') {
            return redirect('/');
        }

        if(!isset($data['id'])) {
            return redirect('/forum/home');
        }

        if(!$this->db->table('forum_replies')->where('id', $data['id'])->exists()) {
            Session::put('error', 'This thread doesn\'t exist');
            return redirect('/forum/home');
        }

        $reply = (array) $this->db->table('forum_replies')
            ->where('id', $data['id'])
            ->first();

        if($this->db->table('forum_replies')->where('toid', $reply['toid'])->where('sticked', 'y')->exists()) {
            Session::put('error', 'A sticked reply already exists');
            return redirect('/forum/home');
        }

        $this->db->table('forum_replies')
            ->where('id', $data['id'])
            ->update([
                'sticked' => 'y'
            ]);
        
        Session::put('success', 'Successfully sticked');
        return redirect('/forum/post?id=' . $reply['toid']);
    }

    public function unstick(Request $request) {
        $data = $request->all();

        if(!$this->request['data']['siteusername'] || $this->request['data']['user']['status'] != 'admin') {
            return redirect('/');
        }

        if(!isset($data['id'])) {
            return redirect('/forum/home');
        }

        if(!$this->db->table('forum_replies')->where('id', $data['id'])->exists()) {
            Session::put('error', 'This thread doesn\'t exist');
            return redirect('/forum/home');
        }

        $reply = (array) $this->db->table('forum_replies')
            ->where('id', $data['id'])
            ->first();

        $this->db->table('forum_replies')
            ->where('id', $data['id'])
            ->update([
                'sticked' => 'n'
            ]);
        
        Session::put('success', 'Successfully sticked');
        return redirect('/forum/post?id=' . $reply['toid']);
    }

    public function createxml(Request $request) {
        $this->request['data']['embeds']['title'] = 'New XML Asset' . $this->request['data']['embeds']['title'];
        $data = $request->all();

        if(!$this->request['data']['siteusername'] || $this->request['data']['user']['status'] != 'admin') {
            return redirect('/');
        }

        if($request->isMethod('post')) {
            $validator = Validator::make($data, [
                'title' => 'required|string|min:3|max:255',
                'description' => 'nullable|string|max:8192',
                'price' => 'required|integer|min:0',
                'onsale' => 'nullable|in:on,1,true,0,false,off',
                'mesh' => 'nullable|file',
                'xml' => 'nullable|file|mimetypes:text/plain',
                'texture' => 'nullable|file|mimetypes:image/png',
                'type' => 'required|string|in:hat,gear,mesh,texture'
            ]);

            if($validator->fails()) {
                Session::put('error', $validator->errors()->first());
                return redirect('/admin/createxml');
            }
            
            if(in_array($data['type'], ['hat', 'gear'])) {
                if($request->hasFile('mesh') && !str_starts_with(file_get_contents($request->file('mesh')->getPathname()), 'version')) {
                    Session::put('error', 'Unsupported mesh format');
                    return redirect('/admin/createxml');
                }

                try {
                    $id = Asset::createHatOrGear(
                        $data['title'],
                        ($request->hasFile('texture') ? ['tmp_name' => $request->file('texture')->getPathname()] : false),
                        ($request->hasFile('mesh') ? ['tmp_name' => $request->file('mesh')->getPathname()] : false),
                        ['tmp_name' => $request->file('xml')->getPathname()],
                        $this->request['data']['user']['id'],
                        $data['description'] ?? '',
                        intval($data['price']),
                        isset($data['onsale']),
                        false,
                        [],
                        ($data['type'] == 'hat' ? 8 : 19)
                    );
                } catch(\Exception $e) {
                    Session::put('error', $e->getMessage());
                    return redirect('/admin/createxml');
                }

                Session::put('success', 'Success');
                return redirect('/item/' . $id);
            } elseif($data['type'] == 'mesh') {
                if($request->hasFile('mesh') && !str_starts_with(file_get_contents($request->file('mesh')->getPathname()), 'version')) {
                    Session::put('error', 'Unsupported mesh format');
                    return redirect('/admin/createxml');
                }

                $id = Asset::createAsset(
                    $data['title'],
                    4,
                    $this->request['data']['user']['id'],
                    file_get_contents($request->file('mesh')->getPathname()),
                    '',
                    'n',
                    []
                );
            } elseif($data['type'] == 'texture') {
                $id = Asset::createAsset(
                    $data['title'],
                    1,
                    $this->request['data']['user']['id'],
                    file_get_contents($request->file('texture')->getPathname()),
                    '',
                    'n',
                    []
                );
            }

            Session::put('success', 'Success (http://www.finobe.net/asset/?id=' . $id . ')');
            return redirect('/admin/createxml');
        }

        return view($this->request['data']['user']['version'] . '/Admin/CreateXML', $this->request);
    }

    public function give_dius(Request $request) {
        $this->request['data']['embeds']['title'] = 'Reward Dius' . $this->request['data']['embeds']['title'];
        $data = $request->all();

        if(!$this->request['data']['siteusername'] || $this->request['data']['user']['status'] != 'admin') {
            return redirect('/');
        }

        if($request->isMethod('post')) {
            $validator = Validator::make($data, [
                'name' => 'required|string|max:255',
                'amount' => 'required|numeric',
                'toggler' => 'nullable'
            ]);

            if($validator->fails()) {
                Session::put('error', $validator->errors()->first());
                return redirect('/admin/give_dius');
            }

            if(isset($data['toggler'])) {
                if(!User::where('username', $data['name'])->exists()) {
                    Session::put('error', 'User does not exist');
                    return redirect('/admin/give_dius');
                }

                User::where('username', $data['name'])
                    ->update([
                        'diubanned' => DB::raw("CASE WHEN diubanned = 'n' THEN 'y' ELSE 'n' END")
                    ]);
                
                $user = User::where('username', $this->request['data']['user']['username'])->select('diubanned')->first();

                if($user->diubanned == 'y') {
                    Session::put('success', 'Successfully diu banned.');
                } else {
                    Session::put('success', 'Successfully diu unbanned.');
                }

                return redirect('/admin/give_dius');
            }

            if($data['name'] == '*') {
                $users = User::all();
                $insertData = [];

                foreach($users as $user) {
                    $user->Dius += intval($data['amount']);

                    $insertData[] = [
                        'username' => $user->username,
                        'assetid' => 0,
                        'serial' => 0,
                        'author' => 0,
                        'amount' => intval($data['amount']),
                        'type' => 4
                    ];
                }

                User::query()->get()->each(function ($user) use ($data) {
                    $user->increment('Dius', intval($data['amount']));
                });

                $this->db->table('purchases')->insert($insertData);
            } else {
                if(!User::where('username', $data['name'])->exists()) {
                    Session::put('error', 'User does not exist');
                    return redirect('/admin/give_dius');
                }

                $user = User::where('username', $data['name'])->first();
                $user->Dius += intval($data['amount']);
                $user->save();

                $this->db->table('purchases')->insert([
                    'username' => $user->username,
                    'assetid' => 0,
                    'serial' => 0,
                    'author' => 0,
                    'amount' => intval($data['amount']),
                    'type' => 4
                ]);
            }

            Session::put('success', 'Successfully given dius.');
            return redirect('/admin/give_dius');
        }

        return view($this->request['data']['user']['version'] . '/Admin/Dius', $this->request);
    }

    public function warn(Request $request) {
        $this->request['data']['embeds']['title'] = 'Warn Users' . $this->request['data']['embeds']['title'];
        $data = $request->all();

        if(!$this->request['data']['siteusername'] || $this->request['data']['user']['status'] != 'admin') {
            return redirect('/');
        }

        if($request->isMethod('post')) {
            $validator = Validator::make($data, [
                'name' => 'required|string|min:3|max:255',
                'reason' => 'required|string|min:3|max:255'
            ]);

            if($validator->fails()) {
                Session::put('error', $validator->errors()->first());
                return redirect('/admin/warn');
            }

            if(!User::where('username', $data['name'])->exists()) {
                Session::put('error', 'User does not exist');
                return redirect('/admin/warn');
            }

            $this->db->table('warning')->insert([
                'username' => $data['name'],
                'reason' => $data['reason'],
                'moderator' => $this->request['data']['user']['username']
            ]);

            Session::put('success', 'Successfully created.');
            return redirect('/admin/warn');
        }

        return view($this->request['data']['user']['version'] . '/Admin/Warn', $this->request);
    }

    public function give_badges(Request $request) {
        $this->request['data']['embeds']['title'] = 'Give Badges' . $this->request['data']['embeds']['title'];
        $data = $request->all();

        if(!$this->request['data']['siteusername'] || $this->request['data']['user']['status'] != 'admin') {
            return redirect('/');
        }

        if($request->isMethod('post')) {
            $validator = Validator::make($data, [
                'username' => 'required|string|min:3|max:255',
                'message' => 'required|string|min:3|max:255',
                'color' => 'required|string|in:success,primary,danger,info,warning'
            ]);

            if($validator->fails()) {
                Session::put('error', $validator->errors()->first());
                return redirect('/admin/give_badges');
            }

            if(!User::where('username', $data['username'])->exists()) {
                Session::put('error', 'User does not exist');
                return redirect('/admin/give_badges');
            }

            $user = User::where('username', $data['username'])->first();
            $badges = json_decode($user->badges, true);
            $badges['data']['custom_badges'][] = [
                'message' => $data['message'],
                'color' => $data['color']
            ];

            $user->badges = json_encode($badges);
            $user->save();

            Session::put('success', 'Successfully created.');
            return redirect('/admin/give_badges');
        }

        return view($this->request['data']['user']['version'] . '/Admin/Badges', $this->request);
    }

    public function elections(Request $request) {
        $this->request['data']['embeds']['title'] = 'Elections' . $this->request['data']['embeds']['title'];
        $data = $request->all();

        if(!$this->request['data']['siteusername'] || $this->request['data']['user']['status'] != 'admin') {
            return redirect('/');
        }

        if($request->isMethod('post')) {
            $validator = Validator::make($data, [
                'title' => 'required|string|min:3|max:255',
                'css' => 'nullable|string|max:8192',
                'expire' => 'required|date',
                'time' => 'required|date_format:H:i',
                'options' => 'required|array'
            ]);

            if($validator->fails()) {
                Session::put('error', $validator->errors()->first());
                return redirect('/admin/elections');
            }

            if($this->db->table('elections')->where('expire', '>', now())->exists()) {
                Session::put('error', 'There is an election already active');
                return redirect('/admin/elections');
            }

            $id = 1;

            if(!is_array($data['options'])) {
                $options = json_decode($data['options'], true);
            } else {
                $options = $data['options'];
            }

            foreach($options as $key => $option) {
                $options[$key]['id'] = $id;
                $id++;
            }

            $timezone = new \DateTimeZone('America/Los_Angeles');
            $dateTime = new \DateTime($data['expire'] . ' ' . $data['time'], $timezone);
            $expire = $dateTime->format('Y-m-d H:i:s');

            $this->db->table('elections')->insert([
                'title' => $data['title'],
                'author' => $this->request['data']['user']['username'],
                'css' => $data['css'] ?? '',
                'options' => json_encode($options),
                'expire' => $expire,
                'votes' => '[]'
            ]);

            Session::put('success', 'Successfully created.');
            return redirect('/admin/elections');
        }

        return view($this->request['data']['user']['version'] . '/Admin/Elections', $this->request);
    }

    public function servers(Request $request) {
        $data = $request->all();

        if(!$this->request['data']['siteusername'] || $this->request['data']['user']['status'] != 'admin') {
            return redirect('/');
        }

        if($request->isMethod('post')) {
            $validator = Validator::make($data, [
                'type' => 'required|integer|min:1|max:3'
            ]);

            if($validator->fails()) {
                return redirect('/admin/servers');
            }

            if($data['type'] == 1) {
                $validator = Validator::make($data, [
                    'ip' => 'required|string',
                    'port' => 'required|integer',
                    'placeid' => 'required|integer',
                    'jobId' => 'required|string',
                    'status' => 'required|integer'
                ]);

                if($validator->fails()) {
                    return redirect('/admin/servers');
                }

                $this->db->table('servers')->insert([
                    'ip' => $data['id'],
                    'port' => $data['port'],
                    'placeid' => $data['placeid'],
                    'jobId' => $data['jobId'],
                    'status' => $data['status']
                ]);

                return redirect('/admin/servers');
            } elseif($data['type'] == 2) {
                $this->db->statement('TRUNCATE TABLE servers');

                return redirect('/admin/servers');
            } elseif($data['type'] == 3) {
                $validator = Validator::make($data, [
                    'jobId' => 'required|string'
                ]);

                if($validator->fails()) {
                    return redirect('/admin/servers');
                }

                $this->db->table('servers')
                    ->where('jobId', $data['jobId'])
                    ->delete();

                return redirect('/admin/servers');
            }

            return redirect('/admin/servers');
        }

        $this->request['data']['servers'] = $this->db->table('servers')
            ->get()
            ->map(function ($item) {
                return (array) $item;
            })->toArray();

        return view($this->request['data']['user']['version'] . '/Admin/Servers', $this->request);
    }

    public function rbxcreatexml(Request $request) {
        $this->request['data']['embeds']['title'] = 'Create RBX XML asset' . $this->request['data']['embeds']['title'];
        $data = $request->all();

        if(!$this->request['data']['siteusername'] || $this->request['data']['user']['status'] != 'admin') {
            return redirect('/');
        }

        if($request->isMethod('post')) {
            $validator = Validator::make($data, [
                'title' => 'required|string|min:3|max:255',
                'description' => 'nullable|string|max:8192',
                'price' => 'required|integer|min:0',
                'assetid' => 'required|integer',
                'onsale' => 'nullable|in:on,1,true,0,false,off'
            ]);

            if($validator->fails()) {
                Session::put('error', $validator->errors()->first());
                return redirect('/admin/rbxcreatexml');
            }

            $filename = uniqid();
            $xml = Http::withHeaders([
                'User-Agent' => 'Roblox/WinInet',
                'Cookie' => '.ROBLOSECURITY=_|WARNING:-DO-NOT-SHARE-THIS.--Sharing-this-will-allow-someone-to-log-in-as-you-and-to-steal-your-ROBUX-and-items.|_12F7CD4C7A51242AA368F0B8ABAEB272699DB45AC27966C148A771706A48E3D197D33F5CC344F1426FAB821E0E21F72331BDE6548753F9928F4BC4711891B3F796237C22048535C4865498809CED2C25EA849616D88EAE8CF51C88E768A227A06D2E51722F12FF9BB84AB1789BB91F7AC00BF9DF397E169396B7E3EBA47334EBD1B8148689732C64B9EC939E559D51FAB1AEE8BD1E9E5D6173369AA03EC8AC123BBF58D0E251964F13FE680C368FBC8C351EE258A54CFC2D3FB325FF2BE6E42E66F167EDA4785E347E1EA5FECCAB7E245BFAFA6878DB5D61B3CBD853B766B935ED1C37E86C1C807D5834D4A3BA75F08B6F2718964EB86E15AB59CA2174C5E30F4BDAD3BD759ABE532D2FCA26EB8CA3B4773FB19D19CCD23D621A843DCA707B4CECBC4836312B8FAEB92DB7F362A978F01942DE17F974B62C9851A67E20A32157B8D0010CFD7EB2101091CDE3ED79C7F1FE474DECE10648A84FF633B8F245015BF8D6E9B05B2BA5E1215AD2DD793A5754BC92F002E953A9CDFFD4DB285B2ACB6E47647BE5BE3C2F58F263A3EB47CECC20909E51BF8708DE767FC81750F7F63EABABD4A5071AB3130C11DBCDE26C581340BCE06EB104CC0E14FEA1CD52D366153DCF0D3051A3D152819B2BC76F3B2F93E23534380B42134897189D9BB0A55CB9EFC613EF735B08193ED80A40A447BAE6F8E6438FBFDE6B50DE6AA859B5B3CA3FDE0D120DF1C2A7833C7F79D8927A4B90B9046749598EE1764A2AB70795AA3B122A5A2758BCFECC4375421F91CC34DD802DD5A960C931724089084A6227577686E8373D05C989549F8F1B15257714F1D9FA6D5CC79932F3BFFBDF53E4716220476228E950D9C351958A7EA345B1A9CCCB701D51C0F7CC1FB75915A91F36FE73C40F368EF45A924A4816EE60715746763C0A9299F215367B0EBEA6E6D27CF06D02566B6313A915F9E8C0E9B1246A661BF9082A001DA54A523E4A1759A63CC51867DDD2AC562A956CEAD0C7C9157888B0A6026A301E0E85CA4BBA4E5B7A7A812C31547A857639B6739897AEEA2962D49057E358FC57608BA9EEB652E34AE31833BBFBD5D6A1226BF30D4DC72E0C5AF1E855AEA07BDDDF9428AF2954AD57610B4E2CE58E073EA7951DB5ED742A2D8669B8CA2DFD2B5758'
            ])->withoutVerifying()
            ->accept('*/*')
            ->get("https://assetdelivery.roblox.com/v1/asset/", [
                'id' => $data['assetid']
            ])->body();
            $xml = mb_convert_encoding($xml, 'UTF-8', 'UTF-8');

            preg_match('/<Content name="MeshId"><url>(?:http:\/\/www\.roblox\.com\/asset\/\?id=|rbxassetid:\/\/)(\d+)\s*<\/url><\/Content>/', $xml, $matches);
            $meshId = $matches[1] ?? false;

            preg_match('/<Content name="TextureId"><url>(?:http:\/\/www\.roblox\.com\/asset\/\?id=|rbxassetid:\/\/)(\d+)\s*<\/url><\/Content>/', $xml, $matches);
            $textureId = $matches[1] ?? false;

            if(!$meshId || !$textureId) {
                $this->dataService->send_discord_message('DEBUG: ' . $xml);
                Session::put('error', 'Could not find MeshId or TextureId');
                return redirect('/admin/rbxcreatexml');
            }

            $mesh = Http::withHeaders([
                'User-Agent' => 'Roblox/WinInet',
                'Cookie' => '.ROBLOSECURITY=_|WARNING:-DO-NOT-SHARE-THIS.--Sharing-this-will-allow-someone-to-log-in-as-you-and-to-steal-your-ROBUX-and-items.|_12F7CD4C7A51242AA368F0B8ABAEB272699DB45AC27966C148A771706A48E3D197D33F5CC344F1426FAB821E0E21F72331BDE6548753F9928F4BC4711891B3F796237C22048535C4865498809CED2C25EA849616D88EAE8CF51C88E768A227A06D2E51722F12FF9BB84AB1789BB91F7AC00BF9DF397E169396B7E3EBA47334EBD1B8148689732C64B9EC939E559D51FAB1AEE8BD1E9E5D6173369AA03EC8AC123BBF58D0E251964F13FE680C368FBC8C351EE258A54CFC2D3FB325FF2BE6E42E66F167EDA4785E347E1EA5FECCAB7E245BFAFA6878DB5D61B3CBD853B766B935ED1C37E86C1C807D5834D4A3BA75F08B6F2718964EB86E15AB59CA2174C5E30F4BDAD3BD759ABE532D2FCA26EB8CA3B4773FB19D19CCD23D621A843DCA707B4CECBC4836312B8FAEB92DB7F362A978F01942DE17F974B62C9851A67E20A32157B8D0010CFD7EB2101091CDE3ED79C7F1FE474DECE10648A84FF633B8F245015BF8D6E9B05B2BA5E1215AD2DD793A5754BC92F002E953A9CDFFD4DB285B2ACB6E47647BE5BE3C2F58F263A3EB47CECC20909E51BF8708DE767FC81750F7F63EABABD4A5071AB3130C11DBCDE26C581340BCE06EB104CC0E14FEA1CD52D366153DCF0D3051A3D152819B2BC76F3B2F93E23534380B42134897189D9BB0A55CB9EFC613EF735B08193ED80A40A447BAE6F8E6438FBFDE6B50DE6AA859B5B3CA3FDE0D120DF1C2A7833C7F79D8927A4B90B9046749598EE1764A2AB70795AA3B122A5A2758BCFECC4375421F91CC34DD802DD5A960C931724089084A6227577686E8373D05C989549F8F1B15257714F1D9FA6D5CC79932F3BFFBDF53E4716220476228E950D9C351958A7EA345B1A9CCCB701D51C0F7CC1FB75915A91F36FE73C40F368EF45A924A4816EE60715746763C0A9299F215367B0EBEA6E6D27CF06D02566B6313A915F9E8C0E9B1246A661BF9082A001DA54A523E4A1759A63CC51867DDD2AC562A956CEAD0C7C9157888B0A6026A301E0E85CA4BBA4E5B7A7A812C31547A857639B6739897AEEA2962D49057E358FC57608BA9EEB652E34AE31833BBFBD5D6A1226BF30D4DC72E0C5AF1E855AEA07BDDDF9428AF2954AD57610B4E2CE58E073EA7951DB5ED742A2D8669B8CA2DFD2B5758'
            ])->withoutVerifying()
            ->accept('*/*')
            ->get("https://assetdelivery.roblox.com/v1/asset/", [
                'id' => $meshId
            ])->body();
            $mesh = mb_convert_encoding($mesh, 'UTF-8', 'UTF-8');

            if(!str_starts_with($mesh, 'version')) {
                Session::put('error', 'Unsupported mesh format');
                return redirect('/admin/rbxcreatexml');
            }

            $texture = Http::withHeaders([
                'User-Agent' => 'Roblox/WinInet',
                'Cookie' => '.ROBLOSECURITY=_|WARNING:-DO-NOT-SHARE-THIS.--Sharing-this-will-allow-someone-to-log-in-as-you-and-to-steal-your-ROBUX-and-items.|_12F7CD4C7A51242AA368F0B8ABAEB272699DB45AC27966C148A771706A48E3D197D33F5CC344F1426FAB821E0E21F72331BDE6548753F9928F4BC4711891B3F796237C22048535C4865498809CED2C25EA849616D88EAE8CF51C88E768A227A06D2E51722F12FF9BB84AB1789BB91F7AC00BF9DF397E169396B7E3EBA47334EBD1B8148689732C64B9EC939E559D51FAB1AEE8BD1E9E5D6173369AA03EC8AC123BBF58D0E251964F13FE680C368FBC8C351EE258A54CFC2D3FB325FF2BE6E42E66F167EDA4785E347E1EA5FECCAB7E245BFAFA6878DB5D61B3CBD853B766B935ED1C37E86C1C807D5834D4A3BA75F08B6F2718964EB86E15AB59CA2174C5E30F4BDAD3BD759ABE532D2FCA26EB8CA3B4773FB19D19CCD23D621A843DCA707B4CECBC4836312B8FAEB92DB7F362A978F01942DE17F974B62C9851A67E20A32157B8D0010CFD7EB2101091CDE3ED79C7F1FE474DECE10648A84FF633B8F245015BF8D6E9B05B2BA5E1215AD2DD793A5754BC92F002E953A9CDFFD4DB285B2ACB6E47647BE5BE3C2F58F263A3EB47CECC20909E51BF8708DE767FC81750F7F63EABABD4A5071AB3130C11DBCDE26C581340BCE06EB104CC0E14FEA1CD52D366153DCF0D3051A3D152819B2BC76F3B2F93E23534380B42134897189D9BB0A55CB9EFC613EF735B08193ED80A40A447BAE6F8E6438FBFDE6B50DE6AA859B5B3CA3FDE0D120DF1C2A7833C7F79D8927A4B90B9046749598EE1764A2AB70795AA3B122A5A2758BCFECC4375421F91CC34DD802DD5A960C931724089084A6227577686E8373D05C989549F8F1B15257714F1D9FA6D5CC79932F3BFFBDF53E4716220476228E950D9C351958A7EA345B1A9CCCB701D51C0F7CC1FB75915A91F36FE73C40F368EF45A924A4816EE60715746763C0A9299F215367B0EBEA6E6D27CF06D02566B6313A915F9E8C0E9B1246A661BF9082A001DA54A523E4A1759A63CC51867DDD2AC562A956CEAD0C7C9157888B0A6026A301E0E85CA4BBA4E5B7A7A812C31547A857639B6739897AEEA2962D49057E358FC57608BA9EEB652E34AE31833BBFBD5D6A1226BF30D4DC72E0C5AF1E855AEA07BDDDF9428AF2954AD57610B4E2CE58E073EA7951DB5ED742A2D8669B8CA2DFD2B5758'
            ])->withoutVerifying()
            ->accept('*/*')
            ->get("https://assetdelivery.roblox.com/v1/asset/", [
                'id' => $textureId
            ])->body();

            preg_match('/class="([^"]+)"/', $xml, $matches);
            $classValue = $matches[1] ?? null;

            if(in_array($classValue, ['Hat', 'Accessory'])) {
                $assettype = 8;
            } elseif(in_array($classValue, ['Tool'])) {
                $assettype = 19;
            } else {
                Session::put('error', 'Unknown XML class type (' . $classValue . ')');
                return redirect('/admin/rbxcreatexml');
            }

            file_put_contents(public_path('dynamic/temp/' . $filename . '.xml'), $xml);
            file_put_contents(public_path('dynamic/temp/' . $filename . '.mesh'), $mesh);
            file_put_contents(public_path('dynamic/temp/' . $filename . '.png'), $texture);

            try {
                $id = Asset::createHatOrGear(
                    $data['title'],
                    ['tmp_name' => public_path('dynamic/temp/' . $filename . '.png')],
                    ['tmp_name' => public_path('dynamic/temp/' . $filename . '.mesh')],
                    ['tmp_name' => public_path('dynamic/temp/' . $filename . '.xml')],
                    $this->request['data']['user']['id'],
                    $data['description'] ?? '',
                    intval($data['price']),
                    isset($data['onsale']),
                    false,
                    [],
                    $assettype
                );
            } catch(\Exception $e) {
                Session::put('error', $e->getMessage());
                return redirect('/admin/createxml');
            }

            Session::put('success', 'Success');
            return redirect('/item/' . $id);
        }

        return view($this->request['data']['user']['version'] . '/Admin/RBXCreateXML', $this->request);
    }

    public function changeversions(Request $request) {
        $this->request['data']['embeds']['title'] = 'Change client version' . $this->request['data']['embeds']['title'];
        $data = $request->all();

        if(!$this->request['data']['siteusername'] || $this->request['data']['user']['status'] != 'admin') {
            return redirect('/');
        }

        if($request->isMethod('post')) {
            $validator = Validator::make($data, [
                'application' => 'required|regex:/^\d+\.\d+\.\d+pcapplication$/',
                'md5' => 'required|regex:/^[a-f0-9]{32}$/i',
                'version' => 'required|regex:/^version-[a-f0-9]{16}$/'
            ]);

            if($validator->fails()) {
                Session::put('error', $validator->errors->first());
                return redirect('/admin/changeversions');
            }

            $json = [
                'application' => $data['application'],
                'md5' => $data['md5'],
                'version' => $data['version']
            ];

            file_put_contents(storage_path('app/private/versions.json'), json_encode($json));

            Session::put('success', 'Successfully changed');
            return redirect('/admin/changeversions');
        }

        $json = json_decode(file_get_contents(storage_path('app/private/versions.json')), true);

        $this->request['data']['application'] = $json['application'];
        $this->request['data']['md5'] = $json['md5'];
        $this->request['data']['version'] = $json['version'];

        return view($this->request['data']['user']['version'] . '/Admin/Changeversions', $this->request);
    }
}
