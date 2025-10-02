<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;

class rbxAPIs extends Controller
{
    protected $response;
    protected $db;

    public function __construct() {
        $this->response = [];
        $this->db = DB::connection('finobe');
    }

    public function quietGet(Request $request) {
        $bucketName = $request->query('bucket');
        switch ($bucketName) {
            //TODO: fetch them all from database
            case "ClientAppSettings":
                return file_get_contents(storage_path("rbx/fflags/PCDesktopClient_2016.json"));
            case "PCApplicationSettings":
                return file_get_contents(storage_path("rbx/fflags/PCDesktopClient_2016.json"));
            case "CloudSettings":
                if (RobloxUtilities::IsFinobeCloudAuthorized()) {
                    return file_get_contents(storage_path("rbx/fflags/WindowsComputeCloud_2016.json"));
                }
                
                return response()->json($this->response, 403);
            default:
                $this->response = [
                    "FFlagDeprecateMeaninglessTerrainPropertiesAndMethods" => "True",
                    "FFlagImmediateYieldResultsEnabled" => "True",
                    "FFlagIsChangeHistoryRunAware" => "True",
                    "FFlagNonScriptableAccessEnabled" => "False",
                    "FFlagReparentingLockEnabled" => "False",
                    "FFlagServerScriptProtection" => "True",
                    "FFlagTaskSchedulerUseSharedPtr" => "True",
                    "FFlagWaterEnabled" => "True",
                    "FLogAsserts" => "0",
                    "FLogContentPoviderRequests" => "8",
                    "FLogRCCDataModelInit" => "7",
                    "FLogReplicationDataLifetime" => "0",
                    "FLogTaskSchedulerRun" => "0"
                ];

                return response()->json($this->response, 200);
        }
    }

    public function getAllowedSecurityVersions() {
        if (RobloxUtilities::IsFinobeCloudAuthorized()) {
            $this->response = [
                "data" => [
                    json_decode(file_get_contents(storage_path('app/private/versions.json')), true)['application']
                    //"0.235.0pcplayer" debug
                ]
            ];

            return response()->json($this->response, 200);
        }
        
        $this->response = [
            "code" => 403,
            "message" => "Unauthorized"
        ];

        return response()->json($this->response, 403);
    }

    public function getAllowedMD5Hashes() {
        if (RobloxUtilities::IsFinobeCloudAuthorized()) {
            $this->response = [
                "data" => [
                    json_decode(file_get_contents(storage_path('app/private/versions.json')), true)['md5']
                ]
            ];

            return response()->json($this->response, 200);
        }
        
        $this->response = [
            "code" => 403,
            "message" => "Unauthorized"
        ];

        return response()->json($this->response, 403);
    }
    public function getCompatibility(Request $request)
    {
        $bucket = $request->query('bucket');
        
        switch($bucket) {
            case 'Hashes':
                return $this->getAllowedMD5Hashes();
            case 'Versions':
                return $this->getAllowedSecurityVersions();
            default:
                return response()->json(['error' => 'Invalid bucket parameter'], 400);
        }
    }

    public function validatePlaceJoin() {
        return response('true', 200);
    }

