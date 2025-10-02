<?php

namespace App\Http\Controllers;

use FFMpeg\FFMpeg;
use FFMpeg\Format\Audio\Mp3;
use FFMpeg\Format\Video\X264;
use FFMpeg\Coordinate\TimeCode;
use Carbon\Carbon;
use App\Mail\DynamicContentEmail;
use App\Models\User;
use App\Jobs\ProcessVideo;
use App\Http\Controllers\dataController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Process\Exception\ProcessFailedException;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\MarkdownConverter;
use League\CommonMark\Extension\DisallowedRawHtml\DisallowedRawHtmlExtension;
use League\CommonMark\Renderer\Block\ParagraphRenderer;
use League\CommonMark\Node\Block\Paragraph;
use League\CommonMark\Util\HtmlElement;
use Parsedown;

class frontEnd extends Controller
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
                'lucky_number' => rand(0, Cache::remember('user_count', 3600, fn() => User::count())) . '/' . Cache::remember('user_count', 3600, fn() => User::count()),
                'string_replacements' => [
                    'phrasesToReplace' => [
                        'fuck',
                        'fucking',
                        'roblox',
                        'rob lox',
                        'robux',
                        'ass',
                        'asshole',
                        'shit',
                        'r*blox'
                    ],
                    'replacements' => [
                        'OBAMA BALL',
                        'sonic 06',
                        'blockland.us'
                    ]
                ]
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
            $this->request['data']['user'] = Auth::user();

            if(!$this->request['data']['user']) {
                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return redirect('/');
            }

            $this->request['data']['user'] = $this->request['data']['user']->toArray();
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
            
            if(strtotime($this->request['data']['user']['lastdiu']) <= time() && $this->request['data']['user']['diubanned'] == 'n') {
                /*
                $this->db->table('users')
                    ->where('username', $this->request['data']['user']['username'])
                    ->update([
                        'Dius' => $this->request['data']['user']['Dius'] + 25,
                        'lastdiu' => DB::raw('DATE_ADD(CURRENT_TIMESTAMP(), INTERVAL 1 DAY)')
                    ]);
                */
                
                $user = Auth::user();
                $user->Dius = $this->request['data']['user']['Dius'] + 25;
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
        $this->request['data']['embeds']['title'] = 'Home' . $this->request['data']['embeds']['title'];

        if($request->isMethod('post')) {
            if(!$this->request['data']['siteusername']) {
                return redirect('/');
            }

            if($this->db->table('bans')->where('username', $this->request['data']['user']['username'])->where('expire', '<', DB::raw('now()'))->where('reactivated', 'n')->exists()) {
                $ban = (array) $this->db->table('bans')
                    ->where('username', $this->request['data']['user']['username'])
                    ->where('expire', '<', DB::raw('now()'))
                    ->where('reactivated', 'n')
                    ->first();
                
                if($ban['perm'] == 'y') {
                    return redirect('/');
                }

                if(Carbon::parse($ban['expire'])->lt(now())) {
                    $this->db->table('bans')
                        ->where('username', $this->request['data']['user']['username'])
                        ->where('reactivated', 'n')
                        ->update([
                            'reactivated' => 'y'
                        ]);
                } else {
                    Session::put('error', 'This activity has been logged and your ban may be extended');
                    return redirect('/');
                }
            }

            if($this->db->table('warning')->where('username', $this->request['data']['user']['username'])->where('reactivated', 'n')->exists()) {
                $this->db->table('warning')
                    ->where('username', $this->request['data']['user']['username'])
                    ->where('reactivated', 'n')
                    ->update([
                        'reactivated' => 'y'
                    ]);
            }

            Session::put('successv2', 'Your moderation action has been lifted.');
            return redirect('/');
        }

        if($this->request['data']['siteusername']) {
            $this->request['data']['games'] = [];

            $games = Cache::remember('latest_places', 60 * 10, function() {
                return $this->db->table('assets')
                    ->select('assets.*', DB::raw('SUM(servers.players) AS total_players'))
                    ->leftJoin('servers', 'assets.id', '=', 'servers.placeid')
                    ->where('asset_type', 9)
                    ->groupBy('assets.id')
                    ->orderByDesc('total_players')
                    ->limit(6)
                    ->get()
                    ->map(function ($item) {
                        return (array) $item;
                    })->toArray();
            });
            
            foreach($games as $key => $game) {
                $game['additional'] = json_decode($game['additional'], true);
                $players = 0;

                $servers = $this->db->table('servers')
                    ->select('players')
                    ->where('placeid', $game['id'])
                    ->get()
                    ->map(function ($item) {
                        return (array) $item;
                    })->toArray();
                
                foreach($servers as $server) {
                    $players += count(json_decode($server['players']));
                }

                $thumbnail = $this->db->table('assets')
                    ->select('file')
                    ->where('id', $game['additional']['media']['imageAssetId'])
                    ->value('file');
                
                $this->request['data']['games'][] = [
                    'id' => $game['id'],
                    'title' => $game['title'],
                    'author' => User::find($game['author'])->value('username'),
                    'thumbnail' => $thumbnail,
                    'visits' => number_format($game['additional']['visits']),
                    'version' => $game['additional']['version'],
                    'players' => $players
                ];
            }
        }

        return view($this->request['data']['user']['version'] . '/Landing', $this->request);
    }

    public function user(Request $request, $id) {
        if(!$this->request['data']['siteusername']) {
            return redirect('/');
        }

        if(!User::where('id', $id)->exists()) {
            return response()->view($this->request['data']['user']['version'] . '/404', [], 404);
        }

        $user = User::find($id)->toArray();

        $this->request['data']['embeds']['title'] = htmlspecialchars($user['username']) . $this->request['data']['embeds']['title'];

        $user['places'] = [];
        $user['created'] = date('m/d/Y h:i:s A', strtotime($user['created']));
        $user['blurb'] = nl2br(str_replace('${myDius}', '<span class="n-money-text text-nowrap"><img src="/s/img/diu_16.png" alt="Diu" title="Diu" class="img-responsive align-middle "> [' . number_format($user['Dius']) . ']</span>', preg_replace('/\b((?:https?|ftp):\/\/\S+)/i', '<a href="$1">$1</a>', strip_tags(htmlspecialchars($user['blurb'])))));
        $user['badges'] = json_decode($user['badges'], true)['data']['custom_badges'] ?? [];
        $user['friends'] = json_decode($user['friends'], true);
        $user['CurrentFriends'] = array_reverse(array_filter($user['friends'], function ($friend) {
            return $friend['status'] == 'friends';
        }));

        $servers = $this->db->table('servers')
            ->get()
            ->map(function ($item) {
                return (array) $item;
            })->toArray();
        
        foreach($servers as $server) {
            $placeid = 0;
            $jobId = '';
            $found = false;
            $server['players'] = json_decode($server['players'], true);

            foreach($server['players'] as $player) {
                if($player == $user['id']) {
                    $found = true;
                    $placeid = $server['placeid'];
                    $jobId = $server['jobId'];
                    break;
                }
            }

            if($found) {
                $user['InGame'] = true;
                $user['game'] = [
                    'id' => $placeid,
                    'title' => strip_tags(htmlspecialchars($this->db->table('assets')->where('id', $placeid)->value('title'))),
                    'jobId' => $jobId
                ];
            }
        }

        $user['friends'] = array_reverse($user['friends']);

        foreach($user['friends'] as $key => $friend) {
            $user['friends'][$key]['username'] = Cache::remember('username_' . $friend['userid'], 60 * 60, function() use ($friend) { return User::where('id', $friend['userid'])->value('username'); });
            $user['friends'][$key]['pfp'] = Cache::remember('pfp_' . $friend['userid'], 60 * 60, function() use ($friend) { return User::where('id', $friend['userid'])->value('pfp'); });
        }

        foreach($user['CurrentFriends'] as $key => $friend) {
            $user['CurrentFriends'][$key]['username'] = Cache::remember('username_' . $friend['userid'], 60 * 60, function() use ($friend) { return User::where('id', $friend['userid'])->value('username'); });
            $user['CurrentFriends'][$key]['pfp'] = Cache::remember('pfp_' . $friend['userid'], 60 * 60, function() use ($friend) { return User::where('id', $friend['userid'])->value('pfp'); });
        }

        $places = $this->db->table('assets')
            ->where('author', $user['id'])
            ->where('asset_type', 9)
            ->get()
            ->map(function ($item) {
                return (array) $item;
            })->toArray();

        foreach($places as $index => $place) {
            $place['additional'] = json_decode($place['additional'], true);
            $place['count'] = $index + 1;
            $place['thumbnail'] = Cache::remember('thumbnail_' . $place['additional']['media']['imageAssetId'], 60 * 60, function() use ($place) { return $this->db->table('assets')->select('file')->where('id', $place['additional']['media']['imageAssetId'])->value('file'); });
            $user['places'][] = $place;
        }

        if($this->db->table('bans')->where('username', $user['username'])->where('perm', 'y')->exists()) {
            $user['ban'] = [
                'IsBanned' => true,
                'data' => [
                    'reason' => $this->db->table('bans')->select('reason')->where('username', $user['username'])->where('perm', 'y')->value('reason')
                ]
            ];
        }

        $this->request['data']['profile'] = $user;
        return view($this->request['data']['user']['version'] . '/User', $this->request);
    }

    public function user_add(Request $request, $id) {
        $data = $request->all();

        if(!$this->request['data']['siteusername']) {
            return redirect('/');
        }

        if(!User::where('id', $id)->exists()) {
            Session::put('error', 'User does not exist');
            return redirect('/');
        }

        $user = User::find($id);
        $user->friends = json_decode($user->friends, true);
        
        if($user->id == $this->request['data']['user']['id']) {
            return redirect('/user/' . $id);
        }

        foreach($user->friends as $friend) {
            if($friend['userid'] == $this->request['data']['user']['id']) {
                return redirect('/user/' . $id);
            }
        }

        $friends = $user->friends;
        $friends[] = [
            'userid' => $this->request['data']['user']['id'],
            'status' => 'pending'
        ];

        $user->friends = json_encode($friends, JSON_FORCE_OBJECT);
        $user->save();

        return redirect('/user/' . $id);
    }

    public function user_accept(Request $request, $id) {
        $data = $request->all();

        if(!$this->request['data']['siteusername']) {
            return redirect('/');
        }

        if(!User::where('id', $id)->exists()) {
            Session::put('error', 'User does not exist');
            return redirect('/');
        }

        if(empty(array_filter($this->request['data']['user']['friends'], function ($entry) use ($id) {
            return $entry['userid'] == $id;
        }))) {
            if(isset($data['feature'])) {
                return redirect('/friends/incoming');
            } else {
                return redirect('/user/' . $id);
            }
        }

        $user = User::find($id);
        $user->friends = json_decode($user->friends, true);
        
        if($user->id == $this->request['data']['user']['id']) {
            if(isset($data['feature'])) {
                return redirect('/friends/incoming');
            } else {
                return redirect('/user/' . $id);
            }
        }

        foreach($user->friends as $friend) {
            if($friend['userid'] == $this->request['data']['user']['id']) {
                Session::put('error', 'You already added this user');
                if(isset($data['feature'])) {
                    return redirect('/friends/incoming');
                } else {
                    return redirect('/user/' . $id);
                }
            }
        }

        $friends = $user->friends;
        $friends[] = [
            'userid' => $this->request['data']['user']['id'],
            'status' => 'friends'
        ];

        $user->friends = json_encode($friends, JSON_FORCE_OBJECT);
        $user->save();

        foreach($this->request['data']['user']['friends'] as $key => $friend) {
            if($friend['userid'] == $user->id) {
                $this->request['data']['user']['friends'][$key]['status'] = 'friends';
                break;
            }
        }

        User::where('id', $this->request['data']['user']['id'])->update([
            'friends' => json_encode($this->request['data']['user']['friends'], JSON_FORCE_OBJECT)
        ]);

        if(isset($data['feature'])) {
            return redirect('/friends/incoming');
        } else {
            return redirect('/user/' . $id);
        }
    }

    public function user_remove(Request $request, $id) {
        $data = $request->all();

        if(!$this->request['data']['siteusername']) {
            return redirect('/');
        }

        if(!User::where('id', $id)->exists()) {
            Session::put('error', 'User does not exist');
            return redirect('/');
        }

        if(empty(array_filter($this->request['data']['user']['friends'], function ($entry) use ($id) {
            return $entry['userid'] == $id;
        }))) {
            if(isset($data['feature'])) {
                return redirect('/friends/incoming');
            } else {
                return redirect('/user/' . $id);
            }
        }

        $user = User::find($id);
        $user->friends = json_decode($user->friends, true);
        
        if($user->id == $this->request['data']['user']['id']) {
            if(isset($data['feature'])) {
                return redirect('/friends/incoming');
            } else {
                return redirect('/user/' . $id);
            }
        }

        $friends = $user->friends;

        foreach($friends as $key => $friend) {
            if($friend['userid'] == $this->request['data']['user']['id']) {
                unset($friends[$key]);
                break;
            }
        }

        $user->friends = json_encode($friends, JSON_FORCE_OBJECT);
        $user->save();

        foreach($this->request['data']['user']['friends'] as $key => $friend) {
            if($friend['userid'] == $user->id) {
                unset($this->request['data']['user']['friends'][$key]);
                break;
            }
        }

        User::where('id', $this->request['data']['user']['id'])->update([
            'friends' => json_encode($this->request['data']['user']['friends'], JSON_FORCE_OBJECT)
        ]);

        if(isset($data['feature'])) {
            return redirect('/friends/incoming');
        } else {
            return redirect('/user/' . $id);
        }
    }

    public function user_friends(Request $request, $id) {
        $data = $request->all();

        if(!$this->request['data']['siteusername']) {
            return redirect('/');
        }

        if(!User::where('id', $id)->exists()) {
            return view($this->request['data']['user']['version'] . '/404', [], 404);
        }

        $user = User::find($id)->toArray();
        $user['friends'] = array_reverse(array_filter(json_decode($user['friends'], true), function ($friend) {
            return $friend['status'] == 'friends';
        }));

        foreach($user['friends'] as $key => $friend) {
            $user['friends'][$key]['username'] = Cache::remember('username_' . $friend['userid'], 60 * 60, function() use ($friend) { return User::where('id', $friend['userid'])->value('username'); });
            $user['friends'][$key]['pfp'] = Cache::remember('pfp_' . $friend['userid'], 60 * 60, function() use ($friend) { return User::where('id', $friend['userid'])->value('pfp'); });
        }

        $pages_to_show = 10;
        $results_per_page = 12;
        $number_of_pages = ceil(count($user['friends']) / $results_per_page);
        $currentPage = isset($data['page']) ? max(1, intval($data['page'])) : 1;
        $offset = ($currentPage - 1) * $results_per_page;
        $start_page = max(1, min($currentPage - floor($pages_to_show / 2), $number_of_pages - $pages_to_show + 1));
        $end_page = min($number_of_pages, $start_page + $pages_to_show - 1);
        $user['friends'] = array_slice($user['friends'], $offset, $results_per_page);
        
        $this->request['data']['pagination'] = [
            'data' => [],
            'pages' => [
                'info' => [
                    'current_page' => $currentPage,
                    'previous_page' => max(1, $currentPage - 1),
                    'next_page' => min($number_of_pages, $currentPage + 1),
                    'start_page' => $start_page,
                    'end_page' => $end_page,
                    'number_of_pages' => $number_of_pages
                ],
                'data' =>[]
            ]
        ];

        for ($page = $start_page; $page <= $end_page; $page++) {
            $this->request['data']['pagination']['pages']['data'][] = ['page' => $page];
        }

        if(!count($user['friends'])) {
            $this->request['data']['pagination']['pages']['data'][] = [
                'page' => 1
            ];
        }

        $this->request['data']['profile'] = $user;
        $this->request['data']['embeds']['title'] = htmlspecialchars($user['username']) . '\'s Friends' . $this->request['data']['embeds']['title'];

        return view($this->request['data']['user']['version'] . '/User_friends', $this->request);
    }

    public function friends_incoming(Request $request) {
        $this->request['data']['embeds']['title'] = 'Friends Incoming' . $this->request['data']['embeds']['title'];
        $data = $request->all();

        if(!$this->request['data']['siteusername']) {
            return redirect('/');
        }

        $this->request['data']['user']['friends'] = array_reverse(array_filter($this->request['data']['user']['friends'], function ($friend) {
            return $friend['status'] == 'pending';
        }));

        foreach($this->request['data']['user']['friends'] as $key => $friend) {
            $this->request['data']['user']['friends'][$key]['username'] = Cache::remember('username_' . $friend['userid'], 60 * 60, function() use ($friend) { return User::where('id', $friend['userid'])->value('username'); });
            $this->request['data']['user']['friends'][$key]['pfp'] = Cache::remember('pfp_' . $friend['userid'], 60 * 60, function() use ($friend) { return User::where('id', $friend['userid'])->value('pfp'); });
        }

        return view($this->request['data']['user']['version'] . '/User_friends_incoming', $this->request);
    }

    public function transactions(Request $request) {
        $this->request['data']['embeds']['title'] = 'Transaction log' . $this->request['data']['embeds']['title'];

        if(!$this->request['data']['siteusername']) {
            return redirect('/');
        }

        $purchases = [];
        $results = $this->db->table('purchases')
            ->where('username', $this->request['data']['user']['username'])
            ->orderBy('date', 'DESC')
            ->limit(100)
            ->get()
            ->map(function ($item) {
                return (array) $item;
            })->toArray();
        
        foreach($results as $result) {
            $result['date'] = date('m/d/Y', strtotime($result['date']));


            if($result['type'] == 1 || $result['type'] == 2) {
                $result['assetname'] = strip_tags(htmlspecialchars(
                    $this->db->table('assets')->select('title')->where('id', $result['assetid'])->value('title')
                ));
            } elseif($result['type'] == 3) {
                $result['assetname'] = 'Place Slot';
            } elseif($result['type'] == 4) {
                $result['assetname'] = 'Dius';
            } elseif($result['type'] == 5) {
                $result['assetname'] = 'Asset Upload Fee';
            }

            $result['uuid'] = User::where('id', $result['author'])->exists() ? User::where('id', $result['author'])->value('id') : false;
            $result['author'] = $result['uuid'] ? User::where('id', $result['author'])->value('username') : htmlspecialchars($result['author']);
            $result['amount'] = $this->dataService->formatNumber($result['amount']);
            $purchases[] = $result;
        }

        $this->request['data']['purchases'] = $purchases;

        return view($this->request['data']['user']['version'] . '/Transactions', $this->request);
    }

    public function users(Request $request) {
        $this->request['data']['embeds']['title'] = 'Users' . $this->request['data']['embeds']['title'];
        $data = $request->all();

        if(!$this->request['data']['siteusername']) {
            return redirect('/');
        }

        $users = [];
        $pages_to_show = 10;
        $results_per_page = 16;

        if(isset($data['search'])) {
            $search = '%' . htmlspecialchars($data['search']) . '%';
            $results = User::whereNotIn('username', function ($query) {
                $query->select('username')
                    ->from('bans')
                    ->where('perm', 'y');
                })
                ->whereRaw('LOWER(username) LIKE LOWER(?)', ["%{$search}%"])
                ->orderBy('lastlogin', 'desc')
                ->count();
        } else {
            $results = User::whereNotIn('username', function ($query) {
                $query->select('username')
                    ->from('bans')
                    ->where('perm', 'y');
                })
                ->orderBy('lastlogin', 'desc')
                ->count();
        }

        $number_of_pages = ceil($results / $results_per_page);
        $currentPage = isset($data['page']) ? max(1, intval($data['page'])) : 1;
        $offset = ($currentPage - 1) * $results_per_page;
        $start_page = max(1, min($currentPage - floor($pages_to_show / 2), $number_of_pages - $pages_to_show + 1));
        $end_page = min($number_of_pages, $start_page + $pages_to_show - 1);

        if(isset($data['search'])) {
            $results = User::whereNotIn('username', function ($query) {
                $query->select('username')
                    ->from('bans')
                    ->where('perm', 'y');
                })
                ->whereRaw('LOWER(username) LIKE LOWER(?)', ["%{$search}%"])
                ->orderBy('lastlogin', 'desc')
                ->offset($offset)
                ->limit($results_per_page)
                ->get()
                ->toArray();
        } else {
            $results = User::whereNotIn('username', function ($query) {
                $query->select('username')
                    ->from('bans')
                    ->where('perm', 'y');
                })
                ->orderBy('lastlogin', 'desc')
                ->offset($offset)
                ->limit($results_per_page)
                ->get()
                ->toArray();
        }

        $servers = $this->db->table('servers')
            ->get()
            ->map(function ($item) {
                return (array) $item;
            })->toArray();

        foreach($results as $key => $result) {
            $user = [
                'id' => $result['id'],
                'username' => $result['username'],
                'pfp' => $result['pfp'],
                'lastlogin' => date('m/d/Y h:i A', strtotime($result['lastlogin'])),
                'IsOnline' => Carbon::parse($result['lastlogin'])->gt(Carbon::now()->subMinutes(2)),
                'InGame' => false
            ];

            foreach($servers as $server) {
                $placeid = 0;
                $found = false;
                $server['players'] = json_decode($server['players'], true);
    
                foreach($server['players'] as $player) {
                    if($player == $result['id']) {
                        $found = true;
                        $placeid = $server['placeid'];
                        break;
                    }
                }
    
                if($found) {
                    $user['InGame'] = true;
                    $user['game'] = [
                        'title' => strip_tags(htmlspecialchars($this->db->table('assets')->where('id', $placeid)->value('title')))
                    ];
                }
            }

            $users[] = $user;
        }

        foreach($users as $key => $user) {
            if($user['InGame']) {
                unset($users[$key]);
                array_unshift($users, $user);
            }
        }

        $this->request['data']['pagination'] = [
            'data' => [],
            'pages' => [
                'info' => [
                    'current_page' => $currentPage,
                    'previous_page' => max(1, $currentPage - 1),
                    'next_page' => min($number_of_pages, $currentPage + 1),
                    'start_page' => $start_page,
                    'end_page' => $end_page,
                    'number_of_pages' => $number_of_pages
                ],
                'data' =>[]
            ]
        ];

        for ($page = $start_page; $page <= $end_page; $page++) {
            $this->request['data']['pagination']['pages']['data'][] = ['page' => $page];
        }

        if(!count($users)) {
            $this->request['data']['pagination']['pages']['data'][] = [
                'page' => 1
            ];
        }

        $this->request['data']['users'] = $users;
        $this->request['data']['search'] = isset($data['search']) ? $data['search'] : false;

        return view($this->request['data']['user']['version'] . '/Users', $this->request);
    }

    public function forum_home(Request $request) {
        $data = $request->all();
        $this->request['data']['embeds']['title'] = 'Forum' . $this->request['data']['embeds']['title'];
        $this->request['data']['section'] = false;

        $posts = $this->db->table('forum_threads')->count();
        
        $pages_to_show = 10;
        $results_per_page = 10;
        $number_of_pages = ceil($posts / $results_per_page);
        $currentPage = isset($data['page']) ? max(1, intval($data['page'])) : 1;
        $offset = ($currentPage - 1) * $results_per_page;
        $start_page = max(1, min($currentPage - floor($pages_to_show / 2), $number_of_pages - $pages_to_show + 1));
        $end_page = min($number_of_pages, $start_page + $pages_to_show - 1);
        $phrasesToReplace = $this->request['data']['string_replacements']['phrasesToReplace'];
        $replacements = $this->request['data']['string_replacements']['replacements'];

        $posts = $this->db->table('forum_threads')
            ->orderBy('pinned', 'DESC')
            ->orderBy('lastreplied', 'DESC')
            ->offset($offset)
            ->limit($results_per_page)
            ->get()
            ->map(function ($item) {
                return (array) $item;
            })->toArray();
        
        $this->request['data']['threads'] = [
            'data' => [],
            'pages' => [
                'info' => [
                    'current_page' => $currentPage,
                    'previous_page' => max(1, $currentPage - 1),
                    'next_page' => min($number_of_pages, $currentPage + 1),
                    'start_page' => $start_page,
                    'end_page' => $end_page,
                    'number_of_pages' => $number_of_pages
                ],
                'data' =>[]
            ]
        ];

        foreach($posts as $post) {
            $post['status'] = User::where('username', $post)->value('status');
            $post['replies'] = $this->db->table('forum_replies')->where('toid', $post['id'])->count();
            $post['title'] = htmlspecialchars($post['title']);
            $post['title'] = preg_replace_callback('/\b(' . implode('|', array_map('preg_quote', $phrasesToReplace)) . ')\b/i', function ($matches) use ($replacements) {
                return $replacements[array_rand($replacements)];
            }, $post['title']);
            $post['author'] = htmlspecialchars($post['author']);
            $post['ago'] = $this->dataService->time_elapsed_string($post['date']);
            $post['date'] = date('F d, Y g:i a', strtotime($post['date']));
            $post['rating'] = $this->db->table('forum_ratings')->where('type', '1')->where('toid', $post['id'])->where('rate_type', 'l')->count();
            $post['rating'] = $post['rating'] - $this->db->table('forum_ratings')->where('type', '1')->where('toid', $post['id'])->where('rate_type', 'd')->count();
            $this->request['data']['threads']['data'][] = $post;
        }

        for ($page = $start_page; $page <= $end_page; $page++) {
            $this->request['data']['threads']['pages']['data'][] = ['page' => $page];
        }

        if(!count($posts)) {
            $this->request['data']['threads']['pages']['data'][] = [
                'page' => 1
            ];
        }

        return view($this->request['data']['user']['version'] . '/Forum/Index', $this->request);
    }

    public function forum_section(Request $request, $section) {
        $data = $request->all();
        $this->request['data']['embeds']['title'] = 'Forum' . $this->request['data']['embeds']['title'];
        $this->request['data']['section'] = $section;

        $posts = $this->db->table('forum_threads')
            ->where('category', $section)
            ->count();
        
        $pages_to_show = 10;
        $results_per_page = 10;
        $number_of_pages = ceil($posts / $results_per_page);
        $currentPage = isset($data['page']) ? max(1, intval($data['page'])) : 1;
        $offset = ($currentPage - 1) * $results_per_page;
        $start_page = max(1, min($currentPage - floor($pages_to_show / 2), $number_of_pages - $pages_to_show + 1));
        $end_page = min($number_of_pages, $start_page + $pages_to_show - 1);
        $phrasesToReplace = $this->request['data']['string_replacements']['phrasesToReplace'];
        $replacements = $this->request['data']['string_replacements']['replacements'];

        $posts = $this->db->table('forum_threads')
            ->where('category', $section)
            ->orderBy('pinned', 'DESC')
            ->orderBy('lastreplied', 'DESC')
            ->offset($offset)
            ->limit($results_per_page)
            ->get()
            ->map(function ($item) {
                return (array) $item;
            })->toArray();
        
        $this->request['data']['threads'] = [
            'data' => [],
            'pages' => [
                'info' => [
                    'current_page' => $currentPage,
                    'previous_page' => max(1, $currentPage - 1),
                    'next_page' => min($number_of_pages, $currentPage + 1),
                    'start_page' => $start_page,
                    'end_page' => $end_page,
                    'number_of_pages' => $number_of_pages
                ],
                'data' =>[]
            ]
        ];

        foreach($posts as $post) {
            $post['status'] = User::where('username', $post)->value('status');
            $post['replies'] = $this->db->table('forum_replies')->where('toid', $post['id'])->count();
            $post['title'] = htmlspecialchars($post['title']);
            $post['title'] = preg_replace_callback('/\b(' . implode('|', array_map('preg_quote', $phrasesToReplace)) . ')\b/i', function ($matches) use ($replacements) {
                return $replacements[array_rand($replacements)];
            }, $post['title']);
            $post['author'] = htmlspecialchars($post['author']);
            $post['ago'] = $this->dataService->time_elapsed_string($post['date']);
            $post['date'] = date('F d, Y g:i a', strtotime($post['date']));
            $post['rating'] = $this->db->table('forum_ratings')->where('type', '1')->where('toid', $post['id'])->where('rate_type', 'l')->count();
            $post['rating'] = $post['rating'] - $this->db->table('forum_ratings')->where('type', '1')->where('toid', $post['id'])->where('rate_type', 'd')->count();
            $this->request['data']['threads']['data'][] = $post;
        }

        for ($page = $start_page; $page <= $end_page; $page++) {
            $this->request['data']['threads']['pages']['data'][] = ['page' => $page];
        }

        if(!count($posts)) {
            $this->request['data']['threads']['pages']['data'][] = [
                'page' => 1
            ];
        }

        return view($this->request['data']['user']['version'] . '/Forum/Index', $this->request);
    }

    public function forum_search(Request $request) {
        $data = $request->all();
        $this->request['data']['embeds']['title'] = 'Forum' . $this->request['data']['embeds']['title'];

        if(!isset($data['q']) || empty($data['q'])) {
            return redirect('/forum/home');
        }

        $this->request['data']['search'] = htmlspecialchars($data['q']);
        $search = '%' . htmlspecialchars($data['q']) . '%';

        $posts = $this->db->table('forum_threads')
            ->whereRaw('LOWER(title) LIKE ?', [$search])
            ->count();
        
        $pages_to_show = 10;
        $results_per_page = 10;
        $number_of_pages = ceil($posts / $results_per_page);
        $currentPage = isset($data['page']) ? max(1, intval($data['page'])) : 1;
        $offset = ($currentPage - 1) * $results_per_page;
        $start_page = max(1, min($currentPage - floor($pages_to_show / 2), $number_of_pages - $pages_to_show + 1));
        $end_page = min($number_of_pages, $start_page + $pages_to_show - 1);
        $phrasesToReplace = $this->request['data']['string_replacements']['phrasesToReplace'];
        $replacements = $this->request['data']['string_replacements']['replacements'];

        $posts = $this->db->table('forum_threads')
            ->whereRaw('LOWER(title) LIKE ?', [$search])
            ->orderBy('pinned', 'DESC')
            ->orderBy('lastreplied', 'DESC')
            ->offset($offset)
            ->limit($results_per_page)
            ->get()
            ->map(function ($item) {
                return (array) $item;
            })->toArray();
        
        $this->request['data']['threads'] = [
            'data' => [],
            'pages' => [
                'info' => [
                    'current_page' => $currentPage,
                    'previous_page' => max(1, $currentPage - 1),
                    'next_page' => min($number_of_pages, $currentPage + 1),
                    'start_page' => $start_page,
                    'end_page' => $end_page,
                    'number_of_pages' => $number_of_pages
                ],
                'data' =>[]
            ]
        ];

        foreach($posts as $post) {
            $post['status'] = User::where('username', $post)->value('status');
            $post['replies'] = $this->db->table('forum_replies')->where('toid', $post['id'])->count();
            $post['title'] = htmlspecialchars($post['title']);
            $post['title'] = preg_replace_callback('/\b(' . implode('|', array_map('preg_quote', $phrasesToReplace)) . ')\b/i', function ($matches) use ($replacements) {
                return $replacements[array_rand($replacements)];
            }, $post['title']);
            $post['author'] = htmlspecialchars($post['author']);
            $post['ago'] = $this->dataService->time_elapsed_string($post['date']);
            $post['date'] = date('F d, Y g:i a', strtotime($post['date']));
            $post['rating'] = $this->db->table('forum_ratings')->where('type', '1')->where('toid', $post['id'])->where('rate_type', 'l')->count();
            $post['rating'] = $post['rating'] - $this->db->table('forum_ratings')->where('type', '1')->where('toid', $post['id'])->where('rate_type', 'd')->count();
            $this->request['data']['threads']['data'][] = $post;
        }

        for ($page = $start_page; $page <= $end_page; $page++) {
            $this->request['data']['threads']['pages']['data'][] = ['page' => $page];
        }

        if(!count($posts)) {
            $this->request['data']['threads']['pages']['data'][] = [
                'page' => 1
            ];
        }

        return view($this->request['data']['user']['version'] . '/Forum/Index', $this->request);
    }

    public function forum_post(Request $request) {
        $data = $request->all();

        if($request->isMethod('post')) {
            if(!$this->request['data']['siteusername']) {
                return redirect('/');
            }

            $validator = Validator::make($data, [
                'id' => 'required|integer',
                'content' => 'required|string|min:3|max:16384'
            ]);

            if(!(bool)env('FINOBE_FORUM_POST')) {
                Session::put('error', 'Posting on the forums have been disabled');
                return redirect('/forum/post?id=' . $data['id']);
            }

            if($validator->fails()) {
                Session::put('error', $validator->errors()->first());
                return redirect('/forum/home');
            }

            if(!$this->db->table('forum_threads')->where('id', $data['id'])->exists()) {
                Session::put('error', 'This post doesn\'t exist');
                return redirect('/forum/home');
            }

            $post = (array) $this->db->table('forum_threads')
                ->where('id', $data['id'])
                ->first();
            
            if($post['author'] != $this->request['data']['user']['username']) {
                Session::put('error', 'You do not own this post');
                return redirect('/forum/post?id=' . $data['id']);
            }

            if($post['locked'] == 'y') {
                Session::put('error', 'This post is locked');
                return redirect('/forum/post?id=' . $data['id']);
            }

            $this->db->table('forum_threads')
                ->where('id', $data['id'])
                ->update([
                    'comment' => trim($data['content'])
                ]);
            
            /*
            $this->db->table('users')
                ->where('username', $this->request['data']['user']['username'])
                ->update([
                    'post_cooldown' => DB::raw('CURRENT_TIMESTAMP()')
                ]);

            */
            $user = User::find($this->request['data']['user']['id']);
            $user->post_cooldown = now();
            $user->save();
            
            Session::put('success', 'Successfully edited.');
            return redirect('/forum/post?id=' . $data['id']);
        }

        if(!isset($data['id']) || empty($data['id'])) {
            return redirect('/forum/home');
        }

        if(!$this->db->table('forum_threads')->where('id', $data['id'])->exists()) {
            Session::put('error', 'This thread doesn\'t exist or was deleted.');
            return redirect('/forum/home');
        }

        $post = (array) $this->db->table('forum_threads')
            ->where('id', $data['id'])
            ->first();

        if(isset($data['edit']) && (!$this->request['data']['siteusername'] || $post['author'] != $this->request['data']['user']['username'])) {
            return redirect('/forum/post?id=' . $data['id']);
        }
        
        $this->request['data']['embeds']['title'] = htmlspecialchars($post['title']) . $this->request['data']['embeds']['title'];
        $this->request['data']['editing'] = isset($data['edit']);
        $post['title'] = htmlspecialchars($post['title']);
        $post['author'] = htmlspecialchars($post['author']);
        $post['format_date'] = date('M d Y h:i:s A', strtotime($post['date']));
        $post['date'] = date('m/d/Y h:i A', strtotime($post['date']));
        $post['edited_date'] = date('m/d/Y h:i A', strtotime($post['edited_date']));

        $user = User::where('username', $post['author'])->select('id', 'status', 'pfp', 'lastlogin', 'badges')->first()?->toArray();
        $post['uuid'] = $user['id'];
        $post['status'] = $user['status'];
        $post['pfp'] = $user['pfp'];
        $post['posts'] = $this->db->table('forum_threads')->where('author', $post['author'])->count() + $this->db->table('forum_replies')->where('author', $post['author'])->count();
        $post['badges'] = json_decode($user['badges'], true)['data']['custom_badges'] ?? [];
        $phrasesToReplace = $this->request['data']['string_replacements']['phrasesToReplace'];
        $replacements = $this->request['data']['string_replacements']['replacements'];

        if(!isset($data['edit'])) {
            $environment = new Environment([
                'html_input' => ($post['status'] == 'admin' ? 'allow' : 'strip'),
                'allow_unsafe_links' => false,
            ]);

            $environment->addExtension(new CommonMarkCoreExtension());
            $converter = new MarkdownConverter($environment);

            $post['title'] = preg_replace_callback('/\b(' . implode('|', array_map('preg_quote', $phrasesToReplace)) . ')\b/i', function ($matches) use ($replacements) {
                return $replacements[array_rand($replacements)];
            }, $post['title']);

            $post['comment'] = preg_replace_callback('/\b(' . implode('|', array_map('preg_quote', $phrasesToReplace)) . ')\b/i', function ($matches) use ($replacements) {
                return $replacements[array_rand($replacements)];
            }, $post['comment']);

            if($post['status'] != "admin") {
                $post['comment'] = strip_tags(htmlspecialchars($post['comment'], ENT_QUOTES, 'UTF-8'));
            }

            $post['comment'] = $converter->convert($post['comment'])->getContent();
            $post['comment'] = preg_replace_callback('/(<img[^>]*>|\b(?:https?|ftp):\/\/\S+)/i', function ($matches) {
                if (strpos($matches[0], '<img') === 0) {
                    return $matches[0];
                } else {
                    return '<a href="' . strip_tags($matches[0]) . '" target="_blank">' . $matches[0] . '</a>';
                }
            }, $post['comment']);
        } else {
            $post['title'] = preg_replace_callback('/\b(' . implode('|', array_map('preg_quote', $phrasesToReplace)) . ')\b/i', function ($matches) use ($replacements) {
                return $replacements[array_rand($replacements)];
            }, $post['title']);

            $post['comment'] = preg_replace_callback('/\b(' . implode('|', array_map('preg_quote', $phrasesToReplace)) . ')\b/i', function ($matches) use ($replacements) {
                return $replacements[array_rand($replacements)];
            }, $post['comment']);

            if($post['status'] != "admin") {
                $post['comment'] = strip_tags(htmlspecialchars($post['comment'], ENT_QUOTES, 'UTF-8'));
            }
        }

        $post['rating'] = $this->db->table('forum_ratings')->where('type', '1')->where('toid', $post['id'])->where('rate_type', 'l')->count();
        $post['upvotes'] = $post['rating'];
        $post['rating'] -= $this->db->table('forum_ratings')->where('type', '1')->where('toid', $post['id'])->where('rate_type', 'd')->count();
        $post['downvotes'] = $this->db->table('forum_ratings')->where('type', '1')->where('toid', $post['id'])->where('rate_type', 'd')->count();

        if($this->request['data']['siteusername']) {
            if($this->db->table('forum_ratings')->where('type', '1')->where('toid', $post['id'])->where('sender', $this->request['data']['user']['username'])->exists()) {
                $post['userRating'] = $this->db->table('forum_ratings')->select('rate_type')->where('type', '1')->where('toid', $post['id'])->where('sender', $this->request['data']['user']['username'])->value('rate_type');
            }

            $post['subscription'] = $this->db->table('subscriptions')->where('username', $this->request['data']['user']['username'])->where('forumId', $post['id'])->exists();
        }

        $post['online'] = Carbon::parse($user['lastlogin'])->gt(Carbon::now()->subMinutes(2));
        $this->request['data']['post'] = $post;

        if($this->db->table('forum_replies')->where('toid', $post['id'])->where('sticked', 'y')->exists()) {
            $sticked = (array) $this->db->table('forum_replies')
                ->where('toid', $post['id'])
                ->where('sticked', 'y')
                ->limit(1)
                ->first();
            
            $sticked['author'] = htmlspecialchars($sticked['author']);
            $sticked['date'] = date('m/d/Y h:i A', strtotime($sticked['date']));
            $sticked['edited_date'] = date('m/d/Y h:i A', strtotime($sticked['edited_date']));

            $user = User::where('username', $sticked['author'])->select('id', 'status', 'pfp', 'lastlogin', 'badges')->first()?->toArray();
            $sticked['uuid'] = $user['id'];
            $sticked['status'] = $user['status'];
            $sticked['pfp'] = $user['pfp'];
            $sticked['posts'] = $this->db->table('forum_threads')->where('author', $sticked['author'])->count() + $this->db->table('forum_replies')->where('author', $sticked['author'])->count();
            $sticked['badges'] = json_decode($user['badges'], true)['data']['custom_badges'] ?? [];
            $sticked['comment'] = preg_replace_callback('/\b(' . implode('|', array_map('preg_quote', $phrasesToReplace)) . ')\b/i', function ($matches) use ($replacements) {
                return $replacements[array_rand($replacements)];
            }, $sticked['comment']);

            $environment = new Environment([
                'html_input' => ($sticked['status'] == 'admin' ? 'allow' : 'strip'),
                'allow_unsafe_links' => false,
            ]);

            $environment->addExtension(new CommonMarkCoreExtension());
            $converter = new MarkdownConverter($environment);

            if($sticked['status'] != "admin") {
                $sticked['comment'] = strip_tags(htmlspecialchars($sticked['comment']));
            }

            $sticked['comment'] = $converter->convert($sticked['comment'])->getContent();
            $sticked['comment'] = preg_replace_callback('/(<img[^>]*>|\b(?:https?|ftp):\/\/\S+)/i', function ($matches) {
                if (strpos($matches[0], '<img') === 0) {
                    return $matches[0];
                } else {
                    return '<a href="' . strip_tags($matches[0]) . '" target="_blank">' . $matches[0] . '</a>';
                }
            }, $sticked['comment']);

            $sticked['rating'] = $this->db->table('forum_ratings')->where('type', '2')->where('toid', $sticked['id'])->where('rate_type', 'l')->count();
            $sticked['upvotes'] = $sticked['rating'];
            $sticked['rating'] -= $this->db->table('forum_ratings')->where('type', '2')->where('toid', $sticked['id'])->where('rate_type', 'd')->count();
            $sticked['downvotes'] = $this->db->table('forum_ratings')->where('type', '2')->where('toid', $sticked['id'])->where('rate_type', 'd')->count();

            if($sticked['replyTo']) {
                $sticked['replyComment'] = $this->db->table('forum_replies')->select('comment')->where('id', $sticked['replyTo'])->value('comment');
                $sticked['replyComment'] = preg_replace_callback('/\b(' . implode('|', array_map('preg_quote', $phrasesToReplace)) . ')\b/i', function ($matches) use ($replacements) {
                    return $replacements[array_rand($replacements)];
                }, $sticked['replyComment']);
            }

            if($this->request['data']['siteusername']) {
                if($this->db->table('forum_ratings')->where('type', '2')->where('toid', $sticked['id'])->where('sender', $this->request['data']['user']['username'])->exists()) {
                    $sticked['userRating'] = $this->db->table('forum_ratings')->select('rate_type')->where('type', '2')->where('toid', $sticked['id'])->where('sender', $this->request['data']['user']['username'])->value('rate_type');
                }

                $sticked['subscription'] = $this->db->table('subscriptions')->where('username', $this->request['data']['user']['username'])->where('forumId', $sticked['id'])->exists();
            }

            $sticked['online'] = Carbon::parse($user['lastlogin'])->gt(Carbon::now()->subMinutes(2));
            $this->request['data']['sticked'] = $sticked;
        }

        $replies = $this->db->table('forum_replies')
            ->where('toid', $post['id'])
            ->count();
        
        $pages_to_show = 10;
        $results_per_page = 10;
        $number_of_pages = ceil($replies / $results_per_page);
        $currentPage = isset($data['page']) ? max(1, intval($data['page'])) : 1;
        $offset = ($currentPage - 1) * $results_per_page;
        $start_page = max(1, min($currentPage - floor($pages_to_show / 2), $number_of_pages - $pages_to_show + 1));
        $end_page = min($number_of_pages, $start_page + $pages_to_show - 1);

        $replies = $this->db->table('forum_replies')
            ->where('toid', $post['id'])
            ->offset($offset)
            ->limit($results_per_page)
            ->get()
            ->map(function ($item) {
                return (array) $item;
            })->toArray();
        
        $this->request['data']['replies'] = [
            'data' => [],
            'pages' => [
                'info' => [
                    'current_page' => $currentPage,
                    'previous_page' => max(1, $currentPage - 1),
                    'next_page' => min($number_of_pages, $currentPage + 1),
                    'start_page' => $start_page,
                    'end_page' => $end_page,
                    'number_of_pages' => $number_of_pages
                ],
                'data' =>[]
            ]
        ];

        foreach($replies as $reply) {
            $reply['author'] = htmlspecialchars($reply['author']);
		    $reply['format_date'] = date('M d Y h:i:s A', strtotime($reply['date']));
            $reply['date'] = date('m/d/Y h:i A', strtotime($reply['date']));
            $reply['edited_date'] = date('m/d/Y h:i A', strtotime($reply['edited_date']));

            $user = User::where('username', $reply['author'])->select('id', 'status', 'pfp', 'lastlogin', 'badges')->first()?->toArray();
            $reply['uuid'] = $user['id'];
            $reply['status'] = $user['status'];
            $reply['pfp'] = $user['pfp'];
            $reply['posts'] = $this->db->table('forum_threads')->where('author', $reply['author'])->count() + $this->db->table('forum_replies')->where('author', $reply['author'])->count();
            $reply['badges'] = json_decode($user['badges'], true)['data']['custom_badges'] ?? [];
            $reply['comment'] = preg_replace_callback('/\b(' . implode('|', array_map('preg_quote', $phrasesToReplace)) . ')\b/i', function ($matches) use ($replacements) {
                return $replacements[array_rand($replacements)];
            }, $reply['comment']);

            $environment = new Environment([
                'html_input' => ($reply['status'] == 'admin' ? 'allow' : 'strip'),
                'allow_unsafe_links' => false,
            ]);

            $environment->addExtension(new CommonMarkCoreExtension());
            $converter = new MarkdownConverter($environment);

            if($reply['status'] != "admin") {
                $reply['comment'] = strip_tags(htmlspecialchars($reply['comment']));
            }

            $reply['comment'] = $converter->convert($reply['comment'])->getContent();
            $reply['comment'] = preg_replace_callback('/(<img[^>]*>|\b(?:https?|ftp):\/\/\S+)/i', function ($matches) {
                if (strpos($matches[0], '<img') === 0) {
                    return $matches[0];
                } else {
                    return '<a href="' . strip_tags($matches[0]) . '" target="_blank">' . $matches[0] . '</a>';
                }
            }, $reply['comment']);

            $reply['rating'] = $this->db->table('forum_ratings')->where('type', '2')->where('toid', $reply['id'])->where('rate_type', 'l')->count();
            $reply['upvotes'] = $reply['rating'];
            $reply['rating'] -= $this->db->table('forum_ratings')->where('type', '2')->where('toid', $reply['id'])->where('rate_type', 'd')->count();
            $reply['downvotes'] = $this->db->table('forum_ratings')->where('type', '2')->where('toid', $reply['id'])->where('rate_type', 'd')->count();

            if($reply['replyTo']) {
                $reply['replyComment'] = $this->db->table('forum_replies')->select('comment')->where('id', $reply['replyTo'])->value('comment');
                $reply['replyComment'] = preg_replace_callback('/\b(' . implode('|', array_map('preg_quote', $phrasesToReplace)) . ')\b/i', function ($matches) use ($replacements) {
                    return $replacements[array_rand($replacements)];
                }, $reply['replyComment']);
            }

            if($this->request['data']['siteusername']) {
                if($this->db->table('forum_ratings')->where('type', '2')->where('toid', $reply['id'])->where('sender', $this->request['data']['user']['username'])->exists()) {
                    $reply['userRating'] = $this->db->table('forum_ratings')->select('rate_type')->where('type', '2')->where('toid', $reply['id'])->where('sender', $this->request['data']['user']['username'])->value('rate_type');
                }

                $reply['subscription'] = $this->db->table('subscriptions')->where('username', $this->request['data']['user']['username'])->where('forumId', $reply['id'])->exists();
            }

            $reply['online'] = Carbon::parse($user['lastlogin'])->gt(Carbon::now()->subMinutes(2));
            $this->request['data']['replies']['data'][] = $reply;
        }

        for ($page = $start_page; $page <= $end_page; $page++) {
            $this->request['data']['replies']['pages']['data'][] = ['page' => $page];
        }

        if(!count($replies)) {
            $this->request['data']['replies']['pages']['data'][] = [
                'page' => 1
            ];
        }

        return view($this->request['data']['user']['version'] . '/Forum/Post', $this->request);
    }

    public function forum_reply(Request $request) {
        $data = $request->all();

        if(!$this->request['data']['siteusername']) {
            return redirect('/');
        }

        if(!isset($data['id'])) {
            return redirect('/forum/home');
        }

        if(!$this->db->table('forum_threads')->where('id', $data['id'])->exists()) {
            Session::put('error', 'This post does not exist');
            return redirect('/forum/home');
        }

        if($request->isMethod('post')) {
            $validator = Validator::make($data, [
                'id' => 'required|integer',
                'content' => 'required|string|min:3|max:16384'
            ]);

            if(!(bool)env('FINOBE_FORUM_POST')) {
                Session::put('error', 'Posting on the forums have been disabled');
                return redirect('/forum/post?id=' . $data['id']);
            }

            if($validator->fails()) {
                Session::put('error', $validator->errors()->first());
                return redirect('/forum/home');
            }

            if(Carbon::parse($this->request['data']['user']['post_cooldown'])->gt(Carbon::now()->subMinutes(5))) {
                Session::put('error', 'You are posting too often');
                return redirect('/forum/post?id=' . $data['id']);
            }

            if(!$this->db->table('forum_threads')->where('id', $data['id'])->exists()) {
                Session::put('error', 'This post doesn\'t exist');
                return redirect('/forum/home');
            }

            $post = (array) $this->db->table('forum_threads')
                ->where('id', $data['id'])
                ->first();
            
            if($post['locked'] == 'y') {
                Session::put('error', 'This post is locked');
                return redirect('/forum/home');
            }
            
            if(Carbon::parse($post['lastreplied'])->lt(now()->subWeeks(3)) && $this->request['data']['user']['status'] == 'normal') {
                if($this->db->table('warning')->where('username', $this->request['data']['user']['username'])->where('date', '>=', DB::raw('DATE_SUB(NOW(), INTERVAL 1 MONTH)'))->count() >= 2) {
                    $this->db->table('bans')->insert([
                        'username' => $this->request['data']['user']['username'],
                        'reason' => 'You are not allowed to necrobump threads that have been inactive for 3 weeks.',
                        'expire' => date('Y-m-d H:i:s', strtotime('+1 week')),
                        'moderator' => 'Auto'
                    ]);
                } else {
                    $this->db->table('warning')->insert([
                        'username' => $this->request['data']['user']['username'],
                        'reason' => 'You are not allowed to necrobump threads that have been inactive for 3 weeks.',
                        'moderator' => 'Auto'
                    ]);
                }

                return redirect('/');
            }

            $id = $this->db->table('forum_replies')->insertGetId([
                'toid' => $data['id'],
                'author' => $this->request['data']['user']['username'],
                'comment' => $data['content']
            ]);

            if($post['author'] != $this->request['data']['user']['username']) {
                $this->db->table('pms')->insert([
                    'owner' => $this->request['data']['user']['id'],
                    'touser' => User::where('username', $post['author'])->value('id'),
                    'message' => $this->request['data']['user']['username'] . ' replied to ' . $post['title'],
                    'forum_id' => $data['id'],
                    'reply_id' => $id
                ]);
            }

            $subscriptions = $this->db->table('subscriptions')
                ->where('forumId', $data['id'])
                ->get()
                ->map(function ($item) {
                    return (array) $item;
                })->toArray();
            
            foreach($subscriptions as $subscription) {
                $this->db->table('pms')->insert([
                    'owner' => $this->request['data']['user']['id'],
                    'touser' => User::where('username', $subscription['username'])->value('id'),
                    'message' => $this->request['data']['user']['username'] . ' replied to ' . $post['title'],
                    'forum_id' => $data['id'],
                    'reply_id' => $id
                ]);
            }

            if(isset($data['reply']) && $this->db->table('forum_replies')->where('id', $data['reply'])->exists()) {
                $this->db->table('pms')->insert([
                    'owner' => $this->request['data']['user']['id'],
                    'touser' => User::where('username', $this->db->table('forum_replies')->select('author')->where('id', $data['reply'])->value('author'))->value('id'), // place with inner query grabs id of user instead of this when forum userid rewrite
                    'message' => $this->request['data']['user']['username'] . ' replied to your reply on ' . $post['title'],
                    'forum_id' => $data['id']
                ]);

                $this->db->table('forum_replies')
                    ->where('id', $id)
                    ->update([
                        'replyTo' => $data['reply']
                    ]);
            }
            
            $user = User::find($this->request['data']['user']['id']);
            $user->post_cooldown = now();
            $user->save();
            
            $this->db->table('forum_threads')
                ->where('id', $data['id'])
                ->update([
                    'lastreplied' => DB::raw('CURRENT_TIMESTAMP()')
                ]);
            
            $results_per_page = 10;
            $position_in_list = $this->db->table('forum_replies')->where('id', '<=', $id)->where('toid', $data['id'])->count();
            $page_of_reply = ceil($position_in_list / $results_per_page);

            Session::put('success', 'Successfully created.');
            return redirect('/forum/post?id=' . $data['id'] . '&page=' . $page_of_reply);
        }

        $post = (array) $this->db->table('forum_threads')
            ->where('id', $data['id'])
            ->first();
        
        if($post['locked'] == 'y') {
            Session::put('error', 'This post is locked');
            return redirect('/forum/post?id=' . $data['id']);
        }

        $this->request['data']['embeds']['title'] = htmlspecialchars($post['title']) . $this->request['data']['embeds']['title'];
        $this->request['data']['replying'] = isset($data['reply']) ? $data['reply'] : false;
        $post['title'] = htmlspecialchars($post['title']);
        $this->request['data']['post'] = $post;

        return view($this->request['data']['user']['version'] . '/Forum/Reply', $this->request);
    }

    public function forum_edit_reply(Request $request) {
        $this->request['data']['embeds']['title'] = 'Edit Reply' . $this->request['data']['embeds']['title'];
        $data = $request->all();

        if(!$this->request['data']['siteusername']) {
            return redirect('/');
        }

        if($request->isMethod('post')) {
            $validator = Validator::make($data, [
                'id' => 'required|integer',
                'postId' => 'required|integer',
                'content' => 'required|string|min:3|max:8192'
            ]);

            if($validator->fails()) {
                Session::put('error', $validator->errors()->first());
                return redirect('/forum/edit?id=' . $data['id']);
            }

            if(!$this->db->table('forum_threads')->where('id', $data['postId'])->exists() || !$this->db->table('forum_replies')->where('id', $data['id'])->exists()) {
                Session::put('error', 'This post or reply does not exist');
                return redirect('/forum/home');
            }

            $reply = (array) $this->db->table('forum_replies')
            ->where('id', $data['id'])
            ->first();
        
            if($reply['author'] != $this->request['data']['user']['username']) {
                Session::put('error', 'You do not own this reply');
                return redirect('/forum/home');
            }

            $post = (array) $this->db->table('forum_threads')
                ->where('id', $reply['toid'])
                ->first();
            
            if($post['locked'] == 'y') {
                Session::put('error', 'This post is locked');
                return redirect('/forum/edit?id=' . $data['id']);
            }

            $this->db->table('forum_replies')
                ->where('id', $data['id'])
                ->update([
                    'comment' => $data['content'],
                    'edited' => 'y',
                    'edited_date' => DB::raw('CURRENT_TIMESTAMP()')
                ]);
            
            $results_per_page = 10;
            $position_in_list = $this->db->table('forum_replies')->where('id', '<=', $data['id'])->where('toid', $data['postId'])->count();
            $page_of_reply = ceil($position_in_list / $results_per_page);

            Session::put('success', 'Successfully edited.');
            return redirect('/forum/post?id=' . $data['postId'] . '&page=' . $page_of_reply);
        }

        if(!isset($data['id'])) {
            return redirect('/forum/home');
        }

        if(!$this->db->table('forum_replies')->where('id', $data['id'])->exists()) {
            Session::put('error', 'This reply doesn\'t exist or was deleted');
            return redirect('/forum/home');
        }

        $reply = (array) $this->db->table('forum_replies')
            ->where('id', $data['id'])
            ->first();
        
        if($reply['author'] != $this->request['data']['user']['username']) {
            Session::put('error', 'You do not own this reply');
            return redirect('/forum/home');
        }

        $post = (array) $this->db->table('forum_threads')
            ->where('id', $reply['toid'])
            ->first();

        $post['title'] = htmlspecialchars($post['title']);
        $this->request['data']['post'] = $post;
        $this->request['data']['reply'] = $reply;

        return view($this->request['data']['user']['version'] . '/Forum/Edit', $this->request);
    }

    public function forum_new_post(Request $request) {
        $this->request['data']['embeds']['title'] = 'New Post' . $this->request['data']['embeds']['title'];
        $data = $request->all();

        if(!$this->request['data']['siteusername']) {
            return redirect('/');
        }

        if($request->isMethod('post')) {
            if($request->hasFile('file')) {
                if($this->request['data']['user']['status'] != 'admin') {
                    return redirect('/app/forum/new/post');
                }

                $validator = Validator::make($data, [
                    'file' => 'required|file|mimes:jpg,jpeg,png,pdf,doc,docx,mp4,mov,gif,exe,ttf,webm,webp'
                ]);

                if($validator->fails()) {
                    Session::put('error', $validator->errors()->first());
                    return redirect('/app/forum/new/post');
                }

                $file = $request->file('file');
                $fileUrl = 'https://cdn.finobe.net/forum/media/' . $file->getClientOriginalName();

                if(file_exists('/var/www/cdn.finobe.net/forum/media/' . $file->getClientOriginalName())) {
                    Session::put('error', 'File already exists. Here is the link: ' . $fileUrl);
                    return redirect('/app/forum/new/post');
                }

                try {
                    $file->move('/var/www/cdn.finobe.net/forum/media', $file->getClientOriginalName());
                    Session::put('success', 'File uploaded successfully. Here is the link: ' . $fileUrl);
                    return redirect('/app/forum/new/post');
                } catch(\Exception $e) {
                    Session::put('error', 'Error uploading file. Check your server configurations.');
                    return redirect('/app/forum/new/post');
                }
            } else {
                $validator = Validator::make($data, [
                    'title' => 'required|string|min:3',
                    'content' => 'required|string|min:3|max:8192',
                    'section' => 'required|integer|min:1|max:10'
                ]);

                if($validator->fails()) {
                    Session::put('error', $validator->errors()->first());
                    return redirect('/app/forum/new/post');
                }

                if(Carbon::parse($this->request['data']['user']['post_cooldown'])->gt(Carbon::now()->subMinutes(5))) {
                    Session::put('error', 'You are posting too often');
                    return redirect('/app/forum/new/post');
                }

                if($data['section'] == '1' && $this->request['data']['user']['status'] != 'admin') {
                    Session::put('error', 'Not enough permissions');
                    return redirect('/app/forum/new/post');
                }

                $id = $this->db->table('forum_threads')->insertGetId([
                    'category' => $data['section'],
                    'author' => $this->request['data']['user']['username'],
                    'title' => $data['title'],
                    'comment' => $data['content']
                ]);

                $user = User::find($this->request['data']['user']['id']);
                $user->post_cooldown = now();
                $user->save();

                Session::put('success', 'Successfully created.');
                return redirect('/forum/post?id=' . $id);
            }
        }

        if($this->request['data']['user']['status'] == 'admin') {
            function displayDirectory($dir) {
                $files = scandir($dir);
                $html = '<ul>';
                foreach($files as $file) {
                    if($file != '.' && $file != '..') {
                        $path = $dir . '/' . $file;
                        $path2 = 'https://cdn.finobe.net/forum/media/' . $file;
                        $html .= '<li>';
                        if(is_dir($path)) {
                            $html .= '<strong>' . $file . '</strong>';
                            displayDirectory($path);
                        } else {
                            $html .= '<a href="' . $path2 . '" target="_blank">' . $file . '</a>';
                        }

                        $html .= '</li>';
                    }
                }

                $html .= '</ul>';
                return $html;
            }

            $this->request['data']['list'] = displayDirectory('/var/www/cdn.finobe.net/forum/media');
        }

        return view($this->request['data']['user']['version'] . '/Forum/New/Post', $this->request);
    }

    public function forum_subscribe(Request $request) {
        $data = $request->all();

        if(!$this->request['data']['siteusername']) {
            return redirect('/');
        }

        $validator = Validator::make($data, [
            'id' => 'required|integer|exists:finobe.forum_threads,id'
        ]);

        if($validator->fails()) {
            Session::put('error', $validator->errors()->first());
            return redirect('/forum/home');
        }

        if($this->db->table('subscriptions')->where('username', $this->request['data']['user']['username'])->where('forumId', $data['id'])->exists()) {
            $this->db->table('subscriptions')
                ->where('username', $this->request['data']['user']['username'])
                ->where('forumId', $data['id'])
                ->delete();
            
            Session::put('success', 'Successfully removed subscription.');
        } else {
            $this->db->table('subscriptions')->insert([
                'username' => $this->request['data']['user']['username'],
                'forumId' => $data['id']
            ]);

            Session::put('success', 'Successfully added subscription.');
        }

        return redirect('/forum/post?id=' . $data['id']);
    }

    public function catalog_index(Request $request, $section) {
        $this->request['data']['embeds']['title'] = 'Catalog' . $this->request['data']['embeds']['title'];
        $data = $request->all();

        if(!$this->request['data']['siteusername']) {
            return redirect('/');
        }

        $sections = [
            "hats" => 8,
            "t-shirts" => 2,
            "shirts" => 11,
            "pants" => 12,
            "gears" => 19,
            "faces" => 18,
            "heads" => 17,
            "packages" => 32,
            "audio" => 3,
            "models" => 10
        ];

        if(!isset($sections[$section])) {
            return redirect('/catalog/hats');
        }

        $items = [];
        $pages_to_show = 10;
        $results_per_page = 12;

        if(isset($data['q'])) {
            $search = htmlspecialchars($data['q']);
            $results = $this->db->table('assets')
                ->whereRaw('LOWER(title) LIKE LOWER(?)', ["%{$search}%"])
                ->where('asset_type', $sections[$section])
                ->where('visibility', 'n')
                ->orderBy('id', 'DESC')
                ->count();
        } else {
            $results = $this->db->table('assets')
                ->where('asset_type', $sections[$section])
                ->where('visibility', 'n')
                ->orderBy('id', 'DESC')
                ->count();
        }

        $number_of_pages = ceil($results / $results_per_page);
        $currentPage = isset($data['page']) ? max(1, intval($data['page'])) : 1;
        $offset = ($currentPage - 1) * $results_per_page;
        $start_page = max(1, min($currentPage - floor($pages_to_show / 2), $number_of_pages - $pages_to_show + 1));
        $end_page = min($number_of_pages, $start_page + $pages_to_show - 1);
        $phrasesToReplace = $this->request['data']['string_replacements']['phrasesToReplace'];
        $replacements = $this->request['data']['string_replacements']['replacements'];

        if(isset($data['q'])) {
            $results = $this->db->table('assets')
                ->whereRaw('LOWER(title) LIKE LOWER(?)', ["%{$search}%"])
                ->where('asset_type', $sections[$section])
                ->where('visibility', 'n')
                ->orderBy('id', 'DESC')
                ->offset($offset)
                ->limit($results_per_page)
                ->get()
                ->map(function ($item) {
                    return (array) $item;
                })->toArray();
        } else {
            $results = $this->db->table('assets')
                ->where('asset_type', $sections[$section])
                ->where('visibility', 'n')
                ->orderBy('id', 'DESC')
                ->offset($offset)
                ->limit($results_per_page)
                ->get()
                ->map(function ($item) {
                    return (array) $item;
                })->toArray();
        }

        foreach($results as $result) {
            $result['additional'] = json_decode($result['additional'], true);
            $result['title'] = htmlspecialchars(preg_replace_callback('/\b(' . implode('|', array_map('preg_quote', $phrasesToReplace)) . ')\b/i', function ($matches) use ($replacements) {
                return $replacements[array_rand($replacements)];
            }, $result['title']));

            if($result['asset_type'] == 3) {
                $result['duration'] = $this->dataService->timestamp($result['additional']['duration']);
            }

            $user = User::find($result['author']);
            $result['uuid'] = $user ? $user->toArray()['id'] : false;
            $result['author'] = htmlspecialchars($user['username'] ?? $result['additional']['oldUser']);
            $items[] = $result;
        }

        if($sections[$section] != 3 && count($results) && count($items) < 12) {
            $missing = $results_per_page - count($items);
            for ($i = 0; $i < $missing; $i++) {
                $items[] = [];
            }
        }

        $this->request['data']['items'] = [
            'data' => [],
            'pages' => [
                'info' => [
                    'current_page' => $currentPage,
                    'previous_page' => max(1, $currentPage - 1),
                    'next_page' => min($number_of_pages, $currentPage + 1),
                    'start_page' => $start_page,
                    'end_page' => $end_page,
                    'number_of_pages' => $number_of_pages
                ],
                'data' =>[]
            ]
        ];

        for ($page = $start_page; $page <= $end_page; $page++) {
            $this->request['data']['items']['pages']['data'][] = ['page' => $page];
        }

        if(!count($items)) {
            $this->request['data']['items']['pages']['data'][] = [
                'page' => 1
            ];
        }

        $this->request['data']['items']['data'] = $items;
        $this->request['data']['section'] = $section;
        $this->request['data']['search'] = isset($data['q']) ? $data['q'] : false;

        return view($this->request['data']['user']['version'] . '/Catalog/Index', $this->request);
    }

    public function item(Request $request, $id) {
        $data = $request->all();
        $phrasesToReplace = $this->request['data']['string_replacements']['phrasesToReplace'];
        $replacements = $this->request['data']['string_replacements']['replacements'];

        if(!$this->request['data']['siteusername']) {
            return redirect('/');
        }

        if(!$this->db->table('assets')->where('id', $id)->exists()) {
            return response()->view($this->request['data']['user']['version'] . '/404', [], 404);
        }

        $item = (array) $this->db->table('assets')
            ->where('id', $id)
            ->first();
        
        if($item['asset_type'] == 9) {
            return redirect('/place/' . $id);
        }

        if($item['asset_type'] == 1) {
            return response()->view($this->request['data']['user']['version'] . '/404', [], 404);
        }

        $item['additional'] = json_decode($item['additional'], true);
        $item['title'] = htmlspecialchars(preg_replace_callback('/\b(' . implode('|', array_map('preg_quote', $phrasesToReplace)) . ')\b/i', function ($matches) use ($replacements) {
            return $replacements[array_rand($replacements)];
        }, $item['title']));
        
        if(User::where('id', $item['author'])->exists()) {
            $item['uuid'] = $item['author'];
        }

        $item['author'] = htmlspecialchars(User::where('id', $item['author'])->value('username') ?? $item['additional']['oldUser']);
        $item['description'] = nl2br(preg_replace('/\b((?:https?|ftp):\/\/\S+)/i', '<a href="$1">$1</a>', strip_tags(htmlspecialchars($item['description']))));
        $item['publish'] = date('m/d/Y', strtotime($item['created']));
	    $item['updated'] = date('m/d/Y', strtotime($item['updated']));

        if($item['asset_type'] == 3) {
            $item['thumbnail'] = Cache::remember('thumbnail_' . $item['additional']['media']['imageAssetId'], 60 * 60, function() use ($item) { return $this->db->table('assets')->select('file')->where('id', $item['additional']['media']['imageAssetId'])->value('file'); });
        }

        $item['isOwned'] = $this->db->table('purchases')->where('username', $this->request['data']['user']['username'])->where('assetid', $id)->where('type', 1)->exists();
        $item['sales'] = number_format($this->db->table('purchases')->where('assetid', $id)->count());

        if($item['visibility'] == 'd') {
            $item['title'] = "[Not Approved]";
            $item['description'] = "[Not Approved]";
        }

        $this->request['data']['embeds']['title'] = $item['title'] . $this->request['data']['embeds']['title'];
        $this->request['data']['item'] = $item;

        return view($this->request['data']['user']['version'] . '/Catalog/Item', $this->request);
    }

    public function item_settings(Request $request, $id) {
        $data = $request->all();

        if(!$this->request['data']['siteusername']) {
            return redirect('/');
        }

        if(!$this->db->table('assets')->where('id', $id)->exists()) {
            return response()->view($this->request['data']['user']['version'] . '/404', [], 404);
        }

        $item = (array) $this->db->table('assets')
            ->where('id', $id)
            ->first();
        
        if($item['author'] != $this->request['data']['user']['id']) {
            Session::put('error', 'You do not own this item');
            return redirect('/item/' . $id);
        }

        if(!in_array($item['asset_type'], [2, 3, 8, 11, 12, 18, 19])) {
            return response()->view($this->request['data']['user']['version'] . '/404', [], 404);
        }

        if($item['visibility'] != 'n') {
            Session::put('error', 'This item is currently unavailable to changes');
            return redirect('/item/' . $id);
        }

        $item['additional'] = json_decode($item['additional'], true);
        $item['description'] = strip_tags(htmlspecialchars($item['description']));

        if($request->isMethod('post')) {
            $validator = Validator::make($data, [
                'title'            => 'required|string|min:3|max:255',
                'description'      => 'nullable|string|max:8192',
                'onsale'           => 'nullable|in:on,1,true,0,false,off',
                'price'            => 'required|integer|min:0'
            ]);

            if($validator->fails()) {
                Session::put('error', $validator->errors()->first());
                return redirect('/item/' . $id . '/settings');
            }

            if(in_array($item['asset_type'], [11, 12])) {
                $validator = Validator::make($data, [
                    'price' => 'required|integer|min:5'
                ]);

                if($validator->fails()) {
                    Session::put('error', $validator->errors()->first());
                    return redirect('/item/' . $id . '/settings');
                }
            } elseif(in_array($item['asset_type'], [2])) {
                $validator = Validator::make($data, [
                    'price' => 'required|integer|min:2'
                ]);

                if($validator->fails()) {
                    Session::put('error', $validator->errors()->first());
                    return redirect('/item/' . $id . '/settings');
                }
            }

            $item['additional']['onSale'] = isset($data['onsale']);
            $item['additional']['price'] = intval($data['price']);

            $this->db->table('assets')
                ->where('id', $id)
                ->update([
                    'title' => $data['title'],
                    'description' => $data['description'] ?? '',
                    'additional' => json_encode($item['additional'])
                ]);

            Session::put('successv2', 'Item settings saved.');
            return redirect('/item/' . $id . '/settings');
        }

        $this->request['data']['embeds']['title'] = $item['title'] . $this->request['data']['embeds']['title'];
        $this->request['data']['item'] = $item;

        return view($this->request['data']['user']['version'] . '/Catalog/Item_settings', $this->request);
    }

    public function character(Request $request) {
        $this->request['data']['embeds']['title'] = 'Character' . $this->request['data']['embeds']['title'];

        if(!$this->request['data']['siteusername']) {
            return redirect('/');
        }

        $purchases = [];
        $results = $this->db->table('purchases')
            ->where('username', $this->request['data']['user']['username'])
            ->where('type', 1)
            ->orderBy('id', 'DESC')
            ->get()
            ->map(function ($item) {
                return (array) $item;
            })->toArray();
        
        foreach($results as $result) {
            if(!$this->db->table('assets')->where('id', $result['assetid'])->exists()) {
                continue;
            }
            
            $item = (array) $this->db->table('assets')
                ->where('id', $result['assetid'])
                ->first();

            if(!in_array($item['asset_type'], [2, 8, 11, 12, 18, 19])) {
                continue;
            }

            $item['additional'] = json_decode($item['additional'], true);
            $result['asset_type'] = $item['asset_type'];
            $result['visibility'] = $item['visibility'];

            if($item['visibility'] == 'd') {
                $item['title'] = '[Not Approved]';
            }

            $result['title'] = htmlspecialchars($item['title']);
            $result['uuid'] = $item['author'];
            $result['author'] = User::select('username')->where('id', $item['author'])->value('username');
            $result['equipped'] = in_array($result['assetid'], $this->request['data']['user']['avatar'][0]['equippedGearVersionIds']);

            if(in_array($item['asset_type'], [2, 8, 11, 12, 18, 19])) {
                $result['thumbnail'] = $item['additional']['media']['thumbnail'];
            }

            $purchases[] = $result;
        }

        $this->request['data']['purchases'] = $purchases;
        
        return view($this->request['data']['user']['version'] . '/Character', $this->request);
    }

    public function place(Request $request, $id) {
        if(!$this->request['data']['siteusername']) {
            return redirect('/');
        }

        if(!$this->db->table('assets')->where('id', $id)->exists()) {
            return response()->view($this->request['data']['user']['version'] . '/404', [], 404);
        }

        $place = (array) $this->db->table('assets')
            ->where('id', $id)
            ->first();
        
        $place['additional'] = json_decode($place['additional'], true);
        $this->request['data']['embeds']['title'] = strip_tags(htmlspecialchars($place['title'])) . $this->request['data']['embeds']['title'];
        
        if($place['asset_type'] != 9) {
            return redirect('/app/places');
        }

        $place = [
            'id' => $place['id'],
            'additional' => $place['additional'],
            'title' => strip_tags(htmlspecialchars($place['title'])),
            'username' => User::where('id', $place['author'])->value('username'),
            'author' => $place['author'],
            'description' => nl2br(preg_replace('/\b((?:https?|ftp):\/\/\S+)/i', '<a href="$1">$1</a>', strip_tags(htmlspecialchars($place['description'])))),
            'created' => date('m/d/Y', strtotime($place['created'])),
            'updated' => date('m/d/Y', strtotime($place['updated'])),
            'visits' => number_format($place['additional']['visits']),
            'thumbnail' => $this->db->table('assets')->select('file')->where('id', $place['additional']['media']['imageAssetId'])->value('file'),
            'servers' => []
        ];

        $servers = [];
        $results = $this->db->table('servers')
            ->where('placeid', $place)
            ->get()
            ->map(function ($item) {
                return (array) $item;
            })->toArray();

        foreach($results as $result) {
            $players = json_decode($result['players'], true);
            $result['players'] = [];

            foreach($players as $playerId) {
                if(User::find($playerId)) {
                    $result['players'][] = [
                        'userid' => $playerId,
                        'username' => User::where('id', $playerId)->value('username'),
                        'avatar' => Cache::remember('pfp_' . User::where('id', $playerId)->value('username'), 60 * 60, function() use ($playerId) { return User::where('username', User::where('id', $playerId)->value('username'))->value('pfp'); })
                    ];
                }
            }

            $place['servers'][] = $result;
        }

        $this->request['data']['place'] = $place;

        return view($this->request['data']['user']['version'] . '/Places/Place', $this->request);
    }

    public function place_settings(Request $request, $id) {
        $data = $request->all();

        if(!$this->request['data']['siteusername']) {
            return redirect('/');
        }

        if(!$this->db->table('assets')->where('id', $id)->exists()) {
            return response()->view($this->request['data']['user']['version'] . '/404', [], 404);
        }

        $place = (array) $this->db->table('assets')
            ->where('id', $id)
            ->first();
        
        if($place['asset_type'] != 9) {
            return redirect('/app/places');
        }

        $place['username'] = User::where('id', $place['author'])->value('username');
        $place['additional'] = json_decode($place['additional'], true);

        if($place['username'] != $this->request['data']['user']['username']) {
            Session::put('error', 'You do not own this place');
            return redirect('/app/places');
        }

        if($request->isMethod('post')) {
            $validator = Validator::make($data, [
                'title'            => 'required|string|min:3|max:255',
                'description'      => 'nullable|string|max:8192',
                'allowplaying'     => 'nullable|in:on,1,true,0,false,off',
                'downloadable'     => 'nullable|in:on,1,true,0,false,off',
                'hideRecent'       => 'nullable|in:on,1,true,0,false,off',
                'combat'           => 'nullable|in:on,1,true,0,false,off',
                'social'           => 'nullable|in:on,1,true,0,false,off',
                'building'         => 'nullable|in:on,1,true,0,false,off',
                'musical'          => 'nullable|in:on,1,true,0,false,off',
                'game-version'     => 'required|in:2012,2016',
                'category'         => 'required|in:original,copy',
                'chat-type'        => 'required|in:classic,bubble_chat,both',
                'max-players'      => 'required|integer|between:5,100',
            ]);

            if($validator->fails()) {
                Session::put('error', $validator->errors()->first());
                return redirect('/place/' . $id . '/settings');
            }

            $place['additional']['allowplaying'] = isset($data['allowplaying']);
            $place['additional']['uncopylocked'] = isset($data['downloadable']);
            $place['additional']['hidden'] = isset($data['hideRecent']);
            $place['additional']['gears']['combat'] = isset($data['combat']);
            $place['additional']['gears']['social'] = isset($data['social']);
            $place['additional']['gears']['building'] = isset($data['building']);
            $place['additional']['gears']['musical'] = isset($data['musical']);
            $place['additional']['version'] = $data['game-version'];
            $place['additional']['category'] = $data['category'];
            $place['additional']['chat_type'] = $data['chat-type'];
            $place['additional']['maxplayers'] = (int) $data['max-players'];

            $this->db->table('assets')
                ->where('id', $id)
                ->update([
                    'title' => $data['title'],
                    'description' => $data['description'] ?? '',
                    'additional' => json_encode($place['additional'])
                ]);
            
            Session::put('successv2', 'Place settings saved.');
            return redirect('/place/' . $id . '/settings');
        }

        $place['title'] = strip_tags(htmlspecialchars($place['title']));
        //$place['description'] = nl2br(preg_replace('/\b((?:https?|ftp):\/\/\S+)/i', '<a href="$1" target="_blank">$1</a>', strip_tags(htmlspecialchars($place['description']))));

        $this->request['data']['place'] = $place;
        $this->request['data']['embeds']['title'] = $place['title'] . $this->request['data']['embeds']['title'];

        return view($this->request['data']['user']['version'] . '/Places/Place_settings', $this->request);
    }

    public function places(Request $request) {
        $this->request['data']['embeds']['title'] = 'Places' . $this->request['data']['embeds']['title'];

        if(!$this->request['data']['siteusername']) {
            return redirect('/');
        }

        return view($this->request['data']['user']['version'] . '/Places/Index', $this->request);
    }

    public function trades(Request $request) {
        $this->request['data']['embeds']['title'] = 'Trades' . $this->request['data']['embeds']['title'];

        if(!$this->request['data']['siteusername']) {
            return redirect('/');
        }

        return view($this->request['data']['user']['version'] . '/Trades', $this->request);
    }

    public function inbox(Request $request) {
        $this->request['data']['embeds']['title'] = 'Inbox' . $this->request['data']['embeds']['title'];
        $this->request['data']['section'] = 'inbox';
        $data = $request->all();

        if(!$this->request['data']['siteusername']) {
            return redirect('/');
        }

        $this->request['data']['messages'] = [
            'data' => [],
            'page' => 1,
            'number_of_pages' => 1
        ];

        $messages = $this->db->table('messages')
            ->where('touser', $this->request['data']['user']['id'])
            ->where('archived', 'n')
            ->count();

        $results_per_page = 12;
        $number_of_pages = ceil($messages / $results_per_page);
        $currentPage = isset($data['page']) ? max(1, intval($data['page'])) : 1;
        $offset = ($currentPage - 1) * $results_per_page;

        $messages = $this->db->table('messages')
            ->where('touser', $this->request['data']['user']['id'])
            ->where('archived', 'n')
            ->orderBy('date', 'DESC')
            ->offset($offset)
            ->limit($results_per_page)
            ->get()
            ->map(function ($item) {
                return (array) $item;
            })->toArray();
        
        foreach($messages as $message) {
            $message['message'] = strip_tags(htmlspecialchars($message['message']));
            $message['subject'] = strip_tags(htmlspecialchars($message['subject']));
            $message['author'] = htmlspecialchars(User::where('id', $message['author'])->value('username'));
            $message['date'] = date('M j, Y | g:i A', strtotime($message['date']));
            $this->request['data']['messages']['data'][] = $message;
        }

        $this->request['data']['messages']['page'] = $currentPage;
        $this->request['data']['messages']['number_of_pages'] = $number_of_pages;

        return view($this->request['data']['user']['version'] . '/Inbox/Index', $this->request);
    }

    public function inbox_sent(Request $request) {
        $this->request['data']['embeds']['title'] = 'Sent Messages' . $this->request['data']['embeds']['title'];
        $this->request['data']['section'] = 'sent';
        $data = $request->all();

        if(!$this->request['data']['siteusername']) {
            return redirect('/');
        }

        $this->request['data']['messages'] = [
            'data' => [],
            'page' => 1,
            'number_of_pages' => 1
        ];

        $messages = $this->db->table('messages')
            ->where('author', $this->request['data']['user']['id'])
            ->where('archived', 'n')
            ->count();

        $results_per_page = 12;
        $number_of_pages = ceil($messages / $results_per_page);
        $currentPage = isset($data['page']) ? max(1, intval($data['page'])) : 1;
        $offset = ($currentPage - 1) * $results_per_page;

        $messages = $this->db->table('messages')
            ->where('author', $this->request['data']['user']['id'])
            ->where('archived', 'n')
            ->orderBy('date', 'DESC')
            ->offset($offset)
            ->limit($results_per_page)
            ->get()
            ->map(function ($item) {
                return (array) $item;
            })->toArray();
        
        foreach($messages as $message) {
            $message['message'] = strip_tags(htmlspecialchars($message['message']));
            $message['subject'] = strip_tags(htmlspecialchars($message['subject']));
            $message['author'] = htmlspecialchars(User::where('id', $message['author'])->value('username'));
            $message['date'] = date('M j, Y | g:i A', strtotime($message['date']));
            $this->request['data']['messages']['data'][] = $message;
        }
        
        $this->request['data']['messages']['page'] = $currentPage;
        $this->request['data']['messages']['number_of_pages'] = $number_of_pages;

        return view($this->request['data']['user']['version'] . '/Inbox/Index', $this->request);
    }

    public function inbox_archive(Request $request) {
        $this->request['data']['embeds']['title'] = 'Archived Messages' . $this->request['data']['embeds']['title'];
        $this->request['data']['section'] = 'archive';
        $data = $request->all();

        if(!$this->request['data']['siteusername']) {
            return redirect('/');
        }

        $this->request['data']['messages'] = [
            'data' => [],
            'page' => 1,
            'number_of_pages' => 1
        ];

        $messages = $this->db->table('messages')
            ->where('touser', $this->request['data']['user']['id'])
            ->where('archived', 'y')
            ->count();

        $results_per_page = 12;
        $number_of_pages = ceil($messages / $results_per_page);
        $currentPage = isset($data['page']) ? max(1, intval($data['page'])) : 1;
        $offset = ($currentPage - 1) * $results_per_page;

        $messages = $this->db->table('messages')
            ->where('touser', $this->request['data']['user']['id'])
            ->where('archived', 'y')
            ->orderBy('date', 'DESC')
            ->offset($offset)
            ->limit($results_per_page)
            ->get()
            ->map(function ($item) {
                return (array) $item;
            })->toArray();
        
        foreach($messages as $message) {
            $message['message'] = strip_tags(htmlspecialchars($message['message']));
            $message['subject'] = strip_tags(htmlspecialchars($message['subject']));
            $message['author'] = htmlspecialchars(User::where('id', $message['author'])->value('username'));
            $message['date'] = date('M j, Y | g:i A', strtotime($message['date']));
            $this->request['data']['messages']['data'][] = $message;
        }
        
        $this->request['data']['messages']['page'] = $currentPage;
        $this->request['data']['messages']['number_of_pages'] = $number_of_pages;

        return view($this->request['data']['user']['version'] . '/Inbox/Index', $this->request);
    }

    public function inbox_message(Request $request) {
        $data = $request->all();

        if(!$this->request['data']['siteusername']) {
            return redirect('/');
        }

        if(!isset($data['id'])) {
            return redirect('/app/inbox');
        }

        if(!$this->db->table('messages')->where('id', $data['id'])->exists()) {
            Session::put('error', 'Message not found');
            return redirect('/app/inbox');
        }

        $message = (array) $this->db->table('messages')
            ->where('id', $data['id'])
            ->first();

        if($this->request['data']['user']['id'] != $message['author'] && $this->request['data']['user']['id'] != $message['touser']) {
            Session::put('error', 'You are not mentioned in this message');
            return redirect('/app/inbox');
        }

        if($request->isMethod('post')) {
            if($message['archived'] == 'n') {
                $this->db->table('messages')
                    ->where('id', $data['id'])
                    ->update([
                        'archived' => 'y'
                    ]);
                
                Session::put('success', 'Successfully archived');
            } else {
                $this->db->table('messages')
                    ->where('id', $data['id'])
                    ->update([
                        'archived' => 'n'
                    ]);
                
                Session::put('success', 'Successfully unarchived');
            }

            return redirect('/app/inbox/message?id=' . $data['id']);
        }

        $message['uid'] = $message['author'];
        $message['message'] = nl2br(preg_replace('/\b((?:https?|ftp):\/\/\S+)/i', '<a href="$1" target="_blank">$1</a>', strip_tags(htmlspecialchars($message['message']))));
        $message['subject'] = strip_tags(htmlspecialchars($message['subject']));
        $message['author'] = htmlspecialchars(User::where('id', $message['author'])->value('username'));
        $message['date'] = date('M j, g:ia', strtotime($message['date']));

        if($message['readed'] == 'n' && $this->request['data']['user']['id'] != $message['uid']) {
            $this->db->table('messages')
                ->where('id', $message['id'])
                ->update([
                    'readed' => 'y'
                ]);
        }

        $this->request['data']['embeds']['title'] = $message['subject'] . $this->request['data']['embeds']['title'];
        $this->request['data']['message'] = $message;

        return view($this->request['data']['user']['version'] . '/Inbox/Message', $this->request);
    }

    public function inbox_compose(Request $request) {
        $this->request['data']['embeds']['title'] = 'Compose Messages' . $this->request['data']['embeds']['title'];
        $data = $request->all();

        if(!$this->request['data']['siteusername']) {
            return redirect('/');
        }

        if($request->isMethod('post')) {
            $validator = Validator::make($data, [
                'username' => 'required|string|max:255',
                'subject' => 'required|string|min:3|max:255',
                'message' => 'required|string|min:3|max:8192'
            ]);

            if($validator->fails()) {
                Session::put('error', $validator->errors()->first());
                return redirect('/app/inbox/compose');
            }

            if(!User::where('username', $data['username'])->exists()) {
                Session::put('error', 'User not found');
                return redirect('/app/inbox');
            }

            if($this->db->table('messages')->where('author', $this->request['data']['user']['id'])->where('date', '>=', DB::raw('NOW() - INTERVAL 5 MINUTE'))->exists()) {
                Session::put('error', 'Wait 5 minutes before sending another message');
                return redirect('/app/inbox/compose');
            }

            if($this->request['data']['user']['username'] == $data['username']) {
                Session::put('error', 'You cannot send a message to yourself');
                return redirect('/app/inbox/compose');
            }

            $this->db->table('messages')->insert([
                'author' => $this->request['data']['user']['id'],
                'touser' => User::where('username', $data['username'])->value('id'),
                'subject' => $data['subject'],
                'message' => $data['message']
            ]);

            Session::put('success', 'Successfully send');
            return redirect('/app/inbox');
        }

        if(isset($data['user'])) {
            if(!User::where('username', $data['user'])->exists()) {
                Session::put('error', 'User not found');
                return redirect('/app/inbox/compose');
            }

            $this->request['data']['sendto'] = [
                'id' => User::where('username', $data['user'])->value('id'),
                'username' => $data['user']
            ];
        }

        if(isset($data['subject'])) {
            $this->request['data']['subject'] = $data['subject'];
        }

        return view($this->request['data']['user']['version'] . '/Inbox/Compose', $this->request);
    }

    public function invites(Request $request) {
        $this->request['data']['embeds']['title'] = 'Invites' . $this->request['data']['embeds']['title'];

        if(!$this->request['data']['siteusername']) {
            return redirect('/');
        }

        $keys = [
            'data' => [],
            'info' => [
                'created' => $this->db->table('invitekeys')->where('author', $this->request['data']['user']['username'])->where(DB::raw('MONTH(creation)'), DB::raw('MONTH(NOW())'))->where(DB::raw('YEAR(creation)'), DB::raw('YEAR(NOW())'))->count()
            ]
        ];

        $inviteKeys = $this->db->table('invitekeys')
            ->where('author', $this->request['data']['user']['username'])
            ->orderBy('creation', 'DESC')
            ->get()
            ->map(function ($item) {
                return (array) $item;
            })->toArray();
        
        foreach($inviteKeys as $key) {
            $key['creation'] = date('m/d/Y', strtotime($key['creation']));

            if($key['used'] == 'y') {
                $key['uuid'] = User::where('username', $key['usedBy'])->value('id');
                $key['dateUsed'] = date('m/d/Y', strtotime($key['dateUsed']));
            }

            $keys['data'][] = $key;
        }

        $this->request['data']['keys'] = $keys;

        return view($this->request['data']['user']['version'] . '/Invitations/Invites', $this->request);
    }

    public function invites_new(Request $request) {
        $this->request['data']['embeds']['title'] = 'Invites' . $this->request['data']['embeds']['title'];
        $data = $request->all();

        if(!$this->request['data']['siteusername']) {
            return redirect('/');
        }

        if($request->isMethod('post')) {
            if(!(bool)env('FINOBE_INVITE_KEYS')) {
                Session::put('error', 'Invite keys are disabled');
                return redirect('/invites');
            }

            if(!(bool)env('FINOBE_CREATE_INVITE_KEYS')) {
                Session::put('error', 'Invite key creation is disabled');
                return redirect('/invites');
            }

            if($this->db->table('invitekeys')->where('author', $this->request['data']['user']['username'])->where(DB::raw('MONTH(creation)'), DB::raw('MONTH(NOW())'))->where(DB::raw('YEAR(creation)'), DB::raw('YEAR(NOW())'))->count() - 2 >= 0) {
                Session::put('error', 'You cannot create any more invites this month');
                return redirect('/invites');
            }

            if($this->db->table('invitekeys')->where('author', $this->request['data']['user']['username'])->where('used', 'n')->count() >= 2) {
                Session::put('error', 'You have too many unused keys');
                return redirect('/invites');
            }

            function inviteKey($length) {
                $characters = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
                $randomString = '';
            
                $maxIndex = strlen($characters) - 1;
            
                for($i = 0; $i < $length; $i++) {
                    $randomString .= $characters[random_int(0, $maxIndex)];
                }
            
                return $randomString;
            }

            $key = inviteKey(32);

            $this->db->table('invitekeys')->insert([
                'author' => $this->request['data']['user']['username'],
                'IID' => $key
            ]);

            Session::put('successv2', 'Invite key created: ' . $key);
            return redirect('/invites');
        }

        return view($this->request['data']['user']['version'] . '/Invitations/Confirmation', $this->request);
    }

    public function election(Request $request) {
        $this->request['data']['embeds']['title'] = 'Invites' . $this->request['data']['embeds']['title'];
        $data = $request->all();

        if(!$this->request['data']['siteusername']) {
            return redirect('/');
        }

        if(!$this->db->table('elections')->where('expire', '>', now())->exists()) {
            Session::put('error', 'There is currently no active elections');
            return redirect('/');
        }

        $election = (array) $this->db->table('elections')
            ->where('expire', '>', now())
            ->first();
        
        $election['title'] = strip_tags(htmlspecialchars($election['title']));
        $election['options'] = json_decode($election['options'], true);
        $election['votes'] = json_decode($election['votes'], true);

        if($request->isMethod('post')) {
            if(!isset($data['index'])) {
                return redirect('/election');
            }

            foreach($election['votes'] as $key => $vote) {
                if($vote['id'] != $data['index'] && $vote['username'] == $this->request['data']['user']['username']) {
                    Session::put('error', 'You can only vote on one option');
                    return redirect('/election');
                }
            }

            foreach($election['votes'] as $key => $vote) {
                if($vote['id'] == $data['index'] && $vote['username'] == $this->request['data']['user']['username']) {
                    unset($election['votes'][$key]);

                    $this->db->table('elections')
                        ->where('id', $election['id'])
                        ->update([
                            'votes' => json_encode($election['votes'])
                        ]);
                    
                    return redirect('/election');
                }
            }

            $election['votes'][] = [
                'id' => $data['index'],
                'username' => $this->request['data']['user']['username']
            ];

            $this->db->table('elections')
                ->where('id', $election['id'])
                ->update([
                    'votes' => json_encode($election['votes'])
                ]);
                    
            return redirect('/election');
        }

        $this->request['data']['election'] = $election;

        return view($this->request['data']['user']['version'] . '/Election', $this->request);
    }

    public function verify_email(Request $request) {
        if(!$this->request['data']['siteusername']) {
            return redirect('/');
        }

        if($this->db->table('verify_email')->where('username', $this->request['data']['user']['username'])->where('used', 'n')->exists()) {
            return redirect('/');
        }

        $verifyid = hash_hmac('sha256', rand(0, 10000), 'privatekey');

        $html = file_get_contents(storage_path('verify_email_template.php'));
        $keywords = ['UUID', 'SIGNATURE', 'RESETID'];
	    $replacementValues = [$this->request['data']['user']['id'], hash_hmac('sha256', 'testingthis', 'privatekey'), $verifyid];
        $html = str_replace($keywords, $replacementValues, $html);

        if((int)env('FINOBE_MAIL_MODE') == 1) {
            $response = Http::withHeaders([
                'accept' => 'application/json',
                'authorization' => 'Zoho-enczapikey ' . env('FINOBE_ZOHO_API_KEY'),
                'cache-control' => 'no-cache',
                'content-type' => 'application/json',
            ])->post('https://api.zeptomail.com/v1.1/email', [
                "from" => [
                    "address" => "noreply@aesthetiful.com"
                ],
                "to" => [
                    [
                        "email_address" => [
                            "address" => $this->request['data']['user']['email'],
                            "name" => $this->request['data']['user']['username']
                        ]
                    ]
                ],
                "subject" => "Verify Email Address",
                "htmlbody" => $html
            ]);

            if(!$response->successful()) {
                Session::put('error', 'There was an error while sending the email, please try again. (this is most likely a issue with our backend system)');
                return redirect('/');
            }
        } elseif((int)env('FINOBE_MAIL_MODE') == 2) {
            $response = Http::withHeaders([
                'accept' => 'application/json',
                'X-Smtp2go-Api-Key' => env('FINOBE_SMTP2GO_API_KEY'),
                'content-type' => 'application/json',
            ])->post('https://us-api.smtp2go.com/v3/email/send', [
                "sender" => "Finobe <noreply@aesthetiful.com>",
                "to" => $this->request['data']['user']['username'] . " <" . $this->request['data']['user']['email'] . ">",
                "subject" => "Verify Email Address",
                "html_body" => $html
            ]);

            if(!$response->successful() || !$response->json()['data']['succeeded']) {
                Session::put('error', 'There was an error while sending the email, please try again.');
                return redirect('/');
            }
        }

        $this->db->table('verify_email')->insert([
            'username' => $this->request['data']['user']['username'],
            'uid' => $verifyid
        ]);

        Session::put('success', 'Sent.');
        return redirect('/');
    }

    public function email_verify(Request $request, $id, $verifyid) {
        if(!User::find($id)) {
            Session::put('error', 'Unknown error');
            return redirect('/');
        }

        $user = User::find($id);

        if(!$this->db->table('verify_email')->where('username', $user->username)->where('uid', $verifyid)->where('used', 'n')->exists()) {
            Session::put('error', 'Session not found');
            return redirect('/');
        }

        $this->db->table('verify_email')
            ->where('username', $user->username)
            ->where('uid', $verifyid)
            ->where('used', 'n')
            ->update([
                'used' => 'y'
            ]);
        
        $user->verified = 'y';
        $user->save();

        Session::put('success', 'Verified email');
        return redirect('/');
    }

    public function password_reset(Request $request) {
        $this->request['data']['embeds']['title'] = 'Reset Password' . $this->request['data']['embeds']['title'];

        if($this->request['data']['siteusername']) {
            return redirect('/');
        }

        return view($this->request['data']['user']['version'] . '/Reset', $this->request);
    }

    public function password_email(Request $request) {
        $data = $request->all();

        if($this->request['data']['siteusername']) {
            return redirect('/');
        }

        $validator = Validator::make($data, [
            'email' => 'required|email'
        ]);

        if($validator->fails() || !User::where('email', $data['email'])->exists()) {
            return redirect('/password/reset');
        }

        $user = User::where('email', $data['email'])->first();

        if($this->db->table('reset_password')->where('username', $user->username)->where('used', 'n')->exists()) {
            return redirect('/password/reset');
        }

        $id = $this->db->table('reset_password')->insertGetId([
            'username' => $user->username,
            'uid' => ''
        ]);

        $resetid = hash_hmac('sha256', $id, 'privatekey');

        $this->db->table('reset_password')
            ->where('username', $user->username)
            ->where('used', 'n')
            ->update([
                'uid' => $resetid
            ]);

        $html = file_get_contents(storage_path('reset_password_template.php'));
        $keywords = ['UUID', 'SIGNATURE', 'RESETID'];
	    $replacementValues = [$user->id, hash_hmac('sha256', 'testingthis', 'privatekey'), $resetid];
        $html = str_replace($keywords, $replacementValues, $html);

        if((int)env('FINOBE_MAIL_MODE') == 1) {
            $response = Http::withHeaders([
                'accept' => 'application/json',
                'authorization' => 'Zoho-enczapikey ' . env('FINOBE_ZOHO_API_KEY'),
                'cache-control' => 'no-cache',
                'content-type' => 'application/json',
            ])->post('https://api.zeptomail.com/v1.1/email', [
                "from" => [
                    "address" => "noreply@aesthetiful.com"
                ],
                "to" => [
                    [
                        "email_address" => [
                            "address" => $data['email'],
                            "name" => $user->username
                        ]
                    ]
                ],
                "subject" => "Finobe Password Reset",
                "htmlbody" => $html
            ]);

            if(!$response->successful()) {
                $this->db->table('reset_password')
                    ->where('username', $user->username)
                    ->where('used', 'n')
                    ->delete();
                
                Session::put('error', 'There was an error while sending the email, please try again.');
                return redirect('/');
            }
        } elseif((int)env('FINOBE_MAIL_MODE') == 2) {
            $response = Http::withHeaders([
                'accept' => 'application/json',
                'X-Smtp2go-Api-Key' => env('FINOBE_SMTP2GO_API_KEY'),
                'content-type' => 'application/json',
            ])->post('https://us-api.smtp2go.com/v3/email/send', [
                "sender" => "Finobe <noreply@aesthetiful.com>",
                "to" => $user->username . " <" . $data['email'] . ">",
                "subject" => "Finobe Password Reset",
                "html_body" => $html
            ]);

            if(!$response->successful()) {
                $this->db->table('reset_password')
                    ->where('username', $user->username)
                    ->where('used', 'n')
                    ->delete();
                
                Session::put('error', 'There was an error while sending the email, please try again.');
                return redirect('/');
            }
        }

        return redirect('/password/reset');
    }

    public function password_verify(Request $request, $id, $resetid) {
        $this->request['data']['embeds']['title'] = 'Reset Password' . $this->request['data']['embeds']['title'];
        $data = $request->all();

        if($this->request['data']['siteusername']) {
            return redirect('/');
        }

        if(!User::find($id)) {
            Session::put('error', 'User not found');
            return redirect('/password/reset');
        }

        $user = User::find($id);

        if(!$this->db->table('reset_password')->where('username', $user->username)->where('uid', $resetid)->where('used', 'n')->exists()) {
            Session::put('error', 'Session not found');
            return redirect('/password/reset');
        }

        $session = (array) $this->db->table('reset_password')
            ->where('username', $user->username)
            ->where('uid', $resetid)
            ->where('used', 'n')
            ->first();

        if(strcasecmp(hash_hmac('sha256', $session['id'], 'privatekey'), $resetid) !== 0) {
            Session::put('error', 'Incorrect hash');
            return redirect('/');
        }

        if($request->isMethod('post')) {
            if(!isset($data['password'])) {
                return redirect('/password/reset');
            }

            $this->db->table('reset_password')
                ->where('id', $session['id'])
                ->update([
                    'used' => 'y'
                ]);
            
            $user->password = password_hash($data['password'], PASSWORD_BCRYPT);
            $user->save();

            Session::put('success', 'Successfully reset');
            return redirect('/');
        }

        $this->request['data']['reset'] = true;

        return view($this->request['data']['user']['version'] . '/Reset', $this->request);
    }

    public function create(Request $request) {
        $this->request['data']['embeds']['title'] = 'Create asset' . $this->request['data']['embeds']['title'];

        if(!$this->request['data']['siteusername']) {
            return redirect('/');
        }

        return view($this->request['data']['user']['version'] . '/Create', $this->request);
    }

    public function place_new(Request $request) {
        $this->request['data']['embeds']['title'] = 'New Place' . $this->request['data']['embeds']['title'];
        $data = $request->all();

        if(!$this->request['data']['siteusername']) {
            return redirect('/');
        }

        if($request->isMethod('post')) {
            $validator = Validator::make($data, [
                'title' => 'required|string|min:3|max:255',
                'description' => 'nullable|string|max:8192'
            ]);

            $games = $this->db->table('assets')
                ->where('author', $this->request['data']['user']['id'])
                ->where('asset_type', 9)
                ->count();
            
            if(!(bool)env('FINOBE_CREATE_PLACES')) {
                Session::put('error', 'Creating assets is currently disabled');
                return redirect('/app/place/new');
            }

            if($games >= $this->request['data']['user']['slots']) {
                Session::put('error', 'You have used all of your place slots');
                return redirect('/app/place/new');
            }

            if($this->request['data']['user']['status'] != 'admin') {
                Session::put('error', 'Admin status is required');
                return redirect('/app/place/new');
            }
            /*
            $id = $this->db->table('assets')->insertGetId([
                'asset_type' => 9,
                'title' => trim($data['title']),
                'description' => trim($data['description'] ?? ''),
                'additional' => json_encode([
                    'visits' => 0,
                    'version' => '2012',
                    'maxplayers' => 15,
                    'category' => 'original',
                    'featured' => false,
                    'gears' => [
                        'combat' => true,
                        'social' => true,
                        'building' => true,
                        'musical' => true
                    ],
                    'uncopylocked' => false,
                    'allowplaying' => true,
                    'chat_type' => 'classic',
                    'media' => [
                        'imageAssetId' => 1
                    ],
                    'hidden' => false
                ])
            ]);
            */
            $defaultPlace = file_get_contents("/var/www/cdn.finobe.net/default.rbxl");
            $id = Asset::createAsset(trim($data['title']), 9, $this->request['data']['user']['id'], $defaultPlace, trim($data['description'] ?? ''), "n", [
                    'visits' => 0,
                    'version' => '2012',
                    'maxplayers' => 15,
                    'category' => 'original',
                    'featured' => false,
                    'gears' => [
                        'combat' => true,
                        'social' => true,
                        'building' => true,
                        'musical' => true
                    ],
                    'uncopylocked' => false,
                    'allowplaying' => true,
                    'chat_type' => 'classic',
                    'media' => [
                        'imageAssetId' => 1
                    ],
                    'hidden' => false
            ]);
            return redirect('/place/' . $id);
        }

        return view($this->request['data']['user']['version'] . '/Places/New', $this->request);
    }

    public function catalog_new(Request $request) {
        $this->request['data']['embeds']['title'] = 'New Asset' . $this->request['data']['embeds']['title'];
        $data = $request->all();

        if(!$this->request['data']['siteusername']) {
            return redirect('/');
        }
        
        if($request->isMethod('post')) {
            if(!(bool)env('FINOBE_CREATE_ASSETS')) {
                Session::put('Creating assets is currently disabled');
                return redirect('/catalog/new');
            }

            $assetTypes = [
                'hats' => 8,
                't-shirts' => 2,
                'shirt' => 11,
                'pants' => 12,
                'gears' => 19,
                'faces' => 18,
                'heads' => 17,
                'packages' => 32,
                'audio' => 3,
                'model' => 10
            ];

            $validator = Validator::make($data, [
                'title' => 'required|string|min:3|max:255',
                'description' => 'nullable|string|max:8192',
                'media-type' => 'required|string',
                'price' => 'required|integer|min:0'
            ]);

            if($validator->fails()) {
                Session::put('error', $validator->errors()->first());
                return redirect('/catalog/new');
            }

            if($this->request['data']['user']['Dius'] - 5 < 0) {
                Session::put('error', 'Not enough dius');
                return redirect('/catalog/new');
            }

            if($data['media-type'] == 'video') {
                if($this->request['data']['user']['status'] != 'admin') {
                    return redirect('/catalog/new');
                }

                $validator = Validator::make($data, [
                    'file' => 'required|file|mimetypes:video/mp4,video/x-msvideo,video/x-ms-wmv,video/x-ms-asf,video/quicktime,video/webm|max:102400'
                ]);

                if($validator->fails()) {
                    Session::put('error', $validator->errors()->first());
                    return redirect('/catalog/new');
                }

                $filename = uniqid();
                $thumbnail = $filename . '.jpg';
                $filename .= '.webm';

                $file = $request->file('file')->store('videos');

                /*
                $ffmpeg = FFmpeg::create();
                $video = $ffmpeg->open(storage_path('app/private/' . $file));
                $format = new X264('aac', 'libx264');
                //$format->setAdditionalParameters(['-movflags', '+faststart']);
                $video->save($format, '/var/www/cdn.finobe.net/videos/data/' . $filename);
                $video->frame(TimeCode::fromSeconds(1))
                    ->save('/var/www/cdn.finobe.net/videos/thumbs/' . $thumbnail);
                */

                Redis::set("video_processing:{$filename}", true);
                ProcessVideo::dispatch($file, $filename, $thumbnail);

                /*
                if(empty(trim(shell_exec("ps aux | grep 'php artisan queue:work' | grep -v grep")))) {
                    exec('cd /var/www/Finobe && php artisan queue:work --timeout=21600 --sleep=3 --tries=3 > /dev/null 2>&1 &');
                }
                */
                
                $id = $this->db->table('videos')->insertGetId([
                    'title' => $data['title'],
                    'author' => $this->request['data']['user']['username'],
                    'filename' => $filename,
                    'thumbnail' => $thumbnail,
                    'description' => $data['description'] ?? ''
                ]);

                $user = User::find($this->request['data']['user']['id']);
                $user->Dius -= 5;
                $user->save();

                $this->db->table('purchases')->insert([
                    'username' => $user->username,
                    'assetid' => 0,
                    'author' => 0,
                    'amount' => -5,
                    'type' => 5
                ]);

                Session::put('success', 'Video is processing, the video will automatically publish when finished processing.');
                return redirect('/videos');
            } elseif($data['media-type'] == 'audio') {
                $validator = Validator::make($data, [
                    'file' => 'required|file|mimetypes:audio/mpeg,audio/ogg,audio/midi,audio/x-midi,audio/wav,audio/x-wav|max:10240'
                ]);

                if($validator->fails()) {
                    Session::put('error', $validator->errors()->first());
                    return redirect('/catalog/new');
                }

                if($this->request['data']['user']['Dius'] - 5 < 0) {
                    Session::put('error', 'Not enough dius');
                    return redirect('/catalog/new');
                }

                $filename = uniqid();
                $ffmpeg = FFmpeg::create();

                try {
                    $file = $request->file('file');
                    if(in_array($file->getMimeType(), ['audio/midi', 'audio/x-midi'])) {
                        $file->move(public_path('dynamic/temp/'), $filename);
                        $input = public_path('dynamic/temp/' . $filename);
                        $output = public_path('dynamic/temp/' . $filename . '.mp3');
                        exec("timidity $input -Ow -o - | ffmpeg -i - -codec:a libmp3lame -b:a 96k $output");
                        $audio = $ffmpeg->open($output);
                        $duration = $audio->getFormat()->get('duration');
                    } else {
                        $file->move(public_path('dynamic/temp/'), $filename);
                        $audio = $ffmpeg->open(public_path('dynamic/temp/' . $filename));
                        $duration = $audio->getFormat()->get('duration');
                        $format = new Mp3();
                        $format->setAudioKiloBitrate(96);
                        $audio->save($format, public_path('dynamic/temp/' . $filename . '.mp3'));
                    }
                    
                    rename(public_path('dynamic/temp/' . $filename . '.mp3'), public_path('dynamic/reviewing/' . $filename)); //ffmpeg is fucking me in the ass without the .mp3 extention
                } catch(ProcessFailedException $e) {
                    Session::put('error', $e->getProcess()->getErrorOutput());
                    return redirect('/catalog/new');
                }

                $id = $this->db->table('assets')->insertGetId([
                    'asset_type' => $assetTypes[$data['media-type']],
                    'title' => $data['title'],
                    'author' => $this->request['data']['user']['id'],
                    'file' => $filename,
                    'description' => $data['description'] ?? '',
                    'visibility' => 'r',
                    'additional' => json_encode([
                        'duration' => $duration,
                        'price' => intval($data['price']),
                        'media' => [
                            'imageAssetId' => 2
                        ],
                        'oldUser' => ''
                    ])
                ]);

                $user = User::find($this->request['data']['user']['id']);
                $user->Dius -= 5;
                $user->save();

                $this->db->table('purchases')->insert([
                    'username' => $user->username,
                    'assetid' => 0,
                    'author' => 0,
                    'amount' => -5,
                    'type' => 5
                ]);

                $this->dataService->send_discord_message('<@541523977475194880>, ' . $this->request['data']['user']['username'] . ' uploaded an item, moderate it! [ https://finobe.net/admin/assets ]');

                return redirect('/item/' . $id);
            } elseif($data['media-type'] == 'shirt') {
                $validator = Validator::make($data, [
                    'file' => 'required|file|mimetypes:image/png,image/jpeg|max:10240'
                ]);

                if($validator->fails()) {
                    Session::put('error', $validator->errors()->first());
                    return redirect('/catalog/new');
                }

                if(intval($data['price']) < 5) {
                    Session::put('error', 'Price must be at least 5 Diu');
                    return redirect('/catalog/new');
                }

                if($this->request['data']['user']['Dius'] - 5 < 0) {
                    Session::put('error', 'Not enough dius');
                    return redirect('/catalog/new');
                }

                $image = getimagesize($request->file('file')->getPathname());

                if(abs(($image[0] / $image[1]) - (585 / 559)) > 0.01) {
                    Session::put('error', 'Image is not correct ratio (585x559)');
                    return redirect('/catalog/new');
                }

                $user = User::find($this->request['data']['user']['id']);

                try {
                    $id = Asset::createAccessory(
                        $data['title'],
                        ['tmp_name' => $request->file('file')->getPathname()],
                        $user->id,
                        $data['description'] ?? '',
                        intval($data['price']),
                        true,
                        false,
                        'shirt'
                    );
                } catch(\Exception $e) {
                    Session::put('error', $e->getMessage());
                    return redirect('/catalog/new');
                }

                $user->Dius -= 5;
                $user->save();

                $this->db->table('purchases')->insert([
                    'username' => $user->username,
                    'assetid' => 0,
                    'author' => 0,
                    'amount' => -5,
                    'type' => 5
                ]);

                $this->dataService->send_discord_message('<@541523977475194880>, ' . $this->request['data']['user']['username'] . ' uploaded an item, moderate it! [ https://finobe.net/admin/assets ]');

                Session::put('success', 'Success');
                return redirect('/item/' . $id);
            } elseif($data['media-type'] == 'pants') {
                $validator = Validator::make($data, [
                    'file' => 'required|file|mimetypes:image/png,image/jpeg|max:10240'
                ]);

                if($validator->fails()) {
                    Session::put('error', $validator->errors()->first());
                    return redirect('/catalog/new');
                }

                if(intval($data['price']) < 5) {
                    Session::put('error', 'Price must be at least 5 Diu');
                    return redirect('/catalog/new');
                }

                if($this->request['data']['user']['Dius'] - 5 < 0) {
                    Session::put('error', 'Not enough dius');
                    return redirect('/catalog/new');
                }

                $image = getimagesize($request->file('file')->getPathname());

                if(abs(($image[0] / $image[1]) - (585 / 559)) > 0.01) {
                    Session::put('error', 'Image is not correct ratio (585x559)');
                    return redirect('/catalog/new');
                }

                $user = User::find($this->request['data']['user']['id']);

                try {
                    $id = Asset::createAccessory(
                        $data['title'],
                        ['tmp_name' => $request->file('file')->getPathname()],
                        $user->id,
                        $data['description'] ?? '',
                        intval($data['price']),
                        true,
                        false,
                        'pants'
                    );
                } catch(\Exception $e) {
                    Session::put('error', $e->getMessage());
                    return redirect('/catalog/new');
                }

                $user->Dius -= 5;
                $user->save();

                $this->db->table('purchases')->insert([
                    'username' => $user->username,
                    'assetid' => 0,
                    'author' => 0,
                    'amount' => -5,
                    'type' => 5
                ]);

                $this->dataService->send_discord_message('<@541523977475194880>, ' . $this->request['data']['user']['username'] . ' uploaded an item, moderate it! [ https://finobe.net/admin/assets ]');

                Session::put('success', 'Success');
                return redirect('/item/' . $id);
            } elseif($data['media-type'] == 'faces') {
                if($this->request['data']['user']['status'] != 'admin') {
                    Session::put('error', 'Admin required');
                    return redirect('/catalog/new');
                }

                $validator = Validator::make($data, [
                    'file' => 'required|file|mimetypes:image/png,image/jpeg|max:10240'
                ]);

                if($validator->fails()) {
                    Session::put('error', $validator->errors()->first());
                    return redirect('/catalog/new');
                }

                if($this->request['data']['user']['Dius'] - 5 < 0) {
                    Session::put('error', 'Not enough dius');
                    return redirect('/catalog/new');
                }

                $image = getimagesize($request->file('file')->getPathname());

                if(abs(($image[0] / $image[1]) - (256 / 256)) > 0.01) {
                    Session::put('error', 'Image is not correct ratio (256x256)');
                    return redirect('/catalog/new');
                }

                $user = User::find($this->request['data']['user']['id']);

                try {
                    $id = Asset::createAccessory(
                        $data['title'],
                        ['tmp_name' => $request->file('file')->getPathname()],
                        $user->id,
                        $data['description'] ?? '',
                        intval($data['price']),
                        true,
                        false,
                        'face'
                    );
                } catch(\Exception $e) {
                    Session::put('error', $e->getMessage());
                    return redirect('/catalog/new');
                }

                $user->Dius -= 5;
                $user->save();

                $this->db->table('purchases')->insert([
                    'username' => $user->username,
                    'assetid' => 0,
                    'author' => 0,
                    'amount' => -5,
                    'type' => 5
                ]);

                $this->dataService->send_discord_message('<@541523977475194880>, ' . $this->request['data']['user']['username'] . ' uploaded an item, moderate it! [ https://finobe.net/admin/assets ]');

                Session::put('success', 'Success');
                return redirect('/item/' . $id);
            } elseif($data['media-type'] == 't-shirts') {
                $validator = Validator::make($data, [
                    'file' => 'required|file|mimetypes:image/png,image/jpeg|max:10240'
                ]);

                if($validator->fails()) {
                    Session::put('error', $validator->errors()->first());
                    return redirect('/catalog/new');
                }

                if(intval($data['price']) < 2) {
                    Session::put('error', 'Price must be at least 2 Diu');
                    return redirect('/catalog/new');
                }

                if($this->request['data']['user']['Dius'] - 5 < 0) {
                    Session::put('error', 'Not enough dius');
                    return redirect('/catalog/new');
                }

                $user = User::find($this->request['data']['user']['id']);

                try {
                    $id = Asset::createAccessory(
                        $data['title'],
                        ['tmp_name' => $request->file('file')->getPathname()],
                        $user->id,
                        $data['description'] ?? '',
                        intval($data['price']),
                        true,
                        false,
                        'tshirt'
                    );
                } catch(\Exception $e) {
                    Session::put('error', $e->getMessage());
                    return redirect('/catalog/new');
                }

                $user->Dius -= 5;
                $user->save();

                $this->db->table('purchases')->insert([
                    'username' => $user->username,
                    'assetid' => 0,
                    'author' => 0,
                    'amount' => -5,
                    'type' => 5
                ]);

                $this->dataService->send_discord_message('<@541523977475194880>, ' . $this->request['data']['user']['username'] . ' uploaded an item, moderate it! [ https://finobe.net/admin/assets ]');

                Session::put('success', 'Success');
                return redirect('/item/' . $id);
            }

            return redirect('/catalog/new');
        }

        return view($this->request['data']['user']['version'] . '/Catalog/New', $this->request);
    }

    public function app_settings(Request $request) {
        $this->request['data']['embeds']['title'] = 'Settings' . $this->request['data']['embeds']['title'];
        $data = $request->all();

        if(!$this->request['data']['siteusername']) {
            return redirect('/');
        }

        if($request->isMethod('post')) {
            if(isset($data['blurb']) && !$request->hasFile('file')) {
                $validator = Validator::make($data, [
                    'blurb' => 'required|string|max:8192'
                ]);

                if($validator->fails()) {
                    Session::put('error', $validator->errors()->first());
                    return redirect('/app/settings');
                }

                $user = User::find($this->request['data']['user']['id']);
                $user->blurb = $data['blurb'];
                $user->save();

                Session::put('success', 'Successfully updated.');
                return redirect('/app/settings');
            } elseif(isset($data['password']) && !$request->hasFile('file')) {
                $validator = Validator::make($data, [
                    'email' => 'required|email',
                    'password' => 'required|string|alpha_dash|unique:finobe.users,email'
                ]);

                if($validator->fails()) {
                    Session::put('error', $validator->errors()->first());
                    return redirect('/app/settings');
                }

                if(!Hash::check($data['password'], $this->request['data']['user']['password'])) {
                    Session::put('errorlogin', true);
                    return redirect('/auth/login');
                }

                $user = Auth::user();
                $user->email = $data['email'];
                $user->verified = 'n';
                $user->save();

                return redirect('/app/settings');
            } elseif($this->request['data']['user']['status'] == 'admin' && $request->hasFile('file')) {
                $validator = Validator::make($data, [
                    'file' => 'required|file|minetypes:image/png,image/jpg|max:10240'
                ]);

                if($validator->fails()) {
                    Session::put('error', $validator->errors()->first());
                    return redirect('/app/settings');
                }

                $file = $request->file('file');
                list($width, $height) = getimagesize($file->getPathname());
                $filename = uniqid() . '.' . $file->extension();

                if($width != $height) {
                    Session::put('error', 'Image needs to be 1:1 ratio');
                    return redirect('/app/settings');
                }

                $file->move('/var/www/cdn.finobe.net/avatar/', $filename);

                $user = User::find($this->request['data']['user']['id']);
                $user->pfp = $filename;
                $user->save();

                Session::put('success', 'Successfully updated.');
                return redirect('/app/settings');
            }

            return redirect('/app/settings');
        }

        return view($this->request['data']['user']['version'] . '/Settings/Index', $this->request);
    }

    public function app_theme(Request $request) {
        $this->request['data']['embeds']['title'] = 'Theme' . $this->request['data']['embeds']['title'];
        $data = $request->all();

        if(!$this->request['data']['siteusername']) {
            return redirect('/');
        }

        if($request->isMethod('post')) {
            if(isset($data['branding'])) {
                $validator = Validator::make($data, [
                    'branding' => 'required|string|in:aesthetiful,finobe'
                ]);

                if($validator->fails()) {
                    Session::put('error', $validator->errors()->first());
                    return redirect('/app/theme');
                }

                $user = User::find($this->request['data']['user']['id']);
                $user->branding = $data['branding'];
                $user->save();

                Session::put('successv2', 'Successfully updated.');
                return redirect('/app/' . ($user->version == 'v1' ? 'settings' : 'theme'));
            } elseif(isset($data['logo'])) {
                $validator = Validator::make($data, [
                    'logo' => 'required|string|in:v1,v2,v3'
                ]);

                if($validator->fails()) {
                    Session::put('error', $validator->errors()->first());
                    return redirect('/app/theme');
                }

                $user = User::find($this->request['data']['user']['id']);
                $user->logo = $data['logo'];
                $user->save();

                Session::put('successv2', 'Successfully updated.');
                return redirect('/app/' . ($user->version == 'v1' ? 'settings' : 'theme'));
            } elseif(isset($data['dark'])) {
                User::where('id', $this->request['data']['user']['id'])
                    ->update([
                        'theme' => DB::raw('CASE WHEN theme = 0 THEN 1 ELSE 0 END')
                    ]);
                
                Session::put('successv2', 'Successfully updated.');
                return redirect('/app/' . ($this->request['data']['user']['version'] == 'v1' ? 'settings' : 'theme'));
            } elseif(isset($data['gary'])) {
                User::where('id', $this->request['data']['user']['id'])
                    ->update([
                        'gary' => DB::raw('CASE WHEN gary = 0 THEN 1 ELSE 0 END')
                    ]);
                
                Session::put('successv2', 'Successfully updated.');
                return redirect('/app/' . ($this->request['data']['user']['version'] == 'v1' ? 'settings' : 'theme'));
            } elseif(isset($data['upsidedown'])) {
                User::where('id', $this->request['data']['user']['id'])
                    ->update([
                        'upsidedown' => DB::raw('CASE WHEN upsidedown = 0 THEN 1 ELSE 0 END')
                    ]);
                
                Session::put('successv2', 'Successfully updated.');
                return redirect('/app/' . ($this->request['data']['user']['version'] == 'v1' ? 'settings' : 'theme'));
            } elseif(isset($data['version'])) {
                $validator = Validator::make($data, [
                    'version' => 'required|string|in:v1,v2'
                ]);

                if($validator->fails()) {
                    Session::put('error', $validator->errors()->first());
                    return redirect('/app/theme');
                }

                User::where('id', $this->request['data']['user']['id'])
                    ->update([
                        'gary' => 0,
                        'upsidedown' => 0,
                        'theme' => 0,
                        'logo' => 'v1',
                        'version' => $data['version']
                    ]);
                
                Session::put('success', 'Successfully changed');
                return redirect('/app/' . ($this->request['data']['user']['version'] == 'v1' ? 'settings' : 'theme'));
            }

            return redirect('/app/theme');
        }

        return view($this->request['data']['user']['version'] . '/Settings/Theme', $this->request);
    }

    public function app_games(Request $request) {
        $this->request['data']['embeds']['title'] = 'Places' . $this->request['data']['embeds']['title'];
        $data = $request->all();

        if(!$this->request['data']['siteusername']) {
            return redirect('/');
        }

        if($request->isMethod('post')) {
            if($this->request['data']['user']['Dius'] < 625) {
                Session::put('error', 'You do not have enough Dius to purchase a place slot');
                return redirect('/app/games');
            }

            $user = User::find($this->request['data']['user']['id']);
            $user->Dius -= 625;
            $user->slots += 1;
            $user->save();

            $this->db->table('purchases')->insert([
                'username' => $user->username,
                'assetid' => 0,
                'author' => 1,
                'amount' => -625,
                'type' => 3
            ]);

            Session::put('successv2', 'Successfully purchased.');
            return redirect('/app/games');
        }

        return view($this->request['data']['user']['version'] . '/Settings/Places', $this->request);
    }

    public function app_connect(Request $request) {
        $this->request['data']['embeds']['title'] = 'Connect' . $this->request['data']['embeds']['title'];
        $data = $request->all();

        if(!$this->request['data']['siteusername']) {
            return redirect('/');
        }

        if($request->isMethod('post')) {
            $validator = Validator::make($data, [
                'username' => 'required|string'
            ]);

            if($validator->fails()) {
                Session::put('error', $validator->errors()->first());
                return redirect('/app/connect');
            }

            $user = User::find($this->request['data']['user']['id']);
            $user->eracast_link = 'None';
            $user->save();

            Http::withHeaders([
                'User-Agent' => 'finobe.net/Server 1.0'
            ])->get('https://api.eracast.cc/v1/update_aesthetifulplus_link', [
                'user' => $data['username'],
                'userid' => 'None'
            ]);

            Session::put('successv2', 'Successfully disconnected.');
            return redirect('/app/connect');
        }

        if($this->request['data']['user']['eracast_link'] != 'None') {
            $response = Http::withHeaders([
                'User-Agent' => 'finobe.net/Server 1.0'
            ])->get('https://api.eracast.cc/v1/get_user_username', [
                'userid' => $this->request['data']['user']['eracast_link']
            ]);

            $this->request['data']['user']['eracast_username'] = $response->body();
        }

        return view($this->request['data']['user']['version'] . '/Settings/Connect', $this->request);
    }

    public function api_connect(Request $request) {
        $this->request['data']['embeds']['title'] = 'Connecting eracast' . $this->request['data']['embeds']['title'];
        $data = $request->all();

        if($request->isMethod('post')) {
            $validator = Validator::make($data, [
                'userid' => 'required|integer',
                'username' => 'required|string'
            ]);

            if($validator->fails()) {
                Session::put('error', $validator->errors()->first());
                return redirect('/app/connect');
            }

            Http::withHeaders([
                'User-Agent' => 'finobe.net/Server 1.0'
            ])->get('https://api.eracast.cc/v1/update_aesthetifulplus_link', [
                'eracast_fiur3ui3uigu3itjuirjifs',
                'user' => $data['username'],
                'userid' => $this->request['data']['user']['id']
            ]);

            $user = User::find($this->request['data']['user']['id']);
            $user->eracast_link = $data['userid'];
            $user->save();

            Session::put('success', 'Successfully linked.');
            return redirect('/app/connect');
        }

        if(!isset($data['data'])) {
            return redirect('https://www.eracast.cc/signin?context=connect&next=&feature=aesthetifulplus');
        } else {
            function decryptData($data, $key) {
                $data = base64_decode(urldecode($data));
                $iv = substr($data, 0, 16);
                $encrypted = substr($data, 16);
                $decrypted = openssl_decrypt($encrypted, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
                parse_str($decrypted, $dataArray);
                return $dataArray;
            }

            $validator = Validator::make($data, [
                'data' => 'required|string'
            ]);

            if($validator->fails()) {
                Session::put('error', $validator->errors()->first());
                return redirect('/app/connect');
            }

            $decoded = decryptData($data['data'], 'connect');

            if(!isset($decoded['e_username'])) {
                Session::put('error', 'There was an error, please try again.');
                return redirect('/app/connect');
            }

            $response = Http::withHeaders([
                'User-Agent' => 'finobe.net/Server 1.0'
            ])->get('https://api.eracast.cc/v1/get_user_pfp', [
                'user' => $decoded['e_username']
            ]);

            $this->request['data']['pfp'] = $response->body();
            $this->request['data']['e_username'] = $decoded['e_username'];
            $this->request['data']['e_id'] = $decoded['e_id'];
        }

        return view($this->request['data']['user']['version'] . '/Settings/ConnectAPI', $this->request);
    }

    public function legal_about_us(Request $request) {
        $this->request['data']['embeds']['title'] = 'About us' . $this->request['data']['embeds']['title'];

        return view($this->request['data']['user']['version'] . '/About-us', $this->request);
    }

    public function legal_welcome(Request $request) {
        $this->request['data']['embeds']['title'] = 'Welcome' . $this->request['data']['embeds']['title'];

        if(!$this->request['data']['siteusername']) {
            return redirect('/');
        }

        return view($this->request['data']['user']['version'] . '/Welcome', $this->request);
    }

    public function legal_rules(Request $request) {
        $this->request['data']['embeds']['title'] = 'Rules' . $this->request['data']['embeds']['title'];

        return view($this->request['data']['user']['version'] . '/Rules', $this->request);
    }

    public function legal_terms(Request $request) {
        $this->request['data']['embeds']['title'] = 'Terms of Service' . $this->request['data']['embeds']['title'];

        return view($this->request['data']['user']['version'] . '/Terms', $this->request);
    }

    public function transparency_bans(Request $request) {
        $this->request['data']['embeds']['title'] = 'Public Ban List' . $this->request['data']['embeds']['title'];
        $data = $request->all();

        $bans = [];
        $results = $this->db->table('bans')
            ->whereIn('username', function ($subquery) {
                $subquery->select('username')->from('users');
            })
            ->orderByDesc('id')
            ->limit(50);

        if(isset($data['q'])) {
            $search = '%' . $data['q'] . '%';
            $results->where('username', 'like', $search);
        }

        $results = $results->get()
            ->map(function ($item) {
                return (array) $item;
            })->toArray();
        
        foreach($results as $result) {
            if(!User::where('username', $result['username'])->exists()) {
                continue;
            }

            $result['username'] = htmlspecialchars($result['username']);
            $result['date'] = date('Y-m-d', strtotime($result['date']));
            $result['expire'] = date('Y-m-d', strtotime($result['expire']));
            $bans[] = $result;
        }

        $results = $this->db->table('warning')
            ->whereIn('username', function ($subquery) {
                $subquery->select('username')->from('users');
            })
            ->orderByDesc('id')
            ->limit(50);

        if(isset($data['q'])) {
            $search = '%' . $data['q'] . '%';
            $results->where('username', 'like', $search);
        }

        $results = $results->get()
            ->map(function ($item) {
                return (array) $item;
            })->toArray();

        foreach($results as $result) {
            if(!User::where('username', $result['username'])->exists()) {
                continue;
            }

            $result['username'] = htmlspecialchars($result['username']);
            $result['date'] = date('Y-m-d', strtotime($result['date']));
            $bans[] = $result;
        }

        usort($bans, function($a, $b) {
			return strtotime($b['date']) - strtotime($a['date']);
		});
        $this->request['data']['bans'] = $bans;

        return view($this->request['data']['user']['version'] . '/Bans', $this->request);
    }

    public function gettoken(Request $request) {
        if(!$this->request['data']['siteusername']) {
            return response('', 204);
        }

        return response($this->request['data']['user']['token'], 200);
    }

    public function videos(Request $request) {
        $this->request['data']['embeds']['title'] = 'Videos' . $this->request['data']['embeds']['title'];
        $data = $request->all();

        $videos = $this->db->table('videos')->count();
        
        $pages_to_show = 10;
        $results_per_page = 20;
        $number_of_pages = ceil($videos / $results_per_page);
        $currentPage = isset($data['page']) ? max(1, intval($data['page'])) : 1;
        $offset = ($currentPage - 1) * $results_per_page;
        $start_page = max(1, min($currentPage - floor($pages_to_show / 2), $number_of_pages - $pages_to_show + 1));
        $end_page = min($number_of_pages, $start_page + $pages_to_show - 1);
        $videos = $this->db->table('videos')
            ->orderBy('id', 'DESC')
            ->offset($offset)
            ->limit($results_per_page)
            ->get()
            ->map(function ($item) {
                return (array) $item;
            })->toArray();
        
        foreach($videos as $key => $video) {
            if(Redis::exists("video_processing:{$video['filename']}")) {
                unset($videos[$key]);
            }
        }
        
        $this->request['data']['videos'] = [
            'data' => $videos,
            'pages' => [
                'info' => [
                    'current_page' => $currentPage,
                    'previous_page' => max(1, $currentPage - 1),
                    'next_page' => min($number_of_pages, $currentPage + 1),
                    'start_page' => $start_page,
                    'end_page' => $end_page,
                    'number_of_pages' => $number_of_pages
                ],
                'data' =>[]
            ]
        ];

        for ($page = $start_page; $page <= $end_page; $page++) {
            $this->request['data']['videos']['pages']['data'][] = ['page' => $page];
        }

        if(!$videos) {
            $this->request['data']['videos']['pages']['data'][] = [
                'page' => 1
            ];
        }

        return view($this->request['data']['user']['version'] . '/Videos/Index', $this->request);
    }

    public function video(Request $request, $id) {
        if(!$this->db->table('videos')->where('id', $id)->exists()) {
            Session::put('error', 'Video not found');
            return redirect('/videos');
        }

        $video = (array) $this->db->table('videos')
            ->where('id', $id)
            ->first();
        
        $video['title'] = strip_tags(htmlspecialchars($video['title']));
        $video['description'] = nl2br(strip_tags(htmlspecialchars($video['description'])));
        $video['uuid'] = User::where('username', $video['author'])->value('id');
        $video['author'] = htmlspecialchars($video['author']);
        $video['rating'] = $this->db->table('video_ratings')->where('toid', $video['id'])->where('rate_type', 'l')->count();
        $video['upvotes'] = $video['rating'];
        $video['rating'] = $video['rating'] - $this->db->table('video_ratings')->where('toid', $video['id'])->where('rate_type', 'd')->count();
        $video['downvotes'] = $this->db->table('video_ratings')->where('toid', $video['id'])->where('rate_type', 'd')->count();

        if($this->request['data']['siteusername']) {
            if($this->db->table('video_ratings')->where('toid', $id)->where('sender', $this->request['data']['user']['username'])->count()) {
                $video['userRating'] = $this->db->table('video_ratings')
                    ->select('rate_type')
                    ->where('toid', $id)
                    ->where('sender', $this->request['data']['user']['username'])
                    ->value('rate_type');
            }
        }

        $this->request['data']['embeds']['title'] = $video['title'] . $this->request['data']['embeds']['title'];
        $this->request['data']['video'] = $video;

        return view($this->request['data']['user']['version'] . '/Videos/Video', $this->request);
    }

    public function video_thumb(Request $request, $id) {
        if(!$this->db->table('videos')->where('id', $id)->exists()) {
            return response()->view($this->request['data']['user']['version'] . '/404', $this->request, 404);
        }

        return redirect('https://cdn.finobe.net/videos/thumbs/' . $this->db->table('videos')->select('thumbnail')->where('id', $id)->value('thumbnail'));
    }

    public function video_data(Request $request, $id) {
        if(!$this->db->table('videos')->where('id', $id)->exists()) {
            return response()->view($this->request['data']['user']['version'] . '/404', $this->request, 404);
        }

        //return redirect('https://cdn.finobe.net/videos/data/' . $this->db->table('videos')->select('filename')->where('id', $id)->value('filename'));
        $video = (array) $this->db->table('videos')
            ->where('id', $id)
            ->first();
        
        if(!file_exists('/var/www/cdn.finobe.net/videos/data/' . $video['filename'])) {
            abort(404, 'Video not found');
        }

        $filePath = '/var/www/cdn.finobe.net/videos/data/' . $video['filename'];
        $size = filesize($filePath);
        $start = 0;
        $end = $size - 1;

        $headers = [
            'Content-Type' => mime_content_type($filePath),
            'Accept-Ranges' => 'bytes',
        ];

        if($request->headers->has('Range')) {
            preg_match('/bytes=(\d+)-(\d*)/', $request->header('Range'), $matches);

            $start = intval($matches[1]);
            $end = isset($matches[2]) && is_numeric($matches[2]) ? intval($matches[2]) : $end;

            $length = $end - $start + 1;

            $headers['Content-Range'] = "bytes $start-$end/$size";
            $headers['Content-Length'] = $length;

            return response()->stream(function () use ($filePath, $start, $length) {
                $handle = fopen($filePath, 'rb');
                fseek($handle, $start);

                $buffer = 1024 * 8;
                $bytesSent = 0;

                while(!feof($handle) && $bytesSent < $length) {
                    $readLength = min($buffer, $length - $bytesSent);
                    echo fread($handle, $readLength);
                    $bytesSent += $readLength;
                    ob_flush();
                    flush();
                }

                fclose($handle);
            }, 206, $headers);
        }

        $headers['Content-Length'] = $size;

        return response()->stream(function () use ($filePath) {
            readfile($filePath);
        }, 200, $headers);
    }

    public function auth_form(Request $request) {
        $this->request['data']['embeds']['title'] = 'Form' . $this->request['data']['embeds']['title'];
        $this->request['data']['invitekeys'] = (bool) env('FINOBE_INVITE_KEYS');
        $data = $request->all();

        if($this->request['data']['siteusername']) {
            return redirect('/');
        }

        return view($this->request['data']['user']['version'] . '/Form', $this->request);
    }

    public function auth_register(Request $request) {
        $this->request['data']['embeds']['title'] = 'Register' . $this->request['data']['embeds']['title'];
        $this->request['data']['invitekeys'] = (bool) env('FINOBE_INVITE_KEYS');
        $data = $request->all();

        if($this->request['data']['siteusername']) {
            return redirect('/');
        }

        if($request->isMethod('post')) {
            $forbiddenPhrases = [
                'raped', 'dick', 'aesthetiful', 'instance', 'fuck', 'shit', 'fag', 'f@g', 'd1ck', 'pussy',
                'jew', 'tranny', 'tr@nny', 'goon', 'g@@n', 'g00n', 'gyat', 'gy@t', 'r@ped', 'tities', 't1t1es',
                'nigg', 'n!gger', 'nigga', 'n1gga', 'n1gger', 'pedo', 'fag', 'faggot'
            ];

            $validator = Validator::make($data, [
                'username' => 'required|string|regex:/^(?!_)(?!.*_$)(?!.*_.*_)[A-Za-z0-9_]+$/|unique:finobe.users,username|min:3|max:20',
                'password' => 'required|string|confirmed|alpha_dash|min:8|max:255',
                'email' => 'required|email|confirmed|unique:finobe.users,email',
                'invite_key' => ($this->request['data']['invitekeys'] ? 'required' : 'nullable') . '|string',
                'g-recaptcha-response' => 'required'
            ]);

            if($validator->fails()) {
                Session::put('error', $validator->errors()->first());
                return redirect('/auth/form');
            }

            if(!$this->request['data']['invitekeys']) {
                Session::put('error', 'Account creation is currently disabled');
                return redirect('/auth/form');
            }

            $username = strtolower($data['username']);
            foreach ($forbiddenPhrases as $phrase) {
                if (str_contains($username, strtolower($phrase))) {
                    Session::put('error', 'The username field must not be greater than 20 characters.');
                    return redirect('/auth/form');
                }
            }

            $response = Http::asForm()->post('https://www.google.com/recaptcha/api/siteverify', [
				'secret' => env('GOOGLE_RECAPTCHA_SECRET'),
				'response' => $data['g-recaptcha-response']
			])->json();

            if(!$response['success']) {
                Session::put('error', 'reCAPTCHA failed.');
                return redirect('/auth/form');
            }

            if($this->request['data']['invitekeys'] && isset($data['invite_key']) && !$this->db->table('invitekeys')->where('IID', $data['invite_key'])->where('used', 'n')->exists()) {
                Session::put('error', 'Invalid invite key.');
                return redirect('/auth/form');
            }

            $user = User::create([
                'username' => trim($data['username']),
                'email' => trim($data['email']),
                'password' => password_hash($data['password'], PASSWORD_BCRYPT),
                'friends' => '[]',
                'inventory' => '[]',
                'badges' => '[]',
                'avatar' => '[{"resolvedAvatarType":"R6","equippedGearVersionIds":[],"backpackGearVersionIds":[],"assetAndAssetTypeIds":[],"bodyColors":{"headColorId":24,"torsoColorId":"23","rightArmColorId":24,"leftArmColorId":24,"rightLegColorId":"119","leftLegColorId":"119"},"scales":{"height":1,"width":1,"head":1,"depth":1,"proportion":0,"bodyType":0}}]',
                'token' => bin2hex(random_bytes(30))
            ]);

            $this->dataService->send_discord_message('<@541523977475194880>, ' . $data['username'] . ' has sign up');

            if($this->request['data']['invitekeys'] && isset($data['invite_key'])) {
                $this->db->table('invitekeys')
                    ->where('IID', $data['invite_key'])
                    ->update([
                        'used' => 'y',
                        'dateUsed' => now(),
                        'usedBy' => trim($data['username'])
                    ]);
            }

            Auth::login($user);
            return redirect('/legal/welcome');
        }

        return view($this->request['data']['user']['version'] . '/Register', $this->request['data']);
    }

    public function auth_login(Request $request) {
        $this->request['data']['embeds']['title'] = 'Login' . $this->request['data']['embeds']['title'];
        $this->request['data']['errorlogin'] = Session::has('errorlogin');
        $data = $request->all();

        Session::forget('errorlogin');

        if($this->request['data']['siteusername']) {
            return redirect('/');
        }

        if($request->isMethod('post')) {
            $validator = Validator::make($data, [
                'email' => 'required|email',
                'password' => 'required|string'
            ]);

            if($validator->fails()) {
                Session::put('errorlogin', true);
                return redirect('/auth/login');
            }

            if(!User::where('email', $data['email'])->exists()) {
                Session::put('errorlogin', true);
                return redirect('/auth/login');
            }

            $user = User::where('email', $data['email'])->first();

            if(!Hash::check($data['password'], $user->password)) {
                Session::put('errorlogin', true);
                return redirect('/auth/login');
            }

            Auth::login($user, isset($data['remember']));
            Session::put('success', 'Successfully logged in.');
            return redirect('/');
        }

        return view($this->request['data']['user']['version'] . '/Login', $this->request);
    }

    public function logout(Request $request) {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
