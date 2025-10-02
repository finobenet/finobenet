<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RobloxUtilities extends Controller
{
    protected $db;

    public function __construct() {
        $this->db = DB::connection('finobe');
    }

    /*
        GenerateClientTicket function
         - Used to generate ClientTicket for secured Finobe servers
         - Arseny: What the fuck I just said? Edit it please :sob:
    */
    public static function GenerateClientTicket(int $userId, string $username, string $charApp, string $jobId): string
    {
        $currentDate = date("n\/j\/Y\ g\:i\:s\ A");
        $ticket = "{$userId}\n{$jobId}\n{$currentDate}";
        $signedTicket = SecurityNotary::CreateSignature($ticket);

        $ticket2 = "{$userId}\n{$username}\n{$charApp}\n{$jobId}\n{$currentDate}";
        $signedTicket2 = SecurityNotary::CreateSignature($ticket2);

        return "{$currentDate};{$signedTicket2};{$signedTicket}";
    }

    /*
        GenerateGUID
         - Generating GUID, pretty simple isnt it?
         - Code taken from: https://stackoverflow.com/questions/21671179/how-to-generate-a-new-guid
    */
    public static function GenerateGUID(): string
    {
        if (function_exists('com_create_guid') === true)
        {
            return trim(com_create_guid(), '{}');
        }

        return sprintf('%04X%04X-%04X-%04X-%04X-%04X%04X%04X', mt_rand(0, 65535), mt_rand(0, 65535), mt_rand(0, 65535), mt_rand(16384, 20479), mt_rand(32768, 49151), mt_rand(0, 65535), mt_rand(0, 65535), mt_rand(0, 65535));
    }
    /*
        IsFinobeCloudAuthorized
         - checks if request was by Finobe Cloud
    */
    public static function IsFinobeCloudAuthorized()
    {
        $requestKey = $_SERVER['HTTP_ACCESSKEY'] ?? "";
        $exceptKey = env('FINOBE_CLOUD_AUTHORIZATION');
        return $requestKey === $exceptKey;
    }
    /*
        ConstructPlaceLauncher
         - Generating a placelauncher response...
         - what.?
    */
    public static function ConstructPlaceLauncher(string $jobId, int $status, string $joinScriptUrl, string $authenticationUrl, string $authenticationToken, string $message)
    {
        return json_encode(
            [
                "jobId" => $jobId,
                "status" => $status,
                "joinScriptUrl" => $joinScriptUrl,
                "authenticationUrl" => $authenticationUrl,
                "authenticationTicket" => $authenticationToken,
                "message" => $message
            ],
            JSON_UNESCAPED_SLASHES
        );
    }

    /*
        ConstructJoinScript
         - Generating a join script...
         - Oh my god wtf.
         - My brain aint braining :sob:
    */
    public static function ConstructJoinScript(object $userInformation, object $placeInformation, object $serverInformation, object $additionalInfo)
    {
        switch ($additionalInfo->version) {
            case "2016":
                return json_encode([
                    "ClientPort" => 0,
                    "MachineAddress" => $serverInformation->ip,
                    "ServerPort" => $serverInformation->port,
                    "PingUrl" => "",
                    "PingInterval" => 20,
                    "UserName" => $userInformation->username, // make this depend on database later
                    "SeleniumTestMode" => false,
                    "UserId" => $userInformation->id, // make this depend on database later
                    "SuperSafeChat" => false,
                    "CharacterAppearance" => "http://www.finobe.net/Asset/CharacterFetch.ashx?userId={$userInformation->id}", // TODO
                    "ClientTicket" => RobloxUtilities::GenerateClientTicket($userInformation->id, $userInformation->username,"http://www.finobe.net/Asset/CharacterFetch.ashx?userId={$userInformation->id}", $serverInformation->jobId),
                    "GameId" => $serverInformation->jobId, // actually jobid not GameId purposefully misleading
                    "PlaceId" => $placeInformation->id, // make this depend on database later
                    "MeasurementUrl" => "", // idk what this does tbh
                    "WaitingForCharacterGuid" => RobloxUtilities::GenerateGUID(),
                    "BaseUrl" => "https://www.finobe.net/",
                    "ChatStyle" => "ClassicAndBubble",
                    "VendorId" => "0",
                    "ScreenShotInfo" => "",
                    "VideoInfo" => "",
                    "CreatorId" => $placeInformation->author,
                    "CreatorTypeEnum" => "User",
                    "MembershipType" => "None",
                    "AccountAge" => "0",
                    "CookieStoreFirstTimePlayKey" => "rbx_evt_ftp",
                    "CookieStoreFiveMinutePlayKey" => "rbx_evt_fmp",
                    "CookieStoreEnabled" => true,
                    "IsRobloxPlace" => $placeInformation->author === 1,
                    "GenerateTeleportJoin" => false,
                    "IsUnknownOrUnder13" => false,
                    "SessionId" => "39412c34-2f9b-436f-b19d-b8db90c2e186|00000000-0000-0000-0000-000000000000|0|190.23.103.228|8|2021-03-03T17:04:47+01:00|0|null|null",
                    "DataCenterId" => 0,
                    "UniverseId" => $placeInformation->author,
                    "BrowserTrackerId" => 0,
                    "UsePortraitMode" => false,
                    "FollowUserId" => 0,
                    "characterAppearanceId" => $userInformation->id // same as userID
                ], JSON_UNESCAPED_SLASHES);
            case "2012":
                //okay wtf
                $joinscript = file_get_contents(storage_path("rbx/files/scripts/join2012.lua"));

                if (!file_exists(storage_path("rbx/files/scripts/join2012.lua"))) {
                    return "I'm so tired bruhh";
                }

                //TODO: clean up this shit.
                $joinscript = str_replace("{placeId}", $placeInformation->id, $joinscript);
                $joinscript = str_replace("{jobId}", $serverInformation->jobId, $joinscript);
                $joinscript = str_replace("{serverip}", $serverInformation->ip, $joinscript);
                $joinscript = str_replace("{creatorId}", $placeInformation->author, $joinscript);
                $joinscript = str_replace("{chatstyle}", "ClassicAndBubble", $joinscript); //TODO: please, do this use db.
                $joinscript = str_replace("{serverport}", $serverInformation->port, $joinscript);
                $joinscript = str_replace("{userid}", $userInformation->id, $joinscript);
                $joinscript = str_replace("{username}", $userInformation->username, $joinscript);
                $joinscript = str_replace("{charapp}", "http://www.finobe.net/Asset/CharacterFetch.ashx?userId={$userInformation->id}&placeId={$placeInformation->id}", $joinscript);
                return $joinscript;
            default:
                return "Not implemented yet!";
        }
    }
    /*
        RequestGame
         - Basically requests server for the game
    */
    public static function RequestGame(int $placeId, object $user): object
    {
        $instance = new self();

        if (!$instance->db->table('assets')->where('id', $placeId)->exists()) {
            return (object)[
                "success" => false,
                "data" => [],
                "publicMessage" => "Invalid placeId"
            ];
        }
        $asset = $instance->db->table('assets')
            ->where('id', $placeId)
            ->first();
        
        $additional = json_decode($asset->additional);
        if ($additional->allowplaying === false && $asset->author !== $user->id)
        {
            return (object)[
                "success" => false,
                "data" => [],
                "publicMessage" => "You don't have permission to join this game"
            ];
        }

        if ($asset->asset_type == 9) { //placeId asset_type
            if (!$instance->db->table('servers')->where('placeid', $placeId)->whereIn('status', [1, 2])->exists()) {
                $port = rand(90000, 130000);
                $ip = "104.223.8.156";
                $aaaaaaaaaaa = "[]";
                $godIhatepdo = 1;
                $jobId = self::startGame($placeId, $asset->author, $port);

                $instance->db->table('servers')->insert([
                    'ip' => $ip,
                    'port' => $port,
                    'placeid' => $placeId,
                    'players' => $aaaaaaaaaaa,
                    'jobId' => $jobId,
                    'status' => $godIhatepdo
                ]);

                return (object)[
                    "success" => true,
                    "data" => [
                        "status" => 0,
                        "jobId" => $jobId,
                    ],
                    "publicMessage" => "Requested a new server"
                ];
            }



            $server = $instance->db->table('servers')
                ->where('placeid', $placeId)
                ->whereIn('status', [1, 2])
                ->first();

            if (count(json_decode($server->players)) >= $additional->maxplayers) {
                $port = rand(90000, 130000);
                $ip = "104.223.8.156";
                $aaaaaaaaaaa = "[]";
                $godIhatepdo = 1;
                $jobId = self::startGame($placeId, $asset->author, $port);

                $instance->db->table('servers')->insert([
                    'ip' => $ip,
                    'port' => $port,
                    'placeid' => $placeId,
                    'players' => $aaaaaaaaaaa,
                    'jobId' => $jobId,
                    'status' => $godIhatepdo
                ]);

                return (object)[
                    "success" => true,
                    "data" => [
                        "status" => 0,
                        "jobId" => $jobId,
                    ],
                    "publicMessage" => "Requested a new server"
                ];
            }

            return (object)[
                "success" => true,
                "data" => [
                    "status" => $server->status,
                    "jobId" => $server->jobId,

                ]
            ];
        }

        return (object)[
            "success" => false,
            "data" => [],
            "publicMessage" => "Invalid placeId"
        ];
    }

    private static function startGame(int $placeId, int $creatorId, int $port)
    {
        $arbiter = new RobloxArbiterUtilities("104.223.8.156", 64989);
        $jobId = self::GenerateGUID();
        $gamescript = file_get_contents(storage_path("rbx/files/scripts/gameserver2016.lua"));
        $constructJob = $arbiter->constructJob(
            $jobId,
            "{$gamescript}", //I'll kill myself if this will work :sob:
            90, //infinity, since its gameserver, also this NOT how roblox did, so i'll adjust it later
            0,
            1,
            "ScriptExecution",
            [
                $placeId,
                $port,
                "https://www.finobe.net",
                $creatorId
            ]
        );

        $arbiter->OpenJobEx($constructJob);

        return $jobId;
    }

    /* 
        RequestGameJob
         - Joins an already exist server with jobId
    */
    public static function RequestGameJob(int $placeId, string $jobId, object $user,)
    {
        $instance = new self();

        if (!$instance->db->table('assets')->where('id', $placeId)->exists()) {
            return (object)[
                "success" => false,
                "data" => [],
                "publicMessage" => "Invalid placeId"
            ];
        }
        $asset = $instance->db->table('assets')
            ->where('id', $placeId)
            ->first();
        
        $additional = json_decode($asset->additional);
        if ($additional->allowplaying === false && $asset->author !== $user->id)
        {
            return (object)[
                "success" => false,
                "data" => [],
                "publicMessage" => "You don't have permission to join this game"
            ];
        }
        if ($asset->asset_type == 9) { //placeId asset_type
            if ($instance->db->table('servers')->where('placeid', $placeId)->where('jobId', $jobId)->where('status', 2)->exists()) {
                return (object)[
                    "success" => true,
                    "data" => [
                        "status" => 2,
                        "jobId" => $jobId,
                    ],
                    "publicMessage" => "Joining a server"
                ];
            }
        } else {
            return (object)[
                "success" => false,
                "data" => [],
                "publicMessage" => "Job doesnt exists"
            ];
        }

        return (object)[
            "success" => false,
            "data" => [],
            "publicMessage" => "Unknown error"
        ];
    }
}