    public function productInfo(Request $request) {
        $data = $request->all();
        $assetId = (int)$data['assetId'] ?? 0;

        if (!$this->db->table('assets')->where('id', $assetId)->exists()) {
            $this->response = [
                "code" => 404,
                "message" => "Invalid assetId"
            ];

            return response()->json($this->response, 404);
        }

        $asset = $this->db->table('assets')
            ->where('id', $assetId)
            ->first();

        $additional = json_decode($asset->additional);
        
        if (!User::find($asset->author)) {
            $this->response = [
                "code" => 404,
                "message" => "Invalid assetId"
            ];

            return response()->json($this->response, 404);
        }

        $user = User::find($asset->author);

        $this->response = [
            "AssetId" => $asset->id,
            "ProductId" => $asset->id,
            "Name" => $asset->title,
            "Description" => $asset->description,
            "AssetTypeId" => $asset->asset_type,
            "ProductType" => "User Product", //idk
            "Creator" => [
                "Id" => $user->id,
                "Name" => $user->username,
            ],
            "IconImageAssetId" => $additional->media->imageAssetId ?? $asset->id,
            "Created" => $asset->created,
            "Updated" => $asset->updated,
            "PriceInRobux" => 0,
            "PriceInTickets" => 0,
            "Sales" => 0,
            "IsNew" => true,
            "IsForSale" => true,
            "IsPublicDomain" => false,
            "IsLimited" => false,
            "IsLimitedUnique" => false,
            "Remaining" => 0,
            "MinimumMembershipLevel" => 0,
            "ContentRatingTypeId" => 0,
        ];

        return response()->json($this->response, 200);
    }

    public function gameServerLua() {
        return response(SecurityNotary::SignScript(file_get_contents(storage_path("rbx/files/scripts/test2012.lua")), FormatVersion::V1), 200);
    }

    public function getScriptStateAshx() {
        return response("0 0 0 00 0 1 0", 200);
    }

