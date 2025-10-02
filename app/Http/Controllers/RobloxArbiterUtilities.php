<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class RobloxArbiterUtilities extends Controller
{
    private $SoapClient;

    function __construct($url = "127.0.0.1", $port = 64989) {
        $this->SoapClient = new \SoapClient(storage_path("rbx/files/RCCService.wsdl"), ["location" => "http://{$url}:$port", "uri" => "http://finobe.net/", "exceptions" => false, "connection_timeout" => 30]); //Arseny: I hope this works like thsis :sob:
    }

    function SoapCallService(string $name, array $arguments = [])
    {
        $result = $this->SoapClient->{$name}($arguments);
        return (!is_soap_fault($result) ? (!isset($result->{$name."Result"}) ? null : $result->{$name."Result"}) : $result);
    }

    private static function ParseJobResult($value) {
        if (isset($value->LuaValue)) {
            if (is_array($value->LuaValue)) {
                $result = $value->LuaValue[0]->value;
            } else {
                $result = $value->LuaValue->value;
            }
        } else {
            $result = null;
        }
        return $result;
    }
    private function verifyLuaValue($value)
    {
        if (is_bool($value) || $value === 1) {
            return json_encode($value);
        }
        return $value;
    }

    private function GetLuaType(string $value): string
    {
        switch ($value) {
            case $value == "true" || $value == "false":
                return "LUA_TBOOLEAN";
            case !is_string($value) && !is_bool($value) && filter_var($value, FILTER_VALIDATE_INT):
                return "LUA_TNUMBER";
            default:
                return "LUA_TSTRING";
        }
    }

    public function ConstructScriptArguments(array $arguments): array
    {
        $luaValues = [];

        foreach ($arguments as $argument) {
            $luaValues[] = [
                "type" => $this->getLuaType($argument),
                "value" => $this->verifyLuaValue($argument)
            ];
        }

        return ["LuaValue" => $luaValues];
    }

    public function ConstructJob(string $jobId, string $script, int $expiration = 60, int $category = 0, int $cores = 1, string $name = "ScriptExecution", array $arguments = []): array
    {
        return [
            "job" => [
                "id" => $jobId,
                "expirationInSeconds" => $expiration,
                "category" => $category,
                "cores" => $cores
            ],
            "script" => [
                "name" => $name,
                "script" => $script,
                "arguments" => $this->ConstructScriptArguments($arguments)
            ]
        ];
    }

    public function OpenJobEx(array $arguments = [])
    {
        $result = $this->SoapCallService("OpenJobEx", $arguments);
        return self::ParseJobResult($result);
    }

    public function GetVersion()
    {
        return $this->SoapCallService("GetVersion");
    }

    public function HelloWorld()
    {
        return $this->SoapCallService("HelloWorld");
    }

    public function CloseAllJobs()
    {
        return $this->SoapCallService("CloseAllJobs");
    }

    public function CloseExpiredJobs()
    {
        return $this->SoapCallService("CloseExpiredJobs");
    }

    public function GetAllJobsEx()
    {
        return $this->SoapCallService("GetAllJobsEx");
    }

    public function GetStatus()
    {
        return $this->SoapCallService("GetStatus");
    }

    function CloseJob($jobID) {
        return $this->SoapCallService("CloseJob", ["jobID" => $jobID]);
    }

    function RenewLease($jobID, $expirationInSeconds) {
        return $this->SoapCallService("RenewLease", ["jobID" => $jobID, "expirationInSeconds" => $expirationInSeconds]);
    }
}