    public function asset(Request $request) {
        $data = $request->all();
        $assetId = (int)($data['ID'] ?? $data['Id'] ?? $data['iD'] ?? $data['id'] ?? 0);

        if (file_exists(storage_path("rbx/files/2012CoreGui/{$assetId}.lua")))
        {
            $script = "%{$assetId}%\r\n".file_get_contents(storage_path("rbx/files/2012CoreGui/{$assetId}.lua"));
            return response(SecurityNotary::SignScript($script, FormatVersion::V1, false));
        }

        if (Asset::isAssetExist($assetId))
        {
            if(Asset::isAllowedToDownload($assetId))
            {
                $assetinfo = Asset::getAssetData($assetId);

                if(!in_array($assetinfo->visibility, ['n', 'r'])) {
                    return response('', 200);
                }

                $requester = request()->header('requester');
                $userAgent = request()->header('User-Agent');

                if($assetinfo->visibility == 'r' && !(strtolower($requester) == 'server' && in_array($userAgent, ['Roblox/WinHttp', 'Finobe/WinHttp']))) {
                    if(!Auth::check() || Auth::user()->toArray()['status'] != 'admin') {
                        return response('', 200);
                    }
                }

                if($assetinfo->asset_type != 3) {
                    return response(file_get_contents(Asset::getCdnLink($assetId)), 200)
                        ->header('Content-Type', 'application/octet-stream');
                } else {
                    // SUPER complicated, its so audio player can skim through a range
                    $filepath = '/var/www/cdn.finobe.net/audios/' . $assetinfo->file;

                    if (!file_exists($filepath)) {
                        abort(404);
                    }

                    $size = filesize($filepath);
                    $start = 0;
                    $end = $size - 1;

                    $headers = [
                        'Content-Type' => 'audio/mpeg',
                        'Accept-Ranges' => 'bytes',
                    ];

                    if (request()->header('Range')) {
                        preg_match('/bytes=(\d*)-(\d*)/', request()->header('Range'), $matches);

                        if (isset($matches[1]) && $matches[1] !== '') {
                            $start = intval($matches[1]);
                        }

                        if (isset($matches[2]) && $matches[2] !== '') {
                            $end = intval($matches[2]);
                        }

                        if ($start > $end || $end >= $size) {
                            return response('', 416)->withHeaders([
                                'Content-Range' => "bytes */$size"
                            ]);
                        }

                        $length = $end - $start + 1;

                        $headers['Content-Range'] = "bytes $start-$end/$size";
                        $headers['Content-Length'] = $length;

                        $stream = function () use ($filepath, $start, $length) {
                            $fp = fopen($filepath, 'rb');
                            fseek($fp, $start);
                            $bufferSize = 8192;
                            $bytesToOutput = $length;

                            while (!feof($fp) && $bytesToOutput > 0) {
                                $readLength = min($bufferSize, $bytesToOutput);
                                echo fread($fp, $readLength);
                                flush();
                                $bytesToOutput -= $readLength;
                            }

                            fclose($fp);
                        };

                        return response()->stream($stream, 206, $headers);
                    }

                    return response()->file($filepath, array_merge($headers, ['Content-Length' => $size]));
                }
            }
        } else {
            // Hopefully works on first try?? -Aesthetiful
            $response = Cache::remember('assetid_' . $assetId, 31556952, function() use ($assetId) {
                $response = Http::withHeaders([
                    'User-Agent' => 'Roblox/WinInet',
                    'requester' => 'server',
                    'Cookie' => '.ROBLOSECURITY=_|WARNING:-DO-NOT-SHARE-THIS.--Sharing-this-will-allow-someone-to-log-in-as-you-and-to-steal-your-ROBUX-and-items.|_12F7CD4C7A51242AA368F0B8ABAEB272699DB45AC27966C148A771706A48E3D197D33F5CC344F1426FAB821E0E21F72331BDE6548753F9928F4BC4711891B3F796237C22048535C4865498809CED2C25EA849616D88EAE8CF51C88E768A227A06D2E51722F12FF9BB84AB1789BB91F7AC00BF9DF397E169396B7E3EBA47334EBD1B8148689732C64B9EC939E559D51FAB1AEE8BD1E9E5D6173369AA03EC8AC123BBF58D0E251964F13FE680C368FBC8C351EE258A54CFC2D3FB325FF2BE6E42E66F167EDA4785E347E1EA5FECCAB7E245BFAFA6878DB5D61B3CBD853B766B935ED1C37E86C1C807D5834D4A3BA75F08B6F2718964EB86E15AB59CA2174C5E30F4BDAD3BD759ABE532D2FCA26EB8CA3B4773FB19D19CCD23D621A843DCA707B4CECBC4836312B8FAEB92DB7F362A978F01942DE17F974B62C9851A67E20A32157B8D0010CFD7EB2101091CDE3ED79C7F1FE474DECE10648A84FF633B8F245015BF8D6E9B05B2BA5E1215AD2DD793A5754BC92F002E953A9CDFFD4DB285B2ACB6E47647BE5BE3C2F58F263A3EB47CECC20909E51BF8708DE767FC81750F7F63EABABD4A5071AB3130C11DBCDE26C581340BCE06EB104CC0E14FEA1CD52D366153DCF0D3051A3D152819B2BC76F3B2F93E23534380B42134897189D9BB0A55CB9EFC613EF735B08193ED80A40A447BAE6F8E6438FBFDE6B50DE6AA859B5B3CA3FDE0D120DF1C2A7833C7F79D8927A4B90B9046749598EE1764A2AB70795AA3B122A5A2758BCFECC4375421F91CC34DD802DD5A960C931724089084A6227577686E8373D05C989549F8F1B15257714F1D9FA6D5CC79932F3BFFBDF53E4716220476228E950D9C351958A7EA345B1A9CCCB701D51C0F7CC1FB75915A91F36FE73C40F368EF45A924A4816EE60715746763C0A9299F215367B0EBEA6E6D27CF06D02566B6313A915F9E8C0E9B1246A661BF9082A001DA54A523E4A1759A63CC51867DDD2AC562A956CEAD0C7C9157888B0A6026A301E0E85CA4BBA4E5B7A7A812C31547A857639B6739897AEEA2962D49057E358FC57608BA9EEB652E34AE31833BBFBD5D6A1226BF30D4DC72E0C5AF1E855AEA07BDDDF9428AF2954AD57610B4E2CE58E073EA7951DB5ED742A2D8669B8CA2DFD2B5758'
                ])->withoutVerifying()
                  ->accept('*/*')
                  ->get("https://assetdelivery.roblox.com/v1/asset/", [
                      'id' => $assetId
                  ]);
            
                if(!$response->ok()) {
                    return 'false,Asset fetch failed,' . $response->status();
                }
            
                return $response->body();
            });

            if(str_starts_with($response, 'false')) {
                $response = explode(',', $response);
                abort($response[2], $response[1] . ': ' . $response[2]);
            }

            if(str_contains($response, '%PNG')) {
                return response($response, 200)->header('Content-Type', 'image/png');
            } else {
                $response = str_ireplace(['roblox.com/asset', 'Accessory'], ['finobe.net/asset', 'Hat'], $response);

                return response($response, 200)->header('Content-Type', 'application/octet-stream');
            }
        }
    }

    public function registerJobId($jobId) {
        if (RobloxUtilities::IsFinobeCloudAuthorized())
        {
            $this->db->table('servers')
                ->where('jobId', $jobId)
                ->update([
                    'status' => 2
                ]);
        }
        return response('', 200); //this return nothing i guess
    }

    public function visitJobId($jobId) {
        if (RobloxUtilities::IsFinobeCloudAuthorized())
        {
            $server = $this->db->table('servers')
                ->where('jobId', $jobId)
                ->first();
            
            $assetId = $server->placeid;

            if ($this->db->table('assets')->where('id', $assetId)->exists()) {
                $asset = $this->db->table('assets')
                    ->where('id', $assetId)
                    ->first();
                
                if ($asset->asset_type == 9) {
                    $additional = json_decode($asset->additional);
                    $additional->visits++;
                    $additional = json_encode($additional);

                    $this->db->table('assets')
                        ->where('id', $assetId)
                        ->update([
                            'additional' => $additional
                        ]);
                }
            }
        }
        return response('', 200); //this return nothing i guess
    }

    public function shutdownJobId($jobId) {
        if (RobloxUtilities::IsFinobeCloudAuthorized())
        {
            $this->db->table('servers')
                ->where('jobId', $jobId)
                ->delete();
            
            $arbiter = new RobloxArbiterUtilities("45.131.65.123", 64989);
            $arbiter->CloseJob($jobId);
        }
        return response('', 200); //this return nothing i guess
    }

    public function aliveJobId($jobId) {
        if (RobloxUtilities::IsFinobeCloudAuthorized())
        {
            if($this->db->table('servers')->select('players')->where('jobId', $jobId)->exists() && count(json_decode($this->db->table('servers')->select('players')->where('jobId', $jobId)->value('players')))) {
                $arbiter = new RobloxArbiterUtilities("45.131.65.123", 64989);
                $arbiter->RenewLease($jobId, 90);
            }
        }
        return response('', 200); //this return nothing i guess
    }

    public function update(Request $request) {
        if (!RobloxUtilities::IsFinobeCloudAuthorized()) {
            $this->response = [
                "code" => 403,
                "message" => "You're not authorized to access this page."
            ];

            return response()->json($this->response, 403);
        }

        $json = $request->getContent();
        $data = json_decode($json);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->response = [
                "code" => 400,
                "message" => "Invalid JSON data."
            ];

            return response()->json($this->response, 400);
        }
        $jobId = $data->jobId ?? "";
        $players = json_encode($data->players);

        if (!$this->db->table('servers')->where('jobId', $jobId)->exists()) {
            $this->response = [
                "code" => 400,
                "message" => "Invalid jobId"
            ];

            return response()->json($this->response, 400);
        }

        $this->db->table('servers')
            ->where('jobId', $jobId)
            ->update([
                'players' => $players
            ]);

        $this->response = [
            "code" => 200,
            "message" => "Players successfully updated"
        ];

        return response()->json($this->response, 200);
    }

    public function characterFetch(Request $request) {
        $data = $request->all();

        if (!isset($data["userId"]))
        {
            return response('', 200);
        }
        $userId = (int)$data["userId"];

        if (!User::find($userId)) {
            $this->response = [
                "code" => 404,
                "message" => "User does not exist."
            ];

            return response()->json($this->response, 404);
        }

        $avatar = json_decode(User::find($userId)->avatar, false);

        $ids = array_merge(
            $avatar[0]->equippedGearVersionIds ?? [],
            $avatar[0]->backpackGearVersionIds ?? []
        );
        $assetUrls = $ids ? implode(";", array_map(
            fn($id) => "http://www.finobe.net/asset/?id=$id",
            $ids
        )) : "";

        return response("http://www.finobe.net/Asset/BodyColors.ashx?userId={$userId};{$assetUrls}", 200);
    }

    public function bodyColors(Request $request) {
        $data = $request->all();

        if (!isset($data["userId"]))
        {
            return response('', 200);
        }
        $userId = (int)$_GET["userId"];

        if (!User::find($userId)) {
            $this->response = [
                "code" => 404,
                "message" => "User does not exist."
            ];

            return response()->json($this->response, 404);
        }

        $colors = json_decode(User::find($userId)->avatar, false)[0]->bodyColors;

        return '<roblox xmlns:xmime="http://www.w3.org/2005/05/xmlmime" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:noNamespaceSchemaLocation="http://www.finobe.net/roblox.xsd" version="4">
            <External>null</External>
            <External>nil</External>
            <Item class="BodyColors">
                <Properties>
                    <int name="HeadColor">'.$colors->headColorId.'</int>
                    <int name="LeftArmColor">'.$colors->leftArmColorId.'</int>
                    <int name="LeftLegColor">'.$colors->leftLegColorId.'</int>
                    <string name="Name">Body Colors</string>
                    <int name="RightArmColor">'.$colors->rightArmColorId.'</int>
                    <int name="RightLegColor">'.$colors->rightLegColorId.'</int>
                    <int name="TorsoColor">'.$colors->torsoColorId.'</int>
                    <bool name="archivable">true</bool>
                </Properties>
            </Item>
        </roblox>';
    }

    public function negotiateAshx(Request $request) {
        $data = $request->all();

        if (!isset($data["suggest"]))
        {
            $this->response = [
                "code" => 405,
                "message" => "Suggest is missing."
            ];

            return response()->json($this->response, 405);
        }

        if (!User::where('token', $data['suggest']))
        {
            $this->response = [
                "code" => 404,
                "message" => "Invalid token."
            ];

            return response()->json($this->response, 404);
        }

        $authToken = User::where('token', $data['suggest'])->first();

        Auth::login($authToken);

        return ($_COOKIE['finobe_session'] ?? Auth::id());
    }

    public function studioAshx() {
        return response(SecurityNotary::SignScript('-- Setup studio cmd bar & load core scripts

        pcall(function() game:GetService("InsertService"):SetFreeModelUrl("http://www.finobe.net/Game/Tools/InsertAsset.ashx?type=fm&q=%s&pg=%d&rs=%d") end)
        pcall(function() game:GetService("InsertService"):SetFreeDecalUrl("http://www.finobe.net/Game/Tools/InsertAsset.ashx?type=fd&q=%s&pg=%d&rs=%d") end)

        game:GetService("ScriptInformationProvider"):SetAssetUrl("http://www.finobe.net/Asset/")
        game:GetService("InsertService"):SetBaseSetsUrl("http://www.finobe.net/Game/Tools/InsertAsset.ashx?nsets=10&type=base")
        game:GetService("InsertService"):SetUserSetsUrl("http://www.finobe.net/Game/Tools/InsertAsset.ashx?nsets=20&type=user&userid=%d")
        game:GetService("InsertService"):SetCollectionUrl("http://www.finobe.net/Game/Tools/InsertAsset.ashx?sid=%d")
        game:GetService("InsertService"):SetAssetUrl("http://www.finobe.net/Asset/?id=%d")
        game:GetService("InsertService"):SetAssetVersionUrl("http://www.finobe.net/Asset/?assetversionid=%d")

        pcall(function() game:GetService("SocialService"):SetFriendUrl("http://www.finobe.net/Game/LuaWebService/HandleSocialRequest.ashx?method=IsFriendsWith&playerid=%d&userid=%d") end)
        pcall(function() game:GetService("SocialService"):SetBestFriendUrl("http://www.finobe.net/Game/LuaWebService/HandleSocialRequest.ashx?method=IsBestFriendsWith&playerid=%d&userid=%d") end)
        pcall(function() game:GetService("SocialService"):SetGroupUrl("http://www.finobe.net/Game/LuaWebService/HandleSocialRequest.ashx?method=IsInGroup&playerid=%d&groupid=%d") end)
        pcall(function() game:GetService("SocialService"):SetGroupRankUrl("http://www.finobe.net/Game/LuaWebService/HandleSocialRequest.ashx?method=GetGroupRank&playerid=%d&groupid=%d") end)
        pcall(function() game:GetService("SocialService"):SetGroupRoleUrl("http://www.finobe.net/Game/LuaWebService/HandleSocialRequest.ashx?method=GetGroupRole&playerid=%d&groupid=%d") end)
        pcall(function() game:GetService("GamePassService"):SetPlayerHasPassUrl("http://www.finobe.net/Game/GamePass/GamePassHandler.ashx?Action=HasPass&UserID=%d&PassID=%d") end)
        pcall(function() game:GetService("MarketplaceService"):SetProductInfoUrl("https://api.finobe.net/marketplace/productinfo?assetId=%d") end)
        pcall(function() game:GetService("MarketplaceService"):SetDevProductInfoUrl("https://api.finobe.net/marketplace/productDetails?productId=%d") end)
        pcall(function() game:GetService("MarketplaceService"):SetPlayerOwnsAssetUrl("https://api.finobe.net/ownership/hasasset?userId=%d&assetId=%d") end)

        local result = pcall(function() game:GetService("ScriptContext"):AddStarterScript(37801172) end)
        if not result then
          pcall(function() game:GetService("ScriptContext"):AddCoreScript(37801172,game:GetService("ScriptContext"),"StarterScript") end)
        end', FormatVersion::V1), 200);
    }

    public function placeLauncher(Request $request) {
        $data = $request->all();

        if (!Auth::check()) {
            $this->response = [
                "code" => 403,
                "message" => "You're not authorized to access this page."
            ];

            return response()->json($this->response, 403);
        }

        $user = (object) Auth::user()->toArray();

        if(!in_array($user->id, [1, 2, 29, 88, 4365, 565, 619, 2394, 1054, 4922, 5317, 395, 4189, 2836, 4861, 4862, 580, 450, 4363, 2722, 1386, 3144, 4203, 5322, 4875, 1211, 246, 3269, 4828, 5373, 4979, 5374, 5368, 4363, 4790, 2402, 5405])) {
            $this->response = [
                "code" => 403,
                "message" => "You're not authorized to access this page."
            ];

            return response()->json($this->response, 403);
        }

        $requestType = $data["request"] ?? "RequestGame";
        $placeId = $data["placeId"] ?? 0;
        $isTeleport = $data["isTeleport"] ?? false;

        switch ($requestType) {
            case "RequestGame":
                $requestGame = RobloxUtilities::RequestGame($placeId, $user);
                if ($requestGame->success == true)
                {
                    $data = (object)$requestGame->data;

                    if ($data->status != 2)
                    {
                        return response(RobloxUtilities::ConstructPlaceLauncher($data->jobId, $data->status, "", "", "", ""), 200);
                    }
                    return response(RobloxUtilities::ConstructPlaceLauncher($data->jobId, $data->status, "https://assetgame.finobe.net/Game/Join.ashx?jobId={$data->jobId}", "https://www.finobe.net/Login/Negotiate.ashx", $user->token, ""), 200);
                }
            case "RequestGameJob":
                $jobId = $data["gameId"];
                $RequestGameJob = RobloxUtilities::RequestGameJob($placeId, $jobId, $user);

                if ($RequestGameJob->success == true)
                {
                    $data = (object)$RequestGameJob->data;

                    if ($data->status != 2)
                    {
                        return response(RobloxUtilities::ConstructPlaceLauncher($data->jobId, $data->status, "", "", "", ""), 200);
                    }
                    return response(RobloxUtilities::ConstructPlaceLauncher($data->jobId, $data->status, "https://assetgame.finobe.net/Game/Join.ashx?jobId={$data->jobId}", "https://www.finobe.net/Login/Negotiate.ashx", $user->token, ""), 200);
                }
            default:
                return response('', 200);
        }
    }

    public function joinAshx(Request $request) {
        $data = $request->all();

        if (!Auth::check()) {
            $this->response = [
                "code" => 403,
                "message" => "You're not authorized to access this page."
            ];

            return response()->json($this->response, 403);
        }

        try
        {
            /*this code may be unnecesary (accounts never get deleted, only marked perm banned for "deleted") 
            $db = new databaseController(conf::get()['project']['database']['db']);

            $userInformationQuery = $db->prepare("SELECT * FROM `users` WHERE `username` = :username LIMIT 1");
            $userInformationQuery->bindParam(":username", $_SESSION['siteusername'], PDO::PARAM_STR);
            $userInformationQuery->execute();

            if (!User::user()) {
                http_response_code(404);
                return json_encode([
                    "code" => 404,
                    "message" => "User does not exist."
                ]);
            }
            */

            $userInformation = Auth::user();

            if (!isset($data["jobId"])) {
                $this->response = [
                    "code" => 405,
                    "message" => "jobId is missing."
                ];

                return response()->json($this->response, 405);
            }
            $jobId = $data["jobId"];

            if (!$this->db->table('servers')->where('jobId', $jobId)->exists()) {
                $this->response = [
                    "code" => 404,
                    "message" => "Server not found."
                ];

                return response()->json($this->response, 404);
            }

            $serverInformation = $this->db->table('servers')->where('jobId', $jobId)->first();

            if (!$this->db->table('servers')->where('placeId', $serverInformation->placeid)->exists()) {
                $this->response = [
                    "code" => 404,
                    "message" => "Invalid placeId"
                ];

                return response()->json($this->response, 404);
            }

            $placeInformation = $this->db->table('assets')->where('id', $serverInformation->placeid)->first();
            $additionalInfo = json_decode($placeInformation->additional, false);

            if ($additionalInfo->allowplaying === false && $placeInformation->author !== $userInformation->id) {
                $this->response = [
                    "code" => 403,
                    "message" => "You do not have permission to join this game."
                ];

                return response()->json($this->response, 403);
            }

            if (count(json_decode($serverInformation->players)) >= $additionalInfo->maxplayers) {
                $this->response = [
                    "code" => 403,
                    "message" => "This game is full."
                ];

                return response()->json($this->response, 403);
            }
            //what the actual fuck
            $joinScript = RobloxUtilities::ConstructJoinScript($userInformation, $placeInformation, $serverInformation, $additionalInfo);

            if ($additionalInfo->version === "2012") {
                return response(SecurityNotary::SignScript($joinScript, FormatVersion::V1), 200);
            }

            return response(SecurityNotary::SignScript($joinScript, FormatVersion::V2), 200);

        }
        catch (exception $e)
        {
            //I hope this won't happen :pray:
            $this->response = [
                "code" => 500,
                "message" => "Something went wrong!",
                "details" => $e->getMessage()
            ];

            return response()->json($this->response, 500);
        }
    }
}
